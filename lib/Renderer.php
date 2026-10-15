<?php

declare(strict_types=1);

namespace Chiptune;

final class Renderer
{
    private const DRUM_ORDER = ['k', 'c', 's', 'o', 'h'];
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /**
     * @param array{title: string, tagline: string, noindex: bool, prompt?: string, outro?: string, base_path?: string, parent?: array{title: string, url: string}|null} $site
     * @param array{css: array{url: string, integrity: string}, js: array{url: string, integrity: string}} $assets
     * @param array<string, array{title: string, url: string, tagline?: string, html?: string}> $authors
     * @param list<array{slug: string, title: string, html: string}> $pages
     */
    public function __construct(
        private readonly array $site,
        private readonly array $assets,
        private readonly array $authors = [],
        private readonly array $pages = [],
    ) {}

    /**
     * @param list<array{prefix: string, slug: string, project: array<string, mixed>}> $entries
     * @param string|null $prefix
     */
    public function library(array $entries, ?string $prefix = null): string
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
            $link = $url === '' ? '' : '<a href="' . $this->e($url) . '" rel="noopener noreferrer">' . $this->e(preg_replace('~^https?://~', '', $url)) . '</a>';
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

    /**
     * @param array<string, mixed> $entry
     */
    private function libraryItem(array $entry, ?string $prefix): string
    {
        $project = $entry['project'];
        $href = $this->url($entry['prefix'] . '/' . $entry['slug'] . '/');
        $title = $this->e($project['title']);
        $artist = $this->artistLine($project['artist'], $entry['prefix'], 'span');
        $author = $prefix !== null
            ? ''
            : '<span class="author">von <a href="' . $this->url($entry['prefix'] . '/') . '">' . $this->e($this->authorTitle($entry['prefix'])) . '</a></span>';
        $tags = implode('', array_map(fn (string $tag): string => '<li>' . $this->e($tag) . '</li>', $project['tags']));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        if (($entry['kind'] ?? 'project') === 'session') {
            $facts = '<span>A: <b>' . $this->e($project['a']['title']) . '</b></span> <span>B: <b>' . $this->e($project['b']['title']) . '</b></span>';
            $thumbnail = '';
            $badge = '<span class="badge">Session</span> ';
        } elseif (in_array($entry['kind'] ?? 'project', ['set', 'album'], true)) {
            $isAlbum = $entry['kind'] === 'album';
            $parts = count($project['parts']);
            $length = $this->duration($project['duration']);
            $noun = $isAlbum ? ($parts === 1 ? 'Song' : 'Songs') : ($parts === 1 ? 'Part' : 'Parts');
            $facts = "<span><b>{$parts}</b> {$noun}</span> <span><b>{$length}</b> Länge</span>";
            $thumbnail = $this->setThumbnail($project);
            $badge = '<span class="badge">' . ($isAlbum ? 'Album' : 'Set') . '</span> ';
        } else {
            $bpm = $this->number($project['bpm']);
            $bars = $this->number($project['bars']);
            $tracks = count($project['tracks']);
            $length = $this->duration($project['steps'] * $project['stepSeconds']);
            $facts = "<span><b>{$bpm}</b> BPM</span> <span><b>{$bars}</b> Bars</span> <span><b>{$tracks}</b> Tracks</span> <span><b>{$length}</b> Länge</span>";
            $thumbnail = $this->thumbnail($project);
            $badge = $this->projectBadge($project);
            $facts .= $this->mediaMarks($project);
        }

        return <<<HTML

      <li class="project">
        <div class="title">
          <h2>{$badge}<a href="{$href}">{$title}</a></h2>
          {$artist}{$author}
        </div>
        {$thumbnail}
        <p class="facts">{$facts}</p>
        {$tags}
      </li>
HTML;

    }

    /**
     * @param list<array<string, mixed>> $entries
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
            $group = (string) ($entry['project']['group'] ?? '');

            if (($entry['kind'] ?? 'project') === 'album') {
                $albums[] = $entry;
            } elseif ($group !== '') {
                $groups[mb_strtolower($group)][] = $entry;
            } else {
                $singles[] = $entry;
            }
        }

        $items = fn (array $list): string => '<ul class="projects">' . implode('', array_map(fn (array $entry): string => $this->libraryItem($entry, $prefix), $list)) . "\n    </ul>";
        $html = $albums === [] ? '' : $items($albums);

        foreach ($groups as $members) {
            $name = $this->e((string) $members[0]['project']['group']);
            $count = count($members);
            $noun = $count === 1 ? 'Song' : 'Songs';
            $html .= "\n    <details class=\"group\" open>\n      <summary><span class=\"group-name\">{$name}</span> <span class=\"group-count\">{$count} {$noun}</span></summary>\n    " . $items($members) . "\n    </details>";
        }

        return $html . ($singles === [] ? '' : ($groups === [] ? '' : "\n    <h2 class=\"group-rest\">Weitere Songs</h2>") . $items($singles));
    }

    /**
     * @param list<array{prefix: string, slug: string, kind: string, project: array<string, mixed>}> $entries
     */
    private function authorList(array $entries): string
    {
        if ($this->authors === []) {
            return '<p class="empty">Noch keine Projekte.<br> Lege eine Markdown-Datei in einer Quelle (etc/sources.json) an und baue die Seite neu.</p>';
        }

        $items = '';

        foreach ($this->authors as $prefix => $author) {
            $prefix = (string) $prefix;
            $own = array_filter($entries, fn (array $entry): bool => $entry['prefix'] === $prefix);
            $sets = count(array_filter($own, fn (array $entry): bool => $entry['kind'] === 'set'));
            $albums = count(array_filter($own, fn (array $entry): bool => $entry['kind'] === 'album'));
            $sessions = count(array_filter($own, fn (array $entry): bool => $entry['kind'] === 'session'));
            $projects = count($own) - $sets - $albums - $sessions;
            $withHq = count(array_filter($own, fn (array $entry): bool => $entry['kind'] === 'project' && isset($entry['project']['media']['hq'])));
            $withRelease = count(array_filter($own, fn (array $entry): bool => $entry['kind'] === 'project' && isset($entry['project']['media']['release'])));
            $facts = '<span><b>' . $projects . '</b> ' . ($projects === 1 ? 'Project' : 'Projects') . '</span>';

            if ($albums > 0) {
                $facts .= ' <span><b>' . $albums . '</b> ' . ($albums === 1 ? 'Album' : 'Alben') . '</span>';
            }

            if ($sets > 0) {
                $facts .= ' <span><b>' . $sets . '</b> ' . ($sets === 1 ? 'Set' : 'Sets') . '</span>';
            }

            if ($sessions > 0) {
                $facts .= ' <span><b>' . $sessions . '</b> ' . ($sessions === 1 ? 'Session' : 'Sessions') . '</span>';
            }

            $count = [];

            foreach ($own as $entry) {
                foreach ($entry['project']['tags'] as $tag) {
                    $count[$tag] = ($count[$tag] ?? 0) + 1;
                }
            }

            arsort($count);
            $tags = implode('', array_map(fn (string $tag): string => '<li>' . $this->e($tag) . '</li>', array_slice(array_map(strval(...), array_keys($count)), 0, 6)));
            $chips = ($withHq > 0 ? '<li class="chip" title="Projekte mit HQ-Version"><b>' . $withHq . '</b> HQ</li>' : '')
                . ($withRelease > 0 ? '<li class="chip" title="Projekte mit Veröffentlichung"><b>' . $withRelease . '</b> VÖ</li>' : '');
            $tags = $chips . $tags;
            $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
            $tagline = ($author['tagline'] ?? '') === '' ? '' : '<p class="tagline">' . $this->e($author['tagline']) . '</p>';
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

    private function pageViews(): string
    {
        $html = '';

        foreach ($this->pages as $page) {
            $head = $this->head(null, 'cat ' . $page['slug'] . '.md');
            $html .= sprintf(
                "\n  <div class=\"page\" id=\"%s\">\n  %s\n  <main>\n    <div class=\"heading\"><h1>%s</h1></div>\n    <section class=\"panel\"><div class=\"panel-inner\"><div class=\"notes\">%s</div></div></section>\n  </main>\n  </div>",
                $this->e($page['slug']),
                $head,
                $this->e($page['title']),
                $page['html'],
            );
        }

        return $html;
    }

    private function url(string $path): string
    {
        return $this->e(($this->site['base_path'] ?? '/') . $path);
    }

    /**
     * @param string|null $prefix
     * @param bool $linkHost
     * @param bool $linkPrefix
     */
    private function head(?string $prefix, string $command, bool $linkHost = true, bool $linkPrefix = true): string
    {
        $host = ($this->site['prompt'] ?? '') !== '' ? $this->site['prompt'] : $this->site['title'];
        $hostHtml = $linkHost
            ? '<a class="p-user" href="' . $this->url('') . '">' . $this->e($host) . '</a>'
            : '<span class="p-user">' . $this->e($host) . '</span>';
        $path = '';

        if ($prefix !== null) {
            $segments = explode('/', $prefix);
            $walked = '';

            foreach ($segments as $index => $segment) {
                $walked .= ($walked === '' ? '' : '/') . $segment;
                $last = $index === count($segments) - 1;
                $path .= $linkPrefix || ! $last
                    ? '<a class="p-path" href="' . $this->url($walked . '/') . '">' . $this->e('/' . $segment) . '</a>'
                    : '<span class="p-path">' . $this->e('/' . $segment) . '</span>';
            }
        }

        return '<header class="pagehead"><nav class="prompt" aria-label="Brotkrümel">' . $hostHtml . $path
            . '<span class="p-dollar">$</span> <span class="p-cmd">' . $this->e($command) . '</span></nav></header>';
    }

    private function artistLine(string $artist, string $prefix, string $tag): string
    {
        if ($artist === '' || strcasecmp(trim($artist), $this->authorTitle($prefix)) === 0) {
            return '';
        }

        return "<{$tag} class=\"artist\">" . $this->e($artist) . "</{$tag}>";
    }

    private function authorTitle(string $prefix): string
    {
        return $this->authors[$prefix]['title'] ?? $prefix;
    }

    private function projectBadge(array $project): string
    {
        if (! empty($project['layers'])) {
            return '<span class="badge">Layer</span> ';
        }

        return ($project['credits'] ?? []) === [] ? '' : '<span class="badge">Remix</span> ';
    }

    /**
     * @param array<string, mixed> $project
     */
    private function layersPanel(array $project): string
    {
        if (empty($project['layers'])) {
            return '';
        }

        $descriptions = [];

        foreach ($project['tracks'] as $track) {
            if (isset($track['group']) && ! isset($descriptions[$track['group']['ref']])) {
                $descriptions[$track['group']['ref']] = $track['group']['description'];
            }
        }

        $items = '';

        foreach ($project['layers'] as $index => $layer) {
            $href = $this->url($layer['ref'] . '/');
            $original = abs($layer['bpm'] - $project['bpm']) < 0.05
                ? $this->number($layer['bpm']) . ' BPM'
                : $this->number($layer['bpm']) . ' -> ' . $this->number($project['bpm']) . ' BPM';
            $text = $descriptions[$layer['ref']] ?? '';
            $items .= sprintf(
                "\n      <li class=\"layer t%d\"><p><a href=\"%s\">%s</a> <span class=\"layer-facts\">steigt ein bei Bar %d, %s</span></p>%s</li>",
                $index % 8,
                $href,
                $this->e($layer['title']),
                $layer['at'],
                $original,
                $text === '' ? '' : '<div class="notes">' . $text . '</div>',
            );
        }

        return '<section class="layers"><h2 class="sub">Layer</h2><ol>' . $items . "\n    </ol></section>";
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function projectPayload(array $project): array
    {
        return [
            'title' => $project['title'],
            'artist' => $project['artist'],
            'bpm' => $project['bpm'],
            'stepsPerBeat' => $project['stepsPerBeat'],
            'stepsPerBar' => $project['stepsPerBar'],
            'stepSeconds' => $project['stepSeconds'],
            'loop' => $project['loop'],
            'steps' => $project['steps'],
            'media' => (object) (($project['media'] ?? []) + (isset($project['media']['release']) ? ['releaseLabel' => $this->releaseLabel((string) $project['media']['release'])] : [])),
            'tracks' => array_map(function (array $track): array {
                $notes = $track['notesHtml'] !== '';
                unset($track['notesHtml']);

                $track['refs'] = array_map(fn (array $ref): array => $ref + ['href' => $this->url($ref['ref'] . '/')], $track['refs'] ?? []);

                if (isset($track['group'])) {
                    $track['group'] = [
                        'title' => $track['group']['title'],
                        'href' => $this->url($track['group']['ref'] . '/'),
                        'bpm' => $track['group']['bpm'],
                    ];
                }

                return $track + ['notes' => $notes];
            }, $project['tracks']),
        ];
    }

    /**
     * @param list<array{id: string, title: string, artist: string, source: string, sourceId: string, album: string, albumId: string, tags: list<string>, bpm: float|int, bars: float|int, href: string, data: string}> $catalog
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

    /** @param array{prefix: string, slug: string, project: array<string, mixed>} $entry */
    public function project(array $entry): string
    {
        $project = $entry['project'];
        $title = $this->e($project['title']);
        $artist = $this->artistLine($project['artist'], $entry['prefix'], 'p');
        $tags = implode('', array_map(fn (string $tag): string => '<li>' . $this->e($tag) . '</li>', $project['tags']));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $project['notesHtml'] === '' ? '' : '<section class="aside wide"><div class="notes">' . $project['notesHtml'] . '</div></section>';
        $head = $this->head($entry['prefix'] . ($entry['album'] !== null ? '/' . $entry['album'] : ''), 'play ' . basename($entry['slug']));
        $credits = $this->credits($project['credits'] ?? []);
        $albumNav = $this->albumNav($entry['nav'] ?? null, $entry['prefix']);
        $thumbnail = $this->thumbnail($project);

        $payload = json_encode($this->projectPayload($project), self::JSON_FLAGS);

        $trackNotes = $this->trackNotes($project['tracks']);
        $layersPanel = $this->layersPanel($project);
        $badge = $this->projectBadge($project);
        $mediaLinks = $this->mediaLinks($project['media'] ?? []);

        return $this->page($project['title'] . ' – ' . $this->site['title'], 'project-page', <<<HTML
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
    <p class="source"><a href="source.md">Quelltext dieses Projects</a></p>
  </main>
HTML, withScript: true);
    }

    private function releaseLabel(string $url): string
    {
        return $this->releaseService($url) ?? 'Veröffentlichung';
    }

    private function releaseService(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $services = [
            'soundcloud.com' => 'SoundCloud',
            'bandcamp.com' => 'Bandcamp',
            'spotify.com' => 'Spotify',
            'spotify.link' => 'Spotify',
            'youtube.com' => 'YouTube',
            'youtu.be' => 'YouTube',
        ];

        foreach ($services as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $project
     */
    private function mediaMarks(array $project): string
    {
        $media = $project['media'] ?? [];
        $marks = '';

        if (isset($media['hq'])) {
            $marks .= ' <span class="mark" title="Mit HQ-Version: in einer DAW gerendert, läuft im Takt des Rasters">HQ</span>';
        }

        if (isset($media['release'])) {
            $release = (string) $media['release'];

            if (isset($media['releaseOwn'])) {
                $marks .= ' <span class="mark mark-own" title="Veröffentlichung: direkt hier abspielbar">VÖ</span>';
            } elseif ($this->releaseService($release) === 'SoundCloud') {
                $marks .= ' <span class="mark mark-soundcloud" title="Veröffentlichung auf SoundCloud">VÖ</span>';
            } else {
                $marks .= ' <span class="mark mark-ext" title="Mit Veröffentlichung' . ($this->releaseService($release) === null ? ' (externer Link)' : ' (' . $this->e($this->releaseLabel($release)) . ')') . '">VÖ</span>';
            }
        }

        return $marks;
    }

    /**
     * @param array<string, mixed> $media
     */
    private function mediaLinks(array $media): string
    {
        $items = '';

        if (isset($media['hq'])) {
            $items .= '<li><a href="' . $this->e((string) $media['hq']) . '" rel="noopener noreferrer">HQ-Version (Audio)</a></li>';
        }

        if (isset($media['release'])) {
            $own = isset($media['releaseOwn']);
            $class = $own ? ' class="own"' : ($this->releaseService((string) $media['release']) === 'SoundCloud' ? ' class="soundcloud"' : '');
            $items .= '<li><a' . $class . ' href="' . $this->e((string) $media['release']) . '" rel="noopener noreferrer">' . $this->e($this->releaseLabel((string) $media['release'])) . ($own ? ' (Audio)' : '') . '</a></li>';
        }

        return $items === '' ? '' : '<ul class="media-links">' . $items . '</ul>';
    }

    /**
     * @param array<string, mixed> $set
     */
    private function setThumbnail(array $set): string
    {
        $scale = 10;
        $height = 96;
        $width = max(1, (int) round($set['duration'] * $scale));
        $svg = sprintf(
            '<svg class="thumb" viewBox="0 0 %d %d" height="%d" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true" focusable="false">',
            $width,
            $height,
            $height,
        );
        $x = 0.0;

        foreach ($set['parts'] as $part) {
            $run = ($part['stop'] - $part['start']) * $part['stepSeconds'] * $scale;

            for ($i = 0; $i < $part['repeat']; $i++) {
                $svg .= sprintf(
                    '<svg x="%s" y="0" width="%s" height="%d" viewBox="%d 0 %d %d" preserveAspectRatio="none">%s</svg>',
                    $this->number($x, 2),
                    $this->number($run, 2),
                    $height - 2,
                    $part['start'],
                    $part['stop'] - $part['start'],
                    $this->rowsHeight($part['tracks']),
                    $this->rows($part['tracks']),
                );
                $x += $run;
            }

            $svg .= sprintf('<rect class="sep" x="%s" y="0" width="1" height="%d"/>', $this->number($x - 0.5, 2), $height);
        }

        return $svg . '</svg>';
    }

    /**
     * @param list<array{prefix: string, slug: string, title: string}> $credits
     */
    /**
     * @param array{album: array{slug: string, title: string}, number: int, total: int, prev: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}|null $nav
     */
    private function albumNav(?array $nav, string $prefix): string
    {
        if ($nav === null) {
            return '';
        }

        $link = fn (?array $target, string $class, string $label): string => $target === null
            ? '<span class="' . $class . ' off"></span>'
            : '<a class="' . $class . '" href="' . $this->url($prefix . '/' . $target['slug'] . '/') . '" rel="' . ($class === 'prev' ? 'prev' : 'next') . '">' . $label . ' <b>' . $this->e($target['title']) . '</b></a>';

        return '<nav class="album-nav" aria-label="Album">'
            . $link($nav['prev'], 'prev', '←')
            . '<a class="where" href="' . $this->url($prefix . '/' . $nav['album']['slug'] . '/') . '">' . $this->e($nav['album']['title']) . ' | ' . $nav['number'] . ' von ' . $nav['total'] . '</a>'
            . $link($nav['next'], 'next', '→')
            . '</nav>';
    }

    private function credits(array $credits): string
    {
        if ($credits === []) {
            return '';
        }

        $links = array_map(
            fn (array $credit): string => '<a href="' . $this->url($credit['prefix'] . '/' . $credit['slug'] . '/') . '">' . $this->e($credit['title']) . '</a> von ' . $this->e($this->authorTitle($credit['prefix'])),
            $credits,
        );

        return '<p class="credits">Enthält Material aus ' . implode(', ', $links) . '.</p>';
    }

    public static function mixId(string $prefix, string $slug): string
    {
        return $prefix . '--' . str_replace('/', '--', $slug);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function mixLink(array $session, string $title): string
    {
        $query = http_build_query([
            'a' => self::mixId($session['a']['prefix'], $session['a']['slug']),
            'b' => self::mixId($session['b']['prefix'], $session['b']['slug']),
            'start' => $session['start'],
            'auto' => $session['auto'] ? 1 : 0,
            'fader' => $session['fader'],
            'name' => $title,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->url('+mixer/') . '#' . $this->e($query);
    }

    /**
     * @param array{prefix: string, slug: string, project: array<string, mixed>} $entry
     */
    public function session(array $entry): string
    {
        $session = $entry['project'];
        $title = $this->e($session['title']);
        $artist = $this->artistLine($session['artist'], $entry['prefix'], 'p');
        $tags = implode('', array_map(fn (string $tag): string => '<li>' . $this->e($tag) . '</li>', $session['tags']));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $session['notesHtml'] === '' ? '' : '<section class="aside wide"><div class="notes">' . $session['notesHtml'] . '</div></section>';
        $head = $this->head($entry['prefix'] . ($entry['album'] !== null ? '/' . $entry['album'] : ''), 'mix ' . basename($entry['slug']));
        $href = $this->mixLink($session, $session['title']);
        $rows = '';

        foreach (['a' => 'Deck A', 'b' => 'Deck B'] as $key => $label) {
            $deck = $session[$key];
            $bpm = $this->number($deck['bpm']);
            $bars = $this->number($deck['bars']);
            $detail = $key === 'b' && $session['start'] > 1
                ? "steigt auf Takt <b>{$session['start']}</b> von Deck A ein"
                : 'startet auf Takt <b>1</b>';
            $rows .= sprintf(
                "\n      <li><span class=\"deck-label\">%s</span> <a href=\"%s\">%s</a> <span class=\"layer-facts\">%s BPM, %s Bars, %s</span></li>",
                $label,
                $this->url($deck['prefix'] . '/' . $deck['slug'] . '/'),
                $this->e($deck['title']),
                $bpm,
                $bars,
                $detail,
            );
        }

        $auto = $session['auto'] ? 'Auto-Sync ist an: Deck B folgt dem Tempo von Deck A.' : 'Auto-Sync ist aus.';

        return $this->page($session['title'] . ' – ' . $this->site['title'], 'project-page session-page', <<<HTML
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

    /**
     * @param array{prefix: string, slug: string, project: array<string, mixed>} $entry
     */
    public function set(array $entry): string
    {
        $set = $entry['project'];
        $isAlbum = $entry['kind'] === 'album';
        $title = $this->e($set['title']);
        $artist = $this->artistLine($set['artist'], $entry['prefix'], 'p');
        $tags = implode('', array_map(fn (string $tag): string => '<li>' . $this->e($tag) . '</li>', $set['tags']));
        $tags = $tags === '' ? '' : '<ul class="tags">' . $tags . '</ul>';
        $notes = $set['notesHtml'] === '' ? '' : ('<section class="aside' . ($isAlbum ? '' : ' wide') . '"><div class="notes">' . $set['notesHtml'] . '</div></section>');
        $head = $isAlbum ? $this->head($entry['prefix'], 'ls ' . $entry['slug']) : $this->head($entry['prefix'], 'play ' . basename($entry['slug']));
        $badge = $isAlbum ? 'Album' : 'Set';
        $source = $isAlbum ? '' : '<p class="source"><a href="source.md">Quelltext dieses Sets</a></p>';
        $introNotes = $isAlbum ? $notes : '';
        $closingNotes = $isAlbum ? '' : $notes;

        $sessionList = '';

        foreach ($entry['sessions'] ?? [] as $session) {
            $sessionList .= sprintf(
                "\n      <li><a href=\"%s\">%s</a> <span class=\"layer-facts\">A: %s, B: %s</span></li>",
                $this->url($session['prefix'] . '/' . $session['slug'] . '/'),
                $this->e($session['project']['title']),
                $this->e($session['project']['a']['title']),
                $this->e($session['project']['b']['title']),
            );
        }

        $sessions = $sessionList === '' ? '' : '<section class="album-sessions"><h2 class="sub">Sessions</h2><ul>' . $sessionList . "\n    </ul></section>";

        $payload = json_encode([
            'title' => $set['title'],
            'album' => $entry['kind'] === 'album',
            'loop' => $set['loop'],
            'parts' => array_map(fn (array $part): array => [
                'title' => $part['title'],
                'bpm' => $part['bpm'],
                'stepSeconds' => $part['stepSeconds'],
                'stepsPerBar' => $part['stepsPerBar'],
                'stepsPerBeat' => $part['stepsPerBeat'],
                'start' => $part['start'],
                'stop' => $part['stop'],
                'startBar' => $part['startBar'],
                'repeat' => $part['repeat'],
                'tracks' => array_map(fn (array $track): array => [
                    'instrument' => $track['instrument'],
                    'volume' => $track['volume'],
                    'gate' => $track['gate'],
                    'pw' => $track['pw'],
                    'events' => $track['events'],
                ], $part['tracks']),
            ], $set['parts']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        $items = '';

        foreach ($set['parts'] as $index => $part) {
            $number = $index + 1;
            $href = $this->url($part['prefix'] . '/' . $part['slug'] . '/');
            $partTitle = $this->e($part['title']);
            $by = $this->e($this->authorTitle($part['prefix']));
            $bpm = $this->number($part['bpm']);
            $from = $part['startBar'];
            $to = $from + intdiv($part['stop'] - $part['start'], $part['stepsPerBar']) - 1;
            $bars = $from === $to ? "Bar {$from}" : "Bars {$from}–{$to}";
            $repeat = $part['repeat'] > 1 ? ' <span>× <b>' . $part['repeat'] . '</b></span>' : '';
            $length = $this->duration(($part['stop'] - $part['start']) * $part['stepSeconds'] * $part['repeat']);
            $thumb = $this->thumbnail(['tracks' => $part['tracks'], 'steps' => $part['steps']], [$part['start'], $part['stop']]);
            $text = $part['notesHtml'] === '' ? '' : '<div class="notes inner">' . $part['notesHtml'] . '</div>';

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

        return $this->page($set['title'] . ' – ' . $this->site['title'], 'project-page set-page', <<<HTML
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
    {$closingNotes}
    {$source}
  </main>
HTML, withScript: true);
    }

    /** @param list<array<string, mixed>> $tracks */
    private function trackNotes(array $tracks): string
    {
        $html = '';

        foreach ($tracks as $index => $track) {
            if ($track['notesHtml'] === '') {
                continue;
            }

            $html .= sprintf(
                "\n      <details class=\"track-note t%d\" id=\"track-%d\"><summary><span class=\"name\">%s</span> <span class=\"instrument\">%s</span></summary><div class=\"notes inner\">%s</div></details>",
                $index % 8,
                $index + 1,
                $this->e(isset($track['group']) ? $track['group']['title'] . ' | ' . $track['name'] : $track['name']),
                $this->e($track['instrument']),
                $track['notesHtml'],
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

    public function thumbnail(array $project, ?array $window = null): string
    {
        $tracks = $project['tracks'];
        [$from, $to] = $window ?? [0, $project['steps']];

        return sprintf(
            '<svg class="thumb" viewBox="%d 0 %d %d" height="%d" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true" focusable="false">',
            $from,
            $to - $from,
            $this->rowsHeight($tracks),
            max(96, count($tracks) * 24),
        ) . $this->rows($tracks) . '</svg>';
    }

    private const ROW = 8;
    private const GAP = 3;

    /** @param list<array<string, mixed>> $tracks */
    private function rowsHeight(array $tracks): int
    {
        return count($tracks) * (self::ROW + self::GAP) - self::GAP;
    }

    private function rows(array $tracks): string
    {
        $row = self::ROW;
        $svg = '';

        foreach ($tracks as $index => $track) {
            $top = $index * ($row + self::GAP);
            $svg .= '<g class="t' . ($index % 8) . '">';

            if ($track['instrument'] === 'drums') {
                $kinds = array_values(array_filter(self::DRUM_ORDER, fn (string $kind): bool => in_array($kind, array_column($track['events'], 'd'), true)));
                $part = $kinds === [] ? $row : $row / count($kinds);

                foreach ($track['events'] as $event) {
                    $svg .= $this->rect($event['t'], $top + array_search($event['d'], $kinds, true) * $part, $event['len'], max($part - ($part < $row ? 0.5 : 0), 0.5));
                }
            } else {
                $midis = array_map(fn (array $event): float => 69 + 12 * log($event['f'] / 440, 2), $track['events']);
                $low = $midis === [] ? 0 : min($midis);
                $span = $midis === [] ? 1 : max($midis) - $low;

                foreach ($track['events'] as $position => $event) {
                    $level = $span > 0 ? ($midis[$position] - $low) / $span : 0.5;
                    $svg .= $this->rect($event['t'], $top + (1 - $level) * ($row - 2.5), $event['len'], 2.5);
                }
            }

            $svg .= '</g>';
        }

        return $svg;
    }

    private function rect(int $x, float $y, int $width, float $height): string
    {
        return sprintf('<rect x="%d" y="%s" width="%d" height="%s"/>', $x, $this->number($y, 2), $width, $this->number($height, 2));
    }

    private function page(string $title, string $bodyClass, string $body, bool $withScript = false, bool $mixLink = true): string
    {
        $title = $this->e($title);
        $css = $this->e($this->assets['css']['url']);
        $cssIntegrity = $this->e($this->assets['css']['integrity']);
        $robots = $this->site['noindex'] ? "\n  <meta name=\"robots\" content=\"noindex, nofollow\">" : '';
        $script = $withScript
            ? "\n  <script src=\"" . $this->e($this->assets['js']['url']) . '" integrity="' . $this->e($this->assets['js']['integrity']) . '"' . $this->crossorigin($this->assets['js']['url']) . ' defer></script>'
            : '';
        $cssCross = $this->crossorigin($this->assets['css']['url']);
        $footer = $this->footer($mixLink);

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>{$robots}
  <link rel="stylesheet" href="{$css}" integrity="{$cssIntegrity}"{$cssCross}>{$script}
</head>
<body class="{$bodyClass}">
  <div class="rainbow"></div>
{$body}{$footer}
</body>
</html>

HTML;
    }

    private function crossorigin(string $url): string
    {
        return str_starts_with($url, 'https://') ? ' crossorigin="anonymous"' : '';
    }

    private function footer(bool $mixLink = true): string
    {
        $links = array_map(
            fn (array $page): string => '<a href="' . $this->url('#' . $page['slug']) . '">' . $this->e($page['title']) . '</a>',
            $this->pages,
        );

        $parent = $this->site['parent'] ?? null;

        if ($parent !== null) {
            $links[] = '<a href="' . $this->e($parent['url']) . '">' . $this->e($parent['title']) . '</a>';
        }

        $outro = $this->site['outro'] ?? '';

        $mix = $mixLink
            ? '<a class="mix-link" href="' . $this->url('+mixer/') . '" title="Mixer: zwei Decks, Crossfader und Effekte" aria-label="Mixer: zwei Decks, Crossfader und Effekte"><b>+</b>Mixer</a>'
            : '';

        if ($links === [] && $outro === '' && $mix === '') {
            return '';
        }

        $nav = $links === [] && $mix === '' ? '' : '<nav aria-label="Informationen">' . implode(' ', $links) . $mix . '</nav>';
        $outro = $outro === '' ? '' : '<div class="outro">' . $this->e($outro) . '</div>';

        return "\n  <footer class=\"footer\">{$nav}{$outro}</footer>";
    }

    private function number(float|int $value, int $decimals = 1): string
    {
        $text = number_format((float) $value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
