<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class MapBox
{
    /**
     * @param string            $value  its count, "0" while nothing was recorded
     * @param list<Tag>         $tags
     * @param ?string           $path   the screen it leads to
     * @param ?string           $anchor the section of that screen
     * @param 'warn'|'bad'|null $tone
     * @param list<int>         $series
     */
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly array $tags = [],
        public readonly ?string $path = null,
        public readonly ?string $anchor = null,
        public readonly ?string $tone = null,
        public readonly array $series = [],
    ) {}
}
