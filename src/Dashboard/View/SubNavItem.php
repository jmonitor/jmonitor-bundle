<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

use Jmonitor\JmonitorBundle\Dashboard\NavBadge;

final class SubNavItem
{
    /** @param array<string, string> $query */
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly bool $current = false,
        public readonly ?Mark $mark = null,
        public readonly array $query = [],
        public readonly ?NavBadge $badge = null,
        public readonly ?string $icon = null,
    ) {}
}
