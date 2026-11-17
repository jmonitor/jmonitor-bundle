<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class RouteStats
{
    public function __construct(
        public readonly string $key,
        public readonly string $method,
        public readonly string $route,
        public readonly int $requests,
        public readonly float $avgMs,
        public readonly float $p95Ms,
        public readonly float $maxMs,
        public readonly float $totalMs,
        public readonly int $errors,
        public readonly int $slow,
        public readonly int $peakMemoryBytes,
    ) {}
}
