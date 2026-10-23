<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

/**
 * Liest `var/docs.md`, den handgeschriebenen Teil der Code-Übersicht.
 *
 * Die Datei ist ein Multi-Content-Markdown: Alles vor dem ersten `<!-- next: name -->` ist die Einleitung,
 * jedes weitere Dokument ein Tab mit `title` in der Frontmatter. Eine `##`-Überschrift, die wie ein Ordner
 * unter `lib/` heißt (oder wie dessen Titel in `CodeDocs::AREAS`), bekommt später die Klassen dieses Ordners
 * darunter. Ordner, die in der Datei fehlen, hängt das Buch am Ende des Tabs `lib` automatisch an.
 */
final class CodeBook
{
    private const MAX_BYTES = 65_536;

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     intro: string,
     *     tabs: list<array{slug: string, title: string, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
     * }
     */
    public function read(): array
    {
        $path = $this->root . '/var/docs.md';

        if (!is_file($path) || is_link($path)) {
            throw new BuildError('var/docs.md fehlt.');
        }

        $content = (string) file_get_contents($path);

        if (strlen($content) > self::MAX_BYTES) {
            throw new BuildError('var/docs.md: Die Datei ist größer als 64 KB.');
        }

        $sanitizer = new HtmlSanitizer();
        $chunks = [];

        foreach (new Markdown(null)->parseMany($content, 'docs', 'next') as $chunk) {
            $chunks[] = $chunk;
        }

        $head = array_shift($chunks) ?? throw new BuildError('var/docs.md: Die Datei ist leer.');
        $title = $head->frontmatter['title'] ?? 'Code-Übersicht';

        if (!is_string($title) || trim($title) === '') {
            throw new BuildError('var/docs.md: „title“ muss ein Text sein.');
        }

        $tabs = [];

        foreach ($chunks as $chunk) {
            $slug = (string) $chunk->name;
            $tabTitle = $chunk->frontmatter['title'] ?? null;

            if (preg_match('/^[a-z0-9][a-z0-9-]{0,23}$/', $slug) !== 1) {
                throw new BuildError("var/docs.md: Tab „{$slug}“: Der Name muss aus a-z, 0-9 und Bindestrich bestehen (zum Beispiel <!-- next: lib -->).");
            }

            if (isset($tabs[$slug])) {
                throw new BuildError("var/docs.md: Der Tab „{$slug}“ kommt doppelt vor.");
            }

            if (!is_string($tabTitle) || trim($tabTitle) === '' || mb_strlen($tabTitle) > 30) {
                throw new BuildError("var/docs.md: Tab „{$slug}“ braucht einen „title“ bis 30 Zeichen.");
            }

            [$html, $sections] = $this->split($sanitizer->clean($chunk->html));
            $tabs[$slug] = ['slug' => $slug, 'title' => trim($tabTitle), 'html' => $html, 'sections' => $sections];
        }

        $tabs = $this->appendMissingAreas($tabs);

        return ['title' => trim($title), 'intro' => $sanitizer->clean($head->html), 'tabs' => array_values($tabs)];
    }

    /**
     * @return array{string, list<array{area: string|null, title: string, html: string}>}
     */
    private function split(string $html): array
    {
        $parts = preg_split('~(?=<h2\b)~', $html) ?: [$html];
        $first = (string) array_shift($parts);
        $sections = [];

        foreach ($parts as $part) {
            $match = [];

            if (preg_match('~^<h2[^>]*>(.*?)</h2>(.*)$~s', $part, $match) !== 1) {
                $sections[] = ['area' => null, 'title' => '', 'html' => $part];

                continue;
            }

            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $area = $this->areaFor($title);
            $sections[] = $area === null ? ['area' => null, 'title' => '', 'html' => $part] : ['area' => $area, 'title' => $title, 'html' => trim($match[2])];
        }

        return [$first, $sections];
    }

    private function areaFor(string $title): ?string
    {
        foreach (CodeDocs::AREAS as $area => [$areaTitle]) {
            if ($title === $area || $title === $areaTitle) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{slug: string, title: string, html: string, sections: list<array{area: string|null, title: string, html: string}>}> $tabs
     * @return array<string, array{slug: string, title: string, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
     */
    private function appendMissingAreas(array $tabs): array
    {
        $used = [];

        foreach ($tabs as $tab) {
            foreach ($tab['sections'] as $section) {
                if ($section['area'] !== null) {
                    $used[$section['area']] = true;
                }
            }
        }

        $missing = array_diff(array_keys(CodeDocs::AREAS), array_keys($used));

        if ($missing === []) {
            return $tabs;
        }

        $target = isset($tabs['lib']) ? 'lib' : array_key_last($tabs);

        if ($target === null) {
            throw new BuildError('var/docs.md: Es gibt keinen Tab für die Klassen aus lib/ (zum Beispiel <!-- next: lib -->).');
        }

        foreach ($missing as $area) {
            [$title, $intro] = CodeDocs::AREAS[$area];
            $tabs[$target]['sections'][] = ['area' => $area, 'title' => $title, 'html' => '<p>' . htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'];
        }

        return $tabs;
    }
}
