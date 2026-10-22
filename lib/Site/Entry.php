<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Format\Described;
use Chiptune\Format\Link;
use Chiptune\Format\Session;
use Chiptune\Format\Set;
use Chiptune\Format\Song;

/**
 * Eine Seite der Bibliothek: eine Datei (Song, Set, Session oder Link) mit ihrer Stelle in der Site.
 *
 * Dazu gehören Präfix und Slug, das Album mit Navigation, der Strudel-Stand und die zugehörigen
 * Sessions und Links.
 */
final readonly class Entry
{
    /**
     * @param array{album: array{slug: string, title: string}, number: int, total: int, prev: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}|null $nav
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

    /** @param array{album: array{slug: string, title: string}, number: int, total: int, prev: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}|null $nav */
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
