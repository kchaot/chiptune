<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

/**
 * Liest Layer-Songs aus Markdown.
 *
 * Jeder Block ist ein Layer: ein Song eines Autors, ganz oder in Auswahl (`tracks`, `bars`), an eine Stelle
 * gesetzt (`at`), transponiert, wiederholt oder bis zum Ende gefüllt (`repeat`). Aus den Layern entsteht ein
 * Song mit gemeinsamem Raster, dessen Tracks aus Clips der Quellsongs bestehen. Das Ergebnis ist ein `Song`.
 */
final class LayerParser
{
    public const MAX_LAYERS = 8;
    public const MAX_LAYER_TRACKS = 32;

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly SongAssembler $assembler = new SongAssembler(),
        private readonly ClipParser $clips = new ClipParser(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve, string $owner): Song
    {
        Fields::size($source, SongParser::MAX_BYTES);

        [$head, $chunks] = Fields::blocks($this->markdown, $source, 'layer');

        /** @var array<string, mixed> $meta */

        $meta = $head->frontmatter ?? [];

        Fields::version($meta);

        if ($chunks === []) {
            throw new BuildError('Keine Layer gefunden. Jeder Layer beginnt mit einem Marker <!-- layer: prefix/slug -->.');
        }

        if (count($chunks) > self::MAX_LAYERS) {
            throw new BuildError(sprintf('Mehr als %d Layer.', self::MAX_LAYERS));
        }

        $remix = Fields::optionalFlag($meta, 'remix', 'Layer-Song');

        /** @var list<array{ref: string, prefix: string, slug: string, source: Song, name: string, tracks: list<string>, at: int, bars: mixed, transpose: int, repeat: int|'fill', where: string, description: string, length: int, start: int}> $layers */
        $layers = [];

        foreach ($chunks as $index => $chunk) {
            $ref = trim((string) $chunk->name);
            $where = 'Layer ' . ($index + 1) . " („{$ref}“)";

            [$prefix, $slug] = Fields::marker($ref, $where, 'layer');

            /** @var array<string, mixed> $fm */

            $fm = $chunk->frontmatter;
            Fields::keys($fm, ['at', 'bars', 'repeat', 'transpose', 'tracks', 'name'], $where);

            $source = $resolve($ref)['song'];

            Fields::mayRemix($where, $ref, $prefix, $owner, $source->remix);

            $name = $fm['name'] ?? $source->title;

            if (!is_string($name) || $name === '' || mb_strlen($name) > 32 || preg_match('/[\x00-\x1F\x7F<>&"]/', $name) === 1) {
                throw new BuildError("{$where}: „name“ hat 1 bis 32 Zeichen ohne < > & \".");
            }

            $names = array_map(fn(Track $track): string => $track->name, $source->tracks);
            $selected = $names;

            if (array_key_exists('tracks', $fm)) {
                $selected = $fm['tracks'];

                if (!is_array($selected) || $selected === [] || !array_is_list($selected)) {
                    throw new BuildError("{$where}: „tracks“ muss eine Liste von Track-Namen sein.");
                }

                foreach ($selected as $wanted) {
                    if (!is_string($wanted) || !in_array($wanted, $names, true)) {
                        throw new BuildError(
                            "{$where}: „{$ref}“ hat keinen Track „"
                            . (is_string($wanted) ? $wanted : (string) json_encode($wanted))
                            . '“ (vorhanden: '
                            . implode(', ', $names)
                            . ').',
                        );
                    }
                }
            }

            /** @var list<string> $selected */
            $repeat = $fm['repeat'] ?? 1;

            if ($repeat !== 'fill') {
                $repeat = Fields::integer($repeat, "{$where}: „repeat“", 1, ClipParser::MAX_REPEAT);
            }

            $layers[] = [
                'ref' => $ref,
                'prefix' => $prefix,
                'slug' => $slug,
                'source' => $source,
                'name' => $name,
                'tracks' => array_values($selected),
                'at' => isset($fm['at']) ? Fields::integer($fm['at'], "{$where}: „at“", 1, ClipParser::MAX_AT) : 1,
                'bars' => $fm['bars'] ?? null,
                'transpose' => isset($fm['transpose']) ? Fields::integer($fm['transpose'], "{$where}: „transpose“", -ClipParser::MAX_TRANSPOSE, ClipParser::MAX_TRANSPOSE) : 0,
                'repeat' => $repeat,
                'where' => $where,
                'description' => $this->sanitizer->clean($chunk->html),
                'length' => 0,
                'start' => 0,
            ];
        }

        $bpm = Fields::number($meta, 'bpm', (float) $layers[0]['source']->bpm, SongParser::BPM_MIN, SongParser::BPM_MAX);

        if (isset($meta['grid'])) {
            $grid = (int) Fields::number($meta, 'grid', SongParser::GRID_DEFAULT, SongParser::GRID_MIN, SongParser::GRID_MAX);

            if (($grid % 4) !== 0) {
                throw new BuildError('Layer-Song: „grid“ muss durch 4 teilbar sein (zum Beispiel 8, 16 oder 32).');
            }

            $stepsPerBeat = intdiv($grid, 4);
        } else {
            $stepsPerBeat = 1;

            foreach ($layers as $layer) {
                $other = $layer['source']->stepsPerBeat;
                $stepsPerBeat = intdiv($stepsPerBeat * $other, $this->gcd($stepsPerBeat, $other));
            }

            if (($stepsPerBeat * 4) > SongParser::GRID_MAX) {
                throw new BuildError(
                    'Die Raster der Layer (grid '
                    . implode(', ', array_map(fn(array $l): string => (string) ($l['source']->stepsPerBeat * 4), $layers))
                    . ') ergeben zusammen mehr als grid 64. Ein Layer müsste ein anderes Raster bekommen.',
                );
            }
        }

        $dstBar = $stepsPerBeat * 4;

        foreach ($layers as $i => $layer) {
            $src = $layer['source'];

            if ($layer['bars'] !== null) {
                [$a, $b] = Fields::range($layer['bars'], "{$layer['where']}: „bars“");

                if (($b * $src->stepsPerBar) > $src->steps) {
                    throw new BuildError("{$layer['where']}: Der Ausschnitt reicht bis Bar {$b}, „{$layer['ref']}“ hat nur {$src->bars} Bars.");
                }

                $length = ($b - $a + 1) * $dstBar;
            } else {
                if ((($src->steps * $stepsPerBeat) % $src->stepsPerBeat) !== 0) {
                    throw new BuildError(
                        "{$layer['where']}: Das Raster von „{$layer['ref']}“ (grid " . ($src->stepsPerBeat * 4) . ") passt nicht auf grid {$dstBar} dieses Layers.",
                    );
                }

                $length = intdiv($src->steps * $stepsPerBeat, $src->stepsPerBeat);
            }

            $layers[$i]['length'] = $length;
            $layers[$i]['start'] = ($layer['at'] - 1) * $dstBar;
        }

        $total = 0;

        foreach ($layers as $layer) {
            $fixed = $layer['repeat'];

            if ($fixed !== 'fill') {
                $total = max($total, $layer['start'] + ($layer['length'] * $fixed));
            }
        }

        if ($total === 0) {
            throw new BuildError('Mindestens ein Layer braucht eine feste Länge. Bei lauter „repeat: fill“ gibt es nichts, woran sich das Füllen orientiert.');
        }

        $tracks = [];
        $credits = [];

        foreach ($layers as $layer) {
            $repeat = $layer['repeat'] === 'fill' ? max(1, (int) ceil(($total - $layer['start']) / $layer['length'])) : $layer['repeat'];

            $group = new TrackGroup(
                title: $layer['name'],
                ref: $layer['ref'],
                bpm: $layer['source']->bpm,
                at: $layer['at'],
                description: $layer['description'],
            );

            foreach ($layer['tracks'] as $trackName) {
                $isDrums = false;

                foreach ($layer['source']->tracks as $candidate) {
                    if ($candidate->name === $trackName) {
                        $isDrums = $candidate->isDrums();
                    }
                }

                $clip = ['from' => $layer['ref'] . '#' . $trackName, 'at' => $layer['at'], 'repeat' => $repeat];

                if ($layer['bars'] !== null) {
                    $clip['bars'] = $layer['bars'];
                }

                if ($layer['transpose'] !== 0 && !$isDrums) {
                    $clip['transpose'] = $layer['transpose'];
                }

                $result = $this->clips->parse([$clip], "{$layer['where']}, Track „{$trackName}“", $stepsPerBeat, $resolve, $owner, $credits);
                $first = $result['first'] ?? throw new \LogicException("{$layer['where']}: Track „{$trackName}“ ohne Quelle.");
                $events = $result['events'];
                usort($events, fn(array $a, array $b): int => $a['t'] <=> $b['t']);

                $tracks[] = new Track(
                    name: $trackName,
                    instrument: $first->instrument,
                    volume: $first->volume,
                    gate: $first->gate,
                    pw: $first->pw,
                    steps: $result['end'],
                    events: $events,
                    refs: $result['bands'],
                    notesHtml: $first->notesHtml,
                    group: $group,
                );
            }
        }

        if (count($tracks) > self::MAX_LAYER_TRACKS) {
            throw new BuildError(sprintf('Mehr als %d Tracks insgesamt. Mit „tracks:“ lässt sich pro Layer eine Auswahl treffen.', self::MAX_LAYER_TRACKS));
        }

        $layerInfo = array_values(array_map(fn(array $l): SongLayer => new SongLayer($l['name'], $l['ref'], $l['source']->bpm, $l['at']), $layers));

        return $this->assembler->assemble($meta, $head, $tracks, $bpm, $stepsPerBeat, $fallbackTitle, $remix, $credits, $layerInfo);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
