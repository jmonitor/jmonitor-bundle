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

    /** the row that counts the most entries of the metrics added together, the smallest key on a tie */
    public static function mostCounted(Report $report, string $metric, string ...$metrics): ?self
    {
        $metrics = [$metric, ...$metrics];
        $culprit = null;
        $most = 0;
        $counting = 0;

        foreach ($report->rows as $row) {
            $count = array_sum(array_map(static fn(string $type): int => $row->count($type), $metrics));

            if ($count < 1) {
                continue;
            }

            ++$counting;

            if ($culprit === null || $count > $most || ($count === $most && strcmp($row->key, $culprit->key) < 0)) {
                $culprit = $row;
                $most = $count;
            }
        }

        return $culprit === null ? null : new self($culprit, $most, $counting - 1);
    }

    public static function times(int $count): string
    {
        return $count === 1 ? 'once' : Format::number($count) . ' times';
    }

    public static function others(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? '1 ' . $singular : Format::number($count) . ' ' . $plural;
    }
}
