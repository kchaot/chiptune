<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Der Hinweis, aus welchem Song ein Layer-Song einen Layer übernommen hat.
 *
 * Er hält Titel und Verweis des Quell-Songs, dessen ursprüngliches Tempo und den Takt (`at`),
 * ab dem der Layer einsteigt.
 */
final readonly class SongLayer
{
    public function __construct(
        public string $title,
        public string $ref,
        public float $bpm,
        public int $at,
    ) {}
}
