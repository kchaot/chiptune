<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Format\SetPart;
use Chiptune\Format\Track;

/**
 * Rendert die Seite eines Sets oder Albums mit den Abschnitten.
 */
final class SetRenderer extends PageRenderer
{
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
}
