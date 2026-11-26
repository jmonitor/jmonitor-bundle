<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @internal the cells of a route read in the Request family, the same on every screen that lists routes
 */
final class RouteCells
{
    public static function route(ReportRow $row): Cell
    {
        return Cell::route($row->key, true);
    }

    public static function requests(ReportRow $row): Cell
    {
        return new Cell(Format::number($row->count(Metric::REQUEST_DURATION)));
    }

    /** @param float $largest the largest total time shown beside it */
    public static function total(ReportRow $row, float $largest): Cell
    {
        $total = $row->sum(Metric::REQUEST_DURATION);

        return Cell::bar(Format::duration($total), $largest > 0 ? $total / $largest : 0.0);
    }

    public static function split(ReportRow $row): Cell
    {
        return self::averageSplit($row->metrics);
    }

    /**
     * Where an average request goes, from the totals of the Request family over requests that counted at least one.
     *
     * @param array<string, MetricValue> $metrics metric type => total
     */
    public static function averageSplit(array $metrics): Cell
    {
        $requests = $metrics[Metric::REQUEST_DURATION]->count ?? 0;
        $per = static fn(string $type): float => $requests === 0 ? 0.0 : ($metrics[$type]->sum ?? 0.0) / $requests;
        $has = static fn(string $type): bool => ($metrics[$type]->count ?? 0) > 0;

        return Cell::averageSplit(
            $per(Metric::REQUEST_DURATION),
            $has(Metric::REQUEST_SQL_TIME) ? $per(Metric::REQUEST_SQL_TIME) : null,
            $per(Metric::REQUEST_SQL_COUNT),
            $has(Metric::REQUEST_HTTP_TIME) ? $per(Metric::REQUEST_HTTP_TIME) : null,
        );
    }
}
