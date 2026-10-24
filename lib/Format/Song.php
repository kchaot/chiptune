<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Ein Song: Tracks mit Noten auf einem festen Raster.
 *
 * Das Raster hat `stepsPerBeat` Steps pro Schlag und vier Schläge pro Bar, ein Step dauert
 * `stepSeconds` Sekunden. Ein Song entsteht aus Noten, die direkt in der Datei stehen, oder als
 * Layer-Song aus Tracks anderer Songs (`layers`). Mit `remix` erlaubt der Autor anderen, Material
 * aus dem Song zu übernehmen.
 */
final readonly class Song implements Described
{
    /**
     * @param list<string> $tags
     * @param list<Track> $tracks
     * @param list<string> $warnings
     * @param list<Credit> $credits
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
