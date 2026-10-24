<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\Filesystem;
use Chiptune\Format\Set\SetParser;
use Chiptune\Format\Song\SongParser;
use Chiptune\Site\Builder\AlbumBuilder;
use Chiptune\Site\Builder\Assets;
use Chiptune\Site\Builder\EntryLoader;
use Chiptune\Site\Builder\Problems;
use Chiptune\Site\Builder\SiteConfig;
use Chiptune\Site\CodeBook\CodeDocs;

/**
 * Baut die ganze Seite.
 *
 * Er holt die Quellen, lässt `EntryLoader` und `AlbumBuilder` alles einlesen und verknüpfen, rendert
 * mit dem `Renderer` und schreibt Seiten, Mixer, Synth und Serverdateien. Ein Fehler in einer strikten Quelle
 * bricht den Bau ab, bei den anderen wird die Datei übersprungen (`Problems`).
 */
final class Builder
{
    private const MARKER = '.chiptune-site';

    public function __construct(
        private readonly string $root,
        private readonly string $public,
    ) {}

    public function build(bool $fetch = true): int
    {
        $config = new SiteConfig($this->root);
        $site = $config->site();
        $pages = $config->pages();
        $sources = new Sources($this->root, $this->root . '/.cache/sources');
        $collected = $sources->collect($config->json($this->root . '/etc/sources.json'), $fetch);

        $sets = new SetParser();
        $library = new Library($collected['files'], new SongParser(), $sets);
        $problems = new Problems();

        $entries = new EntryLoader()->load($collected['files'], $library, $problems);
        $albums = new AlbumBuilder();
        $albumEntries = $albums->buildAll($collected['albums'], $entries, $library, $sets, $problems);

        if ($problems->any()) {
            fwrite(STDERR, "Der Build wurde abgebrochen:\n- " . implode("\n- ", $problems->lines()) . "\n");

            return 1;
        }

        usort($albumEntries, fn(Entry $a, Entry $b): int => strcasecmp($a->title(), $b->title()));
        $entries = $albums->link($entries, $albumEntries);
        $authors = $config->authors($collected['authors'], $entries);

        $assets = new Assets($this->root, $this->public);
        $assets->prepare();
        $renderer = new Renderer($site, $assets->write($site['assets_base']), $authors, $pages);

        $this->writePages($renderer, $entries, $albumEntries, $authors);
        $this->writeMixer($renderer, $site['base_path'], $entries, $albumEntries, $authors);
        Filesystem::write($this->public . '/+synth/index.html', $renderer->synth());
        $this->writeServerFiles($site['noindex'], $site['assets_origin'], $site['base_path'], $collected['media']);
        $this->report($entries, $albumEntries, $authors);

        return 0;
    }

    /**
     * @param list<Entry> $entries
     * @param list<Entry> $albumEntries
     * @param array<string, array<string, mixed>> $authors
     */
    private function writePages(Renderer $renderer, array $entries, array $albumEntries, array $authors): void
    {
        Filesystem::write($this->public . '/index.html', $renderer->library([...$entries, ...$albumEntries]));
        Filesystem::write($this->public . '/404.html', $renderer->notFound());
        Filesystem::write($this->public . '/+code/index.html', $renderer->code(new CodeBook($this->root)->read(), new CodeDocs($this->root)->areas()));

        foreach (array_map(strval(...), array_keys($authors)) as $prefix) {
            $ownAlbums = array_values(array_filter($albumEntries, fn(Entry $entry): bool => $entry->prefix === $prefix));
            $singles = array_values(array_filter($entries, fn(Entry $entry): bool => $entry->prefix === $prefix && $entry->album === null));
            Filesystem::write("{$this->public}/{$prefix}/index.html", $renderer->library([...$ownAlbums, ...$singles], $prefix));
        }

        foreach ($albumEntries as $entry) {
            Filesystem::write("{$this->public}/{$entry->prefix}/{$entry->slug}/index.html", $renderer->set($entry));
        }

        foreach ($entries as $entry) {
            if ($entry->kind === EntryKind::Link && !$entry->link()->media->releaseOwn) {
                continue;
            }

            $directory = "{$this->public}/{$entry->prefix}/{$entry->slug}";
            Filesystem::write("{$directory}/index.html", match ($entry->kind) {
                EntryKind::Set => $renderer->set($entry),
                EntryKind::Session => $renderer->session($entry),
                EntryKind::Link => $renderer->link($entry),
                default => $renderer->song($entry),
            });
            Filesystem::write("{$directory}/source.md", (string) file_get_contents((string) $entry->source));
        }
    }

    /**
     * @param list<Entry> $entries
     * @param list<Entry> $albumEntries
     * @param array<string, array<string, mixed>> $authors
     */
    private function writeMixer(Renderer $renderer, string $basePath, array $entries, array $albumEntries, array $authors): void
    {
        $catalog = [];
        $albumTitles = [];

        foreach ($albumEntries as $albumEntry) {
            $albumTitles[$albumEntry->prefix . '/' . $albumEntry->slug] = $albumEntry->title();
        }

        foreach ($entries as $entry) {
            if ($entry->kind !== EntryKind::Song) {
                continue;
            }

            $id = Renderer::mixId($entry->prefix, $entry->slug);
            Filesystem::write("{$this->public}/+mixer/data/{$id}.json", (string) json_encode($renderer->songPayload($entry->song()), Renderer::JSON_FLAGS));
            $catalog[] = [
                'id' => $id,
                'title' => $entry->title(),
                'artist' => $entry->artist(),
                'bpm' => $entry->song()->bpm,
                'bars' => $entry->song()->bars,
                'source' => (string) ($authors[$entry->prefix]['title'] ?? $entry->prefix),
                'sourceId' => (string) $entry->prefix,
                'album' => $entry->album === null ? '' : $albumTitles[$entry->prefix . '/' . $entry->album] ?? (string) $entry->album,
                'albumId' => $entry->album === null ? '' : $entry->prefix . '/' . $entry->album,
                'group' => $entry->album === null ? $entry->group() : '',
                'groupId' => $entry->album === null && $entry->group() !== '' ? $entry->prefix . '/' . mb_strtolower($entry->group()) : '',
                'tags' => array_values(array_map('strval', $entry->tags())),
                'href' => $basePath . "{$entry->prefix}/{$entry->slug}/",
                'data' => "data/{$id}.json",
            ];
        }

        Filesystem::write($this->public . '/+mixer/index.html', $renderer->mix($catalog));
    }

    /**
     * @param list<string> $mediaHosts
     */
    private function writeServerFiles(bool $noindex, string $assetsOrigin, string $basePath, array $mediaHosts): void
    {
        Filesystem::write($this->public . '/robots.txt', $noindex ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nAllow: /\n");
        Filesystem::write($this->public . '/' . self::MARKER, "Von build.php erzeugt. Das Deployment ersetzt den Inhalt dieses Ordners.\n");

        $htaccess = $this->root . '/var/resources/htaccess';

        if (!is_file($htaccess)) {
            return;
        }

        $origin = $assetsOrigin === '' ? '' : ' ' . $assetsOrigin;
        $hosts = implode(' ', array_map(fn(string $host): string => "https://{$host}", $mediaHosts));
        $mediaSrc = $mediaHosts === [] ? '' : " media-src 'self' {$hosts};";
        $imgHosts = $mediaHosts === [] ? '' : " {$hosts}";
        Filesystem::write($this->public . '/.htaccess', str_replace(
            ['__ASSET_ORIGIN__', '__BASE_PATH__', '__MEDIA_SRC__', '__IMG_HOSTS__'],
            [$origin, $basePath, $mediaSrc, $imgHosts],
            (string) file_get_contents($htaccess),
        ));
    }

    /**
     * @param list<Entry> $entries
     * @param list<Entry> $albumEntries
     * @param array<string, array<string, mixed>> $authors
     */
    private function report(array $entries, array $albumEntries, array $authors): void
    {
        $count = fn(EntryKind $kind): int => count(array_filter($entries, fn(Entry $entry): bool => $entry->kind === $kind));
        $sets = $count(EntryKind::Set);
        $sessions = $count(EntryKind::Session);
        $links = $count(EntryKind::Link);

        fwrite(STDOUT, sprintf(
            "%d Songs, %d Sets, %d Sessions, %d Links und %d Alben von %d Autoren gebaut: %s\n",
            count($entries) - $sets - $sessions - $links,
            $sets,
            $sessions,
            $links,
            count($albumEntries),
            count($authors),
            $this->public,
        ));
    }
}
