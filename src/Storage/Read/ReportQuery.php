<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Period;

final class ReportQuery
{
    public const FIELDS = ['count', 'sum', 'max', 'avg'];

    /**
     * @param list<string> $metrics the metric types read for each key, every one of the family when empty
     */
    public function __construct(
        public readonly Period $period,
        public readonly int $now,
        public readonly Family $family,
        public readonly ?string $sortMetric = null,
        public readonly string $sortField = 'sum',
        public readonly bool $descending = true,
        public readonly int $limit = 100,
        public readonly ?string $seriesMetric = null,
        public readonly array $metrics = [],
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

        if ($metrics !== [] && $family->isOpen()) {
            throw new \InvalidArgumentException(sprintf('The open family "%s" is read with all its metric types.', $family->value));
        }

        foreach ($metrics as $metric) {
            if (Family::ofMetric($metric) !== $family) {
                throw new \InvalidArgumentException(sprintf('The metric type "%s" is not of the family "%s".', $metric, $family->value));
            }
        }
    }
}
