<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Highlight\Highlighter;
use Tempest\Markdown\Markdown;

/**
 * Liest `var/docs.md`, den handgeschriebenen Teil der Code-Übersicht.
 *
 * Die Datei ist ein Multi-Content-Markdown: Alles vor dem ersten `<!-- next: name -->` ist die Einleitung,
 * jedes weitere Dokument ein Tab mit `title` in der Frontmatter. Optional: `group` (höchstens 24 Zeichen) fasst
 * aufeinanderfolgende Tabs unter einer Beschriftung zusammen, `featured: true` hebt einen Tab hervor (höchstens einen). Eine `##`-Überschrift, die wie ein Ordner
 * unter `lib/` heißt (oder wie dessen Titel in `CodeDocs::AREAS`), bekommt später die Klassen dieses Ordners
 * darunter. Ordner, die in der Datei fehlen, hängt das Buch am Ende des Tabs `lib` automatisch an.
 *
 * Zahlen aus den Parsern setzt `{{SongParser::MAX_TRACKS}}` ein (mit `|kb` als Kilobyte), damit der Text nicht
 * vom Code abweicht. Code in Fenced Blocks wird gefärbt. Eine Zeile `<!-- code: var/resources/player.js#tone -->` holt einen
 * Ausschnitt live aus der Datei: Dort steht er zwischen `// docs: tone` und `// docs-end`. Ohne `#name`
 * kommt eine kleine Beispieldatei ganz (zum Beispiel aus `var/songs/`). Danach dürfen `lines` (Zeilennummern, bei Ausschnitten mit
 * den echten Zeilen der Datei) und `fold` (eingeklappt) stehen.
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
     *     tabs: list<array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
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
        $content = (string) preg_replace_callback(
            '/\{\{(\w+)::(\w+)(\|kb)?\}\}/',
            function (array $match): string {
                $name = "Chiptune\\Format\\{$match[1]}::{$match[2]}";

                if (!defined($name) || !is_int(constant($name))) {
                    throw new BuildError("var/docs.md: {$match[1]}::{$match[2]} ist keine Zahl-Konstante in lib/Format.");
                }

                $value = (int) constant($name);

                return (string) (($match[3] ?? '') === '|kb' ? intdiv($value, 1024) : $value);
            },
            $content,
        );

        $blocks = [];
        $content = (string) preg_replace_callback(
            '/^<!-- code: ([^\s#]+)(?:#([a-z0-9-]+))?((?: (?:lines|fold))*) -->[ \t]*$/m',
            function (array $match) use (&$blocks): string {
                [$language, $code, $first] = $this->snippet($match[1], $match[2] !== '' ? $match[2] : null);
                $highlighter = str_contains($match[3], 'lines') ? new Highlighter()->withGutter($first) : new Highlighter();
                $html = '<pre>' . $highlighter->parse($code, $language) . '</pre>';
                $count = substr_count($code, "\n") + 1;
                $blocks[] = str_contains($match[3], 'fold') ? '<details class="doc-fold"><summary>Code anzeigen (' . $count . ' Zeilen)</summary>' . $html . '</details>' : $html;

                return "*Aus {$match[1]}:*\n\nCODESNIPPET" . (count($blocks) - 1) . 'END';
            },
            $content,
        );

        foreach (new Markdown()->parseMany($content, 'docs', 'next') as $chunk) {
            $chunks[] = $chunk;
        }

        $fill = static function (string $html) use ($blocks): string {
            foreach ($blocks as $index => $block) {
                $html = str_replace("<p>CODESNIPPET{$index}END</p>", $block, $html);
            }

            return $html;
        };

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

            $group = $chunk->frontmatter['group'] ?? null;

            if ($group !== null && (!is_string($group) || trim($group) === '' || mb_strlen($group) > 24)) {
                throw new BuildError("var/docs.md: Tab „{$slug}“: „group“ muss ein Text bis 24 Zeichen sein.");
            }

            $featured = $chunk->frontmatter['featured'] ?? false;

            if (!is_bool($featured) && $featured !== 'true' && $featured !== 'false') {
                throw new BuildError("var/docs.md: Tab „{$slug}“: „featured“ muss true oder false sein.");
            }

            [$html, $sections] = $this->split($this->anchors($fill($sanitizer->clean($chunk->html)), $slug));
            $tabs[$slug] = [
                'slug' => $slug,
                'title' => trim($tabTitle),
                'group' => $group === null ? null : trim($group),
                'featured' => $featured === true || $featured === 'true',
                'html' => $html,
                'sections' => $sections,
            ];
        }

        $this->checkTabs($tabs);

        $tabs = $this->appendMissingAreas($tabs);

        return ['title' => trim($title), 'intro' => $fill($sanitizer->clean($head->html)), 'tabs' => array_values($tabs)];
    }

    /**
     * @return array{string, string, int}
     */
    private function snippet(string $file, ?string $name): array
    {
        $path = $this->root . '/' . $file;
        $parts = [];

        if (
            preg_match('~^(lib|bin|etc|var/resources|var/songs|var/pages)/[A-Za-z0-9_./-]+\.(php|js|json|sh|md)$~', $file, $parts) !== 1
            || str_contains($file, '..')
            || !is_file($path)
            || is_link($path)
        ) {
            throw new BuildError("var/docs.md: „{$file}“ ist keine Datei, aus der Ausschnitte geholt werden dürfen.");
        }

        $language = ['php' => 'php', 'js' => 'js', 'json' => 'json', 'sh' => 'bash', 'md' => 'markdown'][$parts[2]];
        $all = preg_split('/\R/', rtrim((string) file_get_contents($path))) ?: [];

        if ($name === null) {
            if (count($all) > 80) {
                throw new BuildError("var/docs.md: {$file} ist zu lang für ein ganzes Beispiel (mehr als 80 Zeilen). Besser einen Ausschnitt mit Marke nehmen.");
            }

            return [$language, implode("\n", $all), 1];
        }

        $lines = [];
        $inside = false;
        $found = false;
        $first = 1;

        foreach ($all as $number => $line) {
            if (preg_match('~^\s*//\s*docs:\s*' . preg_quote($name, '~') . '\s*$~', $line) === 1) {
                $inside = true;
                $found = true;
                $first = $number + 2;

                continue;
            }

            if ($inside && preg_match('~^\s*//\s*docs-end\s*$~', $line) === 1) {
                break;
            }

            if ($inside) {
                $lines[] = $line;
            }
        }

        if (!$found || $lines === []) {
            throw new BuildError("var/docs.md: In {$file} fehlt die Marke „// docs: {$name}“ mit einem Ausschnitt bis „// docs-end“.");
        }

        $indent = min(array_map(static fn(string $line): int => trim($line) === '' ? PHP_INT_MAX : strlen($line) - strlen(ltrim($line)), $lines));

        return [$language, rtrim(implode("\n", array_map(static fn(string $line): string => substr($line, min($indent, strlen($line))), $lines))), $first];
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

    private function anchors(string $html, string $tab): string
    {
        $seen = [];

        return (string) preg_replace_callback(
            '~<(h[23])>(.*?)</\1>~s',
            static function (array $match) use ($tab, &$seen): string {
                $text = html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = strtr(mb_strtolower($text), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
                $id = $tab . '-' . ($slug === '' ? 'abschnitt' : $slug);
                $seen[$id] = ($seen[$id] ?? 0) + 1;

                if ($seen[$id] > 1) {
                    $id .= '-' . $seen[$id];
                }

                return '<' . $match[1] . ' id="' . $id . '">' . $match[2] . '</' . $match[1] . '>';
            },
            $html,
        );
    }

    /**
     * @param array<string, array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}> $tabs
     */
    private function checkTabs(array $tabs): void
    {
        $featured = array_filter($tabs, static fn(array $tab): bool => $tab['featured']);

        if (count($featured) > 1) {
            throw new BuildError('var/docs.md: Nur ein Tab darf „featured“ sein.');
        }

        $seen = [];
        $last = null;

        foreach ($tabs as $tab) {
            $group = $tab['group'];

            if ($group !== null && $group !== $last && isset($seen[$group])) {
                throw new BuildError("var/docs.md: Die Gruppe „{$group}“ muss aus aufeinanderfolgenden Tabs bestehen.");
            }

            if ($group !== null) {
                $seen[$group] = true;
            }

            $last = $group;
        }
    }

    /**
     * @param array<string, array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}> $tabs
     * @return array<string, array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
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
