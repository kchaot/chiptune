<?php

declare(strict_types=1);

namespace Chiptune;

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
