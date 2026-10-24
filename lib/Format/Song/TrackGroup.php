<?php

declare(strict_types=1);

namespace Chiptune\Format\Song;

/**
 * Fasst die Tracks zusammen, die ein Layer-Song aus demselben Quell-Song übernommen hat.
 *
 * Sie trägt Titel und Verweis des Quell-Songs, dessen ursprüngliches Tempo, den Takt (`at`),
 * ab dem der Layer einsteigt, und den Begleittext aus der Datei.
 */
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
