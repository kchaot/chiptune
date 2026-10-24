<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Format\Session;
use Chiptune\Format\Song;
use Chiptune\Site\Entry;

/**
 * Rendert den Mixer und die Seiten der Sessions.
 *
 * Eine Session lädt zwei Songs in die Decks des Mixers, `mixId()` gibt jedem Song seine Adresse dort.
 */
final class MixRenderer extends PageRenderer
{
    /**
     * @param list<array{id: string, title: string, artist: string, source: string, sourceId: string, album: string, albumId: string, tags: list<string>, bpm: float|int, bars: float|int, group: string, groupId: string, href: string, data: string}> $catalog
     */
    public function mix(array $catalog): string
    {
        $payload = json_encode(['catalog' => $catalog], self::JSON_FLAGS);
        $head = $this->head(null, 'mix');
        $title = 'Mixer';

        return $this->page($title . ' – ' . $this->site['title'], 'project-page mix-page', <<<HTML
              <script type="application/json" id="mix">{$payload}</script>
              {$head}
              <main>
                <div class="heading">
                  <h1>Mixer</h1>
                </div>
                <div class="daw mixer-page" id="daw">
                  <noscript><p class="empty">Der Mix braucht JavaScript.</p></noscript>
                </div>
              </main>
            HTML, true, false);
    }

    public function session(Entry $entry): string
    {
        $session = $entry->session();
        $title = $this->e($session->title);
        $artist = $this->artistLine($session->artist, $entry->prefix, 'p');
        $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', $session->tags));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $session->notesHtml === '' ? '' : '<section class="aside wide"><div class="notes">' . $session->notesHtml . '</div></section>';
        $head = $this->head($entry->prefix . ($entry->album !== null ? '/' . $entry->album : ''), 'mix ' . basename($entry->slug));
        $href = $this->mixLink($session, $session->title);
        $rows = '';

        foreach (['a' => 'Deck A', 'b' => 'Deck B'] as $key => $label) {
            $deck = $key === 'a' ? $session->a : $session->b;
            $bpm = $this->number($deck->bpm);
            $bars = $this->number($deck->bars);
            $detail = $key === 'b' && $session->start > 1 ? "steigt auf Takt <b>{$session->start}</b> von Deck A ein" : 'startet auf Takt <b>1</b>';
            $rows .= sprintf(
                "\n      <li><span class=\"deck-label\">%s</span> <a href=\"%s\">%s</a> <span class=\"layer-facts\">%s BPM, %s Bars, %s</span></li>",
                $label,
                $this->url($deck->prefix . '/' . $deck->slug . '/'),
                $this->e($deck->title),
                $bpm,
                $bars,
                $detail,
            );
        }

        $auto = $session->auto ? 'Auto-Sync ist an: Deck B folgt dem Tempo von Deck A.' : 'Auto-Sync ist aus.';

        return $this->page($session->title . ' – ' . $this->site['title'], 'project-page session-page', <<<HTML
              {$head}
              <main>
                <div class="heading">
                  <h1><span class="badge">Session</span> {$title}</h1>
                  {$artist}
                  {$tags}
                </div>
                <section class="session">
                  <ul class="session-decks">{$rows}
                  </ul>
                  <p class="session-hint">{$auto} Im Mixer startest du die Session mit einem Klick.</p>
                  <p><a class="play session-open" href="{$href}">Im Mixer öffnen</a></p>
                </section>
                {$notes}
                <p class="source"><a href="source.md">Quelltext dieser Session</a></p>
              </main>
            HTML);
    }

    private function mixLink(Session $session, string $title): string
    {
        $query = http_build_query(
            [
                'a' => self::mixId($session->a->prefix, $session->a->slug),
                'b' => self::mixId($session->b->prefix, $session->b->slug),
                'start' => $session->start,
                'auto' => $session->auto ? 1 : 0,
                'fader' => $session->fader,
                'name' => $title,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return $this->url('+mixer/') . '#' . $this->e($query);
    }

    public static function mixId(string $prefix, string $slug): string
    {
        return $prefix . '--' . str_replace('/', '--', $slug);
    }
}
