<?php

declare(strict_types=1);

namespace Chiptune\Format;

final readonly class Session implements Described
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
        public SessionDeck $a,
        public SessionDeck $b,
        public int $start,
        public bool $auto,
        public int $fader,
        public string $notesHtml,
        public array $credits,
        public array $warnings = [],
    ) {}
}
