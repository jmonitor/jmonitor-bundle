<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class InstanceNavRow
{
    /** @param list<InstanceNavItem> $items */
    public function __construct(
        public readonly string $label,
        public readonly array $items,
    ) {}
}
