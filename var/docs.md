---
title: Code-Übersicht
---
Für alle, die am Code mitbauen. Die Seite entsteht bei jedem Build: Dieser Text kommt aus `var/docs.md`, die Klassen darunter aus den Beschreibungen in `lib/`. Das **Songformat** steht vorn, weil alles andere darauf aufbaut. Danach folgen die vier Verzeichnisse des Repos (`bin`, `etc`, `lib`, `var`) und zuletzt `player.js`, ein Ausschnitt aus den Ressourcen der Stage.

<!-- next: songformat -->
---
title: Songformat
featured: true
---
Was in einer Markdown-Datei stehen darf und was der Build daraus macht. Alle Zahlen kommen aus den Konstanten der Parser in `lib/Format/` und stimmen damit mit dem Code überein. Was sich nicht als Konstante abbilden lässt (zum Beispiel die Grenzen von `volume` und `gate`), steht als Text. Wo es hilft, führt ein Verweis zu den Klassen, die das lesen: zum Beispiel [SongParser](#songparser) und [Song](#song) für Songs, [Media](#media) für Aufnahmen und Veröffentlichungen. Wie die Klassen zusammenhängen, steht im Tab [lib](#tab-lib).

## Begriffe

- **Quelle:** ein Ordner oder Git-Repo aus `etc/sources.json`, mit einem Präfix (`kchaot`, ...).
- **Eintrag:** eine Datei in einer Quelle. Ihre Adresse ist `prefix/name`, in einem Album `prefix/album/name`.
- **Song:** ein Eintrag mit Spuren und Noten. Im Code `Song`, abrufbar als Quelltext (`source.md`).
- **Layer-Song:** ein Song, der aus Spuren anderer Songs besteht.
- **Set:** mehrere Songs hintereinander. Ein **Album** ist ein Ordner mit Songs und wird als Set gebaut.
- **Session:** zwei Songs für den Mixer, mit Startpunkt und Crossfader.
- **Link:** ein Verweis auf eine fertige Aufnahme.
- **Spur:** ein Instrument mit seinen Noten innerhalb eines Songs (im Code `Track`).
- **Step, Takt:** ein Step ist die kleinste Zeiteinheit. Wie viele Steps ein Takt hat, bestimmt `grid`.

## Dateien und Ordner

Eine Quelle ist ein Ordner. Darin gilt:

- Jede Datei `name.md` ist ein Eintrag. Der Name besteht aus `a-z`, `0-9` und `-` (am Anfang kein Bindestrich, höchstens 64 Zeichen), sonst bricht der Build ab.
- Dateien mit `_` am Anfang liest der Build nicht, ebenso `README`, `LICENSE`, `CONTRIBUTING`, `CODE_OF_CONDUCT`, `CHANGELOG` und `SECURITY`.
- Ein Unterordner (gleiches Namensmuster) ist ein Album, es gibt genau eine Ebene. Ein Name darf nicht zugleich Datei und Album sein.
- `_profile.md` im Wurzelordner beschreibt den Autor, `_album.md` im Album-Ordner das Album.
- Symbolische Links werden überall übergangen.

Größen: Song, Layer-Song und Set höchstens {{SongParser::MAX_BYTES|kb}} KB, Session und Link {{SessionParser::MAX_BYTES|kb}} KB, `_album.md` und `_profile.md` 32 KB.

## Dateitypen

Der Typ steht im Kopf als `kind`. Ohne `kind` ist die Datei ein Song.

| `kind` | Typ | Aufbau |
| --- | --- | --- |
| *(fehlt)* oder `song` | Song | Kopf, dann ein Block je Spur (`<!-- track: name -->`) |
| `layer` | Layer-Song | Kopf, dann ein Block je Layer (`<!-- layer: prefix/name -->`) |
| `set` | Set | Kopf, dann ein Block je Abschnitt (`<!-- part: prefix/name -->`) |
| `session` | Session | nur Kopf |
| `link` oder `release` | Link | nur Kopf |

`kind: project` gilt weiter als Song. Gelesen werden die Dateitypen von [SongParser](#songparser), [SetParser](#setparser), [SessionParser](#sessionparser) und [LinkParser](#linkparser).

Jede Datei hat einen Kopf (Frontmatter zwischen `---`), Text darunter und je nach Typ Blöcke. Ein Block beginnt mit seinem Marker (`<!-- track: … -->`, `<!-- layer: … -->` oder `<!-- part: … -->`) und darf eine eigene Frontmatter haben. Der Text vor dem ersten Marker gehört zum Kopf, der Text eines Blocks zu seinem Block.

## Der gemeinsame Kopf

Diese Schlüssel kennen alle Typen (ein Album nur `title`, `artist`, `group` und `tags`):

| Schlüssel | Bedeutung | Grenzen |
| --- | --- | --- |
| `format` | Version des Formats | nur `1` |
| `kind` | Dateityp, siehe oben | |
| `title` | Titel | bis {{SongParser::MAX_TITLE}} Zeichen, sonst der Dateiname |
| `artist` | Künstler | bis {{SongParser::MAX_ARTIST}} Zeichen |
| `group` | Gruppe | bis {{SongParser::MAX_GROUP}} Zeichen |
| `tags` | Schlagworte | höchstens {{SongParser::MAX_TAGS}}, je Buchstaben, Ziffern, Leerzeichen, Bindestrich, bis 24 Zeichen |

Wahrheitswerte (`loop`, `auto`, `remix`) sind echtes `true` oder `false`, nichts anderes.

Verweise auf andere Dateien haben die Form `prefix/name` oder `prefix/album/name`. Wer Songs anderer Autoren verwendet (Clips, Layer, Links), braucht deren Erlaubnis: Der Song muss `remix: true` im Kopf haben. Songs mit gleichem Präfix sind immer erlaubt.

## Song

Gelesen von [SongParser](#songparser), das Ergebnis ist ein [Song](#song) mit [Track](#track)s.

| Schlüssel | Bedeutung | Grenzen | Standard |
| --- | --- | --- | --- |
| `bpm` | Tempo | {{SongParser::BPM_MIN}} bis {{SongParser::BPM_MAX}} | {{SongParser::BPM_DEFAULT}} |
| `grid` | Steps pro Takt | {{SongParser::GRID_MIN}} bis {{SongParser::GRID_MAX}}, durch 4 teilbar | {{SongParser::GRID_DEFAULT}} |
| `loop` | Wiederholen | `true` oder `false` | `true` |
| `remix` | Andere dürfen Spuren und Ausschnitte verwenden | `true` oder `false` | nicht gesetzt |
| `hq` | https-Adresse einer hochwertigen Aufnahme | | |
| `hq-offset` | Versatz der Aufnahme in Sekunden | −10 bis 10, nur mit `hq` | |
| `release` | Veröffentlichung, Adresse oder Block | | |

`grid: 16` heißt 16 Steps pro Takt, also 4 Steps pro Schlag. Was das bedeutet, steht unter [Steps und Takt](#songformat-steps-und-takt), die Schreibweise der Noten unter [Raster](#songformat-raster). Ein vollständiges Beispiel zeigt der Tab [var](#var-songs).

`release` ist eine https-Adresse oder ein Block mit `url` (Pflicht), `cover`, `title`, `date` (`JJJJ`, `JJJJ-MM` oder `JJJJ-MM-TT`), `label`, `catalog`, `license` und `credits` (Rolle: Name oder Liste von Namen, höchstens 12 Rollen mit je 6 Namen). Adressen: nur `https`, Kleinbuchstaben im Hostnamen, höchstens 300 Zeichen. Der Host muss in `media` der Quelle freigegeben sein.

### Spuren

Marker `<!-- track: name -->`, der Name hat 1 bis 32 Zeichen ohne `< > & "`. Höchstens {{SongParser::MAX_TRACKS}} Spuren, genau ein Raster-Block je Spur (außer die Spur besteht nur aus `clips`).

| Schlüssel | Bedeutung | Grenzen | Standard |
| --- | --- | --- | --- |
| `instrument` | Klang | `square`, `pulse`, `triangle`, `saw`, `sine`, `drums` | `square` |
| `volume` | Lautstärke | 0 bis 1 | 0,2 (Drums 0,3) |
| `gate` | Anteil der Notenlänge, der klingt | 0,05 bis 1 | 0,9 |
| `pw` | Pulsbreite (bei `pulse`) | 0,05 bis 0,5 | 0,25 |
| `clips` | Teile aus anderen Songs | 1 bis {{SongParser::MAX_CLIPS}} Einträge | |

### Steps und Takt

Die Zeit eines Songs wird in **Steps** gezählt. Ein Step ist der kleinste Abschnitt, in dem eine Note beginnen kann, und jedes Zeichen im Raster ist genau ein Step. Der Takt ist immer ein Vierviertel-Takt mit vier Schlägen. `grid` legt fest, wie viele Steps auf einen Takt kommen, die Steps pro Schlag sind `grid` geteilt durch 4:

| `grid` | Steps pro Schlag | Feinheit |
| --- | --- | --- |
| 4 | 1 | Viertel |
| 8 | 2 | Achtel |
| 12 | 3 | Achteltriolen |
| 16 | 4 | Sechzehntel (Standard) |
| 32 | 8 | Zweiunddreißigstel |
| 64 | 16 | feinstes Raster |

Das Tempo bestimmt allein `bpm`. Ein Takt dauert immer 240 ÷ `bpm` Sekunden, ein Step 60 ÷ `bpm` ÷ (`grid` ÷ 4) Sekunden. Bei `bpm: 128` und `grid: 16` sind das 1,875 Sekunden je Takt und rund 117 Millisekunden je Step. Ein höheres `grid` macht den Song also nicht schneller, sondern das Raster feiner.

Alle Spuren eines Songs teilen dasselbe Raster. Sie sollten gleich lang sein und ein ganzes Vielfaches des Takts ergeben, sonst meldet der Build eine Warnung. Mit höchstens {{SongParser::MAX_STEPS}} Steps je Spur sind das bei `grid: 16` bis zu 256 Takte. Beim Layer-Song ergibt sich das gemeinsame Raster aus den Layern, siehe [Layer-Song](#songformat-layer-song). Clips aus anderen Songs müssen auf das Raster passen, siehe [Clips](#songformat-clips).

### Raster

Die Noten einer Spur stehen in einem Codeblock mit der Sprache `grid`. Die Zeichen sind durch Leerzeichen getrennt, Zeilenumbrüche dürfen beliebig stehen. Jedes Zeichen außer dem Taktstrich ist ein Step:

- `.` ist eine Pause (ein Step).
- `-` hält die Note davor einen Step länger. Ohne vorhergehende Note ist es ein Fehler.
- Ein senkrechter Strich ist ein Taktstrich. Er dient nur der Lesbarkeit und zählt nicht.
- Eine Note besteht aus Buchstabe `A` bis `G`, optional `#` oder `b` und einer Oktave (eine Ziffer, auch negativ): `C4`, `F#3`, `Bb2`.
- Drums (nur bei `instrument: drums`): `k` Kick, `s` Snare, `c` Clap, `h` Hi-Hat, `o` Open Hat.

Eine neue Note beendet die vorige. Ein `.` beendet sie ebenfalls, danach darf kein `-` folgen. Wie lange eine Note klingt, bestimmt ihre Länge in Steps mal `gate` der Spur. Ein Taktstrich muss von Leerzeichen umgeben sein, `k|` wäre ein unbekanntes Zeichen.

Ein Takt bei `grid: 16`, eine Bassfigur mit gehaltenen Noten und Pausen:

```text
E2 - . E2 | G2 - - . | A2 . B2 - | . . E3 -
```

Die Stimmung ist gleichstufig mit `A4 = 440 Hz`. Höchstens {{SongParser::MAX_STEPS}} Steps je Spur. Der Build meldet eine Warnung, wenn Spuren unterschiedlich lang sind oder die Länge kein ganzes Vielfaches des Takts ist.

### Clips

Ein Clip kopiert Noten einer Spur aus einem anderen Song. Er steht in der Frontmatter der Spur unter `clips`:

| Schlüssel | Bedeutung | Grenzen |
| --- | --- | --- |
| `from` | `prefix/name#spur` | Pflicht |
| `bars` oder `steps` | Ausschnitt, Zahl oder Bereich wie `3-6` | nicht beides |
| `at` oder `at-step` | Ziel im Takt oder im Step | 1 bis {{SongParser::MAX_AT}}, nicht beides. Ohne Angabe direkt hinter dem Vorigen |
| `transpose` | Halbtöne | −{{SongParser::MAX_TRANSPOSE}} bis {{SongParser::MAX_TRANSPOSE}}, nicht bei Drums |
| `repeat` | Wiederholungen | 1 bis {{SongParser::MAX_REPEAT}} |

`at_step` ist der alte Name von `at-step` und gilt weiter. Passt das Raster der Quelle nicht zum Raster des Songs, meldet der Build das. Ganze Takte gehen immer.

## Layer-Song

Gelesen ebenfalls von [SongParser](#songparser), das Ergebnis enthält [SongLayer](#songlayer)s.

Mehrere Songs übereinander. Jeder Block ist ein Layer, Marker `<!-- layer: prefix/name -->`. Höchstens {{SongParser::MAX_LAYERS}} Layer und {{SongParser::MAX_LAYER_TRACKS}} Spuren insgesamt. Der Kopf ist der des Songs. `bpm` ist standardmäßig das Tempo des ersten Layers, `grid` das gemeinsame Raster der Layer (höchstens {{SongParser::GRID_MAX}}).

| Schlüssel im Layer | Bedeutung | Grenzen | Standard |
| --- | --- | --- | --- |
| `name` | Anzeigename | 1 bis 32 Zeichen | Titel des Quell-Songs |
| `tracks` | Auswahl der Spuren (Liste von Spurnamen) | | alle |
| `at` | Start im Takt | 1 bis {{SongParser::MAX_AT}} | 1 |
| `bars` | Ausschnitt | Zahl oder Bereich | alles |
| `repeat` | Wiederholungen oder `fill` | 1 bis {{SongParser::MAX_REPEAT}} | 1 |
| `transpose` | Halbtöne | −{{SongParser::MAX_TRANSPOSE}} bis {{SongParser::MAX_TRANSPOSE}} | 0 |

Mindestens ein Layer braucht eine feste Länge, also nicht `fill`.

```markdown
---
kind: layer
title: Erster Puls, doppelt
remix: true
---
<!-- layer: kchaot/erster-puls -->
---
tracks: [kick, bass]
repeat: 4
---
```

## Set und Album

Gelesen von [SetParser](#setparser), das Ergebnis ist ein [Set](#set) aus [SetPart](#setpart)s. Das Album baut die Site: [Builder](#builder).

Ein Set spielt Songs nacheinander. Jeder Abschnitt hat den Marker `<!-- part: prefix/name -->`, höchstens {{SetParser::MAX_PARTS}} Abschnitte. Im Kopf gibt es `loop` (Standard `false`). Im Abschnitt:

| Schlüssel | Bedeutung | Grenzen | Standard |
| --- | --- | --- | --- |
| `bars` | Ausschnitt | Zahl oder Bereich wie `1-8` | alles |
| `repeat` | Wiederholungen | 1 bis {{SongParser::MAX_REPEAT}} | 1 |

```markdown
---
kind: set
title: Mein Set
---
<!-- part: kchaot/erster-puls -->
---
repeat: 2
---
<!-- part: kchaot/skizzen/01-nur-kick -->
```

Ein **Album** ist ein Ordner mit Songs. Seine optionale `_album.md` kennt `title`, `artist`, `group`, `tags`, `songs` und `loop`. `songs` ist die Reihenfolge als Liste von Dateinamen ohne `.md`, was fehlt, kommt alphabetisch dahinter. `tracks` ist der alte Name von `songs` und gilt weiter, beides zusammen ist ein Fehler. Sessions und Links im Ordner gehören zum Album, spielen aber nicht mit.

## Session und Link

Beide bestehen nur aus dem Kopf und dem Text darunter. Gelesen von [SessionParser](#sessionparser) und [LinkParser](#linkparser).

**Session:** zwei Decks für den Mixer.

| Schlüssel | Bedeutung | Grenzen | Standard |
| --- | --- | --- | --- |
| `a`, `b` | Song für Deck A und B | Pflicht, `prefix/name` | |
| `start` | Takt von Deck A, auf dem Deck B einsteigt | 1 bis Takte von A | 1 |
| `auto` | Crossfader fährt von allein | `true` oder `false` | `true` |
| `fader` | Stand des Crossfaders: 0 nur A, 100 nur B | 0 bis 100, ganze Zahl | 50 |

<!-- code: var/songs/kchaot/kasinopunk/kein-plan-auf-rot.md -->

**Link:** verweist auf eine fertige Aufnahme.

| Schlüssel | Bedeutung | Grenzen |
| --- | --- | --- |
| `release` | Adresse oder Block (wie beim Song) | Pflicht |
| `from` | Songs, aus denen die Aufnahme entstanden ist | Liste, höchstens {{LinkParser::MAX_FROM}}, `prefix/name` |

```markdown
---
kind: link
title: Meine Aufnahme
release: https://example.org/aufnahme.mp3
from: [kchaot/erster-puls]
---
```

## Profil des Autors

Die Datei `_profile.md` im Wurzelordner einer Quelle, gelesen vom [Builder](#builder):

| Schlüssel | Bedeutung | Grenzen |
| --- | --- | --- |
| `tagline` | Untertitel | bis 80 Zeichen |
| `author` | Anzeigename | bis 60 Zeichen, ohne `< > & "` |
| `url` | Adresse | `http` oder `https`, bis 200 Zeichen |

Der Text darunter erscheint auf der Autorenseite. Ist die Datei fehlerhaft, übergeht der Build sie mit einer Meldung.

<!-- next: bin -->
---
title: bin
group: Verzeichnisse
---
Die Skripte im Ordner `bin/`. Die Composer-Skripte (`composer qa` und andere) rufen sie auf.

```bash
composer qa          # Format prüfen, Lint, Analyse, Klassenbeschreibungen, Build
composer fmt         # Code formatieren
composer compare     # Ausgabe gegen einen Git-Stand vergleichen
```

## build.php

Baut die ganze Seite nach `tmp/public`. Optionen:

- `--local-only` holt keine Git-Quellen neu, sondern nimmt, was im Cache liegt (`composer build` setzt sie).
- `--serve` startet nach dem Build eine Vorschau auf `http://localhost:8000`.

Eine unbekannte Option beendet das Skript mit Code 2, ein Fehler in den Daten mit Code 1.

```bash
php bin/build.php                  # alles bauen, Git-Quellen neu holen
php bin/build.php --local-only     # nur mit dem Cache, ohne Netz
php bin/build.php --local-only --serve   # bauen und auf http://localhost:8000 ansehen
```

## docs.php

Schreibt die Klassenbeschreibungen als Markdown, auf die Konsole oder in die angegebene Datei. Mit `--check` meldet es Klassen ohne Beschreibung und endet dann mit Code 1. `composer qa` und die CI rufen den Check auf.

```bash
php bin/docs.php                   # Markdown der Klassenbeschreibungen auf die Konsole
php bin/docs.php tmp/code.md       # oder in eine Datei
php bin/docs.php --check           # Klassen ohne Beschreibung? Dann Code 1
```

## compare.php

Baut einen Git-Stand (Standard: `HEAD`) und den Arbeitsstand und vergleicht jede Ausgabedatei per SHA-1. Code 0 heißt: Die Seite ist bitgleich. So lässt sich nach einem Umbau zeigen, dass sich die Ausgabe nicht geändert hat.

```bash
php bin/build.php --local-only     # legt .cache/sources an, falls es fehlt
php bin/compare.php                # Arbeitsstand gegen HEAD
php bin/compare.php origin/main    # gegen den Stand auf main
```

## pull.sh

Läuft auf dem Server, per Cron alle fünf Minuten. Es holt das Release `build` von GitHub, prüft die Prüfsumme (`public.tar.gz.sha256`), packt es nach `releases/<Hash>` aus und stellt den Link `current` atomar um. Die letzten Stände bleiben liegen, damit sich zurückrollen lässt. Gesteuert wird es über Umgebungsvariablen:

| Variable | Bedeutung |
| --- | --- |
| `CHIPTUNE_BASE` | Pflicht. Ordner mit `releases/` und `current`. |
| `CHIPTUNE_REPO` | Repo mit dem Release, Standard `kchaot/chiptune`. |
| `CHIPTUNE_URL` | Download-Adresse, Standard das Release `build` des Repos. |
| `CHIPTUNE_KEEP` | Wie viele alte Stände bleiben, Standard 3. |

```bash
# einmal von Hand
CHIPTUNE_BASE=$HOME/www/chiptune bash bin/pull.sh

# alle fünf Minuten per Cron
*/5 * * * * CHIPTUNE_BASE=$HOME/www/chiptune /pfad/zu/pull.sh >> /pfad/zu/pull.log 2>&1
```

<!-- next: etc -->
---
title: etc
group: Verzeichnisse
---
Die Einstellungen. Beide Dateien sind JSON, und der Build prüft sie streng.

## site.json

| Feld | Bedeutung |
| --- | --- |
| `title` | Name der Seite. |
| `tagline` | Untertitel. |
| `noindex` | `true` sperrt Suchmaschinen (`robots.txt` und Meta-Angabe). |
| `prompt` | Der Hostname im Kopf, zum Beispiel `chiptune.kchaot.de`. |
| `base_path` | Unterpfad der Seite, mit `/` am Anfang und am Ende. |
| `assets_base` | Wo CSS, JavaScript und Fonts liegen (Pfad oder `https`-Adresse). Standard ist `<base_path>_cdn/`. |
| `parent` | Link zur übergeordneten Seite im Fuß, mit `title` und `url`. |
| `outro` | Schlusszeile im Fuß. |

## sources.json

Eine Liste von Quellen. Jede ist ein lokaler Ordner oder ein Git-Repo, das in `.cache/sources` landet.

| Feld | Bedeutung |
| --- | --- |
| `name` | Eindeutiger Name der Quelle. |
| `prefix` | Erster Teil der Adresse, also `prefix/slug/`. |
| `title` | Anzeigename des Autors oder der Gruppe. |
| `path` | Ordner mit den Dateien, im Repo oder lokal. |
| `repo`, `ref` | Git-Repo und Zweig. Ohne `repo` ist die Quelle lokal. |
| `media` | Hosts, von denen Audio und Bilder stammen dürfen. |

Zum Beispiel eine lokale Quelle und eine aus einem Git-Repo:

```json
{
    "sources": [
        { "name": "kchaot", "prefix": "kchaot", "title": "KChaot", "path": "var/songs/kchaot", "media": ["kchaot.de"] },
        { "name": "anonig", "prefix": "ab", "title": "AnonigBits", "path": "anonig", "repo": "ek9r/chiptune-data", "ref": "main", "media": ["8rnr.t1s.ch"] }
    ]
}
```

<!-- next: lib -->
---
title: lib
group: Verzeichnisse
---
Der gesamte PHP-Code liegt unter `lib/`, in drei Ordnern. Die Richtung ist **IN → OUT**: Das Format liest, die Site baut, und Gemeinsames brauchen beide. Format und Gemeinsames kennen die Site nicht. Was die Parser lesen, beschreibt der Tab [Songformat](#tab-songformat).

## Das Format (IN)

Liest Markdown und macht daraus unveränderliche Value Objects. Es kennt weder Seiten noch Dateisystem und ließe sich als eigenes Paket herauslösen.

## Die Site (OUT)

Die Referenz-Implementierung: Sie holt die Quellen, baut daraus die Seite und rendert sie. Eine andere Ausgabe könnte dieselben Value Objects anders verwenden.

## Gemeinsames

Was Format und Site beide brauchen: Fehler beim Bauen und das Säubern von HTML.

<!-- next: var -->
---
title: var
group: Verzeichnisse
---
Die Inhalte. Alles, was sich ändert, ohne dass jemand Code anfasst, liegt hier.

## pages

Die Seiten im Fuß (Format, Kontakt, Weiterkommen). Eine Markdown-Datei pro Seite: `title` und `order` in der Frontmatter, und mit `<!-- tab: name -->` lässt sich die Seite in Reiter teilen.

## songs

Die eigenen Songs, im selben Aufbau wie jede andere Quelle. Ein Song ist eine Markdown-Datei aus mehreren Teilen: Kopf mit Titel und Tempo, dann pro Spur ein `<!-- track: name -->`-Block mit den Einstellungen des Instruments und dem Raster. Alle Schlüssel stehen im [Songformat](#songformat-song), das Raster mit seinen Steps und Takten unter [Steps und Takt](#songformat-steps-und-takt) und [Raster](#songformat-raster).

<!-- code: var/songs/kchaot/erster-puls.md fold -->

Ein Ordner mit `_album.md` ist ein Album.

<!-- code: var/songs/kchaot/skizzen/_album.md -->

Die Datei `_profile.md` im Wurzelordner einer Quelle beschreibt den Autor: `tagline`, `author` und `url` in der Frontmatter, darunter ein kurzer Text für die Autorenseite.

<!-- code: var/songs/kchaot/_profile.md -->

Die `README.md` im Wurzelordner liest der Build nicht. Er überspringt sie wie `LICENSE`, `CHANGELOG` und ähnliche Dateien. Sie ist für Menschen gedacht, die das Repo auf GitHub öffnen, und darf deshalb frei bleiben.

## resources

`style.css`, `player.js`, die Fonts und die `htaccess` für den Server. Der Build hängt jedem Namen einen Hash an und trägt die Prüfsumme als `integrity` ein. Zum Player gibt es einen eigenen Tab: [player.js](#tab-player).

<!-- next: player -->
---
title: player.js
group: resources
---
Der Player gehört zu den Ressourcen der Stage (`var/resources/`, siehe [var](#tab-var)). Die Datei `var/resources/player.js` ist ein einziges JavaScript ohne Abhängigkeiten, rund 3600 Zeilen, und läuft vollständig im Browser: Audio über die Web Audio API, Oberfläche aus einfachen DOM-Elementen und SVG. Die Daten bekommt sie als JSON, das der Build in die Seiten schreibt (`project`, `set`, `mix`). Ohne diese Daten tut sie nichts. Der Build minimiert die Datei, Kommentare fallen dabei weg.

Am Ende der Datei entscheidet, was in der Seite steckt: Mixer (`mix`), Set (`set`) oder einzelner Song (`project`). Die Datei ist in dieser Reihenfolge aufgebaut:

- Zustand und kleine Helfer (`makeState`, `el`, `svgNode`) und die Regler eines Decks (`deskControl`)
- die Audio-Kette: Limiter, Rauschen, Kanal, Bus, und die Stimmen (`tone`, `kick`, `drum`)
- `projectDeck`: ein Deck für einen Song mit Raster, Transport, Planung im Voraus und Synchronisation
- Export als MIDI-Datei und als Strudel-Code
- `studioPanel` und `mountProject` für die Song-Seite
- `buildMachine` und `mountMix` für den Mixer, `mountSet` für Sets und Alben

## Zustand eines Decks

Jedes Deck hält seinen Zustand in einem Objekt: Tempo (`rate`), Feinabgleich (`nudge`), die Effekte (`fx`), die abgeschalteten Bänder (`kill`) und die Schleife (`hold`).

<!-- code: var/resources/player.js#state lines -->

## Ein Ton

Eine Note ist ein Oszillator mit einer Hüllkurve. `gate` bestimmt, wie viel der Notenlänge klingt. Geplant wird der Ton auf der Zeitachse des Audio-Kontexts (`when`), nicht auf der Uhr der Seite.

<!-- code: var/resources/player.js#tone lines -->

## Planung im Voraus

`tick()` schaut ein Stück voraus (`LOOKAHEAD`, im Mixer kürzer, bei versteckter Seite länger) und plant alle Noten, die in dieses Fenster fallen. Damit läuft die Musik auch dann sauber, wenn der Browser die Seite im Hintergrund drosselt.

<!-- code: var/resources/player.js#tick lines fold -->
