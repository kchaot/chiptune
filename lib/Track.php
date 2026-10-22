<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Track
{
    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $refs
     */
    public function __construct(
        public string $name,
        public string $instrument,
        public float $volume,
        public float $gate,
        public float $pw,
        public int $steps,
        public array $events,
        public array $refs,
        public string $notesHtml,
        public ?TrackGroup $group = null,
    ) {}

    public function isDrums(): bool
    {
        return $this->instrument === 'drums';
    }
}
