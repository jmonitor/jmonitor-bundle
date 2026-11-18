<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class FixedReader implements ReaderInterface
{
    /** @var list<ReportQuery> */
    public array $queries = [];

    /** @var list<list<string>> */
    public array $labelRequests = [];

    /**
     * @param Report|\Closure(ReportQuery): Report $report
     * @param array<string, string>                $labels key => label
     */
    public function __construct(
        private readonly Report|\Closure $report = new Report(),
        private readonly array $labels = [],
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
}
