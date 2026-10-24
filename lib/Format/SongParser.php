<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;
use Tempest\Markdown\ParsedMarkdown;

/**
 * Liest Songs und Layer-Songs aus Markdown.
 *
 * Die Datei besteht aus einem Kopf (Titel, Tempo, Raster) und je einem Block pro Track mit
 * Einstellungen und Noten. Bei einem Layer-Song steht pro Block ein Layer. Das Ergebnis ist ein
 * `Song`. Die Grenzen (`MAX_*`) werden geprüft, Fehler als `BuildError` gemeldet.
 */
final class SongParser
{
    public const MAX_BYTES = 65_536;
    public const MAX_TRACKS = 16;
    public const MAX_STEPS = 4096;
    public const MAX_LAYERS = 8;
    public const MAX_LAYER_TRACKS = 32;
    public const MAX_CLIPS = 16;
    public const MAX_REPEAT = 64;
    public const MAX_AT = 4096;
    public const MAX_TRANSPOSE = 36;
    public const BPM_MIN = 20;
    public const BPM_MAX = 300;
    public const BPM_DEFAULT = 120;
    public const GRID_MIN = 4;
    public const GRID_MAX = 64;
    public const GRID_DEFAULT = 16;

    private const INSTRUMENTS = ['square', 'pulse', 'triangle', 'saw', 'sine', 'drums'];
    private const DRUMS = ['k', 's', 'c', 'h', 'o'];
    private const SEMITONES = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly MediaParser $media = new MediaParser(),
    ) {}

    public function kind(string $source): string
    {
        foreach ($this->markdown->parseMany($source, 'song', 'part') as $chunk) {
            $kind = $chunk->frontmatter['kind'] ?? 'song';

            if ($kind === 'release') {
                return 'link';
            }

            return in_array($kind, ['set', 'layer', 'session', 'link'], true) ? $kind : 'song';
        }

        return 'song';
    }

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, song: Song})|null $resolve
     * @param string $owner
     */
    public function parse(string $source, string $fallbackTitle = 'Untitled', ?\Closure $resolve = null, string $owner = ''): Song
    {
        Fields::size($source, self::MAX_BYTES);

        [$head, $chunks] = Fields::blocks($this->markdown, $source, 'track');

        if ($chunks === []) {
            throw new BuildError('Keine Tracks gefunden. Jeder Track beginnt mit einem Marker <!-- track: name -->.');
        }

        if (count($chunks) > self::MAX_TRACKS) {
            throw new BuildError(sprintf('Mehr als %d Tracks.', self::MAX_TRACKS));
        }

        /** @var array<string, mixed> $meta */

        $meta = $head->frontmatter ?? [];
        Fields::version($meta);

        $bpm = Fields::number($meta, 'bpm', self::BPM_DEFAULT, self::BPM_MIN, self::BPM_MAX);
        $grid = (int) Fields::number($meta, 'grid', self::GRID_DEFAULT, self::GRID_MIN, self::GRID_MAX);

        if (($grid % 4) !== 0) {
            throw new BuildError('Song: „grid“ muss durch 4 teilbar sein (zum Beispiel 8, 16 oder 32).');
        }

        $stepsPerBeat = intdiv($grid, 4);
        $stepsPerBar = $stepsPerBeat * 4;

        $remix = Fields::optionalFlag($meta, 'remix', 'Song');

        $credits = [];
        $tracks = [];

        foreach ($chunks as $chunk) {
            $tracks[] = $this->parseTrack($chunk, $stepsPerBeat, $resolve, $owner, $credits);
        }

        return $this->assemble($meta, $head, $tracks, $bpm, $stepsPerBeat, $fallbackTitle, $remix, $credits);
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<Track> $tracks
     * @param array<string, array{prefix: string, slug: string, title: string}> $credits
     * @param list<SongLayer> $layers
     */
    private function assemble(
        array $meta,
        ?ParsedMarkdown $head,
        array $tracks,
        float $bpm,
        int $stepsPerBeat,
        string $fallbackTitle,
        ?bool $remix,
        array $credits,
        array $layers = [],
    ): Song {
        $stepsPerBar = $stepsPerBeat * 4;
        $steps = max(array_map(fn(Track $track): int => $track->steps, $tracks));

        $warnings = [];

        foreach ($tracks as $track) {
            if ($layers === [] && $track->steps !== $steps) {
                $warnings[] = "Track „{$track->name}“ hat {$track->steps} Steps, der längste {$steps}.";
            }
        }

        if (($steps % $stepsPerBar) !== 0) {
            $warnings[] = "Die Länge von {$steps} Steps ist kein ganzes Vielfaches von {$stepsPerBar} Steps pro Bar.";
        }

        $loop = Fields::flag($meta, 'loop', true, 'Song');

        $info = Fields::describe($meta, $fallbackTitle);

        return new Song(
            title: $info['title'],
            artist: $info['artist'],
            group: $info['group'],
            tags: $info['tags'],
            bpm: $bpm,
            stepsPerBeat: $stepsPerBeat,
            stepsPerBar: $stepsPerBar,
            stepSeconds: (60 / $bpm) / $stepsPerBeat,
            loop: $loop,
            steps: $steps,
            bars: $steps / $stepsPerBar,
            tracks: $tracks,
            warnings: $warnings,
            notesHtml: $this->sanitizer->clean($head->html ?? ''),
            media: $this->media->parse($meta),
            remix: $remix,
            credits: array_values($credits),
            layers: $layers,
        );
    }

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parseLayer(string $source, string $fallbackTitle, \Closure $resolve, string $owner): Song
    {
        Fields::size($source, self::MAX_BYTES);

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
                $repeat = Fields::integer($repeat, "{$where}: „repeat“", 1, self::MAX_REPEAT);
            }

            $layers[] = [
                'ref' => $ref,
                'prefix' => $prefix,
                'slug' => $slug,
                'source' => $source,
                'name' => $name,
                'tracks' => array_values($selected),
                'at' => isset($fm['at']) ? Fields::integer($fm['at'], "{$where}: „at“", 1, self::MAX_AT) : 1,
                'bars' => $fm['bars'] ?? null,
                'transpose' => isset($fm['transpose']) ? Fields::integer($fm['transpose'], "{$where}: „transpose“", -self::MAX_TRANSPOSE, self::MAX_TRANSPOSE) : 0,
                'repeat' => $repeat,
                'where' => $where,
                'description' => $this->sanitizer->clean($chunk->html),
                'length' => 0,
                'start' => 0,
            ];
        }

        $bpm = Fields::number($meta, 'bpm', (float) $layers[0]['source']->bpm, self::BPM_MIN, self::BPM_MAX);

        if (isset($meta['grid'])) {
            $grid = (int) Fields::number($meta, 'grid', self::GRID_DEFAULT, self::GRID_MIN, self::GRID_MAX);

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

            if (($stepsPerBeat * 4) > self::GRID_MAX) {
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

                $result = $this->clips([$clip], "{$layer['where']}, Track „{$trackName}“", $stepsPerBeat, $resolve, $owner, $credits);
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

        return $this->assemble($meta, $head, $tracks, $bpm, $stepsPerBeat, $fallbackTitle, $remix, $credits, $layerInfo);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, song: Song})|null $resolve
     * @param array<string, array{prefix: string, slug: string, title: string}> $credits
     */
    private function parseTrack(ParsedMarkdown $chunk, int $stepsPerBeat, ?\Closure $resolve, string $owner, array &$credits): Track
    {
        $name = trim((string) $chunk->name);
        /** @var array<string, mixed> $fm */
        $fm = $chunk->frontmatter;
        $where = "Track „{$name}“";

        if ($name === '' || mb_strlen($name) > 32 || preg_match('/[\x00-\x1F\x7F<>&"]/', $name) === 1) {
            throw new BuildError("Ungültiger Track-Name „{$name}“. Erlaubt sind 1 bis 32 Zeichen ohne < > & \".");
        }

        $hasClips = array_key_exists('clips', $fm);
        [$grid, $description] = $this->splitTrack($chunk, $name, $hasClips);

        $clipEvents = [];
        $clipEnd = 0;
        $inherit = null;
        $sourceKinds = [];
        $bands = [];

        if ($hasClips) {
            ['events' => $clipEvents, 'end' => $clipEnd, 'first' => $inherit, 'drums' => $sourceKinds, 'bands' => $bands] = $this->clips(
                $fm['clips'],
                $where,
                $stepsPerBeat,
                $resolve,
                $owner,
                $credits,
            );
        }

        $instrument = $fm['instrument'] ?? $inherit->instrument ?? 'square';

        if (!is_string($instrument) || !in_array($instrument, self::INSTRUMENTS, true)) {
            throw new BuildError("{$where}: unbekanntes instrument (erlaubt: " . implode(', ', self::INSTRUMENTS) . ').');
        }

        $isDrums = $instrument === 'drums';

        foreach ($sourceKinds as $sourceIsDrums) {
            if ($sourceIsDrums !== $isDrums) {
                throw new BuildError("{$where}: clips aus einem " . ($sourceIsDrums ? 'Drum' : 'Noten') . '-Track passen nicht zu instrument: ' . $instrument . '.');
            }
        }

        $events = [];
        $step = 0;
        $last = null;

        foreach ($grid === null ? [] : (preg_split('/\s+/', trim($grid), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $token) {
            if ($token === '|') {
                continue;
            }

            if ($step >= self::MAX_STEPS) {
                throw new BuildError("{$where}: mehr als " . self::MAX_STEPS . ' Steps.');
            }

            if ($token === '.') {
                $step++;
                $last = null;

                continue;
            }

            if ($token === '-') {
                if ($last === null) {
                    throw new BuildError("{$where}, Step {$step}: „-“ steht ohne vorhergehende Note.");
                }

                $events[$last]['len']++;
                $step++;

                continue;
            }

            if ($isDrums) {
                if (!in_array($token, self::DRUMS, true)) {
                    throw new BuildError("{$where}, Step {$step}: unbekannter Drum-Befehl „{$token}“ (erlaubt: " . implode(', ', self::DRUMS) . ').');
                }

                $events[] = ['t' => $step, 'len' => 1, 'd' => $token];
            } else {
                $frequency = $this->frequency($token) ?? throw new BuildError("{$where}, Step {$step}: unbekanntes Token „{$token}“.");

                $events[] = ['t' => $step, 'len' => 1, 'f' => round($frequency, 3)];
            }

            $last = array_key_last($events);
            $step++;
        }

        /** @var list<array{t: int, len: int, d?: string, f?: float}> $events */
        $events = array_merge($clipEvents, $events);
        usort($events, fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        return new Track(
            name: $name,
            instrument: $instrument,
            volume: Fields::number($fm, 'volume', $inherit->volume ?? ($isDrums ? 0.3 : 0.2), 0, 1, $where),
            gate: Fields::number($fm, 'gate', $inherit->gate ?? 0.9, 0.05, 1, $where),
            pw: Fields::number($fm, 'pw', $inherit->pw ?? 0.25, 0.05, 0.5, $where),
            steps: max($step, $clipEnd),
            events: $events,
            refs: $bands,
            notesHtml: $this->sanitizer->clean($description),
        );
    }

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, song: Song})|null $resolve
     * @param array<string, array{prefix: string, slug: string, title: string}> $credits
     * @return array{events: list<array{t: int, len: int, d?: string, f?: float}>, end: int, first: Track|null, drums: list<bool>, bands: list<array{ref: string, title: string, track: string, start: int, len: int}>}
     */
    private function clips(mixed $clips, string $where, int $dstSpb, ?\Closure $resolve, string $owner, array &$credits): array
    {
        if (!is_array($clips) || $clips === [] || !array_is_list($clips) || count($clips) > self::MAX_CLIPS) {
            throw new BuildError("{$where}: „clips“ muss eine Liste mit 1 bis " . self::MAX_CLIPS . ' Einträgen sein.');
        }

        if ($resolve === null) {
            throw new BuildError("{$where}: „clips“ braucht den Build, der andere Songs auflösen kann.");
        }

        $dstBar = $dstSpb * 4;
        $events = [];
        $cursor = 0;
        $end = 0;
        $first = null;
        $drums = [];
        $bands = [];

        foreach ($clips as $number => $clip) {
            $label = $where . ', Clip ' . ($number + 1);

            if (!is_array($clip) || array_is_list($clip) && $clip !== []) {
                throw new BuildError("{$label}: Ein Clip braucht Schlüssel wie from, bars und at.");
            }

            Fields::keys($clip, ['from', 'bars', 'steps', 'at', 'at-step', 'transpose', 'repeat'], $label, ['at_step']);

            $from = $clip['from'] ?? null;

            $m = [];
            if (!is_string($from) || preg_match(Fields::TRACK_REF, trim($from), $m) !== 1) {
                throw new BuildError("{$label}: „from“ muss die Form prefix/slug#track haben.");
            }

            [, $prefix, $slug, $trackName] = $m;
            $trackName = trim($trackName);
            $resolved = $resolve("{$prefix}/{$slug}");
            $source = $resolved['song'];

            Fields::mayRemix($label, "{$prefix}/{$slug}", $prefix, $owner, $source->remix);

            $sourceTrack = null;

            foreach ($source->tracks as $candidate) {
                if ($candidate->name === $trackName) {
                    $sourceTrack = $candidate;

                    break;
                }
            }

            if ($sourceTrack === null) {
                throw new BuildError(
                    "{$label}: „{$prefix}/{$slug}“ hat keinen Track „{$trackName}“ (vorhanden: "
                    . implode(', ', array_map(fn(Track $track): string => $track->name, $source->tracks))
                    . ').',
                );
            }

            $srcSpb = $source->stepsPerBeat;
            $srcBar = $source->stepsPerBar;
            $srcSteps = $source->steps;

            if (isset($clip['bars'], $clip['steps'])) {
                throw new BuildError("{$label}: „bars“ und „steps“ schließen sich aus.");
            }

            if (isset($clip['bars'])) {
                [$a, $b] = Fields::range($clip['bars'], "{$label}: „bars“");
                [$start, $stop] = [($a - 1) * $srcBar, $b * $srcBar];
            } elseif (isset($clip['steps'])) {
                [$a, $b] = Fields::range($clip['steps'], "{$label}: „steps“");
                [$start, $stop] = [$a - 1, $b];
            } else {
                [$start, $stop] = [0, $srcSteps];
            }

            if ($stop > $srcSteps) {
                throw new BuildError("{$label}: Der Ausschnitt reicht bis Step {$stop}, „{$prefix}/{$slug}“ hat nur {$srcSteps} Steps ({$source->bars} Bars).");
            }

            if (((($stop - $start) * $dstSpb) % $srcSpb) !== 0) {
                throw new BuildError(
                    "{$label}: Der Ausschnitt passt nicht in das Raster dieses Songs (Quelle grid "
                    . ($srcSpb * 4)
                    . ', hier grid '
                    . ($dstSpb * 4)
                    . '). Mit ganzen Bars geht es immer auf.',
                );
            }

            $length = intdiv(($stop - $start) * $dstSpb, $srcSpb);

            if (isset($clip['at_step'], $clip['at-step'])) {
                throw new BuildError("{$label}: „at-step“ und „at_step“ sind derselbe Schlüssel (at_step ist der alte Name). Bitte nur „at-step“ verwenden.");
            }

            $atStep = $clip['at-step'] ?? $clip['at_step'] ?? null;

            if (isset($clip['at']) && $atStep !== null) {
                throw new BuildError("{$label}: „at“ und „at-step“ schließen sich aus.");
            }

            $at = $cursor;

            if (isset($clip['at'])) {
                $at = (Fields::integer($clip['at'], "{$label}: „at“", 1, self::MAX_AT) - 1) * $dstBar;
            } elseif ($atStep !== null) {
                $at = Fields::integer($atStep, "{$label}: „at-step“", 1, self::MAX_STEPS) - 1;
            }

            $repeat = isset($clip['repeat']) ? Fields::integer($clip['repeat'], "{$label}: „repeat“", 1, self::MAX_REPEAT) : 1;
            $transpose = isset($clip['transpose']) ? Fields::integer($clip['transpose'], "{$label}: „transpose“", -self::MAX_TRANSPOSE, self::MAX_TRANSPOSE) : 0;
            $isDrums = $sourceTrack->isDrums();

            if ($isDrums && $transpose !== 0) {
                throw new BuildError("{$label}: „transpose“ gibt es nur für Noten-Tracks.");
            }

            if (($at + ($length * $repeat)) > self::MAX_STEPS) {
                throw new BuildError("{$label}: Das Ergebnis wäre länger als " . self::MAX_STEPS . ' Steps.');
            }

            for ($r = 0; $r < $repeat; $r++) {
                $base = $at + ($r * $length);

                foreach ($sourceTrack->events as $event) {
                    if ($event['t'] < $start || $event['t'] >= $stop) {
                        continue;
                    }

                    $offset = ($event['t'] - $start) * $dstSpb;
                    $len = min($event['len'], $stop - $event['t']) * $dstSpb;

                    if (($offset % $srcSpb) !== 0 || ($len % $srcSpb) !== 0) {
                        throw new BuildError("{$label}: Das Raster der Quelle (grid " . ($srcSpb * 4) . ') passt nicht auf grid ' . ($dstSpb * 4) . ' dieses Songs.');
                    }

                    $copy = $event;
                    $copy['t'] = $base + intdiv($offset, $srcSpb);
                    $copy['len'] = max(1, intdiv($len, $srcSpb));

                    if (isset($copy['f']) && $transpose !== 0) {
                        $copy['f'] = round($copy['f'] * (2 ** ($transpose / 12)), 3);
                    }

                    $events[] = $copy;
                }
            }

            $cursor = $at + ($length * $repeat);
            $end = max($end, $cursor);
            $first ??= $sourceTrack;
            $drums[] = $isDrums;
            $bands[] = ['ref' => "{$prefix}/{$slug}", 'title' => $source->title, 'track' => $trackName, 'start' => $at, 'len' => $length * $repeat];
            $credits["{$prefix}/{$slug}"] = ['prefix' => $prefix, 'slug' => $slug, 'title' => $source->title];
        }

        return ['events' => $events, 'end' => $end, 'first' => $first, 'drums' => $drums, 'bands' => $bands];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitTrack(ParsedMarkdown $chunk, string $name, bool $allowMissing): array
    {
        $pattern = '~<pre class="language-grid">(.*?)</pre>~s';
        $matches = [];
        $count = (int) preg_match_all($pattern, $chunk->html, $matches);

        if ($count === 0 && $allowMissing) {
            return [null, $chunk->html];
        }

        if ($count === 0) {
            throw new BuildError(
                "Track „{$name}“: kein Code-Block mit der Sprache grid gefunden. Die Noten stehen in einem Block, der mit ```grid beginnt, oder der Track hat clips.",
            );
        }

        if ($count > 1) {
            throw new BuildError("Track „{$name}“: {$count} grid-Blöcke gefunden, erlaubt ist genau einer.");
        }

        $grid = html_entity_decode(strip_tags($matches[1][0]), ENT_QUOTES | ENT_HTML5);

        return [$grid, (string) preg_replace($pattern, '', $chunk->html)];
    }

    private function frequency(string $token): ?float
    {
        $m = [];
        if (preg_match('/^([A-G])(#|b)?(-?\d)$/', $token, $m) !== 1) {
            return null;
        }

        $accidental = match ($m[2] ?? '') {
            '#' => 1,
            'b' => -1,
            default => 0,
        };

        $midi = (12 * ((int) $m[3] + 1)) + self::SEMITONES[$m[1]] + $accidental;

        return 440.0 * (2 ** (($midi - 69) / 12));
    }
}
