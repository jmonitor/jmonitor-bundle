<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;

interface ReaderInterface
{
    public function summary(Period $period, int $now): RequestSummary;

    /** @return list<RouteStats> sans ordre garanti */
    public function routes(Period $period, int $now): array;

    /** @return list<int> 60 valeurs, la plus ancienne en premier */
    public function requestSeries(Period $period, int $now): array;
}
