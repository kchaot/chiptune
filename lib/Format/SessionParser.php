<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Common\BuildError;
use Chiptune\Common\HtmlSanitizer;
use Tempest\Markdown\Markdown;

/**
 * Liest Sessions aus Markdown.
 *
 * Die Datei besteht nur aus dem Kopf: zwei Songs (`a`, `b`), `start`, `auto` und `fader`. Das
 * Ergebnis ist eine `Session`.
 */
final class SessionParser
{
    public const FORMAT = 1;
    public const MAX_BYTES = 16_384;

    public function __construct(
        private readonly SongParser $songs,
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve): Session
    {
        if (strlen($source) > self::MAX_BYTES) {
            throw new BuildError(sprintf('Die Datei ist größer als %d KB.', self::MAX_BYTES / 1024));
        }

        $parsed = $this->markdown->parse($source);
        /** @var array<string, mixed> $meta */
        $meta = $parsed->frontmatter;

        if (($meta['format'] ?? self::FORMAT) !== self::FORMAT) {
            throw new BuildError('Nicht unterstütztes format: ' . (string) json_encode($meta['format']) . '. Unterstützt wird format: ' . self::FORMAT . '.');
        }

        $unknown = array_diff(array_keys($meta), ['kind', 'format', 'title', 'artist', 'group', 'tags', 'a', 'b', 'start', 'auto', 'fader']);

        if ($unknown !== []) {
            throw new BuildError(
                'Session: unbekannter Schlüssel „'
                . implode('“, „', array_map('strval', $unknown))
                . '“ (erlaubt: kind, format, title, artist, group, tags, a, b, start, auto, fader).',
            );
        }

        $decks = [];

        foreach (['a' => 'Deck A', 'b' => 'Deck B'] as $key => $label) {
            $ref = $meta[$key] ?? null;

            if (!is_string($ref) || preg_match('/^([a-z0-9][a-z0-9-]*)\/([a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?)$/', trim($ref)) !== 1) {
                throw new BuildError("Session: „{$key}“ ({$label}) muss ein Song in der Form prefix/slug sein.");
            }

            $resolved = $resolve(trim($ref));
            $song = $resolved['song'];
            $decks[$key] = new SessionDeck(
                ref: trim($ref),
                prefix: $resolved['prefix'],
                slug: $resolved['slug'],
                title: $song->title,
                bpm: $song->bpm,
                bars: $song->bars,
                stepsPerBar: $song->stepsPerBar,
            );
        }

        $start = $meta['start'] ?? 1;

        if (!is_int($start) || $start < 1 || $start > $decks['a']->bars) {
            throw new BuildError("Session: „start“ ist der Takt von Deck A, auf dem Deck B einsteigt, eine ganze Zahl von 1 bis {$decks['a']->bars}.");
        }

        $auto = $this->songs->flag($meta, 'auto', true, 'Session');

        $fader = $meta['fader'] ?? 50;

        if (!is_int($fader) || $fader < 0 || $fader > 100) {
            throw new BuildError('Session: „fader“ muss eine ganze Zahl von 0 (nur Deck A) bis 100 (nur Deck B) sein.');
        }

        $credits = [];

        foreach ($decks as $deck) {
            $credits[$deck->ref] = ['prefix' => $deck->prefix, 'slug' => $deck->slug, 'title' => $deck->title];
        }

        $head = $this->songs->describe($meta, $fallbackTitle);

        return new Session(
            title: $head['title'],
            artist: $head['artist'],
            group: $head['group'],
            tags: $head['tags'],
            a: $decks['a'],
            b: $decks['b'],
            start: $start,
            auto: $auto,
            fader: $fader,
            notesHtml: $this->sanitizer->clean($parsed->html),
            credits: array_values($credits),
        );
    }
}
