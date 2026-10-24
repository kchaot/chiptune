<?php

declare(strict_types=1);

namespace Chiptune\Format\Song;

use Chiptune\Common\HtmlSanitizer;
use Chiptune\Format\Credit;
use Chiptune\Format\Fields;
use Chiptune\Format\Media\MediaParser;
use Chiptune\Format\Song;
use Tempest\Markdown\ParsedMarkdown;

/**
 * Setzt aus Kopf und fertigen Spuren den `Song` zusammen.
 *
 * Hier entstehen die Länge in Steps und Takten, die Warnungen bei ungleich langen Spuren, Schleife,
 * Notiztext, Aufnahmen und Credits. Gemeinsam für einfache Songs und Layer-Songs.
 */
final class SongAssembler
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly MediaParser $media = new MediaParser(),
    ) {}

    /**
     * @param array<string, mixed> $meta
     * @param list<Track> $tracks
     * @param array<string, Credit> $credits
     * @param list<SongLayer> $layers
     */
    public function assemble(
        array $meta,
        ?ParsedMarkdown $head,
        array $tracks,
        float $bpm,
        int $stepsPerBeat,
        string $fallbackTitle,
        ?bool $remix,
        array $credits,
        array $layers = [],
    ): Song {
        $stepsPerBar = $stepsPerBeat * 4;
        $steps = max(array_map(fn(Track $track): int => $track->steps, $tracks));

        $warnings = [];

        foreach ($tracks as $track) {
            if ($layers === [] && $track->steps !== $steps) {
                $warnings[] = "Track „{$track->name}“ hat {$track->steps} Steps, der längste {$steps}.";
            }
        }

        if (($steps % $stepsPerBar) !== 0) {
            $warnings[] = "Die Länge von {$steps} Steps ist kein ganzes Vielfaches von {$stepsPerBar} Steps pro Bar.";
        }

        $loop = Fields::flag($meta, 'loop', true, 'Song');

        $info = Fields::describe($meta, $fallbackTitle);

        return new Song(
            title: $info['title'],
            artist: $info['artist'],
            group: $info['group'],
            tags: $info['tags'],
            bpm: $bpm,
            stepsPerBeat: $stepsPerBeat,
            stepsPerBar: $stepsPerBar,
            stepSeconds: (60 / $bpm) / $stepsPerBeat,
            loop: $loop,
            steps: $steps,
            bars: $steps / $stepsPerBar,
            tracks: $tracks,
            warnings: $warnings,
            notesHtml: $this->sanitizer->clean($head->html ?? ''),
            media: $this->media->parse($meta),
            remix: $remix,
            credits: array_values($credits),
            layers: $layers,
        );
    }
}
