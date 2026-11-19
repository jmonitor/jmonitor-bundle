<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class MetricValue
{
    public function __construct(
        public readonly int $count,
        public readonly float $sum,
        public readonly float $max,
    ) {}
}
