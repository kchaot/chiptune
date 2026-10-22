<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Ein Abschnitt eines Sets: ein Song oder ein Ausschnitt davon, der `repeat`-mal gespielt wird.
 *
 * Der Ausschnitt reicht von Step `start` bis `stop` und beginnt im Song bei Takt `startBar`. Tempo,
 * Raster und Tracks des Songs stehen als Kopie dabei, damit das Set ohne den Song auskommt.
 */
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
