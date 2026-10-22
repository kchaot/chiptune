<?php

declare(strict_types=1);

namespace Chiptune\Format;

final readonly class TrackGroup
{
    public function __construct(
        public string $title,
        public string $ref,
        public float $bpm,
        public int $at,
        public string $description,
    ) {}
}
