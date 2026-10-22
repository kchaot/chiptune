<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Was jede Datei der Bibliothek mitbringt, egal ob Song, Set, Session oder Link.
 *
 * Das sind Titel, Künstler, Gruppe, Tags, Begleittext, Credits und Warnungen. Die Site kann damit
 * Listen und Seitenköpfe bauen, ohne die Art der Datei zu kennen.
 */
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
