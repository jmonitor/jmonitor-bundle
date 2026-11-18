<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

use Jmonitor\JmonitorBundle\Recording\Entry;

final class QueryDigest
{
    /**
     * @param list<Entry> $children
     */
    public function __construct(
        public readonly array $children,
        public readonly float $totalMs,
        public readonly int $count,
    ) {}
}
