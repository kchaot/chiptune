<?php

declare(strict_types=1);

namespace Chiptune;

final class Sources
{
    private const IGNORED = '/^(readme|license|licence|contributing|code_of_conduct|changelog|security)(\.[a-z]+)?$/i';
    private const RESERVED_PREFIXES = ['index', '404', 'robots', 'favicon', 'sitemap'];
    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function __construct(
        private readonly string $root,
        private readonly string $cacheDir,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     files: list<array{source: string, prefix: string, slug: string, album: string|null, file: string, strict: bool}>,
     *     albums: array<string, array{source: string, prefix: string, slug: string, file: string|null, strict: bool, items: list<string>}>,
     *     authors: array<string, array{title: string, url: string, profile: string|null}>
     * }
     */
    public function collect(array $config, bool $fetch = true): array
    {
        $sources = $config['sources'] ?? null;

        if (! is_array($sources) || $sources === []) {
            throw new BuildError('sources.json: „sources“ muss eine nicht leere Liste sein.');
        }

        $projects = [];
        $albums = [];
        $authors = [];
        $seenNames = [];

        foreach ($sources as $index => $source) {
            $label = 'sources.json, Eintrag ' . ($index + 1);

            if (! is_array($source)) {
                throw new BuildError("{$label}: Ein Eintrag muss ein Objekt sein.");
            }

            $name = $source['name'] ?? null;

            if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name) !== 1) {
                throw new BuildError("{$label}: „name“ fehlt oder ist ungültig (a-z, 0-9, Bindestrich).");
            }

            if (isset($seenNames[$name])) {
                throw new BuildError("{$label}: Der Name „{$name}“ kommt doppelt vor.");
            }

            $seenNames[$name] = true;

            $prefix = $source['prefix'] ?? $name;

            if (! is_string($prefix) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $prefix) !== 1) {
                throw new BuildError("{$label}: „prefix“ ist ungültig (a-z, 0-9, Bindestrich, höchstens 32 Zeichen).");
            }

            if (in_array($prefix, self::RESERVED_PREFIXES, true)) {
                throw new BuildError("{$label}: Der Prefix „{$prefix}“ ist reserviert.");
            }

            $title = $source['title'] ?? null;
            $url = $source['url'] ?? '';

            if ($title !== null && (! is_string($title) || trim($title) === '' || mb_strlen($title) > 60 || preg_match('/[\x00-\x1F\x7F]/', $title) === 1)) {
                throw new BuildError("{$label}: „title“ muss ein Text mit 1 bis 60 Zeichen sein.");
            }

            if (! is_string($url) || ($url !== '' && (preg_match('~^https?://[^\s<>"\']{1,200}$~', $url) !== 1 || str_contains($url, '\\')))) {
                throw new BuildError("{$label}: „url“ muss mit http:// oder https:// beginnen.");
            }

            $strict = $source['strict'] ?? ! isset($source['repo']);

            if (! is_bool($strict)) {
                throw new BuildError("{$label}: „strict“ muss true oder false sein.");
            }

            if (isset($authors[$prefix])) {
                if ($title !== null && $title !== $authors[$prefix]['title']) {
                    throw new BuildError("{$label}: Der Prefix „{$prefix}“ wird schon mit dem Titel „{$authors[$prefix]['title']}“ verwendet.");
                }

                if ($url !== '' && $authors[$prefix]['url'] === '') {
                    $authors[$prefix]['url'] = $url;
                }
            } else {
                $authors[$prefix] = ['title' => $title ?? $prefix, 'url' => $url, 'profile' => null];
            }

            $directory = isset($source['repo'])
                ? $this->repoDirectory($name, $source, $fetch)
                : $this->localDirectory($name, $source);

            if ($directory === null) {
                continue;
            }

            $profile = $directory . '/_profile.md';

            if ($authors[$prefix]['profile'] === null && is_file($profile) && ! is_link($profile)) {
                $authors[$prefix]['profile'] = $profile;
            }

            $found = $this->projectFiles($name, $directory);

            foreach ($found['files'] as $slug => $file) {
                $key = "{$prefix}/{$slug}";

                if (isset($projects[$key])) {
                    throw new BuildError("„{$key}“ kommt in den Quellen „{$projects[$key]['source']}“ und „{$name}“ vor.");
                }

                $projects[$key] = ['source' => $name, 'prefix' => $prefix, 'slug' => (string) $slug, 'album' => null, 'file' => $file, 'strict' => $strict];
            }

            foreach ($found['albums'] as $album => $info) {
                $album = (string) $album;
                $albumKey = "{$prefix}/{$album}";
                $albums[$albumKey] ??= ['source' => $name, 'prefix' => $prefix, 'slug' => $album, 'file' => null, 'strict' => $strict, 'items' => []];
                $albums[$albumKey]['file'] ??= $info['file'];

                foreach ($info['files'] as $slug => $file) {
                    $key = "{$prefix}/{$album}/{$slug}";

                    if (isset($projects[$key])) {
                        throw new BuildError("„{$key}“ kommt in den Quellen „{$projects[$key]['source']}“ und „{$name}“ vor.");
                    }

                    $projects[$key] = ['source' => $name, 'prefix' => $prefix, 'slug' => "{$album}/{$slug}", 'album' => $album, 'file' => $file, 'strict' => $strict];
                    $albums[$albumKey]['items'][] = (string) $slug;
                }
            }
        }

        foreach (array_keys($albums) as $albumKey) {
            if (isset($projects[$albumKey])) {
                throw new BuildError("„{$albumKey}“ ist ein Album (Ordner) und ein Project (Datei). Der Name darf nur einmal vorkommen.");
            }
        }

        ksort($projects);
        ksort($albums);

        return ['files' => array_values($projects), 'albums' => $albums, 'authors' => $authors];
    }

    /** @param array<string, mixed> $source */
    private function localDirectory(string $name, array $source): string
    {
        $path = $source['path'] ?? null;

        if (! is_string($path) || ! $this->isRelativePath($path)) {
            throw new BuildError("Quelle „{$name}“: „path“ muss ein relativer Pfad ohne „..“ sein.");
        }

        $directory = realpath($this->root . '/' . $path);

        if ($directory === false || ! is_dir($directory) || ! str_starts_with($directory, $this->root . DIRECTORY_SEPARATOR)) {
            throw new BuildError("Quelle „{$name}“: Ordner „{$path}“ nicht gefunden.");
        }

        return $directory;
    }

    /** @param array<string, mixed> $source */
    private function repoDirectory(string $name, array $source, bool $fetch): ?string
    {
        $repo = $source['repo'];
        $ref = $source['ref'] ?? 'main';
        $path = $source['path'] ?? '';

        if (! is_string($repo) || preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo) !== 1) {
            throw new BuildError("Quelle „{$name}“: „repo“ muss die Form owner/repo haben.");
        }

        if (! is_string($ref) || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]{0,99}$~', $ref) !== 1 || str_contains($ref, '..')) {
            throw new BuildError("Quelle „{$name}“: „ref“ ist ungültig.");
        }

        if (! is_string($path) || ($path !== '' && ! $this->isRelativePath($path))) {
            throw new BuildError("Quelle „{$name}“: „path“ muss ein relativer Pfad ohne „..“ sein.");
        }

        $checkout = $this->cacheDir . '/' . $name;

        if (! $fetch) {
            if (! is_dir($checkout)) {
                fwrite(STDERR, "Hinweis: Quelle „{$name}“ übersprungen (kein Abruf, kein Cache).\n");

                return null;
            }
        } else {
            $this->clone($name, "https://github.com/{$repo}.git", $ref, $checkout);
        }

        $directory = realpath($checkout . ($path === '' ? '' : '/' . $path));

        if ($directory === false || ! is_dir($directory) || ! str_starts_with($directory, realpath($checkout) . '')) {
            throw new BuildError("Quelle „{$name}“: Ordner „{$path}“ im Repo {$repo} nicht gefunden.");
        }

        return $directory;
    }

    private function clone(string $name, string $url, string $ref, string $target): void
    {
        Filesystem::removeDirectory($target);

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
            throw new BuildError('Cache-Ordner konnte nicht angelegt werden.');
        }

        $process = proc_open(
            ['git', 'clone', '--quiet', '--depth', '1', '--branch', $ref, '--', $url, $target],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0']),
        );

        if (! is_resource($process)) {
            throw new BuildError('git konnte nicht gestartet werden.');
        }

        stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new BuildError("Quelle „{$name}“: Abruf von {$url} (Ref „{$ref}“) fehlgeschlagen. {$error}");
        }
    }

    /**
     * @return array{files: array<string, string>, albums: array<string, array{file: string|null, files: array<string, string>}>}
     */
    private function projectFiles(string $source, string $directory): array
    {
        $files = $this->markdownFiles($source, $directory);
        $albums = [];

        foreach (scandir($directory) ?: [] as $entry) {
            $path = $directory . '/' . $entry;

            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_starts_with($entry, '_') || is_link($path) || ! is_dir($path)) {
                continue;
            }

            if (preg_match(self::SLUG, $entry) !== 1) {
                throw new BuildError("Quelle „{$source}“, Ordner „{$entry}“: Ein Album-Ordner muss aus a-z, 0-9 und Bindestrich bestehen (zum Beispiel beat-fuer-beat).");
            }

            $inside = $this->markdownFiles($source, $path);

            if ($inside === []) {
                continue;
            }

            $meta = $path . '/_album.md';
            $albums[$entry] = ['file' => is_file($meta) && ! is_link($meta) ? $meta : null, 'files' => $inside];
        }

        return ['files' => $files, 'albums' => $albums];
    }

    /** @return array<string, string> */
    private function markdownFiles(string $source, string $directory): array
    {
        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if (! str_ends_with(strtolower($entry), '.md') || is_link($directory . '/' . $entry) || ! is_file($directory . '/' . $entry)) {
                continue;
            }

            if (str_starts_with($entry, '_')) {
                continue;
            }

            $base = substr($entry, 0, -3);

            if (preg_match(self::IGNORED, $entry) === 1) {
                continue;
            }

            if (! str_ends_with($entry, '.md') || preg_match(self::SLUG, $base) !== 1) {
                throw new BuildError("Quelle „{$source}“, Datei „{$entry}“: Der Dateiname muss aus a-z, 0-9 und Bindestrich bestehen und auf .md enden (zum Beispiel nachtschicht.md).");
            }

            $files[$base] = $directory . '/' . $entry;
        }

        return $files;
    }

    private function isRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! in_array('..', explode('/', $path), true)
            && preg_match('/[\x00-\x1F]/', $path) !== 1;
    }
}
