<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @internal the cells of a route read in the Request family
 */
final class RouteCells
{
    private const UNMATCHED = '(unmatched)';

    public static function route(ReportRow $row): Cell
    {
        [, $name] = array_pad(explode(' ', $row->key, 2), 2, '');

        return Cell::route($row->key, true, $name === self::UNMATCHED ? 'Requests that matched no route.' : null);
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

    /**
     * Where an average request of the route goes, PHP being what SQL and HTTP leave, over the requests that counted at least one; its text names the larger of SQL and HTTP.
     */
    public static function split(ReportRow $row): Cell
    {
        $requests = $row->count(Metric::REQUEST_DURATION);
        $per = static fn(string $type): float => $requests === 0 ? 0.0 : $row->sum($type) / $requests;
        $sql = $per(Metric::REQUEST_SQL_TIME);
        $http = $per(Metric::REQUEST_HTTP_TIME);

        $text = match (true) {
            $sql > 0 && $sql >= $http => 'SQL ' . Format::duration($sql),
            $http > 0 => 'HTTP ' . Format::duration($http),
            default => '',
        };

        return Cell::split(['php' => max(0.0, $per(Metric::REQUEST_DURATION) - $sql - $http), 'sql' => $sql, 'http' => $http], $text);
    }
}
