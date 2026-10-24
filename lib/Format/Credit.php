<?php

declare(strict_types=1);

namespace Chiptune\Format;

/**
 * Ein Song, aus dem Material in einer Datei stammt.
 *
 * Clips, Layer, Sets, Sessions und Links nennen ihre Quellen. Die Site zeigt sie als „Enthält Material
 * aus …“ und verlinkt auf den Song.
 */
final readonly class Credit
{
    public function __construct(
        public string $prefix,
        public string $slug,
        public string $title,
    ) {}
}
