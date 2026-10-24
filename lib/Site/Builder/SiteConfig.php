<?php

declare(strict_types=1);

namespace Chiptune\Site\Builder;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Chiptune\Site\Entry;
use Tempest\Markdown\Markdown;

/**
 * Liest die Einstellungen aus `etc/`, die Seiten aus `var/pages` und die Profile der Autoren.
 *
 * `site.json` und `sources.json` prüft sie streng. Ein Profil ist die `_profile.md` im Wurzelordner einer Quelle.
 */
final class SiteConfig
{
    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * @return array{
     *     title: string, tagline: string, noindex: bool, prompt: string, outro: string, base_path: string,
     *     parent: array{title: string, url: string}|null, assets_base: string, assets_origin: string
     * }
     */
    public function site(): array
    {
        $config = $this->json($this->root . '/etc/site.json');

        $basePath = $config['base_path'] ?? '/';

        if (!is_string($basePath) || preg_match('#^/([A-Za-z0-9_~-][A-Za-z0-9_.~-]*/)*$#', $basePath) !== 1 || str_contains($basePath, '..')) {
            throw new BuildError('site.json: „base_path“ muss mit / beginnen und enden, zum Beispiel / oder /~kchaot/chiptune/.');
        }

        $base = $config['assets_base'] ?? $basePath . '_cdn/';
        $local = is_string($base) && preg_match('#^/(?!/)[A-Za-z0-9_~./-]*/$#', $base) === 1;
        $match = [];
        // @mago-expect analysis:redundant-type-comparison
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
            'noindex' => $config['noindex'] ?? false ? true : false,
            'prompt' => $prompt,
            'outro' => $outro,
            'base_path' => $basePath,
            'parent' => $parent,
            'assets_base' => (string) $base,
            'assets_origin' => $external ? $match[1] : '',
        ];
    }

    /**
     * @return list<array{slug: string, title: string, html: string, tabs: list<array{slug: string, title: string, html: string}>}>
     */
    public function pages(): array
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

            $head = array_shift($chunks) ?? throw new BuildError("var/pages/{$entry}: Die Seite ist leer.");
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

    /**
     * @return array{tagline: string, html: string, author: string, url: string}
     */
    public function profile(string $prefix, ?string $file): array
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

    /**
     * @param array<string, array{title: string, url: string, profile: string|null}> $authors
     * @param list<Entry> $entries
     * @return array<string, array{title: string, url: string, profile?: string|null, author?: string, tagline?: string, html?: string}>
     */
    public function authors(array $authors, array $entries): array
    {
        $authors = array_intersect_key($authors, array_flip(array_column($entries, 'prefix')));

        foreach ($authors as $prefix => $author) {
            $prefix = (string) $prefix;
            $profile = $this->profile($prefix, $author['profile'] ?? null);
            $authors[$prefix]['url'] = $author['url'] !== '' ? $author['url'] : $profile['url'];
            $authors[$prefix] += ['tagline' => $profile['tagline'], 'html' => $profile['html'], 'author' => $profile['author']];
        }

        return $authors;
    }

    /** @return array<string, mixed> */
    public function json(string $path): array
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

        /** @var array<string, mixed> $data */

        return $data;
    }
}
