<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Read;

final class Report
{
    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series 60 counts of the series metric, oldest first, or empty
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly ?ReportRow $other = null,
        public readonly int $keyCount = 0,
        public readonly array $series = [],
    ) {}
}
