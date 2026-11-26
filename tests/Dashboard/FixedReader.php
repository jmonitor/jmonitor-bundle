<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\Totals;

final class FixedReader implements ReaderInterface
{
    /** @var list<ReportQuery> */
    public array $queries = [];

    /** @var list<list<string>> */
    public array $labelRequests = [];

    /** @var list<array{Period, int, list<string>}> */
    public array $totalRequests = [];

    /** @var list<list<string>> */
    public array $keyedRequests = [];

    /**
     * @param Report|\Closure(ReportQuery): Report       $report
     * @param array<string, string>                     $labels    key => label
     * @param array<string, MetricValue>                $totals    metric type => total
     * @param array<string, array<string, MetricValue>> $keyTotals metric type => key hash => total
     */
    public function __construct(
        private readonly Report|\Closure $report = new Report(),
        private readonly array $labels = [],
        private readonly array $totals = [],
        private readonly array $keyTotals = [],
    ) {}

    public function read(ReportQuery $query): Report
    {
        $this->queries[] = $query;

        return $this->report instanceof \Closure ? ($this->report)($query) : $this->report;
    }

    public function labels(array $keys): array
    {
        $this->labelRequests[] = $keys;

        return array_intersect_key($this->labels, array_flip($keys));
    }

    public function totals(Period $period, int $now, array $metricTypes, array $keyed = []): Totals
    {
        $this->totalRequests[] = [$period, $now, $metricTypes];
        $this->keyedRequests[] = $keyed;

        return new Totals(array_intersect_key($this->totals, array_flip($metricTypes)), array_intersect_key($this->keyTotals, array_flip($keyed)));
    }
}
