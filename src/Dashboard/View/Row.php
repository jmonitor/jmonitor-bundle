<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Row
{
    /**
     * @param array<string, Cell>   $cells      column id => cell
     * @param array<string, string> $attributes
     */
    public function __construct(
        public readonly string $key,
        public readonly array $cells,
        public readonly array $attributes = [],
        public readonly bool $muted = false,
        public readonly bool $nested = false,
    ) {}
}
