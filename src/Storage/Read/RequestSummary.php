<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class RequestSummary
{
    public function __construct(
        public readonly int $requests,
        public readonly float $avgMs,
        public readonly float $p95Ms,
        public readonly float $errorRate,
    ) {}
}
