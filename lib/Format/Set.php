<?php

declare(strict_types=1);

namespace Chiptune\Format;

final readonly class Set implements Described
{
    /**
     * @param list<string> $tags
     * @param list<SetPart> $parts
     * @param list<array{prefix: string, slug: string, title: string}> $credits
     * @param list<string> $warnings
     */
    public function __construct(
        public string $title,
        public string $artist,
        public string $group,
        public array $tags,
        public bool $loop,
        public array $parts,
        public float $duration,
        public string $notesHtml,
        public array $credits,
        public array $warnings = [],
    ) {}

    public function withNotesHtml(string $notesHtml): self
    {
        return clone($this, ['notesHtml' => $notesHtml]);
    }
}
