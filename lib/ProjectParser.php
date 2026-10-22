<?php

declare(strict_types=1);

namespace Chiptune;

use Tempest\Markdown\Markdown;
use Tempest\Markdown\ParsedMarkdown;

final class ProjectParser
{
    public const FORMAT = 1;
    public const MAX_BYTES = 65_536;
    public const MAX_TRACKS = 16;
    public const MAX_STEPS = 4096;
    public const MAX_LAYERS = 8;
    public const MAX_LAYER_TRACKS = 32;

    private const INSTRUMENTS = ['square', 'pulse', 'triangle', 'saw', 'sine', 'drums'];
    private const DRUMS = ['k', 's', 'c', 'h', 'o'];
    private const SEMITONES = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    public function kind(string $source): string
    {
        foreach ($this->markdown->parseMany($source, 'project', 'part') as $chunk) {
            $kind = $chunk->frontmatter['kind'] ?? 'project';

            if ($kind === 'release') {
                return 'link';
            }

            return in_array($kind, ['set', 'layer', 'session', 'link'], true) ? $kind : 'project';
        }

        return 'project';
    }

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, project: array<string, mixed>})|null $resolve
     * @param string $owner
     * @return array{
     *     format: int, title: string, artist: string, tags: list<string>, bpm: float,
     *     stepsPerBeat: int, stepsPerBar: int, stepSeconds: float, loop: bool, steps: int, bars: float,
     *     tracks: list<Track>, warnings: list<string>, notesHtml: string,
     *     remix: bool|null, credits: list<array{prefix: string, slug: string, title: string}>
     * }
     */
    public function parse(string $source, string $fallbackTitle = 'Untitled', ?\Closure $resolve = null, string $owner = ''): array
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $chunks = [];

        foreach ($this->markdown->parseMany($source, 'project', 'track') as $chunk) {
            $chunks[] = $chunk;
        }

        $head = null;

        if ($chunks !== [] && ($chunks[0]->name === null || $chunks[0]->name === 'project-1')) {
            $head = array_shift($chunks);
        }

        if ($chunks === []) {
            throw new BuildError('Keine Tracks gefunden. Jeder Track beginnt mit einem Marker <!-- track: name -->.');
        }

        if (count($chunks) > self::MAX_TRACKS) {
            throw new BuildError(sprintf('Mehr als %d Tracks.', self::MAX_TRACKS));
        }

        $meta = $head->frontmatter ?? [];
        $format = $meta['format'] ?? self::FORMAT;

        if ($format !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . json_encode($format) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        $bpm = $this->number($meta, 'bpm', 120, 20, 300);
        $grid = (int) $this->number($meta, 'grid', 16, 4, 64);

        if (($grid % 4) !== 0) {
            throw new BuildError('Project: „grid“ muss durch 4 teilbar sein (zum Beispiel 8, 16 oder 32).');
        }

        $stepsPerBeat = intdiv($grid, 4);
        $stepsPerBar = $stepsPerBeat * 4;

        $remix = $meta['remix'] ?? null;

        if ($remix !== null && !is_bool($remix)) {
            throw new BuildError('Project: „remix“ muss true oder false sein.');
        }

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
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
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
        array $extra = [],
    ): array {
        $stepsPerBar = $stepsPerBeat * 4;
        $steps = max(array_map(fn(Track $track): int => $track->steps, $tracks));

        $warnings = [];

        foreach ($tracks as $track) {
            if (!isset($extra['layers']) && $track->steps !== $steps) {
                $warnings[] = "Track „{$track->name}“ hat {$track->steps} Steps, der längste {$steps}.";
            }
        }

        if (($steps % $stepsPerBar) !== 0) {
            $warnings[] = "Die Länge von {$steps} Steps ist kein ganzes Vielfaches von {$stepsPerBar} Steps pro Bar.";
        }

        $loop = filter_var($meta['loop'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($loop === null) {
            throw new BuildError('Project: „loop“ muss true oder false sein.');
        }

        return [
            'kind' => 'project',
            'format' => self::FORMAT,
            ...$this->describe($meta, $fallbackTitle),
            'bpm' => $bpm,
            'stepsPerBeat' => $stepsPerBeat,
            'stepsPerBar' => $stepsPerBar,
            'stepSeconds' => (60 / $bpm) / $stepsPerBeat,
            'loop' => $loop,
            'steps' => $steps,
            'bars' => $steps / $stepsPerBar,
            'tracks' => $tracks,
            'warnings' => $warnings,
            'notesHtml' => $this->sanitizer->clean($head->html ?? ''),
            'media' => $this->media($meta),
            'remix' => $remix,
            'credits' => array_values($credits),
        ] + $extra;
    }

    /**
     * @param \Closure(string): array{prefix: string, slug: string, project: array<string, mixed>} $resolve
     * @return array<string, mixed>
     */
    public function parseLayer(string $source, string $fallbackTitle, \Closure $resolve, string $owner): array
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $chunks = [];

        foreach ($this->markdown->parseMany($source, 'project', 'layer') as $chunk) {
            $chunks[] = $chunk;
        }

        $head = null;

        if ($chunks !== [] && ($chunks[0]->name === null || $chunks[0]->name === 'project-1')) {
            $head = array_shift($chunks);
        }

        $meta = $head->frontmatter ?? [];

        if (($meta['format'] ?? self::FORMAT) !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . json_encode($meta['format']) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        if ($chunks === []) {
            throw new BuildError('Keine Layer gefunden. Jeder Layer beginnt mit einem Marker <!-- layer: prefix/slug -->.');
        }

        if (count($chunks) > self::MAX_LAYERS) {
            throw new BuildError(sprintf('Mehr als %d Layer.', self::MAX_LAYERS));
        }

        $remix = $meta['remix'] ?? null;

        if ($remix !== null && !is_bool($remix)) {
            throw new BuildError('Layer: „remix“ muss true oder false sein.');
        }

        $layers = [];

        foreach ($chunks as $index => $chunk) {
            $ref = trim((string) $chunk->name);
            $where = 'Layer ' . ($index + 1) . " („{$ref}“)";

            if (preg_match('/^([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)$/', $ref, $m) !== 1) {
                throw new BuildError("{$where}: Der Marker muss die Form <!-- layer: prefix/slug --> haben.");
            }

            $fm = $chunk->frontmatter;
            $unknown = array_diff(array_keys($fm), ['at', 'bars', 'repeat', 'transpose', 'tracks', 'name']);

            if ($unknown !== []) {
                throw new BuildError(
                    "{$where}: unbekannter Schlüssel „" . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: at, bars, repeat, transpose, tracks, name).',
                );
            }

            $source = $resolve($ref)['project'];

            if ($m[1] !== $owner && ($source['remix'] ?? null) !== true) {
                throw new BuildError("{$where}: „{$ref}“ erlaubt kein Remixen. Der Autor müsste „remix: true“ in sein Project schreiben.");
            }

            $name = $fm['name'] ?? $source['title'];

            if (!is_string($name) || $name === '' || mb_strlen($name) > 32 || preg_match('/[\x00-\x1F\x7F<>&"]/', $name) === 1) {
                throw new BuildError("{$where}: „name“ hat 1 bis 32 Zeichen ohne < > & \".");
            }

            $names = array_map(fn(Track $track): string => $track->name, $source['tracks']);
            $selected = $names;

            if (array_key_exists('tracks', $fm)) {
                $selected = $fm['tracks'];

                if (!is_array($selected) || $selected === [] || !array_is_list($selected)) {
                    throw new BuildError("{$where}: „tracks“ muss eine Liste von Track-Namen sein.");
                }

                foreach ($selected as $wanted) {
                    if (!is_string($wanted) || !in_array($wanted, $names, true)) {
                        throw new BuildError(
                            "{$where}: „{$ref}“ hat keinen Track „" . (is_string($wanted) ? $wanted : json_encode($wanted)) . '“ (vorhanden: ' . implode(', ', $names) . ').',
                        );
                    }
                }
            }

            $repeat = $fm['repeat'] ?? 1;

            if ($repeat !== 'fill') {
                $repeat = $this->integer($repeat, "{$where}: „repeat“", 1, 64);
            }

            $layers[] = [
                'ref' => $ref,
                'prefix' => $m[1],
                'slug' => $m[2],
                'source' => $source,
                'name' => $name,
                'tracks' => array_values($selected),
                'at' => isset($fm['at']) ? $this->integer($fm['at'], "{$where}: „at“", 1, 1024) : 1,
                'bars' => $fm['bars'] ?? null,
                'transpose' => isset($fm['transpose']) ? $this->integer($fm['transpose'], "{$where}: „transpose“", -36, 36) : 0,
                'repeat' => $repeat,
                'where' => $where,
                'description' => $this->sanitizer->clean($chunk->html),
            ];
        }

        $bpm = $this->number($meta, 'bpm', (float) $layers[0]['source']['bpm'], 20, 300);

        if (isset($meta['grid'])) {
            $grid = (int) $this->number($meta, 'grid', 16, 4, 64);

            if (($grid % 4) !== 0) {
                throw new BuildError('Layer: „grid“ muss durch 4 teilbar sein (zum Beispiel 8, 16 oder 32).');
            }

            $stepsPerBeat = intdiv($grid, 4);
        } else {
            $stepsPerBeat = 1;

            foreach ($layers as $layer) {
                $other = $layer['source']['stepsPerBeat'];
                $stepsPerBeat = intdiv($stepsPerBeat * $other, $this->gcd($stepsPerBeat, $other));
            }

            if (($stepsPerBeat * 4) > 64) {
                throw new BuildError(
                    'Die Raster der Layer (grid '
                    . implode(', ', array_map(fn(array $l): string => (string) ($l['source']['stepsPerBeat'] * 4), $layers))
                    . ') ergeben zusammen mehr als grid 64. Ein Layer müsste ein anderes Raster bekommen.',
                );
            }
        }

        $dstBar = $stepsPerBeat * 4;

        foreach ($layers as $i => $layer) {
            $src = $layer['source'];

            if ($layer['bars'] !== null) {
                [$a, $b] = $this->range($layer['bars'], "{$layer['where']}: „bars“");

                if (($b * $src['stepsPerBar']) > $src['steps']) {
                    throw new BuildError("{$layer['where']}: Der Ausschnitt reicht bis Bar {$b}, „{$layer['ref']}“ hat nur {$src['bars']} Bars.");
                }

                $length = ($b - $a + 1) * $dstBar;
            } else {
                if ((($src['steps'] * $stepsPerBeat) % $src['stepsPerBeat']) !== 0) {
                    throw new BuildError(
                        "{$layer['where']}: Das Raster von „{$layer['ref']}“ (grid " . ($src['stepsPerBeat'] * 4) . ") passt nicht auf grid {$dstBar} dieses Layers.",
                    );
                }

                $length = intdiv($src['steps'] * $stepsPerBeat, $src['stepsPerBeat']);
            }

            $layers[$i]['length'] = $length;
            $layers[$i]['start'] = ($layer['at'] - 1) * $dstBar;
        }

        $total = 0;

        foreach ($layers as $layer) {
            if ($layer['repeat'] !== 'fill') {
                $total = max($total, $layer['start'] + ($layer['length'] * $layer['repeat']));
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
                bpm: $layer['source']['bpm'],
                at: $layer['at'],
                description: $layer['description'],
            );

            foreach ($layer['tracks'] as $trackName) {
                $isDrums = false;

                foreach ($layer['source']['tracks'] as $candidate) {
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
                $first = $result['first'];
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

        $layerInfo = array_map(fn(array $l): array => ['title' => $l['name'], 'ref' => $l['ref'], 'bpm' => $l['source']['bpm'], 'at' => $l['at']], $layers);

        return $this->assemble($meta, $head, $tracks, $bpm, $stepsPerBeat, $fallbackTitle, $remix, $credits, ['layers' => $layerInfo]);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, project: array<string, mixed>})|null $resolve
     * @param array<string, array{prefix: string, slug: string, title: string}> $credits
     */
    private function parseTrack(ParsedMarkdown $chunk, int $stepsPerBeat, ?\Closure $resolve, string $owner, array &$credits): Track
    {
        $name = trim((string) $chunk->name);
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

        foreach ($grid === null ? [] : preg_split('/\s+/', trim($grid), -1, PREG_SPLIT_NO_EMPTY) as $token) {
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

        $events = array_merge($clipEvents, $events);
        usort($events, fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        return new Track(
            name: $name,
            instrument: $instrument,
            volume: $this->number($fm, 'volume', $inherit->volume ?? ($isDrums ? 0.3 : 0.2), 0, 1, $where),
            gate: $this->number($fm, 'gate', $inherit->gate ?? 0.9, 0.05, 1, $where),
            pw: $this->number($fm, 'pw', $inherit->pw ?? 0.25, 0.05, 0.5, $where),
            steps: max($step, $clipEnd),
            events: $events,
            refs: $bands,
            notesHtml: $this->sanitizer->clean($description),
        );
    }

    /**
     * @param array<string, array{prefix: string, slug: string, title: string}> $credits
     * @return array{events: list<array<string, mixed>>, end: int, first: Track|null, drums: list<bool>, bands: list<array<string, mixed>>}
     */
    private function clips(mixed $clips, string $where, int $dstSpb, ?\Closure $resolve, string $owner, array &$credits): array
    {
        if (!is_array($clips) || $clips === [] || !array_is_list($clips) || count($clips) > 16) {
            throw new BuildError("{$where}: „clips“ muss eine Liste mit 1 bis 16 Einträgen sein.");
        }

        if ($resolve === null) {
            throw new BuildError("{$where}: „clips“ braucht den Build, der andere Projects auflösen kann.");
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

            $unknown = array_diff(array_keys($clip), ['from', 'bars', 'steps', 'at', 'at_step', 'transpose', 'repeat']);

            if ($unknown !== []) {
                throw new BuildError(
                    "{$label}: unbekannter Schlüssel „" . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: from, bars, steps, at, at_step, transpose, repeat).',
                );
            }

            $from = $clip['from'] ?? null;

            if (!is_string($from) || preg_match('/^([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)#(.{1,32})$/', trim($from), $m) !== 1) {
                throw new BuildError("{$label}: „from“ muss die Form prefix/slug#track haben.");
            }

            [, $prefix, $slug, $trackName] = $m;
            $trackName = trim($trackName);
            $resolved = $resolve("{$prefix}/{$slug}");
            $source = $resolved['project'];

            if ($prefix !== $owner && ($source['remix'] ?? null) !== true) {
                throw new BuildError("{$label}: „{$prefix}/{$slug}“ erlaubt kein Remixen. Der Autor müsste „remix: true“ in sein Project schreiben.");
            }

            $sourceTrack = null;

            foreach ($source['tracks'] as $candidate) {
                if ($candidate->name === $trackName) {
                    $sourceTrack = $candidate;

                    break;
                }
            }

            if ($sourceTrack === null) {
                throw new BuildError(
                    "{$label}: „{$prefix}/{$slug}“ hat keinen Track „{$trackName}“ (vorhanden: "
                    . implode(', ', array_map(fn(Track $track): string => $track->name, $source['tracks']))
                    . ').',
                );
            }

            $srcSpb = $source['stepsPerBeat'];
            $srcBar = $source['stepsPerBar'];
            $srcSteps = $source['steps'];

            if (isset($clip['bars'], $clip['steps'])) {
                throw new BuildError("{$label}: „bars“ und „steps“ schließen sich aus.");
            }

            if (isset($clip['bars'])) {
                [$a, $b] = $this->range($clip['bars'], "{$label}: „bars“");
                [$start, $stop] = [($a - 1) * $srcBar, $b * $srcBar];
            } elseif (isset($clip['steps'])) {
                [$a, $b] = $this->range($clip['steps'], "{$label}: „steps“");
                [$start, $stop] = [$a - 1, $b];
            } else {
                [$start, $stop] = [0, $srcSteps];
            }

            if ($stop > $srcSteps) {
                throw new BuildError("{$label}: Der Ausschnitt reicht bis Step {$stop}, „{$prefix}/{$slug}“ hat nur {$srcSteps} Steps ({$source['bars']} Bars).");
            }

            if (((($stop - $start) * $dstSpb) % $srcSpb) !== 0) {
                throw new BuildError(
                    "{$label}: Der Ausschnitt passt nicht in das Raster dieses Projects (Quelle grid "
                    . ($srcSpb * 4)
                    . ', hier grid '
                    . ($dstSpb * 4)
                    . '). Mit ganzen Bars geht es immer auf.',
                );
            }

            $length = intdiv(($stop - $start) * $dstSpb, $srcSpb);

            if (isset($clip['at'], $clip['at_step'])) {
                throw new BuildError("{$label}: „at“ und „at_step“ schließen sich aus.");
            }

            $at = $cursor;

            if (isset($clip['at'])) {
                $at = ($this->integer($clip['at'], "{$label}: „at“", 1, 4096) - 1) * $dstBar;
            } elseif (isset($clip['at_step'])) {
                $at = $this->integer($clip['at_step'], "{$label}: „at_step“", 1, self::MAX_STEPS) - 1;
            }

            $repeat = isset($clip['repeat']) ? $this->integer($clip['repeat'], "{$label}: „repeat“", 1, 64) : 1;
            $transpose = isset($clip['transpose']) ? $this->integer($clip['transpose'], "{$label}: „transpose“", -36, 36) : 0;
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
                        throw new BuildError("{$label}: Das Raster der Quelle (grid " . ($srcSpb * 4) . ') passt nicht auf grid ' . ($dstSpb * 4) . ' dieses Projects.');
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
            $bands[] = ['ref' => "{$prefix}/{$slug}", 'title' => $source['title'], 'track' => $trackName, 'start' => $at, 'len' => $length * $repeat];
            $credits["{$prefix}/{$slug}"] = ['prefix' => $prefix, 'slug' => $slug, 'title' => $source['title']];
        }

        return ['events' => $events, 'end' => $end, 'first' => $first, 'drums' => $drums, 'bands' => $bands];
    }

    /** @return array{0: int, 1: int} */
    private function range(mixed $value, string $where): array
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || preg_match('/^\s*(\d{1,5})\s*(?:-\s*(\d{1,5}))?\s*$/', $value, $m) !== 1) {
            throw new BuildError("{$where} muss eine Zahl oder ein Bereich wie 3-6 sein.");
        }

        $a = (int) $m[1];
        $b = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $a;

        if ($a < 1 || $b < $a) {
            throw new BuildError("{$where}: Der Bereich muss bei 1 beginnen und aufsteigen.");
        }

        return [$a, $b];
    }

    private function integer(mixed $value, string $where, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new BuildError("{$where} muss eine ganze Zahl von {$min} bis {$max} sein.");
        }

        return $value;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitTrack(ParsedMarkdown $chunk, string $name, bool $allowMissing): array
    {
        $pattern = '~<pre class="language-grid">(.*?)</pre>~s';
        $count = preg_match_all($pattern, $chunk->html, $matches);

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

    /**
     * @param array<string, mixed> $meta
     * @return array{title: string, artist: string, group: string, tags: list<string>}
     */
    public function describe(array $meta, string $fallbackTitle): array
    {
        return [
            'title' => $this->text($meta, 'title', 80, $fallbackTitle) ?: $fallbackTitle,
            'artist' => $this->text($meta, 'artist', 60),
            'group' => $this->text($meta, 'group', 40),
            'tags' => $this->tags($meta['tags'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function media(array $meta): Media
    {
        $urls = [];
        $release = $meta['release'] ?? null;
        $details = [];

        if (is_array($release)) {
            $unknown = array_diff(array_keys($release), ['url', 'cover', 'title', 'date', 'label', 'catalog', 'license', 'credits']);

            if ($unknown !== []) {
                throw new BuildError(
                    'Project: „release“ kennt nur url, cover, title, date, label, catalog, license und credits (unbekannt: ' . implode(', ', array_map('strval', $unknown)) . ').',
                );
            }

            if (!isset($release['url'])) {
                throw new BuildError('Project: „release“ braucht eine „url“.');
            }

            $details = $release;
            $release = $release['url'];
        }

        foreach (['hq' => $meta['hq'] ?? null, 'release' => $release, 'cover' => $details['cover'] ?? null] as $key => $url) {
            if ($url === null) {
                continue;
            }

            $name = $key === 'hq' ? 'hq' : ($key === 'release' ? 'release' : 'release: cover');

            if (
                !is_string($url)
                || strlen($url) > 300
                || preg_match(
                    '~^https://(?=[a-z0-9.-]{3,100}(?::\d{1,5})?(?:/|$))[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(?::\d{1,5})?(?:/[^\s<>"\'\\\\\x00-\x1F\x7F]*)?$~',
                    $url,
                ) !== 1
            ) {
                throw new BuildError("Project: „{$name}“ muss eine https-Adresse mit Kleinbuchstaben im Hostnamen sein (höchstens 300 Zeichen, ohne Leerzeichen).");
            }

            $urls[$key] = $url;
        }

        $info = $this->releaseInfo($details);
        $hqOffset = null;

        if (isset($meta['hq-offset'])) {
            if (!isset($urls['hq'])) {
                throw new BuildError('Project: „hq-offset“ gehört zu „hq“.');
            }

            $hqOffset = $this->number($meta, 'hq-offset', 0, -10, 10);
        }

        return new Media(
            hq: $urls['hq'] ?? null,
            hqOffset: $hqOffset,
            release: $urls['release'] ?? null,
            cover: $urls['cover'] ?? null,
            info: $info,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function releaseInfo(array $details): ReleaseInfo
    {
        $texts = [];

        foreach (['title' => 120, 'label' => 80, 'catalog' => 40, 'license' => 60] as $key => $max) {
            if (isset($details[$key])) {
                $texts[$key] = $this->text($details, $key, $max);
            }
        }

        $formatted = null;

        if (isset($details['date'])) {
            $date = $details['date'];

            if (is_int($date) && $date > 0) {
                $date = gmdate('Y-m-d', $date);
            }

            if (!is_string($date) || preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $date) !== 1) {
                throw new BuildError('Project: „release: date“ muss JJJJ, JJJJ-MM oder JJJJ-MM-TT sein.');
            }

            $formatted = $date;
        }

        $roles = [];

        if (isset($details['credits'])) {
            $credits = $details['credits'];

            if (!is_array($credits) || array_is_list($credits) || count($credits) > 12) {
                throw new BuildError('Project: „release: credits“ muss eine Zuordnung Rolle: Name sein (höchstens 12 Rollen).');
            }

            foreach ($credits as $role => $names) {
                $names = is_array($names) ? $names : [$names];

                if (!is_string($role) || $names === [] || count($names) > 6 || !array_is_list($names)) {
                    throw new BuildError('Project: „release: credits“ braucht je Rolle einen Namen oder eine Liste mit höchstens 6 Namen.');
                }

                $role = trim((string) preg_replace('/\s+/u', ' ', $role));

                if ($role === '' || mb_strlen($role) > 40) {
                    throw new BuildError('Project: „release: credits“ hat eine leere oder zu lange Rolle (höchstens 40 Zeichen).');
                }

                foreach ($names as $name) {
                    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 80) {
                        throw new BuildError("Project: „release: credits“, Rolle „{$role}“: jeder Name ist Text mit höchstens 80 Zeichen.");
                    }

                    $roles[$role][] = trim((string) preg_replace('/\s+/u', ' ', $name));
                }
            }
        }

        return new ReleaseInfo(
            title: $texts['title'] ?? null,
            label: $texts['label'] ?? null,
            catalog: $texts['catalog'] ?? null,
            license: $texts['license'] ?? null,
            date: $formatted,
            credits: $roles,
        );
    }

    /** @param array<string, mixed> $meta */
    private function number(array $meta, string $key, float $default, float $min, float $max, string $where = 'Project'): float
    {
        $value = $meta[$key] ?? $default;

        if (!is_int($value) && !is_float($value)) {
            throw new BuildError("{$where}: „{$key}“ muss eine Zahl sein.");
        }

        if ($value < $min || $value > $max) {
            throw new BuildError("{$where}: „{$key}“ muss zwischen {$min} und {$max} liegen.");
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $meta */
    private function text(array $meta, string $key, int $max, string $default = ''): string
    {
        $value = $meta[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new BuildError("Project: „{$key}“ muss Text sein.");
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value) > $max) {
            throw new BuildError("Project: „{$key}“ ist länger als {$max} Zeichen.");
        }

        return $value;
    }

    /** @return list<string> */
    private function tags(mixed $tags): array
    {
        if (!is_array($tags) || count($tags) > 6) {
            throw new BuildError('Project: „tags“ muss eine Liste mit höchstens 6 Einträgen sein.');
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = is_int($tag) ? (string) $tag : $tag;

            if (!is_string($tag) || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} -]{0,23}$/u', $tag) !== 1) {
                throw new BuildError('Project: ungültiger Tag. Erlaubt sind Buchstaben, Ziffern, Leerzeichen und Bindestrich, höchstens 24 Zeichen.');
            }

            $clean[] = $tag;
        }

        return $clean;
    }
}
