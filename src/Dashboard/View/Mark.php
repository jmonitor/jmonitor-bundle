<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Mark
{
    /** @param 'bad'|'warn'|'ok'|'unknown'|'info' $tone */
    public function __construct(
        public readonly string $tone,
        public readonly string $label,
    ) {}
}
