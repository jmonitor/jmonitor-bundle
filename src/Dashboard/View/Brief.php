<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

/** What a section brings to the top of its page. */
final class Brief
{
    /** @param 'warn'|'bad'|null $tone */
    public function __construct(
        public readonly string $count,
        public readonly string $clause,
        public readonly string $figures = '',
        public readonly string $standout = '',
        public readonly ?string $tone = null,
    ) {}
}
