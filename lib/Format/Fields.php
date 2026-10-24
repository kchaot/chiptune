<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Tempest\Markdown\Markdown;
use Tempest\Markdown\ParsedMarkdown;

/**
 * Die Prüfungen, die alle Parser des Formats teilen.
 *
 * Größe der Datei, Version, unbekannte Schlüssel, Verweise der Form `prefix/name`, Remix-Erlaubnis
 * und die Felder des gemeinsamen Kopfs (`title`, `artist`, `group`, `tags`) stehen hier einmal.
 * Zahlen, Wahrheitswerte und Bereiche liest `Fields` mit denselben Fehlermeldungen für alle Dateitypen.
 * Alle Methoden werfen einen `BuildError` und haben keinen Zustand.
 */
final class Fields
{
    public const FORMAT = 1;
    public const MAX_TAGS = 6;
    public const MAX_TITLE = 80;
    public const MAX_ARTIST = 60;
    public const MAX_GROUP = 40;

    private const REF_BODY = '([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)';

    /** Verweis auf einen Eintrag: `prefix/name` oder `prefix/album/name`. */
    public const REF = '/^' . self::REF_BODY . '$/';

    /** Verweis auf eine Spur eines Songs: `prefix/name#spur`. */
    public const TRACK_REF = '/^' . self::REF_BODY . '#(.{1,32})$/';

    /**
     * Die Datei darf höchstens `$max` Bytes groß sein.
     */
    public static function size(string $source, int $max): void
    {
        if (strlen($source) > $max) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', $max / 1024));
        }
    }

    /**
     * Ein fehlendes `format` gilt als die aktuelle Version, jede andere ist ein Fehler.
     *
     * @param array<string, mixed> $meta
     */
    public static function version(array $meta): void
    {
        $format = $meta['format'] ?? self::FORMAT;

        if ($format !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . (string) json_encode($format) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }
    }

    /**
     * Der Kopf darf nur bekannte Schlüssel haben. `$hidden` sind alte Namen, die gelten, aber nicht genannt werden.
     *
     * @param array<array-key, mixed> $given
     * @param list<string> $allowed
     * @param list<string> $hidden
     */
    public static function keys(array $given, array $allowed, string $where, array $hidden = []): void
    {
        $unknown = array_diff(array_keys($given), $allowed, $hidden);

        if ($unknown !== []) {
            throw new BuildError(
                "{$where}: unbekannter Schlüssel „" . implode('“, „', array_map('strval', $unknown)) . '“ (erlaubt: ' . implode(', ', $allowed) . ').',
            );
        }
    }

    /**
     * Liest eine Datei in Kopf und Blöcke. Der Kopf ist der Text vor dem ersten Marker und fehlt, wenn die Datei gleich mit einem Marker beginnt.
     *
     * @return array{0: ParsedMarkdown|null, 1: list<ParsedMarkdown>}
     */
    public static function blocks(Markdown $markdown, string $source, string $marker): array
    {
        $chunks = [];

        foreach ($markdown->parseMany($source, 'song', $marker) as $chunk) {
            $chunks[] = $chunk;
        }

        $head = null;

        if ($chunks !== [] && ($chunks[0]->name === null || $chunks[0]->name === 'song-1')) {
            $head = array_shift($chunks);
        }

        return [$head, $chunks];
    }

    /**
     * Prüft den Namen im Marker `<!-- layer: prefix/name -->` und gibt Präfix und Rest zurück.
     *
     * @return array{0: string, 1: string}
     */
    public static function marker(string $ref, string $where, string $marker): array
    {
        $m = [];

        if (preg_match(self::REF, $ref, $m) !== 1) {
            throw new BuildError("{$where}: Der Marker muss die Form <!-- {$marker}: prefix/slug --> haben.");
        }

        return [$m[1], $m[2]];
    }

    /**
     * Ein Song eines anderen Autors darf nur verwendet werden, wenn er `remix: true` hat.
     */
    public static function mayRemix(string $where, string $ref, string $prefix, string $owner, ?bool $remix): void
    {
        if ($prefix !== $owner && $remix !== true) {
            throw new BuildError("{$where}: „{$ref}“ erlaubt kein Remixen. Der Autor müsste „remix: true“ in seinen Song schreiben.");
        }
    }

    /**
     * Ein Wahrheitswert, der fehlen darf. Ist er gesetzt, muss er `true` oder `false` sein.
     *
     * @param array<string, mixed> $meta
     */
    public static function optionalFlag(array $meta, string $key, string $where): ?bool
    {
        $value = $meta[$key] ?? null;

        if ($value !== null && !is_bool($value)) {
            throw new BuildError("{$where}: „{$key}“ muss true oder false sein.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function flag(array $meta, string $key, bool $default, string $where): bool
    {
        $value = $meta[$key] ?? $default;

        if (!is_bool($value)) {
            throw new BuildError("{$where}: „{$key}“ muss true oder false sein.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function number(array $meta, string $key, float $default, float $min, float $max, string $where = 'Song'): float
    {
        $value = $meta[$key] ?? $default;

        if (!is_int($value) && !is_float($value)) {
            throw new BuildError("{$where}: „{$key}“ muss eine Zahl sein.");
        }

        if ($value < $min || $value > $max) {
            throw new BuildError("{$where}: „{$key}“ muss zwischen {$min} und {$max} liegen.");
        }

        return (float) $value;
    }

    public static function integer(mixed $value, string $where, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new BuildError("{$where} muss eine ganze Zahl von {$min} bis {$max} sein.");
        }

        return $value;
    }

    /**
     * Eine Zahl oder ein Bereich wie `3-6`. Das Ergebnis ist von und bis, beginnend bei 1.
     *
     * @return array{0: int, 1: int}
     */
    public static function range(mixed $value, string $where): array
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        $m = [];
        if (!is_string($value) || preg_match('/^\s*(\d{1,5})\s*(?:-\s*(\d{1,5}))?\s*$/', $value, $m) !== 1) {
            throw new BuildError("{$where} muss eine Zahl oder ein Bereich wie 3-6 sein.");
        }

        $a = (int) $m[1];
        $b = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $a;

        if ($a < 1 || $b < $a) {
            throw new BuildError("{$where}: Der Bereich muss bei 1 beginnen und aufsteigen.");
        }

        return [$a, $b];
    }

    /**
     * Text mit höchstens `$max` Zeichen, Leerraum wird zu einem Leerzeichen zusammengezogen.
     *
     * @param array<string, mixed> $meta
     */
    public static function text(array $meta, string $key, int $max, string $default = ''): string
    {
        $value = $meta[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new BuildError("Song: „{$key}“ muss Text sein.");
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value) > $max) {
            throw new BuildError("Song: „{$key}“ ist länger als {$max} Zeichen.");
        }

        return $value;
    }

    /**
     * Der gemeinsame Kopf aller Dateitypen: Titel (ohne Angabe der Dateiname), Künstler, Gruppe und Tags.
     *
     * @param array<string, mixed> $meta
     * @return array{title: string, artist: string, group: string, tags: list<string>}
     */
    public static function describe(array $meta, string $fallbackTitle): array
    {
        return [
            'title' => self::text($meta, 'title', self::MAX_TITLE, $fallbackTitle) ?: $fallbackTitle,
            'artist' => self::text($meta, 'artist', self::MAX_ARTIST),
            'group' => self::text($meta, 'group', self::MAX_GROUP),
            'tags' => self::tags($meta['tags'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $tags): array
    {
        if (!is_array($tags) || count($tags) > self::MAX_TAGS) {
            throw new BuildError(sprintf('Song: „tags“ muss eine Liste mit höchstens %d Einträgen sein.', self::MAX_TAGS));
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = is_int($tag) ? (string) $tag : $tag;

            if (!is_string($tag) || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} -]{0,23}$/u', $tag) !== 1) {
                throw new BuildError('Song: ungültiger Tag. Erlaubt sind Buchstaben, Ziffern, Leerzeichen und Bindestrich, höchstens 24 Zeichen.');
            }

            $clean[] = $tag;
        }

        return $clean;
    }
}
