<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/** @internal */
final class Culprit
{
    /** @param int $others the other rows that count at least one entry */
    private function __construct(
        public readonly ReportRow $row,
        public readonly int $count,
        public readonly int $others,
    ) {}

    /** the row that counts the most entries of the metric, the smallest key on a tie */
    public static function mostCounted(Report $report, string $metric): ?self
    {
        $culprit = null;
        $counting = 0;

        foreach ($report->rows as $row) {
            $count = $row->count($metric);

            if ($count < 1) {
                continue;
            }

            ++$counting;

            if ($culprit === null || $count > $culprit->count($metric) || ($count === $culprit->count($metric) && strcmp($row->key, $culprit->key) < 0)) {
                $culprit = $row;
            }
        }

        return $culprit === null ? null : new self($culprit, $culprit->count($metric), $counting - 1);
    }

    public static function times(int $count): string
    {
        return $count === 1 ? 'once' : Format::number($count) . ' times';
    }

    public static function otherRoutes(int $others): string
    {
        return $others === 1 ? '1 other route' : Format::number($others) . ' other routes';
    }
}
