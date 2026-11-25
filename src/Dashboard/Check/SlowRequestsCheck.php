<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class SlowRequestsCheck implements CheckInterface
{
    public function __construct(private readonly int $thresholdMs) {}

    public function id(): string
    {
        return 'requests.slow';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function screen(): string
    {
        return 'requests';
    }

    public function screenLabel(): string
    {
        return 'Requests';
    }

    public function metrics(): array
    {
        return [Metric::REQUEST_SLOW];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Request);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = $totals[Metric::REQUEST_SLOW]->count ?? 0;
        $threshold = Format::duration($this->thresholdMs);
        $culprit = Culprit::mostCounted($report, Metric::REQUEST_SLOW);

        if ($culprit === null) {
            $sentence = 'Requests that took ' . $threshold . ' or more in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = sprintf(
                '%s took %s or more %s, %s at worst.',
                $culprit->row->key,
                $threshold,
                Culprit::times($culprit->count),
                Format::duration($culprit->row->max(Metric::REQUEST_DURATION)),
            );

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other route', 'other routes') . ' did too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $count === 1 ? '1 slow request' : Format::number($count) . ' slow requests',
            $sentence,
            'A request counts as slow from ' . $threshold . ' (recording.slow_threshold). Someone waited that long for the answer.',
            'Look at where the average of the route goes in the table: your PHP code, SQL or another site.',
            $this->screen(),
        );
    }
}
