---
title: Code-Übersicht
---
Für alle, die am Code mitbauen. Die Seite entsteht bei jedem Build: Dieser Text kommt aus `var/docs.md`, die Klassen darunter aus den Beschreibungen in `lib/`. Die Tabs spiegeln die Ordner des Repos.

<!-- next: lib -->
---
title: lib
---
Der gesamte PHP-Code liegt unter `lib/`, in drei Ordnern. Die Richtung ist **IN → OUT**: Das Format liest, die Site baut, und Gemeinsames brauchen beide. Format und Gemeinsames kennen die Site nicht.

## Das Format (IN)

Liest Markdown und macht daraus unveränderliche Value Objects. Es kennt weder Seiten noch Dateisystem und ließe sich als eigenes Paket herauslösen.

## Die Site (OUT)

Die Referenz-Implementierung: Sie holt die Quellen, baut daraus die Seite und rendert sie. Eine andere Ausgabe könnte dieselben Value Objects anders verwenden.

## Gemeinsames

Was Format und Site beide brauchen: Fehler beim Bauen und das Säubern von HTML.

<!-- next: bin -->
---
title: bin
---
Die Skripte im Ordner `bin/`. Die Composer-Skripte (`composer qa` und andere) rufen sie auf.

## build.php

Baut die ganze Seite nach `tmp/public`. Optionen:

- `--local-only` holt keine Git-Quellen neu, sondern nimmt, was im Cache liegt (`composer build` setzt sie).
- `--serve` startet nach dem Build eine Vorschau auf `http://localhost:8000`.

Eine unbekannte Option beendet das Skript mit Code 2, ein Fehler in den Daten mit Code 1.

## docs.php

Schreibt die Klassenbeschreibungen als Markdown, auf die Konsole oder in die angegebene Datei. Mit `--check` meldet es Klassen ohne Beschreibung und endet dann mit Code 1. `composer qa` und die CI rufen den Check auf.

## compare.php

Baut einen Git-Stand (Standard: `HEAD`) und den Arbeitsstand und vergleicht jede Ausgabedatei per SHA-1. Code 0 heißt: Die Seite ist bitgleich. So lässt sich nach einem Umbau zeigen, dass sich die Ausgabe nicht geändert hat.

## pull.sh

Läuft auf dem Server, per Cron alle fünf Minuten. Es holt das Release `build` von GitHub, prüft die Prüfsumme (`public.tar.gz.sha256`), packt es nach `releases/<Hash>` aus und stellt den Link `current` atomar um. Die letzten Stände bleiben liegen, damit sich zurückrollen lässt. Gesteuert wird es über Umgebungsvariablen:

| Variable | Bedeutung |
| --- | --- |
| `CHIPTUNE_BASE` | Pflicht. Ordner mit `releases/` und `current`. |
| `CHIPTUNE_REPO` | Repo mit dem Release, Standard `kchaot/chiptune`. |
| `CHIPTUNE_URL` | Download-Adresse, Standard das Release `build` des Repos. |
| `CHIPTUNE_KEEP` | Wie viele alte Stände bleiben, Standard 3. |

<!-- next: etc -->
---
title: etc
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

<!-- next: var -->
---
title: var
---
Die Inhalte. Alles, was sich ändert, ohne dass jemand Code anfasst, liegt hier.

## pages

Die Seiten im Fuß (Format, Kontakt, Weiterkommen). Eine Markdown-Datei pro Seite: `title` und `order` in der Frontmatter, und mit `<!-- tab: name -->` lässt sich die Seite in Reiter teilen.

## projects

Die eigenen Songs, im selben Aufbau wie jede andere Quelle. Ein Song ist eine Markdown-Datei aus mehreren Teilen: Kopf mit Titel und Tempo, dann pro Spur ein `<!-- track: name -->`-Block. Ein Ordner mit `_album.md` ist ein Album. `README.md` beschreibt den Autor (`tagline`, `author`, `url`), `_profile.md` ist sein Profil.

## resources

`style.css`, `player.js`, die Fonts und die `htaccess` für den Server. Der Build hängt jedem Namen einen Hash an und trägt die Prüfsumme als `integrity` ein.

<!-- next: player -->
---
title: player.js
---
Der Player in `var/resources/player.js` ist ein einziges JavaScript ohne Abhängigkeiten, rund 3600 Zeilen, und läuft vollständig im Browser: Audio über die Web Audio API, Oberfläche aus einfachen DOM-Elementen und SVG. Die Daten bekommt er als JSON, das der Build in die Seiten schreibt (`project`, `set`, `mix`). Ohne diese Daten tut er nichts.

Der Build minimiert die Datei, Kommentare fallen dabei weg. Eine ausführliche Beschreibung des Aufbaus folgt.
