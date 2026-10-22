<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Media
{
    /**
     * @param array<string, mixed> $info
     */
    public function __construct(
        public ?string $hq = null,
        public ?float $hqOffset = null,
        public ?string $release = null,
        public ?string $cover = null,
        public array $info = [],
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
        return clone($this, ['cover' => null, 'info' => []]);
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
                'info' => $this->info === [] ? null : $this->info,
                'hqOffset' => $this->hqOffset,
                'releaseOwn' => $this->releaseOwn ? true : null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
