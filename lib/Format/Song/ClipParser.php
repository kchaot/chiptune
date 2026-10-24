<?php

declare(strict_types=1);

namespace Chiptune\Format\Song;

use Chiptune\Common\BuildError;
use Chiptune\Format\Credit;
use Chiptune\Format\Fields;
use Chiptune\Format\Song;

/**
 * Liest die `clips` einer Spur: Teile aus Spuren anderer Songs.
 *
 * Ein Clip wählt Takte oder Steps (`bars`, `steps`), setzt sie an eine Stelle (`at`, `at-step`),
 * transponiert (`transpose`) und wiederholt (`repeat`). Geprüft wird auch, ob der Autor des
 * Quellsongs Remixe erlaubt und ob das Raster passt. Der Layer-Song baut seine Layer aus denselben Clips.
 */
final class ClipParser
{
    public const MAX_CLIPS = 16;
    public const MAX_REPEAT = 64;
    public const MAX_AT = 4096;
    public const MAX_TRANSPOSE = 36;

    /**
     * @param (\Closure(string): array{prefix: string, slug: string, song: Song})|null $resolve
     * @param array<string, Credit> $credits
     * @return array{events: list<array{t: int, len: int, d?: string, f?: float}>, end: int, first: Track|null, drums: list<bool>, bands: list<array{ref: string, title: string, track: string, start: int, len: int}>}
     */
    public function parse(mixed $clips, string $where, int $dstSpb, ?\Closure $resolve, string $owner, array &$credits): array
    {
        if (!is_array($clips) || $clips === [] || !array_is_list($clips) || count($clips) > self::MAX_CLIPS) {
            throw new BuildError("{$where}: „clips“ muss eine Liste mit 1 bis " . self::MAX_CLIPS . ' Einträgen sein.');
        }

        if ($resolve === null) {
            throw new BuildError("{$where}: „clips“ braucht den Build, der andere Songs auflösen kann.");
        }

        $dstBar = $dstSpb * 4;
        $events = [];
        $cursor = 0;
        $end = 0;
        $first = null;
        $drums = [];
        $bands = [];

        foreach ($clips as $number => $clip) {
            $label = $where . ', Clip ' . ($number + 1);

            if (!is_array($clip) || array_is_list($clip) && $clip !== []) {
                throw new BuildError("{$label}: Ein Clip braucht Schlüssel wie from, bars und at.");
            }

            Fields::keys($clip, ['from', 'bars', 'steps', 'at', 'at-step', 'transpose', 'repeat'], $label, ['at_step']);

            $from = $clip['from'] ?? null;

            $m = [];
            if (!is_string($from) || preg_match(Fields::TRACK_REF, trim($from), $m) !== 1) {
                throw new BuildError("{$label}: „from“ muss die Form prefix/slug#track haben.");
            }

            [, $prefix, $slug, $trackName] = $m;
            $trackName = trim($trackName);
            $resolved = $resolve("{$prefix}/{$slug}");
            $source = $resolved['song'];

            Fields::mayRemix($label, "{$prefix}/{$slug}", $prefix, $owner, $source->remix);

            $sourceTrack = null;

            foreach ($source->tracks as $candidate) {
                if ($candidate->name === $trackName) {
                    $sourceTrack = $candidate;

                    break;
                }
            }

            if ($sourceTrack === null) {
                throw new BuildError(
                    "{$label}: „{$prefix}/{$slug}“ hat keinen Track „{$trackName}“ (vorhanden: "
                    . implode(', ', array_map(fn(Track $track): string => $track->name, $source->tracks))
                    . ').',
                );
            }

            $srcSpb = $source->stepsPerBeat;
            $srcBar = $source->stepsPerBar;
            $srcSteps = $source->steps;

            if (isset($clip['bars'], $clip['steps'])) {
                throw new BuildError("{$label}: „bars“ und „steps“ schließen sich aus.");
            }

            if (isset($clip['bars'])) {
                [$a, $b] = Fields::range($clip['bars'], "{$label}: „bars“");
                [$start, $stop] = [($a - 1) * $srcBar, $b * $srcBar];
            } elseif (isset($clip['steps'])) {
                [$a, $b] = Fields::range($clip['steps'], "{$label}: „steps“");
                [$start, $stop] = [$a - 1, $b];
            } else {
                [$start, $stop] = [0, $srcSteps];
            }

            if ($stop > $srcSteps) {
                throw new BuildError("{$label}: Der Ausschnitt reicht bis Step {$stop}, „{$prefix}/{$slug}“ hat nur {$srcSteps} Steps ({$source->bars} Bars).");
            }

            if (((($stop - $start) * $dstSpb) % $srcSpb) !== 0) {
                throw new BuildError(
                    "{$label}: Der Ausschnitt passt nicht in das Raster dieses Songs (Quelle grid "
                    . ($srcSpb * 4)
                    . ', hier grid '
                    . ($dstSpb * 4)
                    . '). Mit ganzen Bars geht es immer auf.',
                );
            }

            $length = intdiv(($stop - $start) * $dstSpb, $srcSpb);

            if (isset($clip['at_step'], $clip['at-step'])) {
                throw new BuildError("{$label}: „at-step“ und „at_step“ sind derselbe Schlüssel (at_step ist der alte Name). Bitte nur „at-step“ verwenden.");
            }

            $atStep = $clip['at-step'] ?? $clip['at_step'] ?? null;

            if (isset($clip['at']) && $atStep !== null) {
                throw new BuildError("{$label}: „at“ und „at-step“ schließen sich aus.");
            }

            $at = $cursor;

            if (isset($clip['at'])) {
                $at = (Fields::integer($clip['at'], "{$label}: „at“", 1, self::MAX_AT) - 1) * $dstBar;
            } elseif ($atStep !== null) {
                $at = Fields::integer($atStep, "{$label}: „at-step“", 1, SongParser::MAX_STEPS) - 1;
            }

            $repeat = isset($clip['repeat']) ? Fields::integer($clip['repeat'], "{$label}: „repeat“", 1, self::MAX_REPEAT) : 1;
            $transpose = isset($clip['transpose']) ? Fields::integer($clip['transpose'], "{$label}: „transpose“", -self::MAX_TRANSPOSE, self::MAX_TRANSPOSE) : 0;
            $isDrums = $sourceTrack->isDrums();

            if ($isDrums && $transpose !== 0) {
                throw new BuildError("{$label}: „transpose“ gibt es nur für Noten-Tracks.");
            }

            if (($at + ($length * $repeat)) > SongParser::MAX_STEPS) {
                throw new BuildError("{$label}: Das Ergebnis wäre länger als " . SongParser::MAX_STEPS . ' Steps.');
            }

            for ($r = 0; $r < $repeat; $r++) {
                $base = $at + ($r * $length);

                foreach ($sourceTrack->events as $event) {
                    if ($event['t'] < $start || $event['t'] >= $stop) {
                        continue;
                    }

                    $offset = ($event['t'] - $start) * $dstSpb;
                    $len = min($event['len'], $stop - $event['t']) * $dstSpb;

                    if (($offset % $srcSpb) !== 0 || ($len % $srcSpb) !== 0) {
                        throw new BuildError("{$label}: Das Raster der Quelle (grid " . ($srcSpb * 4) . ') passt nicht auf grid ' . ($dstSpb * 4) . ' dieses Songs.');
                    }

                    $copy = $event;
                    $copy['t'] = $base + intdiv($offset, $srcSpb);
                    $copy['len'] = max(1, intdiv($len, $srcSpb));

                    if (isset($copy['f']) && $transpose !== 0) {
                        $copy['f'] = round($copy['f'] * (2 ** ($transpose / 12)), 3);
                    }

                    $events[] = $copy;
                }
            }

            $cursor = $at + ($length * $repeat);
            $end = max($end, $cursor);
            $first ??= $sourceTrack;
            $drums[] = $isDrums;
            $bands[] = ['ref' => "{$prefix}/{$slug}", 'title' => $source->title, 'track' => $trackName, 'start' => $at, 'len' => $length * $repeat];
            $credits["{$prefix}/{$slug}"] = new Credit($prefix, $slug, $source->title);
        }

        return ['events' => $events, 'end' => $end, 'first' => $first, 'drums' => $drums, 'bands' => $bands];
    }
}
