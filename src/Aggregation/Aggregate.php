<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

final class Aggregate
{
    public function __construct(
        public readonly Period $period,
        public readonly int $bucket,
        public readonly string $type,
        public readonly string $key,
        public int $count,
        public float $sum,
        public float $max,
    ) {}
}
