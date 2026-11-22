<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Notice
{
    /** @param 'bad'|'warn'|'info' $tone */
    public function __construct(
        public readonly string $tone,
        public readonly string $title,
        public readonly string $text = '',
    ) {}
}
