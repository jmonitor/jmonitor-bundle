<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class QueriesScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const UNAVAILABLE_TEXT = '(query text unavailable)';

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'total' => [Metric::QUERY_DURATION, 'sum'],
        'count' => [Metric::QUERY_DURATION, 'count'],
        'avg' => [Metric::QUERY_DURATION, 'avg'],
        'max' => [Metric::QUERY_DURATION, 'max'],
        'slow' => [Metric::QUERY_SLOW, 'count'],
    ];

    public function __construct(
        private readonly bool $available,
        private readonly int $slowThresholdMs,
        private readonly int $nPlusOneThreshold,
    ) {}

    public function id(): string
    {
        return 'queries';
    }

    public function label(): string
    {
        return 'Queries';
    }

    public function heading(): string
    {
        return 'SQL queries run through Doctrine DBAL';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function space(): Space
    {
        return Space::Application;
    }

    public function sorts(): array
    {
        return array_fill_keys(array_keys(self::SORTS), 'desc');
    }

    public function emptyState(): array
    {
        return [
            'title' => 'No queries recorded yet',
            'text' => 'SQL queries run through Doctrine DBAL show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        [$metric, $field] = self::SORTS[$sort] ?? self::SORTS['total'];
        $descending = $direction !== 'asc';
        $report = $reader->read(new ReportQuery($period, $now, Family::Query, $metric, $field, $descending, self::LIMIT, Metric::QUERY_DURATION));

        $rows = array_map(self::queryRow(...), $report->rows);
        if ($report->other !== null) {
            $rows[] = self::queryRow($report->other, true);
        }

        $tables = [new Table('queries', 'Queries', self::queryColumns(), $rows, max(0, $report->keyCount - \count($report->rows)), 'query', 'queries')];
        $repeated = $this->repeatedQueries($reader, $period, $now);
        if ($repeated->rows !== []) {
            $tables[] = $repeated;
        }

        return new ScreenView($this->cards($report, \count($repeated->rows) + $repeated->remaining, $period), $tables);
    }

    /** @return list<Card> */
    private function cards(Report $report, int $repeated, Period $period): array
    {
        $slow = $report->totals[Metric::QUERY_SLOW]->count ?? 0;

        return [
            new Card('Queries', Format::number($report->totals[Metric::QUERY_DURATION]->count ?? 0), Format::period($period), series: $report->series, wide: true),
            new Card('SQL time', Format::duration($report->totals[Metric::QUERY_DURATION]->sum ?? 0), 'Total time spent in queries'),
            new Card('Slow queries', Format::number($slow), $this->slowThresholdMs . ' ms or more', $slow > 0 ? 'warn' : null),
            new Card('Repeated queries', Format::number($repeated), 'Same query ' . $this->nPlusOneThreshold . ' times or more in one request', $repeated > 0 ? 'warn' : null),
        ];
    }

    /** @return list<Column> */
    private static function queryColumns(): array
    {
        return [
            new Column('query', 'Query', false),
            new Column('executions', 'Executions', sort: 'count'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('slow', 'Slow', sort: 'slow'),
        ];
    }

    private static function queryRow(ReportRow $row, bool $other = false): Row
    {
        $executions = $row->count(Metric::QUERY_DURATION);
        $total = $row->sum(Metric::QUERY_DURATION);
        $slow = $row->count(Metric::QUERY_SLOW);

        return new Row($row->hash, [
            'query' => $other ? new Cell('Other queries') : self::sqlCell($row->label),
            'executions' => new Cell(Format::number($executions)),
            'total' => new Cell(Format::duration($total)),
            'avg' => new Cell(Format::duration($executions === 0 ? 0 : $total / $executions)),
            'max' => new Cell(Format::duration($row->max(Metric::QUERY_DURATION)), $slow > 0 ? 'warn' : null),
            'slow' => new Cell(Format::number($slow), $slow > 0 ? 'warn' : 'zero'),
        ], [
            'data-jm-query' => $row->hash,
            'data-jm-executions' => (string) $executions,
        ], $other);
    }

    private function repeatedQueries(ReaderInterface $reader, Period $period, int $now): Table
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::NPlusOne, Metric::NPLUS1_DURATION, 'sum', true, self::LIMIT));

        $entries = [];
        foreach ($report->rows as $row) {
            $key = EntryPointKey::parse($row->key);
            if ($key !== null) {
                $entries[] = [$row, $key, $key->subject];
            }
        }

        $labels = $entries === [] ? [] : $reader->labels(array_values(array_unique(array_column($entries, 2))));

        $rows = [];
        foreach ($entries as [$row, $key, $queryId]) {
            $rows[] = self::repeatedRow($row, $key->unitType === Entry::TYPE_TASK ? (TaskKey::parse($key->unitKey)->label ?? $key->unitKey) : $key->unitKey, [
                'where' => Cell::entryPoint($key->unitType, $key->unitKey),
                'query' => self::sqlCell($labels[$queryId] ?? null),
            ]);
        }
        if ($report->other !== null) {
            $rows[] = self::repeatedRow($report->other, Aggregator::OTHER, ['where' => new Cell('Other repeated queries')], true);
        }

        return new Table('nplus1', 'Repeated queries (N+1)', [
            new Column('where', 'Where', false),
            new Column('query', 'Query', false),
            new Column('calls', 'Calls'),
            new Column('avg_repeats', 'Avg repeats'),
            new Column('max_repeats', 'Max repeats'),
            new Column('total', 'Total time'),
        ], $rows, max(0, $report->keyCount - \count($report->rows)), 'query', 'queries');
    }

    /** @param array<string, Cell> $cells */
    private static function repeatedRow(ReportRow $row, string $entryPoint, array $cells, bool $other = false): Row
    {
        $calls = $row->count(Metric::NPLUS1_CALLS);
        $maxRepeats = (int) $row->max(Metric::NPLUS1_CALLS);

        return new Row($row->hash, $cells + [
            'calls' => new Cell(Format::number($calls)),
            'avg_repeats' => new Cell(Format::number($calls === 0 ? 0 : $row->sum(Metric::NPLUS1_CALLS) / $calls, 1)),
            'max_repeats' => new Cell(Format::number($maxRepeats)),
            'total' => new Cell(Format::duration($row->sum(Metric::NPLUS1_DURATION))),
        ], [
            'data-jm-nplus1' => $entryPoint,
            'data-jm-repeats-max' => (string) $maxRepeats,
        ], $other);
    }

    private static function sqlCell(?string $sql): Cell
    {
        return $sql === null ? new Cell(self::UNAVAILABLE_TEXT, 'zero') : new Cell($sql, kind: 'sql');
    }
}
