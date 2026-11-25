<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
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

final class CacheSection implements SectionInterface
{
    private const LIMIT = 100;
    private const OTHER_POOLS = 'Other pools';
    private const NO_RATE = '—';
    /** Reads from which a hit rate under half is worth a look. */
    private const LOW_RATE_READS = 20;

    public function __construct(private readonly bool $available) {}

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
        return ['reads' => 'desc', 'pool' => 'asc', 'hits' => 'desc', 'misses' => 'desc', 'rate' => 'desc', 'writes' => 'desc', 'deletes' => 'desc'];
    }

    public function emptyState(Period $period): array
    {
        return [
            'title' => 'No cache read or write in the ' . lcfirst(Format::period($period)) . '.',
            'text' => 'Reads and writes on cache.app and on the pools your application declares show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Cache));

        $rows = self::sorted($report->rows, $sort, $direction);
        $poolRows = array_map(static fn(ReportRow $row): Row => self::row($row), \array_slice($rows, 0, self::LIMIT));
        if ($report->other !== null) {
            $poolRows[] = self::row($report->other, true);
        }

        return new ScreenView(self::cards($report, $period), [new Table('pools', 'Pools', [
            new Column('pool', 'Pool', false, 'pool'),
            new Column('hits', 'Hits', sort: 'hits'),
            new Column('misses', 'Misses', sort: 'misses'),
            new Column('rate', 'Hit rate', sort: 'rate'),
            new Column('writes', 'Writes', sort: 'writes'),
            new Column('deletes', 'Deletes', sort: 'deletes'),
        ], $poolRows, max(0, \count($rows) - self::LIMIT), 'pool')]);
    }

    /** @return list<Card> */
    private static function cards(Report $report, Period $period): array
    {
        $count = static fn(string $metric): int => $report->totals[$metric]->count ?? 0;
        $hits = $count(Metric::CACHE_HIT);
        $reads = $hits + $count(Metric::CACHE_MISS);

        return [
            new Card('Reads', Format::number($reads), Format::period($period)),
            new Card('Hit rate', self::rate($hits, $reads), 'Reads found in the cache', self::isLow($hits, $reads) ? 'warn' : null),
            new Card('Writes', Format::number($count(Metric::CACHE_WRITE)), 'Items saved'),
            new Card('Deletes', Format::number($count(Metric::CACHE_DELETE)), 'Items deleted'),
        ];
    }

    private static function row(ReportRow $row, bool $other = false): Row
    {
        $hits = $row->count(Metric::CACHE_HIT);
        $misses = $row->count(Metric::CACHE_MISS);
        $reads = $hits + $misses;

        return new Row($row->hash, [
            'pool' => $other ? new Cell(self::OTHER_POOLS) : new Cell($row->key, kind: 'code'),
            'hits' => new Cell(Format::number($hits)),
            'misses' => new Cell(Format::number($misses)),
            'rate' => new Cell(self::rate($hits, $reads), self::isLow($hits, $reads) ? 'warn' : null),
            'writes' => new Cell(Format::number($row->count(Metric::CACHE_WRITE))),
            'deletes' => new Cell(Format::number($row->count(Metric::CACHE_DELETE))),
        ], [
            'data-jm-pool' => $row->key,
            'data-jm-reads' => (string) $reads,
        ], $other);
    }

    private static function rate(int $hits, int $reads): string
    {
        return $reads === 0 ? self::NO_RATE : Format::percent($hits / $reads);
    }

    private static function isLow(int $hits, int $reads): bool
    {
        return $reads >= self::LOW_RATE_READS && $hits * 2 < $reads;
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return list<ReportRow>
     */
    private static function sorted(array $rows, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;
        $reads = static fn(ReportRow $row): int => $row->count(Metric::CACHE_HIT) + $row->count(Metric::CACHE_MISS);
        $rate = static fn(ReportRow $row): float => $reads($row) === 0 ? -1.0 : $row->count(Metric::CACHE_HIT) / $reads($row);

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * match ($sort) {
            'pool' => strcmp($a->key, $b->key),
            'hits' => $a->count(Metric::CACHE_HIT) <=> $b->count(Metric::CACHE_HIT),
            'misses' => $a->count(Metric::CACHE_MISS) <=> $b->count(Metric::CACHE_MISS),
            'rate' => $rate($a) <=> $rate($b),
            'writes' => $a->count(Metric::CACHE_WRITE) <=> $b->count(Metric::CACHE_WRITE),
            'deletes' => $a->count(Metric::CACHE_DELETE) <=> $b->count(Metric::CACHE_DELETE),
            default => $reads($a) <=> $reads($b),
        } ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
