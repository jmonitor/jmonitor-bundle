<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Lead
{
    /** @param list<int> $series */
    public function __construct(
        public readonly string $sentence,
        public readonly string $detail = '',
        public readonly array $series = [],
        public readonly string $seriesLabel = '',
        public readonly string $seriesStart = '',
    ) {}
}
