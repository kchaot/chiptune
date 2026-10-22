<?php

declare(strict_types=1);

namespace Chiptune;

final readonly class Link
{
    /**
     * @param list<string> $tags
     * @param list<array{prefix: string, slug: string, title: string}> $credits
     * @param list<string> $warnings
     */
    public function __construct(
        public string $title,
        public string $artist,
        public string $group,
        public array $tags,
        public Media $media,
        public string $notesHtml,
        public array $credits,
        public array $warnings = [],
    ) {}

    public function withMedia(Media $media): self
    {
        return clone($this, ['media' => $media]);
    }
}
