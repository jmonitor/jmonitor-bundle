<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class QueriesScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const UNAVAILABLE_TEXT = '(query text unavailable)';
    private const SLOW = 'queries.slow';
    private const REPEATED = 'queries.repeated';

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'total' => [Metric::QUERY_DURATION, 'sum'],
        'count' => [Metric::QUERY_DURATION, 'count'],
        'avg' => [Metric::QUERY_DURATION, 'avg'],
        'max' => [Metric::QUERY_DURATION, 'max'],
        'problems' => [Metric::QUERY_SLOW, 'count'],
    ];

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
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

        if ($report->rows === [] && $report->other === null) {
            return new ScreenView([], [new Table('queries', 'Queries', self::queryColumns(), [], 0, 'query', 'queries')]);
        }

        $raised = $this->checks->raised($reader, $period, $now);
        $repeats = null;
        $texts = [];

        if ($raised->has(self::REPEATED)) {
            $query = $this->checks->query(self::REPEATED, $period, $now);

            if ($query !== null) {
                $repeats = $reader->read($query);
                $texts = self::sqlTexts($reader, $repeats);
            }
        }

        $reports = $repeats === null ? [] : [self::REPEATED => $repeats];
        if ($sort === 'problems' && $descending) {
            $reports[self::SLOW] = $report;
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::SLOW, self::REPEATED], $reports);
        $repeated = $repeats === null ? 0 : RepeatedQueriesCheck::count($repeats);
        $periodText = strtolower(Format::period($period));

        $tables = [new Table(
            'queries',
            'Queries',
            self::queryColumns(),
            self::queryRows($report, array_fill_keys(array_values($texts), true)),
            max(0, $report->keyCount - \count($report->rows)),
            'query',
            'queries',
            note: $sort === 'total' && $direction === 'desc' ? 'the most expensive first' : null,
        )];
        $repeatedTable = $repeats === null ? null : self::repeatedQueries($repeats, $texts);
        if ($repeatedTable !== null && $repeatedTable->rows !== []) {
            $tables[] = $repeatedTable;
        }

        return new ScreenView(
            $this->cards($report, $repeated),
            $tables,
            self::lead($report, $repeated, $period, $periodText),
            new CheckList(
                $this->checks->lines($findings, $this->id(), $period),
                'Nothing to flag',
                'No slow query and no repeated query in the ' . $periodText . '.',
                'Worth a look',
            ),
            $this->guide(),
            $raised,
        );
    }

    /** @return list<Card> */
    private function cards(Report $report, int $repeated): array
    {
        $slow = $report->totals[Metric::QUERY_SLOW]->count ?? 0;

        return [
            new Card('Queries', Format::number($report->totals[Metric::QUERY_DURATION]->count ?? 0), 'Run through Doctrine DBAL.'),
            new Card('SQL time', Format::duration($report->totals[Metric::QUERY_DURATION]->sum ?? 0), 'Time your code spent waiting for the database.'),
            new Card('Slow queries', Format::number($slow), 'Took ' . $this->slowThresholdMs . ' ms or more. The limit is yours to set.', $slow > 0 ? 'warn' : null),
            new Card('Repeated queries', Format::number($repeated), 'Same SELECT ' . $this->nPlusOneThreshold . ' times or more in one request, command or message.', $repeated > 0 ? 'warn' : null),
        ];
    }

    private static function lead(Report $report, int $repeated, Period $period, string $periodText): Lead
    {
        $executions = $report->totals[Metric::QUERY_DURATION]->count ?? 0;
        $slow = $report->totals[Metric::QUERY_SLOW]->count ?? 0;

        $sentence = sprintf(
            '%s in the %s, %s in total.',
            $executions === 1 ? '1 SQL query' : Format::number($executions) . ' SQL queries',
            $periodText,
            Format::duration($report->totals[Metric::QUERY_DURATION]->sum ?? 0),
        );

        $detail = match ($slow) {
            0 => 'None was slow',
            1 => '1 was slow',
            default => Format::number($slow) . ' were slow',
        } . '. ' . match ($repeated) {
            0 => 'None is repeated inside one request',
            1 => '1 query is repeated many times inside the same request',
            default => Format::number($repeated) . ' queries are repeated many times inside the same request',
        } . '.';

        return new Lead($sentence, $detail, $report->series, 'queries', Format::periodStart($period));
    }

    private function guide(): Guide
    {
        return new Guide([
            new GuideItem('One line, one shape of query.', 'The values are replaced by ?, so the same query with other values counts on the same line.'),
            new GuideItem('Fast but frequent.', 'A 2 ms query run 420 times costs more than a 44 ms one run 7 times. Look at the total before the average.'),
            new GuideItem(
                'Slow.',
                'A query counts as slow from ' . $this->slowThresholdMs . ' ms. For those, look at the indexes of the tables in its WHERE. The server itself is on Stack.',
                ['Stack' => 'stack'],
            ),
        ]);
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
            new Column('problems', 'Problems', false, 'problems'),
        ];
    }

    /**
     * @param array<string, true> $repeated the SQL texts of the repeated queries
     *
     * @return list<Row>
     */
    private static function queryRows(Report $report, array $repeated): array
    {
        $shown = $report->other === null ? $report->rows : [...$report->rows, $report->other];
        $largest = max(array_map(static fn(ReportRow $row): float => $row->sum(Metric::QUERY_DURATION), $shown));

        $rows = array_map(static fn(ReportRow $row): Row => self::queryRow($row, $largest, false, $row->label !== null && isset($repeated[$row->label])), $report->rows);
        if ($report->other !== null) {
            $rows[] = self::queryRow($report->other, $largest, true, false);
        }

        return $rows;
    }

    private static function queryRow(ReportRow $row, float $largest, bool $other, bool $nPlusOne): Row
    {
        $executions = $row->count(Metric::QUERY_DURATION);
        $total = $row->sum(Metric::QUERY_DURATION);
        $slow = $row->count(Metric::QUERY_SLOW);

        $tags = [];
        if ($slow > 0) {
            $tags[] = new Tag(Format::number($slow) . ' slow', 'warn');
        }
        if ($nPlusOne) {
            $tags[] = new Tag('N+1', 'warn');
        }

        return new Row($row->hash, [
            'query' => $other ? new Cell('Other queries') : self::sqlCell($row->label),
            'executions' => new Cell(Format::number($executions)),
            'total' => Cell::bar(Format::duration($total), $largest > 0 ? $total / $largest : 0.0),
            'avg' => new Cell(Format::duration($executions === 0 ? 0 : $total / $executions)),
            'max' => new Cell(Format::duration($row->max(Metric::QUERY_DURATION)), $slow > 0 ? 'warn' : null),
            'problems' => Cell::tags($tags),
        ], [
            'data-jm-query' => $row->hash,
            'data-jm-executions' => (string) $executions,
        ], $other);
    }

    /** @return array<string, string> query id => SQL text, for the repeated queries whose text is stored */
    private static function sqlTexts(ReaderInterface $reader, Report $repeats): array
    {
        $ids = [];
        foreach ($repeats->rows as $row) {
            $key = EntryPointKey::parse($row->key);
            if ($key !== null) {
                $ids[] = $key->subject;
            }
        }

        return $ids === [] ? [] : $reader->labels(array_values(array_unique($ids)));
    }

    /** @param array<string, string> $texts query id => SQL text */
    private static function repeatedQueries(Report $report, array $texts): Table
    {
        $rows = [];
        foreach ($report->rows as $row) {
            $key = EntryPointKey::parse($row->key);
            if ($key === null) {
                continue;
            }

            $rows[] = self::repeatedRow($row, $key->unitType === Entry::TYPE_TASK ? (TaskKey::parse($key->unitKey)->label ?? $key->unitKey) : $key->unitKey, [
                'where' => Cell::entryPoint($key->unitType, $key->unitKey),
                'query' => self::sqlCell($texts[$key->subject] ?? null),
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
