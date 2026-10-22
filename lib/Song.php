<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Song implements Described
{
    /**
     * @param list<string> $tags
     * @param list<Track> $tracks
     * @param list<string> $warnings
     * @param list<array{prefix: string, slug: string, title: string}> $credits
     * @param list<SongLayer> $layers
     */
    public function __construct(
        public string $title,
        public string $artist,
        public string $group,
        public array $tags,
        public float $bpm,
        public int $stepsPerBeat,
        public int $stepsPerBar,
        public float $stepSeconds,
        public bool $loop,
        public int $steps,
        public int|float $bars,
        public array $tracks,
        public array $warnings,
        public string $notesHtml,
        public Media $media,
        public ?bool $remix,
        public array $credits,
        public array $layers = [],
    ) {}

    public function withMedia(Media $media): self
    {
        return clone($this, ['media' => $media]);
    }
}
