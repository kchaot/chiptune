<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Common\Url;
use Chiptune\Format\Credit;
use Chiptune\Format\Set;
use Chiptune\Format\Song;
use Chiptune\Format\Song\Track;
use Chiptune\Site\Renderer;

/**
 * Das Gemeinsame aller Seiten: Kopf, Fuß, Rahmen und kleine Bausteine.
 *
 * Hier liegen `head()` und `page()` mit Skripten und Stylesheets, das Escapen und die Adressen, dazu
 * Bausteine, die mehrere Seiten brauchen: Credits, Medien-Hinweise, Badges und die kleinen Notenbilder.
 * Die Renderer der einzelnen Seiten erben davon.
 */
abstract class PageRenderer
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
    private const DRUM_ORDER = ['k', 'c', 's', 'o', 'h'];

    /**
     * @param array{title: string, tagline: string, noindex: bool, prompt?: string, outro?: string, base_path?: string, parent?: array{title: string, url: string}|null, assets_base?: string, assets_origin?: string} $site
     * @param array{css: array{url: string, integrity: string}, js: array{url: string, integrity: string}} $assets
     * @param array<string, array{title: string, url: string, profile?: string|null, author?: string, tagline?: string, html?: string}> $authors
     * @param list<array{slug: string, title: string, html: string, tabs: list<array{slug: string, title: string, html: string}>}> $pages
     */
    public function __construct(
        protected readonly array $site,
        protected readonly array $assets,
        protected readonly array $authors = [],
        protected readonly array $pages = [],
    ) {}

    protected function url(string $path): string
    {
        return $this->e(($this->site['base_path'] ?? '/') . $path);
    }

    /**
     * @param string|null $prefix
     * @param bool $linkHost
     * @param bool $linkPrefix
     */
    protected function head(?string $prefix, string $command, bool $linkHost = true, bool $linkPrefix = true): string
    {
        $prompt = $this->site['prompt'] ?? '';
        $host = $prompt !== '' ? $prompt : $this->site['title'];
        $hostHtml = $linkHost ? '<a class="p-user" href="' . $this->url('') . '">' . $this->e($host) . '</a>' : '<span class="p-user">' . $this->e($host) . '</span>';
        $path = '';

        if ($prefix !== null) {
            $segments = explode('/', $prefix);
            $walked = '';

            foreach ($segments as $index => $segment) {
                $walked .= ($walked === '' ? '' : '/') . $segment;
                $last = $index === (count($segments) - 1);
                $path .= $linkPrefix || !$last
                    ? '<a class="p-path" href="' . $this->url($walked . '/') . '">' . $this->e('/' . $segment) . '</a>'
                    : '<span class="p-path">' . $this->e('/' . $segment) . '</span>';
            }
        }

        return (
            '<header class="pagehead"><nav class="prompt" aria-label="Brotkrümel">'
            . $hostHtml
            . $path
            . '<span class="p-dollar">$</span> <span class="p-cmd">'
            . $this->e($command)
            . '</span></nav></header>'
        );
    }

    protected function page(string $title, string $bodyClass, string $body, bool $withScript = false, bool $mixLink = true, bool $withFooter = true): string
    {
        $title = $this->e($title);
        $css = $this->e($this->assets['css']['url']);
        $cssIntegrity = $this->e($this->assets['css']['integrity']);
        $robots = $this->site['noindex'] ? "\n  <meta name=\"robots\" content=\"noindex, nofollow\">" : '';
        $script = $withScript
            ? "\n  <script src=\""
            . $this->e($this->assets['js']['url'])
            . '" integrity="'
            . $this->e($this->assets['js']['integrity'])
            . '"'
            . $this->crossorigin($this->assets['js']['url'])
            . ' defer></script>'
            : '';
        $cssCross = $this->crossorigin($this->assets['css']['url']);
        $footer = $withFooter ? $this->footer($mixLink) : '';

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

    protected function footer(bool $mixLink = true): string
    {
        $links = array_map(
            fn(array $page): string => '<a href="' . $this->url('#' . $page['slug']) . '">' . $this->e($page['title']) . '</a>',
            $this->pages,
        );

        $parent = $this->site['parent'] ?? null;

        if ($parent !== null) {
            $links[] = '<a href="' . $this->e($parent['url']) . '">' . $this->e($parent['title']) . '</a>';
        }

        $outro = $this->site['outro'] ?? '';

        $mix = $mixLink
            ? '<a class="mix-link" href="'
            . $this->url('+mixer/')
            . '" title="Mixer: zwei Decks, Crossfader und Effekte" aria-label="Mixer: zwei Decks, Crossfader und Effekte"><b>+</b>Mixer</a> <a class="mix-link" href="'
            . $this->url('+synth/')
            . '" title="Synth: Songs im Raster bauen und als Datei mitnehmen" aria-label="Synth: Songs im Raster bauen und als Datei mitnehmen"><b>+</b>Synth</a>'
            : '';

        if ($links === [] && $outro === '' && $mix === '') {
            return '';
        }

        $nav = $links === [] && $mix === '' ? '' : '<nav aria-label="Informationen">' . implode(' ', $links) . $mix . '</nav>';
        $outro = $outro === '' ? '' : '<div class="outro">' . $this->e($outro) . '</div>';

        return "\n  <footer class=\"footer\">{$nav}{$outro}</footer>";
    }

    protected function crossorigin(string $url): string
    {
        return str_starts_with($url, 'https://') ? ' crossorigin="anonymous"' : '';
    }

    protected function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function number(float|int $value, int $decimals = 1): string
    {
        $text = number_format((float) $value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    protected function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    protected function authorTitle(string $prefix): string
    {
        return $this->authors[$prefix]['title'] ?? $prefix;
    }

    protected function artistLine(string $artist, string $prefix, string $tag): string
    {
        if ($artist === '' || strcasecmp(trim($artist), $this->authorTitle($prefix)) === 0) {
            return '';
        }

        return "<{$tag} class=\"artist\">" . $this->e($artist) . "</{$tag}>";
    }

    protected function songBadge(Song $song): string
    {
        if ($song->layers !== []) {
            return '<span class="badge">Layer</span> ';
        }

        return $song->credits === [] ? '' : '<span class="badge">Remix</span> ';
    }

    /**
     * @param list<Credit> $credits
     */
    protected function credits(array $credits): string
    {
        if ($credits === []) {
            return '';
        }

        $links = array_map(
            fn(Credit $credit): string => (
                '<a href="'
                . $this->url($credit->prefix . '/' . $credit->slug . '/')
                . '">'
                . $this->e($credit->title)
                . '</a> von '
                . $this->e($this->authorTitle($credit->prefix))
            ),
            $credits,
        );

        return '<p class="credits">Enthält Material aus ' . implode(', ', $links) . '.</p>';
    }

    protected function dateText(string $value): string
    {
        $m = [];
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $value, $m) !== 1) {
            return '';
        }

        $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        if (!isset($m[2])) {
            return $m[1];
        }

        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            return '';
        }

        return isset($m[3]) ? (int) $m[3] . '. ' . $months[$month] . ' ' . $m[1] : $months[$month] . ' ' . $m[1];
    }

    protected function releaseLabel(string $url): string
    {
        return $this->releaseService($url) ?? 'Veröffentlichung';
    }

    protected function releaseService(string $url): ?string
    {
        $host = Url::host($url);
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

    protected function mediaMarks(Song $song): string
    {
        $media = $song->media;
        $marks = '';

        if ($media->hq !== null) {
            $marks .= ' <span class="mark" title="Mit HQ-Version: in einer DAW gerendert, läuft im Takt des Rasters">HQ</span>';
        }

        if ($media->release !== null) {
            $release = $media->release;

            if ($media->releaseOwn) {
                $marks .= ' <span class="mark mark-own" title="Veröffentlichung: direkt hier abspielbar">VÖ</span>';
            } elseif ($this->releaseService($release) === 'SoundCloud') {
                $marks .= ' <span class="mark mark-soundcloud" title="Veröffentlichung auf SoundCloud">VÖ</span>';
            } else {
                $marks .=
                    ' <span class="mark mark-ext" title="Mit Veröffentlichung'
                    . ($this->releaseService($release) === null ? ' (externer Link)' : ' (' . $this->e($this->releaseLabel($release)) . ')')
                    . '">VÖ</span>';
            }
        }

        return $marks;
    }

    /**
     * @param list<Track> $tracks
     * @param array{int, int}|null $window
     */
    public function thumbnail(array $tracks, int $steps, ?array $window = null): string
    {
        [$from, $to] = $window ?? [0, $steps];

        return sprintf(
            '<svg class="thumb" viewBox="%d 0 %d %d" height="%d" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true" focusable="false">%s</svg>',
            $from,
            $to - $from,
            $this->rowsHeight($tracks),
            max(96, count($tracks) * 24),
            $this->rows($tracks),
        );
    }

    private const ROW = 8;
    private const GAP = 3;

    protected function setThumbnail(Set $set): string
    {
        $scale = 10;
        $height = 96;
        $width = max(1, (int) round($set->duration * $scale));
        $svg = sprintf(
            '<svg class="thumb" viewBox="0 0 %d %d" height="%d" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true" focusable="false">',
            $width,
            $height,
            $height,
        );
        $x = 0.0;

        foreach ($set->parts as $part) {
            $run = ($part->stop - $part->start) * $part->stepSeconds * $scale;

            for ($i = 0; $i < $part->repeat; $i++) {
                $svg .= sprintf(
                    '<svg x="%s" y="0" width="%s" height="%d" viewBox="%d 0 %d %d" preserveAspectRatio="none">%s</svg>',
                    $this->number($x, 2),
                    $this->number($run, 2),
                    $height - 2,
                    $part->start,
                    $part->stop - $part->start,
                    $this->rowsHeight($part->tracks),
                    $this->rows($part->tracks),
                );
                $x += $run;
            }

            $svg .= sprintf('<rect class="sep" x="%s" y="0" width="1" height="%d"/>', $this->number($x - 0.5, 2), $height);
        }

        return $svg . '</svg>';
    }

    /** @param list<Track> $tracks */
    protected function rowsHeight(array $tracks): int
    {
        return (count($tracks) * (self::ROW + self::GAP)) - self::GAP;
    }

    /** @param list<Track> $tracks */
    protected function rows(array $tracks): string
    {
        $row = self::ROW;
        $svg = '';

        foreach ($tracks as $index => $track) {
            $top = $index * ($row + self::GAP);
            $svg .= '<g class="t' . ($index % 8) . '">';

            if ($track->isDrums()) {
                $kinds = array_values(array_filter(self::DRUM_ORDER, fn(string $kind): bool => in_array($kind, array_column($track->events, 'd'), true)));
                $part = $kinds === [] ? $row : $row / count($kinds);

                foreach ($track->events as $event) {
                    $svg .= $this->rect(
                        $event['t'],
                        $top + ((int) array_search($event['d'] ?? '', $kinds, true) * $part),
                        $event['len'],
                        max($part - ($part < $row ? 0.5 : 0), 0.5),
                    );
                }
            } else {
                $midis = array_map(fn(array $event): float => 69 + (12 * log(($event['f'] ?? 440.0) / 440, 2)), $track->events);
                $low = $midis === [] ? 0 : min($midis);
                $span = $midis === [] ? 1 : max($midis) - $low;

                foreach ($track->events as $position => $event) {
                    $level = $span > 0 ? ($midis[$position] - $low) / $span : 0.5;
                    $svg .= $this->rect($event['t'], $top + ((1 - $level) * ($row - 2.5)), $event['len'], 2.5);
                }
            }

            $svg .= '</g>';
        }

        return $svg;
    }

    protected function rect(int $x, float $y, int $width, float $height): string
    {
        return sprintf('<rect x="%d" y="%s" width="%d" height="%s"/>', $x, $this->number($y, 2), $width, $this->number($height, 2));
    }
}
