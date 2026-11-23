<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class InstanceNav
{
    /** @param list<InstanceNavRow> $rows */
    public function __construct(
        public readonly array $rows,
    ) {}
}
