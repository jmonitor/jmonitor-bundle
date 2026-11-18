<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class Report
{
    /**
     * @param list<ReportRow>            $rows
     * @param list<int>                  $series 60 counts of the series metric, oldest first, or empty
     * @param array<string, MetricValue> $totals metric type => total over every key of the family in the period, the `(other)` key included
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly ?ReportRow $other = null,
        public readonly int $keyCount = 0,
        public readonly array $series = [],
        public readonly array $totals = [],
    ) {}
}
