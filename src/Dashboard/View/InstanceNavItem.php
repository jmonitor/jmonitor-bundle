<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class InstanceNavItem
{
    /**
     * @param array<string, string> $query
     * @param ?string               $detail what tells it apart from the others, such as an address
     */
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly array $query,
        public readonly bool $current = false,
        public readonly ?string $detail = null,
        public readonly ?Mark $mark = null,
    ) {}
}
