<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Entry
{
    /**
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
        public Described $project,
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

    public function title(): string
    {
        return $this->project->title;
    }

    public function artist(): string
    {
        return $this->project->artist;
    }

    public function group(): string
    {
        return $this->project->group;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->project->tags;
    }

    public function song(): Song
    {
        return $this->project instanceof Song ? $this->project : throw new \LogicException("Eintrag {$this->key()} ist kein Song.");
    }

    public function link(): Link
    {
        return $this->project instanceof Link ? $this->project : throw new \LogicException("Eintrag {$this->key()} ist kein Link.");
    }

    public function session(): Session
    {
        return $this->project instanceof Session ? $this->project : throw new \LogicException("Eintrag {$this->key()} ist keine Session.");
    }

    public function set(): Set
    {
        return $this->project instanceof Set ? $this->project : throw new \LogicException("Eintrag {$this->key()} ist kein Set.");
    }

    public function key(): string
    {
        return $this->prefix . '/' . $this->slug;
    }
}
