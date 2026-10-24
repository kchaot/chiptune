<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

/**
 * Liest Sets und Alben aus Markdown.
 *
 * Die Datei ist eine Liste von Abschnitten, die je auf einen Song verweisen, wahlweise mit
 * Ausschnitt (`bars`) und Wiederholungen (`repeat`). Das Ergebnis ist ein `Set`.
 */
final class SetParser
{
    public const MAX_BYTES = 65_536;
    public const MAX_PARTS = 32;

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve): Set
    {
        Fields::size($source, self::MAX_BYTES);

        [$head, $chunks] = Fields::blocks($this->markdown, $source, 'part');

        /** @var array<string, mixed> $meta */

        $meta = $head->frontmatter ?? [];

        Fields::version($meta);

        if ($chunks === []) {
            throw new BuildError('Keine Parts gefunden. Jeder Part beginnt mit einem Marker <!-- part: prefix/slug -->.');
        }

        if (count($chunks) > self::MAX_PARTS) {
            throw new BuildError(sprintf('Mehr als %d Parts.', self::MAX_PARTS));
        }

        $loop = Fields::flag($meta, 'loop', false, 'Set');

        $parts = [];
        $credits = [];
        $duration = 0.0;

        foreach ($chunks as $index => $chunk) {
            $ref = trim((string) $chunk->name);
            $where = 'Part ' . ($index + 1) . " („{$ref}“)";

            Fields::marker($ref, $where, 'part');

            $resolved = $resolve($ref);
            $song = $resolved['song'];
            /** @var array<string, mixed> $fm */
            $fm = $chunk->frontmatter;

            Fields::keys($fm, ['bars', 'repeat'], $where);

            $repeat = Fields::integer($fm['repeat'] ?? 1, "{$where}: „repeat“", 1, ClipParser::MAX_REPEAT);

            $bar = $song->stepsPerBar;
            $startBar = 1;
            $start = 0;
            $stop = $song->steps;

            if (isset($fm['bars'])) {
                [$startBar, $endBar] = Fields::range($fm['bars'], "{$where}: „bars“");

                $start = ($startBar - 1) * $bar;
                $stop = $endBar * $bar;

                if ($stop > $song->steps) {
                    throw new BuildError("{$where}: „bars“ reicht bis Bar {$endBar}, der Song hat {$song->bars} Bars.");
                }
            }

            $run = ($stop - $start) * $song->stepSeconds;
            $duration += $run * $repeat;

            $parts[] = new SetPart(
                ref: $ref,
                prefix: $resolved['prefix'],
                slug: $resolved['slug'],
                title: $song->title,
                artist: $song->artist,
                bpm: $song->bpm,
                stepsPerBar: $song->stepsPerBar,
                stepsPerBeat: $song->stepsPerBeat,
                stepSeconds: $song->stepSeconds,
                steps: $song->steps,
                start: $start,
                stop: $stop,
                startBar: $startBar,
                repeat: $repeat,
                tracks: $song->tracks,
                notesHtml: $this->sanitizer->clean($chunk->html),
            );

            $credits[$ref] = ['prefix' => $resolved['prefix'], 'slug' => $resolved['slug'], 'title' => $song->title];
        }

        $info = Fields::describe($meta, $fallbackTitle);

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
