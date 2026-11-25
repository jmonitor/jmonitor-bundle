<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class ServerErrorsCheck implements CheckInterface
{
    private const METRIC = Metric::REQUEST_STATUS_PREFIX . '5xx';

    public function id(): string
    {
        return 'requests.server_errors';
    }

    public function severity(): Severity
    {
        return Severity::Critical;
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
        return [self::METRIC];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Request);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = $totals[self::METRIC]->count ?? 0;
        $culprit = Culprit::mostCounted($report, self::METRIC);

        if ($culprit === null) {
            $sentence = 'Responses with a 5xx status in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = $culprit->row->key . ' answered with a 5xx status ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other route', 'other routes') . ' did too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $count === 1 ? '1 server error' : Format::number($count) . ' server errors',
            $sentence,
            'A 5xx status means your code or a server failed: the visitor got an error instead of an answer.',
            'Open Exceptions to see what was thrown, then look for it in your logs.',
            $this->screen(),
        );
    }
}
