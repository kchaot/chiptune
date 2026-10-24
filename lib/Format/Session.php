<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Format\Session\SessionDeck;

/**
 * Eine Session hält den Startzustand des Mixers fest.
 *
 * Dazu gehören zwei Songs (Deck A und B), der Takt von Deck A, auf dem Deck B einsteigt (`start`),
 * Auto-Sync (`auto`) und die Stellung des Crossfaders (`fader`, 0 = nur Deck A, 100 = nur Deck B).
 */
final readonly class Session implements Described
{
    /**
     * @param list<string> $tags
     * @param list<Credit> $credits
     * @param list<string> $warnings
     */
    public function __construct(
        public string $title,
        public string $artist,
        public string $group,
        public array $tags,
        public SessionDeck $a,
        public SessionDeck $b,
        public int $start,
        public bool $auto,
        public int $fader,
        public string $notesHtml,
        public array $credits,
        public array $warnings = [],
    ) {}
}
