<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class SlowQueriesCheck implements CheckInterface
{
    public function __construct(private readonly int $thresholdMs) {}

    public function id(): string
    {
        return 'queries.slow';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function screen(): string
    {
        return 'queries';
    }

    public function screenLabel(): string
    {
        return 'Queries';
    }

    public function metrics(): array
    {
        return [Metric::QUERY_SLOW];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Query, Metric::QUERY_SLOW, 'count', true, 1);
    }

    public function headline(array $totals): string
    {
        $count = $totals[Metric::QUERY_SLOW]->count ?? 0;

        return $count === 1 ? '1 slow query' : Format::number($count) . ' slow queries';
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $culprit = ($report->rows[0] ?? null)?->count(Metric::QUERY_SLOW) > 0 ? $report->rows[0] : null;

        if ($culprit === null) {
            $sentence = 'Queries that took ' . $this->thresholdMs . ' ms or more in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = sprintf(
                'The one slow most often took %d ms or more %s, %s at worst.',
                $this->thresholdMs,
                Culprit::times($culprit->count(Metric::QUERY_SLOW)),
                Format::duration($culprit->max(Metric::QUERY_DURATION)),
            );

            if ($culprit->label === null) {
                $sentence .= ' Its text is not stored.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $this->headline($totals),
            $sentence,
            'A query counts as slow from ' . $this->thresholdMs . ' ms (recording.sql.slow_threshold). Every request that runs it waits for it.',
            'Run it with EXPLAIN and look at the indexes of the tables in its WHERE and ORDER BY.',
            $this->screen(),
            $culprit?->label,
        );
    }
}
