<?php

declare(strict_types=1);

namespace Chiptune\Format\Media;

use Chiptune\Common\BuildError;
use Chiptune\Format\Fields;
use Chiptune\Format\Media;

/**
 * Liest die Aufnahmen aus dem Kopf einer Datei: `hq`, `hq-offset` und `release`.
 *
 * `release` darf eine Adresse oder ein Block mit Cover, Titel, Datum, Label, Katalognummer, Lizenz und
 * Credits sein. Das Ergebnis ist ein `Media`. Ob eine Adresse hier abspielbar ist, entscheidet erst
 * später die Quelle über ihre freigegebenen Hosts.
 */
final class MediaParser
{
    /**
     * @param array<string, mixed> $meta
     */
    public function parse(array $meta): Media
    {
        $urls = [];
        $release = $meta['release'] ?? null;
        $details = [];

        if (is_array($release)) {
            $unknown = array_diff(array_keys($release), ['url', 'cover', 'title', 'date', 'label', 'catalog', 'license', 'credits']);

            if ($unknown !== []) {
                throw new BuildError(
                    'Song: „release“ kennt nur url, cover, title, date, label, catalog, license und credits (unbekannt: ' . implode(', ', array_map('strval', $unknown)) . ').',
                );
            }

            if (!isset($release['url'])) {
                throw new BuildError('Song: „release“ braucht eine „url“.');
            }

            /** @var array<string, mixed> $release */
            $details = $release;
            $release = $release['url'];
        }

        foreach (['hq' => $meta['hq'] ?? null, 'release' => $release, 'cover' => $details['cover'] ?? null] as $key => $url) {
            if ($url === null) {
                continue;
            }

            $name = $key === 'hq' ? 'hq' : ($key === 'release' ? 'release' : 'release: cover');

            if (
                !is_string($url)
                || strlen($url) > 300
                || preg_match(
                    '~^https://(?=[a-z0-9.-]{3,100}(?::\d{1,5})?(?:/|$))[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(?::\d{1,5})?(?:/[^\s<>"\'\\\\\x00-\x1F\x7F]*)?$~',
                    $url,
                ) !== 1
            ) {
                throw new BuildError("Song: „{$name}“ muss eine https-Adresse mit Kleinbuchstaben im Hostnamen sein (höchstens 300 Zeichen, ohne Leerzeichen).");
            }

            $urls[$key] = $url;
        }

        $info = $this->releaseInfo($details);
        $hqOffset = null;

        if (isset($meta['hq-offset'])) {
            if (!isset($urls['hq'])) {
                throw new BuildError('Song: „hq-offset“ gehört zu „hq“.');
            }

            $hqOffset = Fields::number($meta, 'hq-offset', 0, -10, 10);
        }

        return new Media(
            hq: $urls['hq'] ?? null,
            hqOffset: $hqOffset,
            release: $urls['release'] ?? null,
            cover: $urls['cover'] ?? null,
            info: $info,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function releaseInfo(array $details): ReleaseInfo
    {
        $texts = [];

        foreach (['title' => 120, 'label' => 80, 'catalog' => 40, 'license' => 60] as $key => $max) {
            if (isset($details[$key])) {
                $texts[$key] = Fields::text($details, $key, $max);
            }
        }

        $formatted = null;

        if (isset($details['date'])) {
            $date = $details['date'];

            if (is_int($date) && $date > 0) {
                $date = gmdate('Y-m-d', $date);
            }

            if (!is_string($date) || preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $date) !== 1) {
                throw new BuildError('Song: „release: date“ muss JJJJ, JJJJ-MM oder JJJJ-MM-TT sein.');
            }

            $formatted = $date;
        }

        $roles = [];

        if (isset($details['credits'])) {
            $credits = $details['credits'];

            if (!is_array($credits) || array_is_list($credits) || count($credits) > 12) {
                throw new BuildError('Song: „release: credits“ muss eine Zuordnung Rolle: Name sein (höchstens 12 Rollen).');
            }

            foreach ($credits as $role => $names) {
                $names = is_array($names) ? $names : [$names];

                if (!is_string($role) || $names === [] || count($names) > 6 || !array_is_list($names)) {
                    throw new BuildError('Song: „release: credits“ braucht je Rolle einen Namen oder eine Liste mit höchstens 6 Namen.');
                }

                $role = trim((string) preg_replace('/\s+/u', ' ', $role));

                if ($role === '' || mb_strlen($role) > 40) {
                    throw new BuildError('Song: „release: credits“ hat eine leere oder zu lange Rolle (höchstens 40 Zeichen).');
                }

                foreach ($names as $name) {
                    if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 80) {
                        throw new BuildError("Song: „release: credits“, Rolle „{$role}“: jeder Name ist Text mit höchstens 80 Zeichen.");
                    }

                    $roles[$role][] = trim((string) preg_replace('/\s+/u', ' ', $name));
                }
            }
        }

        return new ReleaseInfo(
            title: $texts['title'] ?? null,
            label: $texts['label'] ?? null,
            catalog: $texts['catalog'] ?? null,
            license: $texts['license'] ?? null,
            date: $formatted,
            credits: $roles,
        );
    }
}
