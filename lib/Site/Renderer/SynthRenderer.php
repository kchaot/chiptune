<?php

declare(strict_types=1);

namespace Chiptune\Site\Renderer;

use Chiptune\Format\Fields;
use Chiptune\Format\Song\SongParser;

/**
 * Rendert den Mini-Synth unter `+synth/`.
 *
 * Der Synth ist ein Raster-Editor im Browser: Spuren anlegen, Noten setzen, abspielen und als Song im
 * Songformat ausgeben. Die Grenzen (Tempo, Raster, Spuren, Längen) stammen aus `SongParser` und `Fields`
 * und werden als JSON in die Seite geschrieben. So bleibt das Format die einzige Quelle, und was der Synth
 * ausgibt, nimmt der Parser an.
 */
final class SynthRenderer extends PageRenderer
{
    public function synth(): string
    {
        $limits = json_encode([
            'format' => Fields::FORMAT,
            'base' => $this->url(''),
            'bpm' => ['min' => SongParser::BPM_MIN, 'max' => SongParser::BPM_MAX, 'default' => SongParser::BPM_DEFAULT],
            'grid' => ['min' => SongParser::GRID_MIN, 'max' => SongParser::GRID_MAX, 'default' => SongParser::GRID_DEFAULT],
            'instruments' => SongParser::INSTRUMENTS,
            'maxTracks' => SongParser::MAX_TRACKS,
            'maxSteps' => SongParser::MAX_STEPS,
            'maxTitle' => Fields::MAX_TITLE,
            'maxArtist' => Fields::MAX_ARTIST,
        ], self::JSON_FLAGS);
        $head = $this->head(null, 'synth');

        return $this->page('Synth – ' . $this->site['title'], 'project-page synth-page', <<<HTML
              <script type="application/json" id="synth">{$limits}</script>
              {$head}
              <main>
                <div class="heading">
                  <h1>Synth</h1>
                  <p class="lead">Baue im Raster einen kleinen Song, spiele ihn ab und nimm ihn als Datei mit. Nichts davon wird gespeichert oder hochgeladen.</p>
                </div>
                <div class="daw synth" id="daw">
                  <noscript><p class="empty">Der Synth braucht JavaScript.</p></noscript>
                </div>
              </main>
            HTML, true, false);
    }
}
