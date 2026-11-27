<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

interface CheckInterface
{
    public function id(): string;

    public function severity(): Severity;

    /** the id of the screen that details it, whose tab carries its badge */
    public function screen(): string;

    public function screenLabel(): string;

    /** @return non-empty-list<string> raised when one of these metric types counts an entry in the period */
    public function metrics(): array;

    /** the read that names its first culprit */
    public function query(Period $period, int $now): ReportQuery;

    /**
     * its title told by the totals alone, without reading its culprit
     *
     * @param array<string, MetricValue> $totals those of metrics()
     */
    public function headline(array $totals): string;

    /** @param array<string, MetricValue> $totals those of metrics() */
    public function finding(Report $report, array $totals, Period $period): Finding;

    /** what it says when it is not raised, such as "no server error" */
    public function passed(): string;
}
