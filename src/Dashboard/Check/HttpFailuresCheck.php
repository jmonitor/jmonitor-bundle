<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class HttpFailuresCheck implements CheckInterface
{
    public function id(): string
    {
        return 'http.failures';
    }

    public function severity(): Severity
    {
        return Severity::Critical;
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
        return [Metric::HTTP_FAILED];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::Http, Metric::HTTP_FAILED, 'count', true, 100);
    }

    public function headline(array $totals): string
    {
        $count = $totals[Metric::HTTP_FAILED]->count ?? 0;

        return $count === 1 ? '1 call without an answer' : Format::number($count) . ' calls without an answer';
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $culprit = Culprit::mostCounted($report, Metric::HTTP_FAILED);

        if ($culprit === null) {
            $sentence = 'Outgoing calls that got no answer in the ' . strtolower(Format::period($period)) . '.';
        } else {
            $sentence = $culprit->row->key . ' did not answer ' . Culprit::times($culprit->count) . '.';

            if ($culprit->others > 0) {
                $sentence .= ' ' . Culprit::others($culprit->others, 'other host', 'other hosts') . ' did not either.';
            }
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            $this->headline($totals),
            $sentence,
            'No answer means a timeout or a network error: your code waited, then had to do without the other site.',
            'Check that the host can be reached from your server, and set a timeout on the client so that a slow host cannot hold your requests.',
            $this->screen(),
        );
    }
}
