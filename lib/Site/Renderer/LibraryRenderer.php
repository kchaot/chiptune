<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Site\Entry;
use Chiptune\Site\EntryKind;

/**
 * Rendert die Bibliothek: die Startseite mit den Autoren und die Seite eines Autors mit Alben und Songs.
 */
final class LibraryRenderer extends PageRenderer
{
    /**
     * @param list<Entry> $entries
     * @param string|null $prefix
     */
    public function render(array $entries, ?string $prefix = null): string
    {
        if ($prefix === null) {
            $list = $this->authorList($entries);
            $heading = $this->e($this->site['title']);
            $lead = $this->e($this->site['tagline']);
            $title = $this->site['title'];
            $sections = '';
            $label = '';
            $command = 'ls .';
        } else {
            $list = $this->libraryList($entries, $prefix);
            $author = $this->authors[$prefix] ?? ['title' => $prefix, 'url' => ''];
            $heading = $this->e($author['title']);
            $name = $this->e((string) ($author['author'] ?? ''));
            $url = (string) ($author['url'] ?? '');
            $link = $url === '' ? '' : '<a href="' . $this->e($url) . '" rel="noopener noreferrer">' . $this->e((string) preg_replace('~^https?://~', '', $url)) . '</a>';
            $lead = $name !== '' && $link !== '' ? "{$name} | {$link}" : $name . $link;
            $title = $author['title'] . ' – ' . $this->site['title'];
            $tagline = $author['tagline'] ?? '';
            $label = $tagline === '' ? '' : '<p class="tagline">' . $this->e($tagline) . '</p>';
            $profile = $author['html'] ?? '';
            $sections = $profile === '' ? '' : "\n    <section class=\"profile aside\"><div class=\"notes\">{$profile}</div></section>";
            $command = 'ls .';
        }

        $lead = $lead === '' ? '' : "\n      <p class=\"lead\">{$lead}</p>";
        $head = $this->head($prefix, $command, $prefix !== null, false);
        $views = $prefix === null ? $this->pageViews() : '';

        return $this->page($title, 'library', <<<HTML
              <div class="home">
              {$head}
              <main>
                <div class="heading">
                  <h1>{$heading}</h1>{$label}{$lead}
                </div>{$sections}
                {$list}
              </main>
              </div>{$views}
            HTML);
    }

    private function libraryItem(Entry $entry, ?string $prefix): string
    {
        $href = $this->url($entry->prefix . '/' . $entry->slug . '/');
        $title = $this->e($entry->title());
        $artist = $this->artistLine($entry->artist(), $entry->prefix, 'span');
        $rel = '';
        $author = $prefix !== null
            ? ''
            : '<span class="author">von <a href="' . $this->url($entry->prefix . '/') . '">' . $this->e($this->authorTitle($entry->prefix)) . '</a></span>';
        $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', $entry->tags()));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        if ($entry->kind === EntryKind::Link) {
            $link = $entry->link();
            $media = $link->media;
            $info = $media->info;
            $own = $media->releaseOwn;
            $href = $own ? $href : $this->e((string) $media->release);
            $facts = $own
                ? '<span>hier abspielbar</span>'
                : '<span><b>' . $this->e($this->releaseLabel((string) $media->release)) . '</b> <span aria-hidden="true">↗</span></span>';
            $date = $this->dateText($info->date ?? '');
            $facts .= $date === '' ? '' : ' <span><b>' . $this->e($date) . '</b></span>';
            $thumbnail = '';
            $badge = '<span class="badge">Link</span> ';
            $links = $this->credits($link->credits);
            $from = $links === '' ? '' : '<span class="author">' . str_replace(['<p class="credits">Enthält Material aus ', '.</p>'], ['aus ', ''], $links) . '</span>';
            $author .= $from;
            $rel = $own ? '' : ' rel="noopener noreferrer"';
        } elseif ($entry->kind === EntryKind::Session) {
            $session = $entry->session();
            $facts = '<span>A: <b>' . $this->e($session->a->title) . '</b></span> <span>B: <b>' . $this->e($session->b->title) . '</b></span>';
            $thumbnail = '';
            $badge = '<span class="badge">Session</span> ';
        } elseif (in_array($entry->kind, [EntryKind::Set, EntryKind::Album], true)) {
            $set = $entry->set();
            $isAlbum = $entry->kind === EntryKind::Album;
            $parts = count($set->parts);
            $length = $this->duration($set->duration);
            $noun = $isAlbum ? ($parts === 1 ? 'Song' : 'Songs') : ($parts === 1 ? 'Part' : 'Parts');
            $facts = "<span><b>{$parts}</b> {$noun}</span> <span><b>{$length}</b> Länge</span>";
            $thumbnail = $this->setThumbnail($set);
            $badge = '<span class="badge">' . ($isAlbum ? 'Album' : 'Set') . '</span> ';
        } else {
            $song = $entry->song();
            $bpm = $this->number($song->bpm);
            $bars = $this->number($song->bars);
            $tracks = count($song->tracks);
            $length = $this->duration($song->steps * $song->stepSeconds);
            $facts = "<span><b>{$bpm}</b> BPM</span> <span><b>{$bars}</b> Bars</span> <span><b>{$tracks}</b> Tracks</span> <span><b>{$length}</b> Länge</span>";
            $thumbnail = $this->thumbnail($song->tracks, $song->steps);
            $badge = $this->songBadge($song);
            $facts .= $this->mediaMarks($song);
        }

        $noThumb = $thumbnail === '' ? ' no-thumb' : '';

        return <<<HTML

                  <li class="project{$noThumb}">
                    <div class="title">
                      <h2>{$badge}<a href="{$href}"{$rel}>{$title}</a></h2>
                      {$artist}{$author}
                    </div>
                    {$thumbnail}
                    <p class="facts">{$facts}</p>
                    {$tags}
                  </li>
            HTML;
    }

    /**
     * @param list<Entry> $entries
     */
    private function libraryList(array $entries, ?string $prefix): string
    {
        if ($entries === []) {
            return '<p class="empty">Noch keine Projekte.<br> Lege eine Markdown-Datei in einer Quelle (etc/sources.json) an und baue die Seite neu.</p>';
        }

        $albums = [];
        $groups = [];
        $singles = [];

        foreach ($entries as $entry) {
            $group = $entry->group();

            if ($entry->kind === EntryKind::Album) {
                $albums[] = $entry;
            } elseif ($group !== '') {
                $groups[mb_strtolower($group)][] = $entry;
            } else {
                $singles[] = $entry;
            }
        }

        $items = fn(array $list): string => (
            '<ul class="projects">' . implode('', array_map(fn(Entry $entry): string => $this->libraryItem($entry, $prefix), $list)) . "\n    </ul>"
        );
        $html = $albums === [] ? '' : $items($albums);

        foreach ($groups as $members) {
            $name = $this->e($members[0]->group());
            $count = count($members);
            $noun = $count === 1 ? 'Song' : 'Songs';
            $html .=
                "\n    <details class=\"group\">\n      <summary><span class=\"group-name\">{$name}</span> <span class=\"group-count\">{$count} {$noun}</span></summary>\n    "
                . $items($members)
                . "\n    </details>";
        }

        return $html . ($singles === [] ? '' : ($groups === [] ? '' : "\n    <h2 class=\"group-rest\">Weitere Songs</h2>") . $items($singles));
    }

    /**
     * @param list<Entry> $entries
     */
    private function authorList(array $entries): string
    {
        if ($this->authors === []) {
            return '<p class="empty">Noch keine Projekte.<br> Lege eine Markdown-Datei in einer Quelle (etc/sources.json) an und baue die Seite neu.</p>';
        }

        $items = '';

        foreach ($this->authors as $prefix => $author) {
            $prefix = (string) $prefix;
            $own = array_filter($entries, fn(Entry $entry): bool => $entry->prefix === $prefix);
            $sets = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Set));
            $albums = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Album));
            $sessions = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Session));
            $links = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Link));
            $songs = count($own) - $sets - $albums - $sessions - $links;
            $withHq = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Song && $entry->song()->media->hq !== null));
            $withRelease = count(array_filter($own, fn(Entry $entry): bool => $entry->kind === EntryKind::Song && $entry->song()->media->release !== null));
            $facts = '<span><b>' . $songs . '</b> ' . ($songs === 1 ? 'Song' : 'Songs') . '</span>';

            if ($albums > 0) {
                $facts .= ' <span><b>' . $albums . '</b> ' . ($albums === 1 ? 'Album' : 'Alben') . '</span>';
            }

            if ($sets > 0) {
                $facts .= ' <span><b>' . $sets . '</b> ' . ($sets === 1 ? 'Set' : 'Sets') . '</span>';
            }

            if ($sessions > 0) {
                $facts .= ' <span><b>' . $sessions . '</b> ' . ($sessions === 1 ? 'Session' : 'Sessions') . '</span>';
            }

            if ($links > 0) {
                $facts .= ' <span><b>' . $links . '</b> ' . ($links === 1 ? 'Link' : 'Links') . '</span>';
            }

            $count = [];

            foreach ($own as $entry) {
                foreach ($entry->tags() as $tag) {
                    $count[$tag] = ($count[$tag] ?? 0) + 1;
                }
            }

            arsort($count);
            $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', array_slice(array_map(strval(...), array_keys($count)), 0, 6)));
            $chips =
                ($withHq > 0 ? '<li class="chip" title="Projekte mit HQ-Version"><b>' . $withHq . '</b> HQ</li>' : '')
                . ($withRelease > 0 ? '<li class="chip" title="Projekte mit Veröffentlichung"><b>' . $withRelease . '</b> VÖ</li>' : '');
            $tags = $chips . $tags;
            $tags = '<ul class="tags">' . $tags . '</ul>';
            $taglineText = $author['tagline'] ?? '';
            $tagline = $taglineText === '' ? '' : '<p class="tagline">' . $this->e($taglineText) . '</p>';
            $href = $this->url($prefix . '/');
            $title = $this->e($author['title']);

            $items .= <<<HTML

                      <li class="author-card">
                        <h2><a href="{$href}">{$title}</a></h2>
                        {$tagline}
                        <p class="facts">{$facts}</p>
                        {$tags}
                      </li>
                HTML;
        }

        return '<ul class="authors">' . $items . "\n    </ul>";
    }

    /**
     * @param array{slug: string, title: string, html: string, tabs: list<array{slug: string, title: string, html: string}>} $page
     */
    private function pageTabs(array $page): string
    {
        if ($page['tabs'] === []) {
            return '';
        }

        $html = '';

        foreach ($page['tabs'] as $index => $tab) {
            $bar = '';

            foreach ($page['tabs'] as $other) {
                $bar .= sprintf(
                    '<a href="%s"%s>%s</a>',
                    $this->url('#' . $page['slug'] . '--' . $other['slug']),
                    $other['slug'] === $tab['slug'] ? ' class="current" aria-current="page"' : '',
                    $this->e($other['title']),
                );
            }

            $html .= sprintf(
                "\n      <section class=\"tab%s\" id=\"%s\"><nav class=\"tabs\" aria-label=\"%s\">%s</nav><div class=\"notes\">%s</div></section>",
                $index === 0 ? ' first' : '',
                $this->e($page['slug'] . '--' . $tab['slug']),
                $this->e($page['title']),
                $bar,
                $tab['html'],
            );
        }

        return '<div class="tab-panels">' . $html . "\n    </div>";
    }

    private function pageViews(): string
    {
        $html = '';

        foreach ($this->pages as $page) {
            $head = $this->head(null, 'cat ' . $page['slug'] . '.md');
            $html .= sprintf(
                "\n  <div class=\"page%s\" id=\"%s\">\n  %s\n  <main>\n    <div class=\"heading\"><h1>%s</h1></div>\n    <section class=\"panel\"><div class=\"panel-inner\"><div class=\"notes\">%s</div>%s</div></section>\n  </main>\n  </div>",
                $page['tabs'] === [] ? '' : ' has-tabs',
                $this->e($page['slug']),
                $head,
                $this->e($page['title']),
                $page['html'],
                $this->pageTabs($page),
            );
        }

        return $html;
    }
}
