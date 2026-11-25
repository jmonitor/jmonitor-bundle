<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;

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
     * A type without data in the period is in neither list of the totals.
     *
     * @param non-empty-list<string> $metricTypes
     * @param list<string>           $keyed       those of $metricTypes also wanted key by key
     *
     * @throws \InvalidArgumentException when a type of $keyed is not in $metricTypes
     */
    public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals;
}
