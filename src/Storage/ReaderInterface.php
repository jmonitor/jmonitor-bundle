<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;

interface ReaderInterface
{
    public function summary(Period $period, int $now): RequestSummary;

    /** @return list<RouteStats> unordered */
    public function routes(Period $period, int $now): array;

    /** @return list<int> 60 values, oldest first */
    public function requestSeries(Period $period, int $now): array;
}
