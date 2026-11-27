<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Tile
{
    /**
     * @param string    $definition what the figure means, read on hover
     * @param list<int> $series
     * @param ?string   $sort       the sort of the table of the screen it leads to, in descending order, while it carries a tag
     * @param bool      $namesPage  whether its label is the title of the page
     */
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly string $definition = '',
        public readonly ?Tag $tag = null,
        public readonly array $series = [],
        public readonly ?string $sort = null,
        public readonly bool $namesPage = false,
    ) {}
}
