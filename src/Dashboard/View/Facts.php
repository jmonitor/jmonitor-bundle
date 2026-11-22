<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Facts
{
    /** @param list<Fact> $facts */
    public function __construct(
        public readonly string $title,
        public readonly array $facts,
    ) {}
}
