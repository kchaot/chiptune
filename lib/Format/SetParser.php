<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

final class SetParser
{
    public const FORMAT = 1;
    public const MAX_BYTES = 65_536;
    public const MAX_PARTS = 32;

    public function __construct(
        private readonly ProjectParser $projects,
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, project: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve): Set
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $chunks = [];

        foreach ($this->markdown->parseMany($source, 'project', 'part') as $chunk) {
            $chunks[] = $chunk;
        }

        $head = null;

        if ($chunks !== [] && ($chunks[0]->name === null || $chunks[0]->name === 'project-1')) {
            $head = array_shift($chunks);
        }

        /** @var array<string, mixed> $meta */

        $meta = $head->frontmatter ?? [];

        if (($meta['format'] ?? self::FORMAT) !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . (string) json_encode($meta['format']) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        if ($chunks === []) {
            throw new BuildError('Keine Parts gefunden. Jeder Part beginnt mit einem Marker <!-- part: prefix/slug -->.');
        }

        if (count($chunks) > self::MAX_PARTS) {
            throw new BuildError(sprintf('Mehr als %d Parts.', self::MAX_PARTS));
        }

        $loop = filter_var($meta['loop'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($loop === null) {
            throw new BuildError('Set: „loop“ muss true oder false sein.');
        }

        $parts = [];
        $credits = [];
        $duration = 0.0;

        foreach ($chunks as $index => $chunk) {
            $ref = trim((string) $chunk->name);
            $where = 'Part ' . ($index + 1) . " („{$ref}“)";

            $m = [];
            if (preg_match('/^([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)$/', $ref, $m) !== 1) {
                throw new BuildError("{$where}: Der Marker muss die Form <!-- part: prefix/slug --> haben.");
            }

            $resolved = $resolve($ref);
            $project = $resolved['project'];
            /** @var array<string, mixed> $fm */
            $fm = $chunk->frontmatter;

            $unknown = array_diff(array_keys($fm), ['bars', 'repeat']);

            if ($unknown !== []) {
                throw new BuildError("{$where}: unbekannter Schlüssel „" . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: bars, repeat).');
            }

            $repeat = $fm['repeat'] ?? 1;

            if (!is_int($repeat) || $repeat < 1 || $repeat > 16) {
                throw new BuildError("{$where}: „repeat“ muss eine ganze Zahl von 1 bis 16 sein.");
            }

            $bar = $project->stepsPerBar;
            $startBar = 1;
            $start = 0;
            $stop = $project->steps;

            if (isset($fm['bars'])) {
                $value = is_int($fm['bars']) ? (string) $fm['bars'] : $fm['bars'];

                $r = [];
                if (!is_string($value) || preg_match('/^\s*(\d{1,4})\s*(?:-\s*(\d{1,4}))?\s*$/', $value, $r) !== 1) {
                    throw new BuildError("{$where}: „bars“ muss eine Zahl oder ein Bereich wie 1-8 sein.");
                }

                $startBar = (int) $r[1];
                $endBar = isset($r[2]) && $r[2] !== '' ? (int) $r[2] : $startBar;

                if ($startBar < 1 || $endBar < $startBar) {
                    throw new BuildError("{$where}: „bars“ muss bei 1 beginnen und aufsteigen.");
                }

                $start = ($startBar - 1) * $bar;
                $stop = $endBar * $bar;

                if ($stop > $project->steps) {
                    throw new BuildError("{$where}: „bars“ reicht bis Bar {$endBar}, das Project hat {$project->bars} Bars.");
                }
            }

            $run = ($stop - $start) * $project->stepSeconds;
            $duration += $run * $repeat;

            $parts[] = new SetPart(
                ref: $ref,
                prefix: $resolved['prefix'],
                slug: $resolved['slug'],
                title: $project->title,
                artist: $project->artist,
                bpm: $project->bpm,
                stepsPerBar: $project->stepsPerBar,
                stepsPerBeat: $project->stepsPerBeat,
                stepSeconds: $project->stepSeconds,
                steps: $project->steps,
                start: $start,
                stop: $stop,
                startBar: $startBar,
                repeat: $repeat,
                tracks: $project->tracks,
                notesHtml: $this->sanitizer->clean($chunk->html),
            );

            $credits[$ref] = ['prefix' => $resolved['prefix'], 'slug' => $resolved['slug'], 'title' => $project->title];
        }

        $info = $this->projects->describe($meta, $fallbackTitle);

        return new Set(
            title: $info['title'],
            artist: $info['artist'],
            group: $info['group'],
            tags: $info['tags'],
            loop: $loop,
            parts: $parts,
            duration: $duration,
            notesHtml: $this->sanitizer->clean($head->html ?? ''),
            credits: array_values($credits),
        );
    }
}
