<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class ScreenRequest
{
    public function __construct(
        public readonly int $now,
    ) {}
}
