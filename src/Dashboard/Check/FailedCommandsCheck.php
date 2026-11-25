<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class FailedCommandsCheck implements CheckInterface
{
    public function id(): string
    {
        return 'commands.failed';
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

    public function metrics(): array
    {
        return [Metric::COMMAND_FAILED];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Command);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = $totals[Metric::COMMAND_FAILED]->count ?? 0;
        $culprit = Culprit::mostCounted($report, Metric::COMMAND_FAILED);

        if ($culprit === null) {
            $sentence = 'Command runs that ended with a non-zero exit code in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = $culprit->row->key . ' ended with a non-zero exit code ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other command', 'other commands') . ' did too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $count === 1 ? '1 command run failed' : Format::number($count) . ' command runs failed',
            $sentence,
            'A non-zero exit code means the command gave up. When cron or a deploy started it, nobody may have seen it.',
            'Run it by hand to read its output, and open Exceptions to see what was thrown.',
            $this->screen(),
        );
    }
}
