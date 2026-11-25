<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class RepeatedQueriesCheck implements CheckInterface
{
    public function __construct(private readonly int $threshold) {}

    public function id(): string
    {
        return 'queries.repeated';
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
        return [Metric::NPLUS1_CALLS];
    }

    public function query(Period $period, int $now): ReportQuery
    {
        return new ReportQuery($period, $now, Family::NPlusOne, Metric::NPLUS1_DURATION, 'sum', true, 100);
    }

    public function finding(Report $report, array $totals, Period $period): Finding
    {
        $count = \count($report->rows) + max(0, $report->keyCount - \count($report->rows));
        $sentence = 'Queries repeated ' . $this->threshold . ' times or more inside one request in the ' . strtolower(Format::period($period)) . '.';

        foreach ($report->rows as $row) {
            $key = EntryPointKey::parse($row->key);

            if ($key === null) {
                continue;
            }

            $calls = $row->count(Metric::NPLUS1_CALLS);
            $average = $calls === 0 ? 0.0 : $row->sum(Metric::NPLUS1_CALLS) / $calls;
            $sentence = sprintf(
                '%s runs the same query %s times on average, %s at worst.',
                self::entryPoint($key),
                Format::number($average, $average < 10 ? 1 : 0),
                Format::number((int) $row->max(Metric::NPLUS1_CALLS)),
            );

            if ($count > 1) {
                $sentence .= $count === 2 ? ' 1 more is listed on Queries.' : ' ' . Format::number($count - 1) . ' more are listed on Queries.';
            }

            break;
        }

        return new Finding(
            $this->id(),
            $this->severity(),
            match ($count) {
                0 => 'Repeated queries (N+1)',
                1 => 'Repeated query (N+1)',
                default => Format::number($count) . ' repeated queries (N+1)',
            },
            $sentence,
            'The code loads a list, then fetches one row per item. Each fetch is a round trip to the database, so the cost grows with the size of the list.',
            'Load the related rows in one query: a join in the first query, or one WHERE id IN (…) for the whole list.',
            $this->screen(),
        );
    }

    private static function entryPoint(EntryPointKey $key): string
    {
        return match ($key->unitType) {
            Entry::TYPE_COMMAND => 'Command ' . $key->unitKey,
            Entry::TYPE_MESSAGE => 'Message ' . $key->unitKey,
            Entry::TYPE_TASK => 'Task ' . (TaskKey::parse($key->unitKey)->label ?? $key->unitKey),
            default => $key->unitKey,
        };
    }
}
