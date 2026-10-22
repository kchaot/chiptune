<?php

declare(strict_types=1);

namespace Chiptune\Site;

/**
 * Die Beschreibung einer Klasse für die Code-Übersicht.
 *
 * Text, Felder, Konstanten, Fälle und Methoden stammen aus dem Code und seinen Docblocks, nicht aus
 * einer zweiten Quelle. Daraus macht `CodeDocs` Markdown und der `Renderer` die Seite.
 */
final readonly class DocEntry
{
    /**
     * @param class-string $name
     * @param list<array{name: string, type: string}> $fields
     * @param list<array{name: string, value: string}> $constants
     * @param list<string> $cases
     * @param list<string> $methods
     */
    public function __construct(
        public string $name,
        public string $short,
        public string $kind,
        public string $text,
        public array $fields,
        public array $constants,
        public array $cases,
        public array $methods,
    ) {}

    public function summary(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', explode("\n\n", $this->text)[0]));
    }
}
