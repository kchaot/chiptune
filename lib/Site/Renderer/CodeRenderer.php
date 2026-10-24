<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Common\BuildError;
use Chiptune\Site\CodeBook;
use Chiptune\Site\CodeBook\CodeDocs;
use Chiptune\Site\CodeBook\DocEntry;

/**
 * Rendert die Code-Seite aus dem `CodeBook` und den Klassenbeschreibungen von `CodeDocs`.
 *
 * Tabs mit Gruppen, eingeklappte Klassenlisten und Querverweise, die der Bau auf tote Ziele prüft.
 */
final class CodeRenderer extends PageRenderer
{
    /**
     * @param array{
     *     title: string,
     *     intro: string,
     *     tabs: list<array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
     * } $book
     * @param array<string, list<DocEntry>> $areas
     */
    public function render(array $book, array $areas): string
    {
        $head = $this->head(null, 'cat docs.md');
        $title = $this->e($book['title']);
        $tabs = '';

        foreach ($book['tabs'] as $index => $tab) {
            $body = $tab['html'];

            foreach ($tab['sections'] as $section) {
                $body .= $section['area'] === null ? $section['html'] : $this->docArea($section['title'], $section['html'], $areas[$section['area']] ?? []);
            }

            $tabs .= sprintf(
                "\n    <section class=\"code-tab%s\" id=\"tab-%s\"><nav class=\"tabs\" aria-label=\"Bereiche\">%s</nav><div class=\"doc-text\">%s</div></section>",
                $index === 0 ? ' first' : '',
                $this->e($tab['slug']),
                $this->codeTabBar($book['tabs'], $tab['slug']),
                $body,
            );
        }

        $this->checkCodeLinks($tabs);

        return $this->page($book['title'] . ' – ' . $this->site['title'], 'library code-page', <<<HTML
              {$head}
              <main>
                <div class="heading">
                  <h1>{$title}</h1>
                </div>
                <div class="doc-intro">{$book['intro']}</div>
                <div class="code-tabs">{$tabs}
                </div>
              </main>
              <script>
                function openTarget() {
                  const target = document.getElementById(location.hash.slice(1));
                  for (let node = target; node; node = node.parentElement) {
                    if (node.tagName === 'DETAILS') node.open = true;
                  }
                  if (target) target.scrollIntoView();
                }
                addEventListener('hashchange', openTarget);
                openTarget();
                document.querySelectorAll('.doc-classes').forEach((group) => {
                  group.addEventListener('toggle', () => {
                    const summary = group.querySelector('summary');
                    if (!group.open && summary.getBoundingClientRect().top < 0) summary.scrollIntoView({ block: 'center' });
                  });
                });
              </script>
            HTML, false, false, false);
    }

    /**
     * @param list<array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}> $tabs
     */
    private function codeTabBar(array $tabs, string $current): string
    {
        $bar = '';
        $open = null;

        foreach ($tabs as $tab) {
            if ($tab['group'] !== $open) {
                $bar .= $open === null ? '' : '</span>';
                $bar .= $tab['group'] === null ? '' : '<span class="tab-group"><span class="tab-group-label">' . $this->e($tab['group']) . '</span>';
                $open = $tab['group'];
            }

            $classes = trim(($tab['featured'] ? 'featured ' : '') . ($tab['slug'] === $current ? 'current' : ''));
            $bar .= sprintf(
                '<a href="#tab-%s"%s%s>%s</a>',
                $this->e($tab['slug']),
                $classes === '' ? '' : ' class="' . $classes . '"',
                $tab['slug'] === $current ? ' aria-current="page"' : '',
                $this->e($tab['title']),
            );
        }

        return $bar . ($open === null ? '' : '</span>');
    }

    private function checkCodeLinks(string $html): void
    {
        $ids = [];
        $links = [];
        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        preg_match_all('/\shref="#([^"]+)"/', $html, $links);

        $missing = array_diff(array_unique($links[1]), $ids[1]);

        if ($missing !== []) {
            throw new BuildError('var/docs.md: Verweis ins Leere: #' . implode(', #', $missing));
        }
    }

    /**
     * @param list<DocEntry> $entries
     */
    private function docArea(string $title, string $prose, array $entries): string
    {
        $groups = [];

        foreach ($entries as $entry) {
            $parts = explode('\\', $entry->name);
            $groups[$parts[2] ?? $entry->short][] = [$entry, count($parts) - 3];
        }

        $links = '';
        $articles = '';

        foreach ($groups as $members) {
            $rootLink = '';
            $rootHtml = '';
            $helperLinks = '';
            $helperHtml = '';
            $folder = '';
            $helperCount = 0;

            foreach ($members as [$entry, $depth]) {
                $id = $this->e(strtolower($entry->short));

                if ($depth === 0) {
                    $parts = explode('\\', $entry->name);
                    $folder = $parts[1] . '/' . $parts[2] . '/';
                    $rootLink = '<a href="#' . $id . '">' . $this->e($entry->short) . '</a> <span>' . $this->docText($entry->summary()) . '</span>';
                    $rootHtml = $this->docEntry($entry, $id, false);
                } else {
                    $helperCount++;
                    $helperLinks .= '<li><a href="#' . $id . '" title="' . $this->e($entry->summary()) . '">' . $this->e($entry->short) . '</a></li>';
                    $helperHtml .= $this->docEntry($entry, $id, true);
                }
            }

            $links .= '<li class="doc-root">' . $rootLink;
            $articles .= '<div class="doc-group">' . $rootHtml;

            if ($helperCount > 0) {
                $links .= '<ul class="doc-helpers" aria-label="' . $this->e($folder) . '">' . $helperLinks . '</ul>';
                $articles .= '<p class="doc-folder"><code>' . $this->e($folder) . '</code> ' . $helperCount . ' Helfer</p>' . $helperHtml;
            }

            $links .= '</li>';
            $articles .= '</div>';
        }

        $count = count($entries);
        $label = $count . ($count === 1 ? ' Klasse' : ' Klassen') . ' anzeigen';

        return (
            '<section class="doc-area"><h2>'
            . $this->e($title)
            . '</h2>'
            . $prose
            . '<details class="doc-classes"><summary>'
            . $label
            . '</summary><ul class="doc-index">'
            . $links
            . '</ul>'
            . $articles
            . '</details></section>'
        );
    }

    private function docEntry(DocEntry $entry, string $id, bool $helper = false): string
    {
        $html =
            '<article class="'
            . ($helper ? 'doc doc-helper' : 'doc')
            . '" id="'
            . $id
            . '"><h3>'
            . $this->e($entry->short)
            . '</h3><p class="doc-name">'
            . $this->e($entry->kind)
            . ' <code>'
            . $this->e($entry->name)
            . '</code></p>';
        $html .= $entry->text === '' ? '<p><em>Noch keine Beschreibung.</em></p>' : $this->docText($entry->text, true);
        $html .= $this->docTable('Felder', ['Name', 'Typ'], array_map(static fn(array $field): array => [$field['name'], $field['type']], $entry->fields));
        $html .= $entry->cases === [] ? '' : '<p class="doc-cases"><b>Fälle</b> <code>' . implode('</code> <code>', array_map($this->e(...), $entry->cases)) . '</code></p>';
        $html .= $this->docTable('Konstanten', ['Name', 'Wert'], array_map(static fn(array $constant): array => [
            $constant['name'],
            $constant['value'],
        ], $entry->constants));
        $html .= $entry->methods === []
            ? ''
            : '<p class="doc-table"><b>Methoden</b></p><ul class="doc-methods">'
            . implode('', array_map(fn(string $method): string => '<li><code>' . $this->e($method) . '</code></li>', $entry->methods))
            . '</ul>';

        return $html . '</article>';
    }

    private function docText(string $text, bool $paragraphs = false): string
    {
        $inline = fn(string $part): string => (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $this->e(trim((string) preg_replace('/\s+/', ' ', $part))));

        if (!$paragraphs) {
            return $inline($text);
        }

        return implode('', array_map(
            fn(string $part): string => '<p>' . $inline($part) . '</p>',
            array_filter(explode("\n\n", $text), static fn(string $part): bool => trim($part) !== ''),
        ));
    }

    /**
     * @param list<string> $columns
     * @param list<array{string, string}> $rows
     */
    private function docTable(string $title, array $columns, array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $head = implode('', array_map(fn(string $column): string => '<th>' . $this->e($column) . '</th>', $columns));
        $body = implode('', array_map(fn(array $row): string => '<tr><td><code>' . $this->e($row[0]) . '</code></td><td><code>' . $this->e($row[1]) . '</code></td></tr>', $rows));

        return '<p class="doc-table"><b>' . $this->e($title) . '</b></p><table class="doc-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }
}
