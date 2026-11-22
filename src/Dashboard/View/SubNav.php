<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class SubNav
{
    /**
     * @param list<SubNavItem> $items
     * @param ?string          $node  the web server that answered
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $node = null,
    ) {}
}
