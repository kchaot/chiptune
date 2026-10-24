<?php

declare(strict_types=1);

namespace Chiptune\Site;

use Chiptune\Common\BuildError;

/**
 * Sammelt, was beim Bau schiefgeht.
 *
 * Ein Fehler in einer strikten Quelle bricht den Bau am Ende ab, bei allen anderen wird die Datei
 * nur mit einer Meldung übersprungen.
 */
final class Problems
{
    /** @var list<string> */
    private array $lines = [];

    public function report(bool $strict, string $label, BuildError $error): void
    {
        if ($strict) {
            $this->add("{$label}: {$error->getMessage()}");

            return;
        }

        fwrite(STDERR, "ÜBERSPRUNGEN: {$label}: {$error->getMessage()}\n");
    }

    public function add(string $line): void
    {
        $this->lines[] = $line;
    }

    public function any(): bool
    {
        return $this->lines !== [];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
