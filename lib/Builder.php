<?php

declare(strict_types=1);

namespace Chiptune;

use Tempest\Markdown\Markdown;

final class Builder
{
    private const MARKER = '.chiptune-site';
    private const ASSET_DIR = '_cdn';

    public function __construct(
        private readonly string $root,
        private readonly string $public,
    ) {}

    public function build(bool $fetch = true): int
    {
        $site = $this->loadSite();
        $pages = $this->loadPages();
        $sources = new Sources($this->root, $this->root . '/.cache/sources');
        $collected = $sources->collect($this->readJson($this->root . '/etc/sources.json'), $fetch);

        $projects = new ProjectParser();
        $sets = new SetParser($projects);
        $library = new Library($collected['files'], $projects, $sets);
        $entries = [];
        $errors = [];

        foreach ($collected['files'] as $file) {
            $label = "{$file['source']}/{$file['slug']}.md";

            try {
                $loaded = $library->load("{$file['prefix']}/{$file['slug']}");
            } catch (BuildError $error) {
                if ($file['strict']) {
                    $errors[] = "{$label}: {$error->getMessage()}";
                } else {
                    fwrite(STDERR, "ÜBERSPRUNGEN: {$label}: {$error->getMessage()}\n");
                }

                continue;
            }

            foreach ($loaded['data']['warnings'] as $warning) {
                fwrite(STDERR, "Warnung in {$label}: {$warning}\n");
            }

            if (in_array($loaded['kind'], ['project', 'link'], true) && isset($loaded['data']['media']) && !$loaded['data']['media']->isEmpty()) {
                $loaded['data']['media'] = $this->checkedMedia($loaded['data']['media'], $file['media'], $file['strict'], $label, $errors);
            }

            if ($file['album'] !== null && ($loaded['data']['group'] ?? '') !== '') {
                fwrite(STDERR, "Warnung in {$label}: „group“ gilt nur für Songs ohne Album und wird ignoriert.\n");
            }

            $entries[] = [
                'prefix' => $file['prefix'],
                'slug' => $file['slug'],
                'album' => $file['album'],
                'source' => $file['file'],
                'kind' => $loaded['kind'],
                'project' => $loaded['data'],
                'strudel' => $loaded['kind'] === 'project' ? $this->handStrudel($file['file'], $loaded['data'], $label) : null,
            ];
        }

        usort($entries, fn(array $a, array $b): int => strcasecmp($a['project']['title'], $b['project']['title']));

        $albumEntries = [];

        foreach ($collected['albums'] as $album) {
            $label = "{$album['source']}/{$album['slug']}/";

            try {
                $albumEntries[] = $this->buildAlbum($album, $entries, $library, $sets);
            } catch (BuildError $error) {
                if ($album['strict']) {
                    $errors[] = "{$label}: {$error->getMessage()}";
                } else {
                    fwrite(STDERR, "ÜBERSPRUNGEN: {$label}: {$error->getMessage()}\n");
                }
            }
        }

        if ($errors !== []) {
            fwrite(STDERR, "Der Build wurde abgebrochen:\n- " . implode("\n- ", $errors) . "\n");

            return 1;
        }

        usort($albumEntries, fn(array $a, array $b): int => strcasecmp($a['project']['title'], $b['project']['title']));
        $entries = $this->linkAlbums($entries, $albumEntries);

        $authors = array_intersect_key($collected['authors'], array_flip(array_column($entries, 'prefix')));

        foreach ($authors as $prefix => $author) {
            $prefix = (string) $prefix;
            $profile = $this->loadProfile($prefix, $author['profile'] ?? null);
            $authors[$prefix]['url'] = $author['url'] !== '' ? $author['url'] : $profile['url'];
            $authors[$prefix] += ['tagline' => $profile['tagline'], 'html' => $profile['html'], 'author' => $profile['author']];
        }

        $this->prepareDist();
        $assets = $this->writeAssets($site['assets_base']);
        $renderer = new Renderer($site, $assets, $authors, $pages);

        Filesystem::write($this->public . '/index.html', $renderer->library([...$entries, ...$albumEntries]));
        Filesystem::write($this->public . '/404.html', $renderer->notFound());

        foreach (array_map(strval(...), array_keys($authors)) as $prefix) {
            $ownAlbums = array_values(array_filter($albumEntries, fn(array $entry): bool => $entry['prefix'] === $prefix));
            $singles = array_values(array_filter($entries, fn(array $entry): bool => $entry['prefix'] === $prefix && $entry['album'] === null));
            Filesystem::write("{$this->public}/{$prefix}/index.html", $renderer->library([...$ownAlbums, ...$singles], $prefix));
        }

        foreach ($albumEntries as $entry) {
            Filesystem::write("{$this->public}/{$entry['prefix']}/{$entry['slug']}/index.html", $renderer->set($entry));
        }

        foreach ($entries as $entry) {
            if ($entry['kind'] === 'link' && !$entry['project']['media']->releaseOwn) {
                continue;
            }

            $directory = "{$this->public}/{$entry['prefix']}/{$entry['slug']}";
            Filesystem::write("{$directory}/index.html", match ($entry['kind']) {
                'set' => $renderer->set($entry),
                'session' => $renderer->session($entry),
                'link' => $renderer->link($entry),
                default => $renderer->project($entry),
            });
            Filesystem::write("{$directory}/source.md", (string) file_get_contents($entry['source']));
        }

        $catalog = [];
        $albumTitles = [];

        foreach ($albumEntries as $albumEntry) {
            $albumTitles[$albumEntry['prefix'] . '/' . $albumEntry['slug']] = (string) $albumEntry['project']['title'];
        }

        foreach ($entries as $entry) {
            if ($entry['kind'] !== 'project') {
                continue;
            }

            $id = Renderer::mixId($entry['prefix'], $entry['slug']);
            Filesystem::write("{$this->public}/+mixer/data/{$id}.json", json_encode($renderer->projectPayload($entry['project']), Renderer::JSON_FLAGS));
            $catalog[] = [
                'id' => $id,
                'title' => $entry['project']['title'],
                'artist' => (string) $entry['project']['artist'],
                'bpm' => $entry['project']['bpm'],
                'bars' => $entry['project']['bars'],
                'source' => (string) ($authors[$entry['prefix']]['title'] ?? $entry['prefix']),
                'sourceId' => (string) $entry['prefix'],
                'album' => $entry['album'] === null ? '' : $albumTitles[$entry['prefix'] . '/' . $entry['album']] ?? (string) $entry['album'],
                'albumId' => $entry['album'] === null ? '' : $entry['prefix'] . '/' . $entry['album'],
                'group' => $entry['album'] === null ? (string) ($entry['project']['group'] ?? '') : '',
                'groupId' =>
                    $entry['album'] === null && ($entry['project']['group'] ?? '') !== '' ? $entry['prefix'] . '/' . mb_strtolower((string) $entry['project']['group']) : '',
                'tags' => array_values(array_map('strval', (array) ($entry['project']['tags'] ?? []))),
                'href' => $site['base_path'] . "{$entry['prefix']}/{$entry['slug']}/",
                'data' => "data/{$id}.json",
            ];
        }

        Filesystem::write($this->public . '/+mixer/index.html', $renderer->mix($catalog));

        Filesystem::write($this->public . '/robots.txt', $site['noindex'] ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nAllow: /\n");
        Filesystem::write($this->public . '/' . self::MARKER, "Von build.php erzeugt. Das Deployment ersetzt den Inhalt dieses Ordners.\n");

        $htaccess = $this->root . '/var/resources/htaccess';

        if (is_file($htaccess)) {
            $origin = $site['assets_origin'] === '' ? '' : ' ' . $site['assets_origin'];
            $hosts = implode(' ', array_map(fn(string $host): string => "https://{$host}", $collected['media']));
            $mediaSrc = $collected['media'] === [] ? '' : " media-src 'self' {$hosts};";
            $imgHosts = $collected['media'] === [] ? '' : " {$hosts}";
            Filesystem::write($this->public . '/.htaccess', str_replace(
                ['__ASSET_ORIGIN__', '__BASE_PATH__', '__MEDIA_SRC__', '__IMG_HOSTS__'],
                [$origin, $site['base_path'], $mediaSrc, $imgHosts],
                (string) file_get_contents($htaccess),
            ));
        }

        $setCount = count(array_filter($entries, fn(array $entry): bool => $entry['kind'] === 'set'));
        $sessionCount = count(array_filter($entries, fn(array $entry): bool => $entry['kind'] === 'session'));
        $linkCount = count(array_filter($entries, fn(array $entry): bool => $entry['kind'] === 'link'));
        fwrite(STDOUT, sprintf(
            "%d Projects, %d Sets, %d Sessions, %d Links und %d Alben von %d Autoren gebaut: %s\n",
            count($entries) - $setCount - $sessionCount - $linkCount,
            $setCount,
            $sessionCount,
            $linkCount,
            count($albumEntries),
            count($authors),
            $this->public,
        ));

        return 0;
    }

    /**
     * @param array{source: string, prefix: string, slug: string, file: string|null, strict: bool, items: list<string>} $album
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function buildAlbum(array $album, array $entries, Library $library, SetParser $sets): array
    {
        $meta = [];
        $html = '';

        if ($album['file'] !== null) {
            $content = (string) file_get_contents($album['file']);

            if (strlen($content) > 32_768) {
                throw new BuildError('_album.md ist größer als 32 KB.');
            }

            $parsed = new Markdown(null)->parse($content);
            $meta = $parsed->frontmatter;
            $html = new HtmlSanitizer()->clean($parsed->html);
        }

        $unknown = array_diff(array_keys($meta), ['title', 'artist', 'tags', 'tracks', 'loop']);

        if ($unknown !== []) {
            throw new BuildError('_album.md: unbekannter Schlüssel „' . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: title, artist, tags, tracks, loop).');
        }

        $available = [];

        foreach ($entries as $entry) {
            if ($entry['prefix'] === $album['prefix'] && $entry['album'] === $album['slug'] && $entry['kind'] === 'project') {
                $available[substr($entry['slug'], strlen($album['slug']) + 1)] = $entry['prefix'] . '/' . $entry['slug'];
            }
        }

        ksort($available);
        $order = [];

        if (isset($meta['tracks'])) {
            if (!is_array($meta['tracks']) || !array_is_list($meta['tracks'])) {
                throw new BuildError('_album.md: „tracks“ muss eine Liste von Dateinamen ohne .md sein.');
            }

            foreach ($meta['tracks'] as $name) {
                $name = is_int($name) ? (string) $name : $name;

                if (!is_string($name) || !isset($available[$name])) {
                    throw new BuildError('_album.md: „tracks“ nennt „' . (is_string($name) ? $name : json_encode($name)) . '“, das ist kein Project in diesem Album.');
                }

                $order[$name] = $available[$name];
            }
        }

        $order += $available;

        if ($order === []) {
            throw new BuildError('Das Album enthält kein Project, das sich abspielen lässt.');
        }

        $head = ['format' => 1, 'kind' => 'set', 'loop' => $meta['loop'] ?? false, 'title' => $meta['title'] ?? ucfirst(str_replace('-', ' ', $album['slug']))];

        foreach (['artist', 'tags'] as $key) {
            if (isset($meta[$key])) {
                $head[$key] = $meta[$key];
            }
        }

        $source = "---\n" . json_encode($head, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n---\n\n";

        foreach ($order as $ref) {
            $source .= "<!-- part: {$ref} -->\n\n";
        }

        $data = $sets->parse($source, $album['slug'], $library->project(...));
        $data['notesHtml'] = $html;

        return [
            'prefix' => $album['prefix'],
            'slug' => $album['slug'],
            'album' => $album['slug'],
            'source' => null,
            'kind' => 'album',
            'project' => $data,
            'order' => array_map(strval(...), array_keys($order)),
            'sessions' => array_values(array_filter(
                $entries,
                fn(array $entry): bool => $entry['prefix'] === $album['prefix'] && $entry['album'] === $album['slug'] && $entry['kind'] === 'session',
            )),
            'links' => array_values(array_filter(
                $entries,
                fn(array $entry): bool => $entry['prefix'] === $album['prefix'] && $entry['album'] === $album['slug'] && $entry['kind'] === 'link',
            )),
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $albums
     * @return list<array<string, mixed>>
     */
    private function linkAlbums(array $entries, array $albums): array
    {
        $titles = [];

        foreach ($entries as $entry) {
            $titles[$entry['prefix'] . '/' . $entry['slug']] = $entry['project']['title'];
        }

        $navigation = [];

        foreach ($albums as $album) {
            $order = $album['order'];

            foreach ($order as $index => $name) {
                $link = fn(?string $other): ?array => (
                    $other === null
                        ? null
                        : [
                            'slug' => "{$album['slug']}/{$other}",
                            'title' => $titles["{$album['prefix']}/{$album['slug']}/{$other}"] ?? $other,
                        ]
                );

                $navigation["{$album['prefix']}/{$album['slug']}/{$name}"] = [
                    'album' => ['slug' => $album['slug'], 'title' => $album['project']['title']],
                    'number' => $index + 1,
                    'total' => count($order),
                    'prev' => $link($order[$index - 1] ?? null),
                    'next' => $link($order[$index + 1] ?? null),
                ];
            }
        }

        return array_map(function (array $entry) use ($navigation): array {
            $entry['nav'] = $navigation[$entry['prefix'] . '/' . $entry['slug']] ?? null;

            return $entry;
        }, $entries);
    }

    /**
     * @return array{
     *     title: string, tagline: string, noindex: bool, prompt: string, outro: string, base_path: string,
     *     parent: array{title: string, url: string}|null, assets_base: string, assets_origin: string
     * }
     */
    private function loadSite(): array
    {
        $config = $this->readJson($this->root . '/etc/site.json');

        $basePath = $config['base_path'] ?? '/';

        if (!is_string($basePath) || preg_match('#^/([A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*$#', $basePath) !== 1 || str_contains($basePath, '..')) {
            throw new BuildError('site.json: „base_path“ muss mit / beginnen und enden, zum Beispiel / oder /~kchaot/chiptune/.');
        }

        $base = $config['assets_base'] ?? $basePath . '_cdn/';
        $local = is_string($base) && preg_match('#^/(?!/)[A-Za-z0-9_~./-]*/$#', $base) === 1;
        $external = is_string($base) && preg_match('#^(https://[a-z0-9.-]+(?::\d{1,5})?)(?:/[A-Za-z0-9_~./-]*)?/$#', $base, $match) === 1;

        if (!$local && !$external || str_contains((string) $base, '..')) {
            throw new BuildError('site.json: „assets_base“ muss ein Pfad wie /_cdn/ oder eine https-Adresse sein und mit / enden.');
        }

        $prompt = $config['prompt'] ?? '';

        if (!is_string($prompt) || mb_strlen($prompt) > 60 || preg_match('/[\x00-\x1F\x7F]/', $prompt) === 1) {
            throw new BuildError('site.json: „prompt“ muss ein Text bis 60 Zeichen sein (Hostname, zum Beispiel chiptune.kchaot.de).');
        }

        $outro = $config['outro'] ?? '';

        if (!is_string($outro) || mb_strlen($outro) > 80 || preg_match('/[\x00-\x1F\x7F]/', $outro) === 1) {
            throw new BuildError('site.json: „outro“ muss ein Text bis 80 Zeichen sein.');
        }

        $parent = $config['parent'] ?? null;

        if ($parent !== null) {
            $title = is_array($parent) ? $parent['title'] ?? null : null;
            $url = is_array($parent) ? $parent['url'] ?? null : null;

            if (
                !is_string($title)
                || trim($title) === ''
                || mb_strlen($title) > 40
                || !is_string($url)
                || preg_match('~^https?://[^\s<>"\']{1,200}$~', $url) !== 1
                || str_contains($url, '\\')
            ) {
                throw new BuildError('site.json: „parent“ braucht „title“ (bis 40 Zeichen) und „url“ (http:// oder https://).');
            }

            $parent = ['title' => trim($title), 'url' => $url];
        }

        return [
            'title' => (string) ($config['title'] ?? 'Chiptune'),
            'tagline' => (string) ($config['tagline'] ?? ''),
            'noindex' => (bool) ($config['noindex'] ?? false),
            'prompt' => $prompt,
            'outro' => $outro,
            'base_path' => $basePath,
            'parent' => $parent,
            'assets_base' => $base,
            'assets_origin' => $external ? $match[1] : '',
        ];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private function checkedMedia(Media $media, array $allowed, bool $strict, string $label, array &$errors): Media
    {
        foreach (['hq', 'cover'] as $key) {
            if ($media->{$key} === null) {
                continue;
            }

            $host = Url::host($media->{$key});

            if (in_array($host, $allowed, true)) {
                continue;
            }

            $message = "„{$key}“ verweist auf „{$host}“. Der Host ist für diese Quelle nicht unter „media“ in sources.json freigegeben.";

            if ($strict) {
                $errors[] = "{$label}: {$message}";
            } else {
                fwrite(STDERR, "Warnung in {$label}: {$message} Der Link wird ignoriert.\n");
            }

            $media = $key === 'hq' ? $media->withoutHq() : $media->withoutCover();
        }

        if ($media->release !== null && in_array(Url::host($media->release), $allowed, true)) {
            $media = $media->asOwnRelease();
        }

        if (($media->cover !== null || $media->info !== []) && !$media->releaseOwn) {
            $message = 'Die Angaben in „release“ (cover, title, date, label, catalog, license, credits) werden nur bei einer selbst veröffentlichten Audiodatei angezeigt (Host unter „media“ in sources.json freigegeben).';

            if ($strict) {
                $errors[] = "{$label}: {$message}";
            } else {
                fwrite(STDERR, "Warnung in {$label}: {$message} Die Angaben werden ignoriert.\n");
            }

            $media = $media->withoutReleaseDetails();
        }

        return $media;
    }

    /**
     * @return array{tagline: string, html: string, author: string, url: string}
     */
    private function loadProfile(string $prefix, ?string $file): array
    {
        $empty = ['tagline' => '', 'html' => '', 'author' => '', 'url' => ''];

        if ($file === null) {
            return $empty;
        }

        $content = (string) file_get_contents($file);

        try {
            if (strlen($content) > 32_768) {
                throw new BuildError('Die Datei ist größer als 32 KB.');
            }

            $parsed = new Markdown(null)->parse($content);
            $tagline = $parsed->frontmatter['tagline'] ?? '';

            if (!is_string($tagline) || mb_strlen($tagline) > 80 || preg_match('/[\x00-\x1F\x7F]/', $tagline) === 1) {
                throw new BuildError('„tagline“ muss ein Text bis 80 Zeichen sein.');
            }

            $author = $parsed->frontmatter['author'] ?? '';

            if (!is_string($author) || mb_strlen($author) > 60 || preg_match('/[\x00-\x1F\x7F<>&"]/', $author) === 1) {
                throw new BuildError('„author“ muss ein Text bis 60 Zeichen ohne < > & " sein.');
            }

            $url = $parsed->frontmatter['url'] ?? '';

            if (!is_string($url) || $url !== '' && (preg_match('~^https?://[^\s<>"\']{1,200}$~', $url) !== 1 || str_contains($url, '\\'))) {
                throw new BuildError('„url“ muss mit http:// oder https:// beginnen.');
            }

            return [
                'tagline' => trim($tagline),
                'html' => new HtmlSanitizer()->clean($parsed->html),
                'author' => trim($author),
                'url' => $url,
            ];
        } catch (BuildError $error) {
            fwrite(STDERR, "ÜBERSPRUNGEN: Profil von „{$prefix}“: {$error->getMessage()}\n");

            return $empty;
        }
    }

    private function handStrudel(string $markdownFile, array $project, string $label): ?string
    {
        $path = substr($markdownFile, 0, -3) . '.strudel';

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $name = basename($path);
        $text = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $stand = Renderer::strudelStand($project);

        if (strlen($text) > 65_536) {
            fwrite(STDERR, "Warnung in {$label}: {$name} ist größer als 64 KB und wird ignoriert.\n");

            return null;
        }

        // Führende Kopfzeilen des Exports („// Quelle: …“, „// Stand: …“) dürfen mitkopiert sein; der Player setzt sie selbst.
        $lines = explode("\n", $text);
        $found = null;

        while ($lines !== [] && preg_match('~^// (Quelle|Stand):[ \t]*(.*?)[ \t]*$~', $lines[0], $match) === 1) {
            if ($match[1] === 'Stand') {
                $found = $match[2];
            }

            array_shift($lines);
        }

        if ($found === null) {
            fwrite(STDERR, "Warnung in {$label}: {$name} braucht eine Zeile „// Stand: {$stand}“ am Anfang und wird ignoriert.\n");

            return null;
        }

        if ($found !== $stand) {
            fwrite(STDERR, "Warnung in {$label}: {$name} ist veraltet (Stand {$found}, Song jetzt {$stand}). Es gilt der generierte Code.\n");

            return null;
        }

        return rtrim(implode("\n", $lines), "\n") . "\n";
    }

    /**
     * @return list<array{slug: string, title: string, html: string, tabs: list<array{slug: string, title: string, html: string}>}>
     */
    private function loadPages(): array
    {
        $directory = $this->root . '/var/pages';
        $markdown = new Markdown(null);
        $sanitizer = new HtmlSanitizer();
        $pages = [];

        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
            if (!str_ends_with($entry, '.md') || !is_file("{$directory}/{$entry}") || is_link("{$directory}/{$entry}")) {
                continue;
            }

            $slug = substr($entry, 0, -3);

            if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $slug) !== 1 || in_array($slug, ['top', 'main'], true)) {
                throw new BuildError("var/pages/{$entry}: Der Dateiname muss aus a-z, 0-9 und Bindestrich bestehen (zum Beispiel impressum.md).");
            }

            $content = (string) file_get_contents("{$directory}/{$entry}");

            if (strlen($content) > 32_768) {
                throw new BuildError("var/pages/{$entry}: Die Datei ist größer als 32 KB.");
            }

            $blocks = [];
            $content = (string) preg_replace_callback(
                '/^~~~[^\n]*\n(.*?)\n~~~[ \t]*$/ms',
                function (array $match) use (&$blocks): string {
                    $blocks[] = '<pre><code>' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';

                    return 'CODEBLOCKPLACEHOLDER' . (count($blocks) - 1) . 'END';
                },
                $content,
            );

            $render = function (string $html) use (&$blocks, $sanitizer): string {
                foreach ($blocks as $index => $block) {
                    $html = (string) preg_replace('~<p>CODEBLOCKPLACEHOLDER' . $index . 'END</p>|CODEBLOCKPLACEHOLDER' . $index . 'END~', str_replace('\\', '\\\\', $block), $html);
                }

                return $sanitizer->clean($html);
            };

            $chunks = [];

            foreach ($markdown->parseMany($content, 'page', 'tab') as $chunk) {
                $chunks[] = $chunk;
            }

            $head = array_shift($chunks);
            $tabs = [];

            if (count($chunks) > 6) {
                throw new BuildError("var/pages/{$entry}: Mehr als 6 Reiter.");
            }

            foreach ($chunks as $chunk) {
                $tabSlug = (string) $chunk->name;
                $tabTitle = $chunk->frontmatter['title'] ?? null;

                if (preg_match('/^[a-z0-9][a-z0-9-]{0,23}$/', $tabSlug) !== 1) {
                    throw new BuildError("var/pages/{$entry}: Reiter „{$tabSlug}“: Der Name muss aus a-z, 0-9 und Bindestrich bestehen (zum Beispiel <!-- tab: auflegen -->).");
                }

                if (isset($tabs[$tabSlug])) {
                    throw new BuildError("var/pages/{$entry}: Der Reiter „{$tabSlug}“ kommt doppelt vor.");
                }

                if (!is_string($tabTitle) || trim($tabTitle) === '' || mb_strlen($tabTitle) > 30) {
                    throw new BuildError("var/pages/{$entry}: Reiter „{$tabSlug}“ braucht ein „title“ bis 30 Zeichen.");
                }

                $tabs[$tabSlug] = ['slug' => $tabSlug, 'title' => trim($tabTitle), 'html' => $render($chunk->html)];
            }

            $title = $head->frontmatter['title'] ?? ucfirst($slug);
            $order = $head->frontmatter['order'] ?? 100;

            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 40 || !is_int($order)) {
                throw new BuildError("var/pages/{$entry}: „title“ muss ein Text bis 40 Zeichen und „order“ eine ganze Zahl sein.");
            }

            $pages[] = ['slug' => $slug, 'title' => trim($title), 'order' => $order, 'html' => $render($head->html), 'tabs' => array_values($tabs)];
        }

        usort($pages, fn(array $a, array $b): int => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);

        return array_map(fn(array $page): array => ['slug' => $page['slug'], 'title' => $page['title'], 'html' => $page['html'], 'tabs' => $page['tabs']], $pages);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new BuildError(basename($path) . ' fehlt.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BuildError(basename($path) . ' ist kein gültiges JSON: ' . $exception->getMessage());
        }

        if (!is_array($data)) {
            throw new BuildError(basename($path) . ' muss ein JSON-Objekt enthalten.');
        }

        return $data;
    }

    private function prepareDist(): void
    {
        $dist = $this->public;

        if ($dist !== $this->root . '/tmp/public') {
            throw new BuildError('Der Ausgabeordner muss tmp/public im Projektordner sein.');
        }

        Filesystem::removeDirectory($dist);

        if (!mkdir($dist, 0o775, true) && !is_dir($dist)) {
            throw new BuildError("Ausgabeordner konnte nicht angelegt werden: {$dist}");
        }
    }

    /**
     * @return array{css: array{url: string, integrity: string}, js: array{url: string, integrity: string}}
     */
    private function writeAssets(string $base): array
    {
        $assets = [];
        $fonts = [];

        foreach (glob($this->root . '/var/resources/fonts/*.woff2') ?: [] as $font) {
            $content = (string) file_get_contents($font);
            $name = basename($font, '.woff2') . '.' . substr(sha1($content), 0, 10) . '.woff2';
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . "/fonts/{$name}", $content);
            $fonts['fonts/' . basename($font)] = 'fonts/' . $name;
        }

        foreach (glob($this->root . '/var/resources/fonts/LICENSE-*.txt') ?: [] as $license) {
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . '/fonts/' . basename($license), (string) file_get_contents($license));
        }

        foreach (['css' => 'style', 'js' => 'player'] as $extension => $name) {
            $source = "{$this->root}/var/resources/{$name}.{$extension}";

            if (!is_file($source)) {
                throw new BuildError("var/resources/{$name}.{$extension} fehlt.");
            }

            $content = (string) file_get_contents($source);

            if ($extension === 'css') {
                $content = new \MatthiasMullie\Minify\CSS(strtr($content, $fonts))->minify();
            } else {
                $content = new \MatthiasMullie\Minify\JS($content)->minify();
            }

            $file = "{$name}." . substr(sha1($content), 0, 10) . ".{$extension}";
            Filesystem::write("{$this->public}/" . self::ASSET_DIR . "/{$file}", $content);
            $assets[$extension] = [
                'url' => $base . $file,
                'integrity' => 'sha384-' . base64_encode(hash('sha384', $content, true)),
            ];
        }

        return $assets;
    }
}
