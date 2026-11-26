<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class FailedTasksCheck implements CheckInterface
{
    public function id(): string
    {
        return 'tasks.failed';
    }

    public function severity(): Severity
    {
        return Severity::Critical;
    }

    public function screen(): string
    {
        return 'background';
    }

    public function screenLabel(): string
    {
        return 'Background';
    }

    public function passed(): string
    {
        return 'no failed scheduled run';
    }

    public function metrics(): array
    {
        return [Metric::TASK_FAILED];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Task, metrics: [Metric::TASK_FAILED]);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = $totals[Metric::TASK_FAILED]->count ?? 0;
        $culprit = Culprit::mostCounted($report, Metric::TASK_FAILED);

        if ($culprit === null) {
            $sentence = 'Scheduled runs that ended with an exception in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = (TaskKey::parse($culprit->row->key)->label ?? $culprit->row->key) . ' failed ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other task', 'other tasks') . ' failed too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $count === 1 ? '1 scheduled run failed' : Format::number($count) . ' scheduled runs failed',
            $sentence,
            'A failed run is work that did not happen at its hour, and nobody is told.',
            'Open Exceptions to see what was thrown, fix it, then wait for the next run or start the task by hand.',
            $this->screen(),
        );
    }
}
