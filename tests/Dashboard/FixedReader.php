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

    public function __construct(private readonly Report $report = new Report()) {}

    public function read(ReportQuery $query): Report
    {
        $this->queries[] = $query;

        return $this->report;
    }

    public function labels(array $keys): array
    {
        return [];
    }
}
