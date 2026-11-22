<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class SubNavItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly bool $current = false,
        public readonly ?Mark $mark = null,
    ) {}
}
