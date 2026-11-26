<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Card
{
    /**
     * @param 'warn'|'bad'|null $tone
     * @param list<int>         $series
     * @param ?string           $anchor the id of the section it leads to
     */
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly string $hint = '',
        public readonly ?string $tone = null,
        public readonly array $series = [],
        public readonly bool $wide = false,
        public readonly ?string $anchor = null,
    ) {}
}
