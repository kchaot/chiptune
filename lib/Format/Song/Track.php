<?php

declare(strict_types=1);

namespace Chiptune\Format\Song;

/**
 * Eine Spur eines Songs: ein Instrument mit seinen Noten.
 *
 * Die Noten stehen als Events: `t` ist der Startstep, `len` die Länge in Steps, `d` das Kürzel
 * eines Drum-Sounds oder `f` die Frequenz in Hz. `volume`, `gate` (Anteil der Notenlänge, der
 * klingt) und `pw` (Pulsbreite) formen den Klang. `refs` merkt sich, welche Abschnitte aus anderen
 * Songs stammen. Kommt der Track aus einem Layer-Song, steht seine `group` dabei.
 */
final readonly class Track
{
    /**
     * @param list<array{t: int, len: int, d?: string, f?: float}> $events
     * @param list<array{ref: string, title: string, track: string, start: int, len: int}> $refs
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
