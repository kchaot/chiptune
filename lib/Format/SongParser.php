<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;
use Tempest\Markdown\ParsedMarkdown;

/**
 * Liest Songs aus Markdown.
 *
 * Die Datei besteht aus einem Kopf (Titel, Tempo, Raster) und je einem Block pro Track mit
 * Einstellungen und Noten. Clips liest `ClipParser`, den Zusammenbau macht `SongAssembler`. Das Ergebnis ist ein
 * `Song`. Die Grenzen (`MAX_*`) werden geprüft, Fehler als `BuildError` gemeldet.
 */
final class SongParser
{
    public const MAX_BYTES = 65_536;
    public const MAX_TRACKS = 16;
    public const MAX_STEPS = 4096;
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
        private readonly SongAssembler $assembler = new SongAssembler(),
        private readonly ClipParser $clips = new ClipParser(),
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

        return $this->assembler->assemble($meta, $head, $tracks, $bpm, $stepsPerBeat, $fallbackTitle, $remix, $credits);
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
            ['events' => $clipEvents, 'end' => $clipEnd, 'first' => $inherit, 'drums' => $sourceKinds, 'bands' => $bands] = $this->clips->parse(
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
