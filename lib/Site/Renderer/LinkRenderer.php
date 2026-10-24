<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Site\Entry;

/**
 * Rendert die Seite eines Links, dessen Aufnahme hier abgespielt werden kann (Host in `media` der Quelle).
 */
final class LinkRenderer extends PageRenderer
{
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
}
