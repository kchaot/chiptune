<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Entry
{
    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed>|null $nav
     * @param list<string> $order
     * @param list<self> $sessions
     * @param list<self> $links
     */
    public function __construct(
        public string $prefix,
        public string $slug,
        public ?string $album,
        public ?string $source,
        public EntryKind $kind,
        public array $project,
        public ?string $strudel = null,
        public ?array $nav = null,
        public array $order = [],
        public array $sessions = [],
        public array $links = [],
    ) {}

    /** @param array<string, mixed>|null $nav */
    public function withNav(?array $nav): self
    {
        return clone($this, ['nav' => $nav]);
    }

    public function key(): string
    {
        return $this->prefix . '/' . $this->slug;
    }
}
