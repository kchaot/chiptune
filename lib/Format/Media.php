<?php

declare(strict_types=1);

namespace Chiptune\Format;

use Chiptune\Format\Media\ReleaseInfo;

/**
 * Audio und Bilder zu einem Song oder Link.
 *
 * Das ist die in einer DAW gerenderte HQ-Version (`hq`, mit `hqOffset` in Sekunden gegen das
 * Raster verschoben), die Adresse der Veröffentlichung (`release`), ein Cover und die Angaben
 * dazu (`info`). `releaseOwn` heißt, dass die Aufnahme zu dieser Seite gehört und hier abspielbar
 * ist, weil ihr Host in `sources.json` freigegeben ist.
 */
final readonly class Media
{
    public function __construct(
        public ?string $hq = null,
        public ?float $hqOffset = null,
        public ?string $release = null,
        public ?string $cover = null,
        public ReleaseInfo $info = new ReleaseInfo(),
        public bool $releaseOwn = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    public function withoutHq(): self
    {
        return clone($this, ['hq' => null, 'hqOffset' => null]);
    }

    public function withoutCover(): self
    {
        return clone($this, ['cover' => null]);
    }

    public function withoutReleaseDetails(): self
    {
        return clone($this, ['cover' => null, 'info' => new ReleaseInfo()]);
    }

    public function asOwnRelease(): self
    {
        return clone($this, ['releaseOwn' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'hq' => $this->hq,
                'release' => $this->release,
                'cover' => $this->cover,
                'info' => $this->info->isEmpty() ? null : $this->info->toArray(),
                'hqOffset' => $this->hqOffset,
                'releaseOwn' => $this->releaseOwn ? true : null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
