<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class ExceptionsScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const OTHER_EXCEPTIONS = 'Other exceptions';

    public function __construct(private readonly bool $available) {}

    public function id(): string
    {
        return 'exceptions';
    }

    public function label(): string
    {
        return 'Exceptions';
    }

    public function heading(): string
    {
        return 'Errors per exception class';
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
        return ['count' => 'desc', 'last' => 'desc', 'class' => 'asc'];
    }

    public function emptyState(): array
    {
        return [
            'title' => 'No exceptions recorded',
            'text' => 'An exception behind a 5xx response, a failed command or a failed message shows up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Exception, seriesMetric: Metric::EXCEPTION_COUNT));
        $where = $reader->read(new ReportQuery($period, $now, Family::ExceptionWhere));

        $rows = self::sorted($report->rows, Metric::EXCEPTION_COUNT, $sort, $direction);
        $exceptionRows = array_map(
            static fn(ReportRow $row): Row => self::row($row, Metric::EXCEPTION_COUNT, $now, ['exception' => new Cell($row->key, kind: 'code')], $row->key),
            \array_slice($rows, 0, self::LIMIT),
        );
        if ($report->other !== null) {
            $exceptionRows[] = self::row($report->other, Metric::EXCEPTION_COUNT, $now, ['exception' => new Cell(self::OTHER_EXCEPTIONS)], $report->other->key, true);
        }

        $tables = [new Table('exceptions', 'Exceptions', [
            new Column('exception', 'Exception', false, 'class'),
            new Column('count', 'Count', sort: 'count'),
            new Column('last', 'Last seen', sort: 'last'),
        ], $exceptionRows, max(0, \count($rows) - self::LIMIT), 'exception')];

        $entryPoints = self::entryPoints($where, $now);
        if ($entryPoints->rows !== []) {
            $tables[] = $entryPoints;
        }

        return new ScreenView(self::cards($report, $where, $period, $now), $tables);
    }

    /** @return list<Card> */
    private static function cards(Report $report, Report $where, Period $period, int $now): array
    {
        $last = null;
        foreach ([...$report->rows, ...($report->other === null ? [] : [$report->other])] as $row) {
            if ($last === null || $row->max(Metric::EXCEPTION_COUNT) > $last->max(Metric::EXCEPTION_COUNT)) {
                $last = $row;
            }
        }

        return [
            new Card('Exceptions', Format::number($report->totals[Metric::EXCEPTION_COUNT]->count ?? 0), Format::period($period), series: $report->series, wide: true),
            new Card('Classes', Format::number($report->keyCount), 'Distinct exception classes'),
            new Card(
                'Last exception',
                $last === null ? '—' : Format::ago((int) $last->max(Metric::EXCEPTION_COUNT), $now),
                $last === null ? '' : ($last === $report->other ? self::OTHER_EXCEPTIONS : $last->key),
            ),
            new Card('Entry points', Format::number($where->keyCount), 'Where an exception was thrown', $where->keyCount > 0 ? 'bad' : null),
        ];
    }

    private static function entryPoints(Report $report, int $now): Table
    {
        $pairs = [];
        foreach (self::sorted($report->rows, Metric::EXCEPTION_WHERE_COUNT, 'count', 'desc') as $row) {
            $key = EntryPointKey::parse($row->key);
            if ($key !== null) {
                $pairs[] = [$row, $key];
            }
        }

        $rows = [];
        foreach (\array_slice($pairs, 0, self::LIMIT) as [$row, $key]) {
            $rows[] = self::row($row, Metric::EXCEPTION_WHERE_COUNT, $now, [
                'exception' => new Cell($key->subject, kind: 'code'),
                'where' => Cell::entryPoint($key->unitType, $key->unitKey),
            ], $key->subject);
        }
        if ($report->other !== null) {
            $rows[] = self::row($report->other, Metric::EXCEPTION_WHERE_COUNT, $now, ['exception' => new Cell('Other entry points')], $report->other->key, true);
        }

        return new Table('where', 'Where', [
            new Column('exception', 'Exception', false),
            new Column('where', 'Where', false),
            new Column('count', 'Count'),
            new Column('last', 'Last seen'),
        ], $rows, max(0, \count($pairs) - self::LIMIT), 'entry point');
    }

    /** @param array<string, Cell> $cells */
    private static function row(ReportRow $row, string $metric, int $now, array $cells, string $exception, bool $other = false): Row
    {
        $count = $row->count($metric);

        return new Row($row->hash, $cells + [
            'count' => new Cell(Format::number($count)),
            'last' => new Cell(Format::ago((int) $row->max($metric), $now)),
        ], [
            'data-jm-exception' => $exception,
            'data-jm-count' => (string) $count,
        ], $other);
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return list<ReportRow>
     */
    private static function sorted(array $rows, string $metric, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * match ($sort) {
            'class' => strcmp($a->key, $b->key),
            'last' => $a->max($metric) <=> $b->max($metric),
            default => $a->count($metric) <=> $b->count($metric),
        } ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
