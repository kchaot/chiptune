<?php

declare(strict_types=1);

namespace Chiptune\Site\Builder;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Chiptune\Format\Set;
use Chiptune\Format\Set\SetParser;
use Chiptune\Format\Song;
use Chiptune\Site\Entry;
use Chiptune\Site\EntryKind;
use Chiptune\Site\Library;
use Tempest\Markdown\Markdown;

/**
 * Baut die Alben: Ein Album ist ein Ordner mit Songs und wird zu einem Set, das die Songs nacheinander spielt.
 *
 * Danach verknüpft `link()` jeden Song mit seinem Album und den Nachbarn davor und dahinter.
 */
final class AlbumBuilder
{
    /**
     * @param array<string, array{source: string, prefix: string, slug: string, file: string|null, strict: bool, items: list<string>}> $albums
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    public function buildAll(array $albums, array $entries, Library $library, SetParser $sets, Problems $problems): array
    {
        $albumEntries = [];

        foreach ($albums as $album) {
            try {
                $albumEntries[] = $this->build($album, $entries, $library, $sets);
            } catch (BuildError $error) {
                $problems->report($album['strict'], "{$album['source']}/{$album['slug']}/", $error);
            }
        }

        return $albumEntries;
    }

    /**
     * @param array{source: string, prefix: string, slug: string, file: string|null, strict: bool, items: list<string>} $album
     * @param list<Entry> $entries
     */
    private function build(array $album, array $entries, Library $library, SetParser $sets): Entry
    {
        $meta = [];
        $html = '';

        if ($album['file'] !== null) {
            $content = (string) file_get_contents($album['file']);

            if (strlen($content) > 32_768) {
                throw new BuildError('_album.md ist größer als 32 KB.');
            }

            $parsed = new Markdown(null)->parse($content);
            /** @var array<string, mixed> $meta */
            $meta = $parsed->frontmatter;
            $html = new HtmlSanitizer()->clean($parsed->html);
        }

        $unknown = array_diff(array_keys($meta), ['title', 'artist', 'group', 'tags', 'songs', 'tracks', 'loop']);

        if ($unknown !== []) {
            throw new BuildError('_album.md: unbekannter Schlüssel „' . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: title, artist, group, tags, songs, loop).');
        }

        if (isset($meta['songs'], $meta['tracks'])) {
            throw new BuildError('_album.md: „songs“ und „tracks“ sind derselbe Schlüssel (tracks ist der alte Name). Bitte nur „songs“ verwenden.');
        }

        $listKey = isset($meta['songs']) ? 'songs' : 'tracks';

        $available = [];

        foreach ($entries as $entry) {
            if ($entry->prefix === $album['prefix'] && $entry->album === $album['slug'] && $entry->kind === EntryKind::Song) {
                $available[substr($entry->slug, strlen($album['slug']) + 1)] = $entry->prefix . '/' . $entry->slug;
            }
        }

        ksort($available);
        $order = [];

        if (isset($meta[$listKey])) {
            if (!is_array($meta[$listKey]) || !array_is_list($meta[$listKey])) {
                throw new BuildError("_album.md: „{$listKey}“ muss eine Liste von Dateinamen ohne .md sein.");
            }

            foreach ($meta[$listKey] as $name) {
                $name = is_int($name) ? (string) $name : $name;

                if (!is_string($name) || !isset($available[$name])) {
                    throw new BuildError("_album.md: „{$listKey}“ nennt „" . (is_string($name) ? $name : (string) json_encode($name)) . '“, das ist kein Song in diesem Album.');
                }

                $order[$name] = $available[$name];
            }
        }

        $order += $available;

        if ($order === []) {
            throw new BuildError('Das Album enthält keinen Song, der sich abspielen lässt.');
        }

        $head = ['format' => 1, 'kind' => 'set', 'loop' => $meta['loop'] ?? false, 'title' => $meta['title'] ?? ucfirst(str_replace('-', ' ', $album['slug']))];

        foreach (['artist', 'group', 'tags'] as $key) {
            if (isset($meta[$key])) {
                $head[$key] = $meta[$key];
            }
        }

        $source = "---\n" . json_encode($head, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n---\n\n";

        foreach ($order as $ref) {
            $source .= "<!-- part: {$ref} -->\n\n";
        }

        $data = $sets->parse($source, $album['slug'], $library->song(...));
        $data = $data->withNotesHtml($html);

        return new Entry(
            prefix: $album['prefix'],
            slug: $album['slug'],
            album: $album['slug'],
            source: null,
            kind: EntryKind::Album,
            data: $data,
            order: array_map(strval(...), array_keys($order)),
            sessions: array_values(array_filter(
                $entries,
                fn(Entry $entry): bool => $entry->prefix === $album['prefix'] && $entry->album === $album['slug'] && $entry->kind === EntryKind::Session,
            )),
            links: array_values(array_filter(
                $entries,
                fn(Entry $entry): bool => $entry->prefix === $album['prefix'] && $entry->album === $album['slug'] && $entry->kind === EntryKind::Link,
            )),
        );
    }

    /**
     * @param list<Entry> $entries
     * @param list<Entry> $albums
     * @return list<Entry>
     */
    public function link(array $entries, array $albums): array
    {
        $titles = [];

        foreach ($entries as $entry) {
            $titles[$entry->prefix . '/' . $entry->slug] = $entry->title();
        }

        $navigation = [];

        foreach ($albums as $album) {
            $order = $album->order;

            foreach ($order as $index => $name) {
                $link = fn(?string $other): ?array => (
                    $other === null
                        ? null
                        : [
                            'slug' => "{$album->slug}/{$other}",
                            'title' => $titles["{$album->prefix}/{$album->slug}/{$other}"] ?? $other,
                        ]
                );

                $navigation["{$album->prefix}/{$album->slug}/{$name}"] = [
                    'album' => ['slug' => $album->slug, 'title' => $album->title()],
                    'number' => $index + 1,
                    'total' => count($order),
                    'prev' => $link($order[$index - 1] ?? null),
                    'next' => $link($order[$index + 1] ?? null),
                ];
            }
        }

        return array_map(fn(Entry $entry): Entry => $entry->withNav($navigation[$entry->key()] ?? null), $entries);
    }
}
