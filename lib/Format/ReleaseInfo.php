<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Angaben zur Veröffentlichung eines Songs (Titel, Datum, Label, Katalognummer, Lizenz, Credits).
 */
final readonly class ReleaseInfo
{
    /**
     * @param array<string, list<string>> $credits
     */
    public function __construct(
        public ?string $title = null,
        public ?string $label = null,
        public ?string $catalog = null,
        public ?string $license = null,
        public ?string $date = null,
        public array $credits = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'title' => $this->title,
                'label' => $this->label,
                'catalog' => $this->catalog,
                'license' => $this->license,
                'date' => $this->date,
                'credits' => $this->credits === [] ? null : $this->credits,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
