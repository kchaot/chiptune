<?php

declare(strict_types=1);

namespace Chiptune;

interface Described
{
    public string $title { get; }

    public string $artist { get; }

    public string $group { get; }

    /** @var list<string> */
    public array $tags { get; }

    public string $notesHtml { get; }

    /** @var list<array{prefix: string, slug: string, title: string}> */
    public array $credits { get; }

    /** @var list<string> */
    public array $warnings { get; }
}
