<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;

interface ReaderInterface
{
    public function read(ReportQuery $query): Report;

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> key => label, without the keys that have none
     */
    public function labels(array $keys): array;

    public function summary(Period $period, int $now): RequestSummary;

    /** @return list<RouteStats> unordered */
    public function routes(Period $period, int $now): array;

    /** @return list<int> 60 values, oldest first */
    public function requestSeries(Period $period, int $now): array;
}
