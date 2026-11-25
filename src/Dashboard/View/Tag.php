<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Tag
{
    /** @param 'warn'|'bad' $tone */
    public function __construct(
        public readonly string $text,
        public readonly string $tone,
    ) {}
}
