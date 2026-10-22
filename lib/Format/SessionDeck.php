<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Ein Deck einer Session: der Song mit den Angaben, die der Mixer zum Einstellen braucht.
 *
 * Das sind Tempo, Länge in Bars und Steps pro Bar.
 */
final readonly class SessionDeck
{
    public function __construct(
        public string $ref,
        public string $prefix,
        public string $slug,
        public string $title,
        public float $bpm,
        public int|float $bars,
        public int $stepsPerBar,
    ) {}
}
