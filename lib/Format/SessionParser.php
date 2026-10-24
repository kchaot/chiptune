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
    public const MAX_BYTES = 16_384;

    public function __construct(
        private readonly Markdown $markdown = new Markdown(null),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {}

    /**
     * @param \Closure(string): array{prefix: string, slug: string, song: Song} $resolve
     */
    public function parse(string $source, string $fallbackTitle, \Closure $resolve): Session
    {
        Fields::size($source, self::MAX_BYTES);

        $parsed = $this->markdown->parse($source);
        /** @var array<string, mixed> $meta */
        $meta = $parsed->frontmatter;

        Fields::version($meta);

        Fields::keys($meta, ['kind', 'format', 'title', 'artist', 'group', 'tags', 'a', 'b', 'start', 'auto', 'fader'], 'Session');

        $decks = [];

        foreach (['a' => 'Deck A', 'b' => 'Deck B'] as $key => $label) {
            $ref = $meta[$key] ?? null;

            if (!is_string($ref) || preg_match(Fields::REF, trim($ref)) !== 1) {
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

        $auto = Fields::flag($meta, 'auto', true, 'Session');

        $fader = $meta['fader'] ?? 50;

        if (!is_int($fader) || $fader < 0 || $fader > 100) {
            throw new BuildError('Session: „fader“ muss eine ganze Zahl von 0 (nur Deck A) bis 100 (nur Deck B) sein.');
        }

        $credits = [];

        foreach ($decks as $deck) {
            $credits[$deck->ref] = ['prefix' => $deck->prefix, 'slug' => $deck->slug, 'title' => $deck->title];
        }

        $head = Fields::describe($meta, $fallbackTitle);

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
