<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Format\Link;
use Chiptune\Format\Session;
use Chiptune\Format\Set;
use Chiptune\Format\Song;
use Chiptune\Site\CodeBook\DocEntry;
use Chiptune\Site\Renderer\CodeRenderer;
use Chiptune\Site\Renderer\LibraryRenderer;
use Chiptune\Site\Renderer\LinkRenderer;
use Chiptune\Site\Renderer\MixRenderer;
use Chiptune\Site\Renderer\PageRenderer;
use Chiptune\Site\Renderer\SetRenderer;
use Chiptune\Site\Renderer\SongRenderer;
use Chiptune\Site\Renderer\SynthRenderer;

/**
 * Erzeugt alle HTML-Seiten und die JSON-Daten für den Player.
 *
 * Dazu gehören Bibliothek, Song, Set, Session, Link, Mixer, Synth, Code-Seite und die Fehlerseite. Der Renderer
 * bekommt fertige Value Objects und kennt das Markdown nicht mehr. Er ist der Einstieg für den `Builder` und übergibt
 * an `LibraryRenderer`, `SongRenderer`, `SetRenderer`, `LinkRenderer`, `MixRenderer`, `SynthRenderer` und `CodeRenderer`. Das Gemeinsame
 * steckt in `PageRenderer`.
 */
final class Renderer extends PageRenderer
{
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

    public function song(Entry $entry): string
    {
        return new SongRenderer($this->site, $this->assets, $this->authors, $this->pages)->song($entry);
    }

    /**
     * @return array<string, mixed>
     */
    public function songPayload(Song $song): array
    {
        return new SongRenderer($this->site, $this->assets, $this->authors, $this->pages)->songPayload($song);
    }

    public function set(Entry $entry): string
    {
        return new SetRenderer($this->site, $this->assets, $this->authors, $this->pages)->set($entry);
    }

    public function link(Entry $entry): string
    {
        return new LinkRenderer($this->site, $this->assets, $this->authors, $this->pages)->link($entry);
    }

    public function session(Entry $entry): string
    {
        return new MixRenderer($this->site, $this->assets, $this->authors, $this->pages)->session($entry);
    }

    /**
     * @param list<array{id: string, title: string, artist: string, source: string, sourceId: string, album: string, albumId: string, tags: list<string>, bpm: float|int, bars: float|int, group: string, groupId: string, href: string, data: string}> $catalog
     */
    public function mix(array $catalog): string
    {
        return new MixRenderer($this->site, $this->assets, $this->authors, $this->pages)->mix($catalog);
    }

    public function synth(): string
    {
        return new SynthRenderer($this->site, $this->assets, $this->authors, $this->pages)->synth();
    }

    public static function mixId(string $prefix, string $slug): string
    {
        return MixRenderer::mixId($prefix, $slug);
    }

    public static function strudelStand(Song $song): string
    {
        return SongRenderer::strudelStand($song);
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
