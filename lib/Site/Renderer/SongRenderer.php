<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Format\Media;
use Chiptune\Format\Song;
use Chiptune\Format\Song\Track;
use Chiptune\Site\Entry;

/**
 * Rendert die Seite eines Songs und die JSON-Daten für den Player.
 *
 * Dazu gehören Studio, Layer, Aufnahmen, Notizen zu den Spuren und die Navigation im Album.
 */
final class SongRenderer extends PageRenderer
{
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
        $synthLink = $this->synthLink($entry, $song);

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
                <p class="source"><a href="source.md">Quelltext dieses Songs</a>{$synthLink}</p>
              </main>
            HTML, withScript: true);
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

    private function synthLink(Entry $entry, Song $song): string
    {
        if ($song->remix !== true || $song->layers !== []) {
            return '';
        }

        foreach ($song->tracks as $track) {
            if ($track->refs !== []) {
                return '';
            }
        }

        return ' | <a href="' . $this->url('+synth/') . '#' . $this->e($entry->prefix . '/' . $entry->slug) . '">Im +Synth öffnen</a>';
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
}
