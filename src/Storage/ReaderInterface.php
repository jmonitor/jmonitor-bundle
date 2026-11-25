<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

interface ReaderInterface
{
    public function read(ReportQuery $query): Report;

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> key => label, without the keys that have none
     */
    public function labels(array $keys): array;

    /**
     * @param non-empty-list<string> $metricTypes
     *
     * @return array<string, MetricValue> metric type => total over every key in the period, without the types that have none
     */
    public function totals(Period $period, int $now, array $metricTypes): array;
}
