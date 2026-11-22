<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class NavBadge
{
    /** @param 'warn'|'bad' $tone */
    public function __construct(
        public readonly string $text,
        public readonly string $tone,
        public readonly string $title,
    ) {}
}
