<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class FailedMessagesCheck implements CheckInterface
{
    public function id(): string
    {
        return 'messages.failed';
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
        return 'no failed message';
    }

    public function metrics(): array
    {
        return [Metric::MESSAGE_FAILED];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Message, metrics: [Metric::MESSAGE_FAILED]);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = $totals[Metric::MESSAGE_FAILED]->count ?? 0;
        $culprit = Culprit::mostCounted($report, Metric::MESSAGE_FAILED);

        if ($culprit === null) {
            $sentence = 'Messages handled with an exception in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = $culprit->row->key . ' failed ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other message class', 'other message classes') . ' failed too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $count === 1 ? '1 message failed' : Format::number($count) . ' messages failed',
            $sentence,
            'A failed message is work that did not happen. Nobody saw an error page: only this screen tells you.',
            'Open Exceptions to see what was thrown. Fix the cause, then run them again: bin/console messenger:failed:retry',
            $this->screen(),
        );
    }
}
