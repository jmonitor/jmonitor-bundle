<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class HttpErrorsCheck implements CheckInterface
{
    private const CLIENT_ERRORS = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const SERVER_ERRORS = Metric::HTTP_STATUS_PREFIX . '5xx';

    public function id(): string
    {
        return 'http.errors';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function screen(): string
    {
        return 'cache-http';
    }

    public function screenLabel(): string
    {
        return 'Cache & HTTP';
    }

    public function metrics(): array
    {
        return [self::CLIENT_ERRORS, self::SERVER_ERRORS];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Http, self::SERVER_ERRORS, 'count', true, 100);
    }

    public function headline(array $totals): string
    {
        $count = ($totals[self::CLIENT_ERRORS]->count ?? 0) + ($totals[self::SERVER_ERRORS]->count ?? 0);

        return $count === 1 ? '1 error response' : Format::number($count) . ' error responses';
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $culprit = Culprit::mostCounted($report, self::CLIENT_ERRORS, self::SERVER_ERRORS);

        if ($culprit === null) {
            $sentence = 'Answers with a 4xx or 5xx status from another site in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = $culprit->row->key . ' answered with a 4xx or 5xx status ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other host', 'other hosts') . ' did too.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $this->headline($totals),
            $sentence,
            '4xx: the other site refused the call, often a bad key, a wrong address or a limit reached. 5xx: the other site failed.',
            'Look in your logs for the status and the address of the call: a 4xx is fixed on your side, a 5xx on theirs.',
            $this->screen(),
        );
    }
}
