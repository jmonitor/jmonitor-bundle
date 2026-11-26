<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class CacheSection implements SectionInterface
{
    private const LIMIT = 100;
    private const SCREEN = 'cache-http';
    private const CHECK = 'cache.low_hit_rate';
    private const OTHER_POOLS = 'Other pools';
    private const NO_RATE = '—';

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'cache';
    }

    public function title(): string
    {
        return 'Cache';
    }

    public function summary(): string
    {
        return 'What your code keeps aside to avoid computing or fetching it again.';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return ['reads' => 'desc', 'pool' => 'asc', 'rate' => 'desc', 'misses' => 'desc', 'writes' => 'desc', 'deletes' => 'desc'];
    }

    public function emptyState(Period $period): array
    {
        return [
            'title' => 'No cache read or write in the ' . lcfirst(Format::period($period)) . '.',
            'text' => 'Reads and writes on cache.app and on the pools your application declares show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Cache));

        $rows = self::sorted($report->rows, $sort, $direction);
        $poolRows = array_map(static fn(ReportRow $row): Row => self::row($row), \array_slice($rows, 0, self::LIMIT));
        if ($report->other !== null) {
            $poolRows[] = self::row($report->other, true);
        }
        $table = new Table('pools', 'Pools', self::columns(), $poolRows, max(0, \count($rows) - self::LIMIT), 'pool');

        if ($poolRows === []) {
            return new ScreenView([], [$table]);
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::CHECK], [self::CHECK => $report]);
        $low = \count(array_filter($report->rows, static fn(ReportRow $row): bool => CacheHitRateCheck::isLow($row->count(Metric::CACHE_HIT), self::reads($row))));

        return new ScreenView(
            self::cards($report, $low),
            [$table],
            checks: new CheckList($this->checks->lines($findings, self::SCREEN, $period), emptyText: 'no pool with a low hit rate'),
            guide: new Guide([
                new GuideItem('Hit rate.', 'The share of reads the cache could answer. A miss means the work was done again. A pool under 50 % on 20 reads or more is flagged: it costs more than it saves.'),
            ]),
            brief: self::brief($report, $low),
        );
    }

    /** @return list<Column> */
    private static function columns(): array
    {
        return [
            new Column('pool', 'Pool', false, 'pool'),
            new Column('reads', 'Reads', sort: 'reads'),
            new Column('rate', 'Hit rate', sort: 'rate'),
            new Column('misses', 'Misses', sort: 'misses'),
            new Column('writes', 'Writes', sort: 'writes'),
            new Column('deletes', 'Deletes', sort: 'deletes'),
            new Column('problems', 'Problems', false),
        ];
    }

    /** @return list<Card> */
    private static function cards(Report $report, int $low): array
    {
        $hits = self::total($report, Metric::CACHE_HIT);
        $reads = $hits + self::total($report, Metric::CACHE_MISS);

        return [
            new Card('Reads', Format::number($reads), 'Times your code asked the cache.'),
            new Card('Hit rate', $reads === 0 ? self::NO_RATE : Format::percent($hits / $reads), 'Reads found in the cache.', $low > 0 ? 'warn' : null),
            new Card('Writes', Format::number(self::total($report, Metric::CACHE_WRITE)), 'Items saved.'),
            new Card('Deletes', Format::number(self::total($report, Metric::CACHE_DELETE)), 'Items removed by your code.'),
        ];
    }

    private static function brief(Report $report, int $low): Brief
    {
        $hits = self::total($report, Metric::CACHE_HIT);
        $reads = $hits + self::total($report, Metric::CACHE_MISS);
        $writes = self::total($report, Metric::CACHE_WRITE);
        $deletes = self::total($report, Metric::CACHE_DELETE);

        [$count, $clause] = match (true) {
            $reads > 0 => [$reads, self::counted($reads, 'cache read') . ' (' . Format::percent($hits / $reads) . ' found)'],
            $writes > 0 => [$writes, self::counted($writes, 'cache write')],
            default => [$deletes, self::counted($deletes, 'cache delete')],
        };

        return new Brief(Format::number($count), $clause, standout: match ($low) {
            0 => '',
            1 => '1 pool has a low hit rate.',
            default => Format::number($low) . ' pools have a low hit rate.',
        });
    }

    private static function counted(int $count, string $noun): string
    {
        return $count === 1 ? '1 ' . $noun : Format::number($count) . ' ' . $noun . 's';
    }

    private static function total(Report $report, string $metric): int
    {
        return $report->totals[$metric]->count ?? 0;
    }

    private static function reads(ReportRow $row): int
    {
        return $row->count(Metric::CACHE_HIT) + $row->count(Metric::CACHE_MISS);
    }

    private static function row(ReportRow $row, bool $other = false): Row
    {
        $hits = $row->count(Metric::CACHE_HIT);
        $reads = self::reads($row);

        return new Row($row->hash, [
            'pool' => $other ? new Cell(self::OTHER_POOLS) : new Cell($row->key, kind: 'code'),
            'reads' => new Cell(Format::number($reads)),
            'rate' => $reads === 0 ? new Cell(self::NO_RATE, 'zero') : Cell::bar(Format::percent($hits / $reads), $hits / $reads),
            'misses' => new Cell(Format::number($row->count(Metric::CACHE_MISS))),
            'writes' => new Cell(Format::number($row->count(Metric::CACHE_WRITE))),
            'deletes' => new Cell(Format::number($row->count(Metric::CACHE_DELETE))),
            'problems' => Cell::tags(!$other && CacheHitRateCheck::isLow($hits, $reads) ? [new Tag('low hit rate', 'warn')] : []),
        ], [
            'data-jm-pool' => $row->key,
            'data-jm-reads' => (string) $reads,
        ], $other);
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return list<ReportRow>
     */
    private static function sorted(array $rows, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;
        $rate = static fn(ReportRow $row): float => self::reads($row) === 0 ? -1.0 : $row->count(Metric::CACHE_HIT) / self::reads($row);

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * match ($sort) {
            'pool' => strcmp($a->key, $b->key),
            'misses' => $a->count(Metric::CACHE_MISS) <=> $b->count(Metric::CACHE_MISS),
            'rate' => $rate($a) <=> $rate($b),
            'writes' => $a->count(Metric::CACHE_WRITE) <=> $b->count(Metric::CACHE_WRITE),
            'deletes' => $a->count(Metric::CACHE_DELETE) <=> $b->count(Metric::CACHE_DELETE),
            default => self::reads($a) <=> self::reads($b),
        } ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
