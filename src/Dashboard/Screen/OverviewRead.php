<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;

/**
 * @internal what the Overview read of the application in a period
 */
final class OverviewRead
{
    /**
     * @param Report       $requests the Request family, restricted to what the Overview shows
     * @param list<string> $counted  the types of the totals that count an activity
     */
    public function __construct(
        public readonly RaisedChecks $raised,
        public readonly Report $requests,
        private readonly array $counted,
    ) {}

    public function isEmpty(): bool
    {
        if ($this->requests->rows !== [] || $this->requests->other !== null) {
            return false;
        }

        foreach ($this->requests->totals as $total) {
            if ($total->count > 0) {
                return false;
            }
        }

        foreach ($this->counted as $type) {
            if (($this->raised->total($type)->count ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    public function count(string $metric): int
    {
        return $this->total($metric)->count;
    }

    /** the total of a type in the read that raised the checks, zero when it counted nothing */
    public function total(string $metric): MetricValue
    {
        return $this->raised->total($metric) ?? new MetricValue(0, 0.0, 0.0);
    }

    /** the requests of the Request family */
    public function requestCount(): int
    {
        return $this->requests->totals[Metric::REQUEST_DURATION]->count ?? 0;
    }
}
