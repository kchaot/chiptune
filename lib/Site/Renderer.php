<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Format\Media;
use Chiptune\Format\Session;
use Chiptune\Format\Set;
use Chiptune\Format\SetPart;
use Chiptune\Format\Song;
use Chiptune\Format\Track;

/**
 * Erzeugt alle HTML-Seiten und die JSON-Daten für den Player.
 *
 * Dazu gehören Bibliothek, Song, Set, Session, Link, Mixer, Code-Seite und die Fehlerseite. Der Renderer
 * bekommt fertige Value Objects und kennt das Markdown nicht mehr. Bibliothek und Code-Seite rendern
 * `LibraryRenderer` und `CodeRenderer`, das Gemeinsame steckt in `PageRenderer`.
 */
final class Renderer extends PageRenderer
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /**
     * @param list<Entry> $entries
     */
    public function library(array $entries, ?string $prefix = null): string
    {
        return new LibraryRenderer($this->site, $this->assets, $this->authors, $this->pages)->render($entries, $prefix);
    }

    /**
     * @param array{
     *     title: string,
     *     intro: string,
     *     tabs: list<array{slug: string, title: string, group: string|null, featured: bool, html: string, sections: list<array{area: string|null, title: string, html: string}>}>
     * } $book
     * @param array<string, list<DocEntry>> $areas
     */
    public function code(array $book, array $areas): string
    {
        return new CodeRenderer($this->site, $this->assets, $this->authors, $this->pages)->render($book, $areas);
    }

    private function layersPanel(Song $song): string
    {
        if ($song->layers === []) {
            return '';
        }

        $descriptions = [];

        foreach ($song->tracks as $track) {
            if ($track->group !== null && !isset($descriptions[$track->group->ref])) {
                $descriptions[$track->group->ref] = $track->group->description;
            }
        }

        $items = '';

        foreach ($song->layers as $index => $layer) {
            $href = $this->url($layer->ref . '/');
            $original = abs($layer->bpm - $song->bpm) < 0.05 ? $this->number($layer->bpm) . ' BPM' : $this->number($layer->bpm) . ' -> ' . $this->number($song->bpm) . ' BPM';
            $text = $descriptions[$layer->ref] ?? '';
            $items .= sprintf(
                "\n      <li class=\"layer t%d\"><p><a href=\"%s\">%s</a> <span class=\"layer-facts\">steigt ein bei Bar %d, %s</span></p>%s</li>",
                $index % 8,
                $href,
                $this->e($layer->title),
                $layer->at,
                $original,
                $text === '' ? '' : '<div class="notes">' . $text . '</div>',
            );
        }

        return '<section class="layers"><h2 class="sub">Layer</h2><ol>' . $items . "\n    </ol></section>";
    }

    public static function strudelStand(Song $song): string
    {
        $data = [
            $song->bpm,
            $song->stepsPerBeat,
            $song->steps,
            array_map(fn(Track $track): array => [$track->name, $track->instrument, $track->volume, $track->gate, $track->events], $song->tracks),
        ];

        return substr(sha1(json_encode($data, JSON_THROW_ON_ERROR)), 0, 8);
    }

    /**
     * @return array<string, mixed>
     */
    public function songPayload(Song $song): array
    {
        return [
            'title' => $song->title,
            'artist' => $song->artist,
            'bpm' => $song->bpm,
            'stand' => self::strudelStand($song),
            'stepsPerBeat' => $song->stepsPerBeat,
            'stepsPerBar' => $song->stepsPerBar,
            'stepSeconds' => $song->stepSeconds,
            'loop' => $song->loop,
            'steps' => $song->steps,
            'media' => (object) ($song->media->toArray() + (isset($song->media->release) ? ['releaseLabel' => $this->releaseLabel($song->media->release)] : [])),
            'tracks' => array_map(function (Track $track): array {
                $data = [
                    'name' => $track->name,
                    'instrument' => $track->instrument,
                    'volume' => $track->volume,
                    'gate' => $track->gate,
                    'pw' => $track->pw,
                    'steps' => $track->steps,
                    'events' => $track->events,
                    'refs' => array_map(fn(array $ref): array => $ref + ['href' => $this->url($ref['ref'] . '/')], $track->refs),
                ];

                if ($track->group !== null) {
                    $data['group'] = [
                        'title' => $track->group->title,
                        'href' => $this->url($track->group->ref . '/'),
                        'bpm' => $track->group->bpm,
                    ];
                }

                return $data + ['notes' => $track->notesHtml !== ''];
            }, $song->tracks),
        ];
    }

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

    public function song(Entry $entry): string
    {
        $song = $entry->song();
        $title = $this->e($song->title);
        $artist = $this->artistLine($song->artist, $entry->prefix, 'p');
        $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', $song->tags));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $song->notesHtml === '' ? '' : '<section class="aside wide"><div class="notes">' . $song->notesHtml . '</div></section>';
        $head = $this->head($entry->prefix . ($entry->album !== null ? '/' . $entry->album : ''), 'play ' . basename($entry->slug));
        $credits = $this->credits($song->credits);
        $albumNav = $this->albumNav($entry->nav, $entry->prefix);
        $thumbnail = $this->thumbnail($song->tracks, $song->steps);

        $payload = json_encode($this->songPayload($song) + ($entry->strudel !== null ? ['strudel' => $entry->strudel] : []), self::JSON_FLAGS);

        $trackNotes = $this->trackNotes($song->tracks);
        $layersPanel = $this->layersPanel($song);
        $badge = $this->songBadge($song);
        $mediaLinks = $this->mediaLinks($song->media);

        return $this->page($song->title . ' – ' . $this->site['title'], 'project-page', <<<HTML
              <script type="application/json" id="project">{$payload}</script>
              {$head}
              <main>
                <div class="heading">
                  <h1>{$badge}{$title}</h1>
                  {$artist}
                  {$tags}
                </div>
                <div class="daw" id="daw">
                  <noscript>
                    {$thumbnail}
                    <p>Zum Abspielen braucht diese Seite JavaScript.</p>
                    {$mediaLinks}
                  </noscript>
                </div>
                {$albumNav}
                {$layersPanel}
                {$trackNotes}
                {$notes}
                {$credits}
                <p class="source"><a href="source.md">Quelltext dieses Songs</a></p>
              </main>
            HTML, withScript: true);
    }

    private function mediaLinks(Media $media): string
    {
        $items = '';

        if ($media->hq !== null) {
            $items .= '<li><a href="' . $this->e($media->hq) . '" rel="noopener noreferrer">HQ-Version (Audio)</a></li>';
        }

        if ($media->release !== null) {
            $own = $media->releaseOwn;
            $class = $own ? ' class="own"' : ($this->releaseService($media->release) === 'SoundCloud' ? ' class="soundcloud"' : '');
            $items .=
                '<li><a'
                . $class
                . ' href="'
                . $this->e($media->release)
                . '" rel="noopener noreferrer">'
                . $this->e($this->releaseLabel($media->release))
                . ($own ? ' (Audio)' : '')
                . '</a></li>';
        }

        return $items === '' ? '' : '<ul class="media-links">' . $items . '</ul>';
    }

    /**
     * @param array{album: array{slug: string, title: string}, number: int, total: int, prev: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}|null $nav
     */
    private function albumNav(?array $nav, string $prefix): string
    {
        if ($nav === null) {
            return '';
        }

        return (
            '<nav class="album-nav" aria-label="Album">'
            . $this->albumNavLink($nav['prev'], 'prev', '←', $prefix)
            . '<a class="where" href="'
            . $this->url($prefix . '/' . $nav['album']['slug'] . '/')
            . '">'
            . $this->e($nav['album']['title'])
            . ' | '
            . $nav['number']
            . ' von '
            . $nav['total']
            . '</a>'
            . $this->albumNavLink($nav['next'], 'next', '→', $prefix)
            . '</nav>'
        );
    }

    /**
     * @param array{slug: string, title: string}|null $target
     */
    private function albumNavLink(?array $target, string $class, string $label, string $prefix): string
    {
        if ($target === null) {
            return '<span class="' . $class . ' off"></span>';
        }

        return (
            '<a class="'
            . $class
            . '" href="'
            . $this->url($prefix . '/' . $target['slug'] . '/')
            . '" rel="'
            . ($class === 'prev' ? 'prev' : 'next')
            . '">'
            . $label
            . ' <b>'
            . $this->e($target['title'])
            . '</b></a>'
        );
    }

    public static function mixId(string $prefix, string $slug): string
    {
        return $prefix . '--' . str_replace('/', '--', $slug);
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

    public function link(Entry $entry): string
    {
        $release = $entry->link();
        $media = $release->media;
        $info = $media->info;
        $url = (string) $media->release;
        $own = $media->releaseOwn;
        $heading = $info->title ?? $release->title;
        $title = $this->e($heading);
        $artist = $this->artistLine($release->artist, $entry->prefix, 'p');
        $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', $release->tags));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $release->notesHtml === '' ? '' : '<section class="aside wide"><div class="notes">' . $release->notesHtml . '</div></section>';
        $head = $this->head($entry->prefix . ($entry->album !== null ? '/' . $entry->album : ''), 'link ' . basename($entry->slug));
        $facts = array_filter([$this->dateText($info->date ?? ''), $info->catalog ?? '', $info->label ?? '', $info->license ?? '']);
        $factLine = $facts === [] ? '' : '<p class="release-facts">' . $this->e(implode(' | ', $facts)) . '</p>';
        $credits = '';

        foreach ($info->credits as $role => $names) {
            $credits .= '<dt>' . $this->e((string) $role) . '</dt><dd>' . $this->e(implode(', ', (array) $names)) . '</dd>';
        }

        $credits = $credits === '' ? '' : '<dl class="release-credits">' . $credits . '</dl>';
        $cover = $media->cover !== null
            ? '<div class="rel-cover"><img src="' . $this->e($media->cover) . '" alt="Cover" width="400" height="400" decoding="async" referrerpolicy="no-referrer"></div>'
            : '';
        $service = $this->releaseLabel($url);

        if ($own) {
            $action =
                '<audio controls preload="none" src="'
                . $this->e($url)
                . '">Dein Browser kann das Audio nicht abspielen. <a href="'
                . $this->e($url)
                . '" rel="noopener noreferrer">Datei öffnen</a></audio>';
        } else {
            $action = '<a class="play" href="' . $this->e($url) . '" rel="noopener noreferrer">Auf ' . $this->e($service) . ' hören</a>';
        }

        $coverClass = $cover === '' ? '' : ' has-cover';
        $from = $this->credits($release->credits);
        $from = $from === '' ? '' : str_replace('Enthält Material aus', 'Entstanden aus', $from);

        return $this->page($heading . ' – ' . $this->site['title'], 'project-page release-page', <<<HTML
              {$head}
              <main>
                <div class="heading">
                  <h1><span class="badge">Link</span> {$title}</h1>
                  {$artist}
                  {$tags}
                </div>
                <section class="rel-page{$coverClass}">
                  {$cover}
                  <div class="rel-body">
                    {$factLine}
                    {$credits}
                    <div class="rel-action">{$action}</div>
                  </div>
                </section>
                {$from}
                {$notes}
                <p class="source"><a href="source.md">Quelltext dieses Links</a></p>
              </main>
            HTML, $own);
    }

    public function set(Entry $entry): string
    {
        $set = $entry->set();
        $isAlbum = $entry->kind === EntryKind::Album;
        $title = $this->e($set->title);
        $artist = $this->artistLine($set->artist, $entry->prefix, 'p');
        $tags = implode('', array_map(fn(string $tag): string => '<li>' . $this->e($tag) . '</li>', $set->tags));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $set->notesHtml === '' ? '' : '<section class="aside' . ($isAlbum ? '' : ' wide') . '"><div class="notes">' . $set->notesHtml . '</div></section>';
        $head = $isAlbum ? $this->head($entry->prefix, 'ls ' . $entry->slug) : $this->head($entry->prefix, 'play ' . basename($entry->slug));
        $badge = $isAlbum ? 'Album' : 'Set';
        $source = $isAlbum ? '' : '<p class="source"><a href="source.md">Quelltext dieses Sets</a></p>';
        $introNotes = $isAlbum ? $notes : '';
        $closingNotes = $isAlbum ? '' : $notes;

        $sessionList = '';

        foreach ($entry->sessions as $session) {
            $sessionList .= sprintf(
                "\n      <li><a href=\"%s\">%s</a> <span class=\"layer-facts\">A: %s, B: %s</span></li>",
                $this->url($session->prefix . '/' . $session->slug . '/'),
                $this->e($session->title()),
                $this->e($session->session()->a->title),
                $this->e($session->session()->b->title),
            );
        }

        $sessions = $sessionList === '' ? '' : '<section class="album-sessions"><h2 class="sub">Sessions</h2><ul>' . $sessionList . "\n    </ul></section>";

        $linkList = '';

        foreach ($entry->links as $audio) {
            $own = $audio->link()->media->releaseOwn;
            $linkList .= sprintf(
                "\n      <li><a href=\"%s\"%s>%s</a> <span class=\"layer-facts\">%s</span></li>",
                $own ? $this->url($audio->prefix . '/' . $audio->slug . '/') : $this->e((string) $audio->link()->media->release),
                $own ? '' : ' rel="noopener noreferrer"',
                $this->e($audio->link()->title),
                $this->e($own ? $audio->artist() : $audio->artist() . ' | ' . $this->releaseLabel((string) $audio->link()->media->release) . ' ↗'),
            );
        }

        $albumLinks = $linkList === '' ? '' : '<section class="album-sessions"><h2 class="sub">Links</h2><ul>' . $linkList . "\n    </ul></section>";

        $payload = json_encode(
            [
                'title' => $set->title,
                'album' => $entry->kind === EntryKind::Album,
                'loop' => $set->loop,
                'parts' => array_map(fn(SetPart $part): array => [
                    'title' => $part->title,
                    'bpm' => $part->bpm,
                    'stepSeconds' => $part->stepSeconds,
                    'stepsPerBar' => $part->stepsPerBar,
                    'stepsPerBeat' => $part->stepsPerBeat,
                    'start' => $part->start,
                    'stop' => $part->stop,
                    'startBar' => $part->startBar,
                    'repeat' => $part->repeat,
                    'tracks' => array_map(fn(Track $track): array => [
                        'instrument' => $track->instrument,
                        'volume' => $track->volume,
                        'gate' => $track->gate,
                        'pw' => $track->pw,
                        'events' => $track->events,
                    ], $part->tracks),
                ], $set->parts),
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        $items = '';

        foreach ($set->parts as $index => $part) {
            $number = $index + 1;
            $href = $this->url($part->prefix . '/' . $part->slug . '/');
            $partTitle = $this->e($part->title);
            $by = $this->e($this->authorTitle($part->prefix));
            $bpm = $this->number($part->bpm);
            $from = $part->startBar;
            $to = $from + intdiv($part->stop - $part->start, $part->stepsPerBar) - 1;
            $bars = $from === $to ? "Bar {$from}" : "Bars {$from}–{$to}";
            $repeat = $part->repeat > 1 ? ' <span>× <b>' . $part->repeat . '</b></span>' : '';
            $length = $this->duration(($part->stop - $part->start) * $part->stepSeconds * $part->repeat);
            $thumb = $this->thumbnail($part->tracks, $part->steps, [$part->start, $part->stop]);
            $text = $part->notesHtml === '' ? '' : '<div class="notes inner">' . $part->notesHtml . '</div>';

            $items .= <<<HTML

                      <li class="part" id="part-{$number}">
                        <div class="part-title">
                          <span class="num">{$number}</span>
                          <h2><a href="{$href}">{$partTitle}</a></h2>
                          <span class="by">von {$by}</span>
                        </div>
                        <p class="facts"><span><b>{$bpm}</b> BPM</span> <span>{$bars}</span>{$repeat} <span><b>{$length}</b> Länge</span></p>
                        {$thumb}
                        <div class="part-bar" aria-hidden="true"><span class="part-progress"></span></div>
                        {$text}
                      </li>
                HTML;
        }

        return $this->page($set->title . ' – ' . $this->site['title'], 'project-page set-page', <<<HTML
              <script type="application/json" id="set">{$payload}</script>
              {$head}
              <main>
                <div class="heading">
                  <h1><span class="badge">{$badge}</span> {$title}</h1>
                  {$artist}
                  {$tags}
                </div>
                {$introNotes}
                <div class="daw" id="daw">
                  <noscript><p>Zum Abspielen braucht diese Seite JavaScript.</p></noscript>
                </div>
                <ol class="parts">{$items}
                </ol>
                {$sessions}
                {$albumLinks}
                {$closingNotes}
                {$source}
              </main>
            HTML, withScript: true);
    }

    /** @param list<Track> $tracks */
    private function trackNotes(array $tracks): string
    {
        $html = '';

        foreach ($tracks as $index => $track) {
            if ($track->notesHtml === '') {
                continue;
            }

            $html .= sprintf(
                "\n      <details class=\"track-note t%d\" id=\"track-%d\"><summary><span class=\"name\">%s</span> <span class=\"instrument\">%s</span></summary><div class=\"notes inner\">%s</div></details>",
                $index % 8,
                $index + 1,
                $this->e($track->group !== null ? $track->group->title . ' | ' . $track->name : $track->name),
                $this->e($track->instrument),
                $track->notesHtml,
            );
        }

        return $html === '' ? '' : '<section class="track-notes"><h2 class="sub">Zusätzliche Spurinformationen</h2>' . $html . "\n    </section>";
    }

    public function notFound(): string
    {
        $site = $this->e($this->site['title']);
        $home = $this->url('');
        $head = $this->head(null, 'cat 404.html');

        return $this->page('Nicht gefunden – ' . $this->site['title'], 'library', <<<HTML
              {$head}
              <main>
                <div class="heading">
                  <h1>Nicht gefunden</h1>
                  <p class="lead">Diese Adresse gibt es nicht. Alle Autoren stehen auf der <a href="{$home}">Startseite</a>.</p>
                </div>
              </main>
            HTML);
    }
}
