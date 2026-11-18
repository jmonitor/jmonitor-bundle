<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Period;

final class ReportQuery
{
    public const FIELDS = ['count', 'sum', 'max', 'avg'];

    public function __construct(
        public readonly Period $period,
        public readonly int $now,
        public readonly Family $family,
        public readonly ?string $sortMetric = null,
        public readonly string $sortField = 'sum',
        public readonly bool $descending = true,
        public readonly int $limit = 100,
        public readonly ?string $seriesMetric = null,
    ) {
        if ($family->isOpen() && $sortMetric === null) {
            throw new \InvalidArgumentException(sprintf('The open family "%s" needs a sort metric.', $family->value));
        }

        if (!\in_array($sortField, self::FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown sort field "%s".', $sortField));
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be positive.');
        }
    }
}
