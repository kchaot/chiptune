<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class SetPart
{
    /**
     * @param list<Track> $tracks
     */
    public function __construct(
        public string $ref,
        public string $prefix,
        public string $slug,
        public string $title,
        public string $artist,
        public float $bpm,
        public int $stepsPerBar,
        public int $stepsPerBeat,
        public float $stepSeconds,
        public int $steps,
        public int $start,
        public int $stop,
        public int $startBar,
        public int $repeat,
        public array $tracks,
        public string $notesHtml,
    ) {}
}
