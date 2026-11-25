<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheSectionTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheSectionDeclaresItself(): void
    {
        $screen = self::section();

        static::assertSame('cache', $screen->id());
        static::assertSame('Cache', $screen->title());
        static::assertSame('What your code keeps aside to avoid computing or fetching it again.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['reads' => 'desc', 'pool' => 'asc', 'rate' => 'desc', 'misses' => 'desc', 'writes' => 'desc', 'deletes' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame([
            'title' => 'No cache read or write in the last hour.',
            'text' => 'Reads and writes on cache.app and on the pools your application declares show up here within a few seconds.',
        ], $screen->emptyState(Period::OneHour));
        static::assertSame('No cache read or write in the last 6 hours.', $screen->emptyState(Period::SixHours)['title']);
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new CacheSection(false, new ApplicationChecks([])))->isAvailable());
    }

    public function testTheScreenReadsTheWholeFamilyOnce(): void
    {
        foreach (array_keys(self::section()->sorts()) as $sort) {
            $reader = new FixedReader();
            self::section()->build($reader, Period::SixHours, self::NOW, $sort, 'asc', self::raised());

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Cache, Period::SixHours, self::NOW, null], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
            static::assertSame([], $reader->totalRequests);
        }
    }

    public function testTheCellsOfAPool(): void
    {
        $table = $this->build([self::pool('h1', 'cache.app', 30, 10, 4, 2)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('pools', $table->id);
        static::assertSame('Pools', $table->title);
        static::assertSame('pool', $table->noun);
        static::assertSame(
            [
                ['pool', 'Pool', false, 'pool'],
                ['reads', 'Reads', true, 'reads'],
                ['rate', 'Hit rate', true, 'rate'],
                ['misses', 'Misses', true, 'misses'],
                ['writes', 'Writes', true, 'writes'],
                ['deletes', 'Deletes', true, 'deletes'],
                ['problems', 'Problems', false, null],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-pool' => 'cache.app', 'data-jm-reads' => '40'], $row->attributes);
        static::assertSame(
            ['pool' => 'cache.app', 'reads' => '40', 'rate' => '75 %', 'misses' => '10', 'writes' => '4', 'deletes' => '2', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['pool']->kind);
        static::assertSame(['bar', ['share' => '75'], null], [$row->cells['rate']->kind, $row->cells['rate']->data, $row->cells['rate']->tone]);
        static::assertSame(['tags', []], [$row->cells['problems']->kind, $row->cells['problems']->tags]);
    }

    public function testAPoolWithoutReadsHasNoRateNorBar(): void
    {
        $row = $this->build([self::pool('h1', 'app.written', 0, 0, 5, 1)])->tables[0]->rows[0];

        static::assertSame(['—', 'zero', 'text'], [$row->cells['rate']->text, $row->cells['rate']->tone, $row->cells['rate']->kind]);
        static::assertSame(['0', '0', '5', '1'], array_map(static fn(string $id): string => $row->cells[$id]->text, ['reads', 'misses', 'writes', 'deletes']));
        static::assertSame([], $row->cells['problems']->tags);
        static::assertSame('0', $row->attributes['data-jm-reads']);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function provideThreshold(): iterable
    {
        yield '19 reads, none found' => [0, 19, false];
        yield '20 reads, 10 found' => [10, 10, false];
        yield '20 reads, 9 found' => [9, 11, true];
        yield 'no read' => [0, 0, false];
        yield '40 reads, 1 found' => [1, 39, true];
    }

    #[DataProvider('provideThreshold')]
    public function testALowPoolIsTaggedFlaggedAndCountedTheSameWay(int $hits, int $misses, bool $low): void
    {
        $rows = [self::pool('h1', 'app.pool', $hits, $misses, 1), self::pool('h2', 'app.fine', 90, 10)];
        $raised = self::raisedFor($rows);
        $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, 'pool', 'asc', $raised);
        $row = $view->tables[0]->rows[1];

        static::assertSame('app.pool', $row->cells['pool']->text);
        static::assertSame($low, $raised->has('cache.low_hit_rate'));
        static::assertSame($low ? [['low hit rate', 'warn']] : [], array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags));
        static::assertNotNull($view->checks);
        static::assertSame($low ? ['cache.low_hit_rate'] : [], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
        static::assertNotNull($view->brief);
        static::assertSame($low ? '1 pool has a low hit rate.' : '', $view->brief->standout);
        static::assertNotSame('warn', $row->cells['rate']->tone);
    }

    public function testTheHitRateCardIsJudgedByTheSameRule(): void
    {
        $low = $this->build([self::pool('h1', 'app.a', 9, 11)]);
        $half = $this->build([self::pool('h1', 'app.a', 10, 10)]);
        $few = $this->build([self::pool('h1', 'app.a', 0, 19)]);

        static::assertSame(['45 %', 'warn'], [$low->cards[1]->value, $low->cards[1]->tone]);
        static::assertSame(['50 %', null], [$half->cards[1]->value, $half->cards[1]->tone]);
        static::assertSame(['0 %', null], [$few->cards[1]->value, $few->cards[1]->tone]);
    }

    public function testTheHitRateCardIsNotInAlertWhenNoListedPoolIsFlagged(): void
    {
        $rows = [self::pool('h1', 'cache.app', 5, 5)];
        $view = self::section()->build($this->reader($rows, self::pool('ho', Aggregator::OTHER, 1, 39)), Period::OneHour, self::NOW, 'reads', 'desc', self::raisedFor($rows));

        static::assertSame(['12 %', null], [$view->cards[1]->value, $view->cards[1]->tone]);
        static::assertSame([[], []], array_map(static fn(Row $row): array => $row->cells['problems']->tags, $view->tables[0]->rows));
        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertNotNull($view->brief);
        static::assertSame('', $view->brief->standout);
    }

    public function testTheHitRateCardIsInAlertWhenAListedPoolIsFlaggedEvenAboveHalfOverall(): void
    {
        $rows = [self::pool('h1', 'app.hot', 90, 0), self::pool('h2', 'app.cold', 2, 18)];
        $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, 'reads', 'desc', self::raisedFor($rows));

        static::assertSame(['84 %', 'warn'], [$view->cards[1]->value, $view->cards[1]->tone]);
        static::assertNotNull($view->checks);
        static::assertSame(['cache.low_hit_rate'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testOtherPoolsAreNeverTaggedNorCounted(): void
    {
        $other = self::pool('ho', Aggregator::OTHER, 1, 39, 7, 3);
        $rows = [self::pool('h1', 'cache.app', 30, 10), self::pool('h2', 'app.cold', 2, 28)];
        $view = self::section()->build($this->reader($rows, $other), Period::OneHour, self::NOW, 'reads', 'desc', self::raisedFor($rows));
        $table = $view->tables[0];

        static::assertCount(3, $table->rows);
        $last = $table->rows[2];
        static::assertTrue($last->muted);
        static::assertSame('ho', $last->key);
        static::assertSame(
            ['pool' => 'Other pools', 'reads' => '40', 'rate' => '2.5 %', 'misses' => '39', 'writes' => '7', 'deletes' => '3', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $last->cells),
        );
        static::assertSame(['text', 'bar', []], [$last->cells['pool']->kind, $last->cells['rate']->kind, $last->cells['problems']->tags]);
        static::assertSame(['data-jm-pool' => Aggregator::OTHER, 'data-jm-reads' => '40'], $last->attributes);
        static::assertSame(0, $table->remaining);
        static::assertNotNull($view->brief);
        static::assertSame('1 pool has a low hit rate.', $view->brief->standout);
    }

    public function testTheSorts(): void
    {
        $rows = [
            self::pool('h1', 'cache.app', 30, 10, 1, 5),
            self::pool('h2', 'app.users', 2, 30, 9, 0),
            self::pool('h3', 'app.empty', 0, 0, 3, 1),
            self::pool('h4', 'app.hot', 50, 0, 0, 2),
        ];

        $expected = [
            'reads desc' => ['app.hot', 'cache.app', 'app.users', 'app.empty'],
            'reads asc' => ['app.empty', 'app.users', 'cache.app', 'app.hot'],
            'pool asc' => ['app.empty', 'app.hot', 'app.users', 'cache.app'],
            'pool desc' => ['cache.app', 'app.users', 'app.hot', 'app.empty'],
            'misses desc' => ['app.users', 'cache.app', 'app.empty', 'app.hot'],
            'rate desc' => ['app.hot', 'cache.app', 'app.users', 'app.empty'],
            'rate asc' => ['app.empty', 'app.users', 'cache.app', 'app.hot'],
            'writes desc' => ['app.users', 'app.empty', 'cache.app', 'app.hot'],
            'deletes desc' => ['cache.app', 'app.hot', 'app.empty', 'app.users'],
        ];

        foreach ($expected as $order => $pools) {
            [$sort, $direction] = explode(' ', $order);
            $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, $sort, $direction, self::raised());

            static::assertSame($pools, array_map(static fn(Row $row): string => $row->cells['pool']->text, $view->tables[0]->rows), $order);
        }
    }

    public function testARemovedSortFallsBackToTheDefault(): void
    {
        $rows = [self::pool('h1', 'app.small', 9, 0), self::pool('h2', 'app.big', 1, 30)];

        foreach (['hits', 'unknown'] as $sort) {
            $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, $sort, 'desc', self::raised());

            static::assertSame(['app.big', 'app.small'], array_map(static fn(Row $row): string => $row->cells['pool']->text, $view->tables[0]->rows), $sort);
        }
    }

    public function testTheCards(): void
    {
        $view = $this->build([
            self::pool('h1', 'cache.app', 12, 3, 3, 1),
            self::pool('h2', 'app.users', 2, 23, 20, 0),
        ], self::pool('ho', Aggregator::OTHER, 1, 1, 1, 4), Period::OneDay);

        static::assertSame(['Reads', 'Hit rate', 'Writes', 'Deletes'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['42', '36 %', '24', '5'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(
            ['Times your code asked the cache.', 'Reads found in the cache.', 'Items saved.', 'Items removed by your code.'],
            array_map(static fn(Card $card): string => $card->hint, $view->cards),
        );
        static::assertSame(['warn', null, null, null], [$view->cards[1]->tone, $view->cards[0]->tone, $view->cards[2]->tone, $view->cards[3]->tone]);
        static::assertSame([false, false, false, false], array_map(static fn(Card $card): bool => $card->wide, $view->cards));
    }

    public function testCardsWithoutReadsShowNoRate(): void
    {
        $view = $this->build([self::pool('h1', 'cache.app', 0, 0, 2, 0)]);

        static::assertSame(['0', '—', '2', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertNull($view->cards[1]->tone);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, string, string}>
     */
    public static function provideBrief(): iterable
    {
        yield 'reads' => [[self::pool('h1', 'a', 1600, 68, 5), self::pool('h2', 'b', 1, 0)], '1,669', '1,669 cache reads (96 % found)'];
        yield 'one read' => [[self::pool('h1', 'a', 1, 0, 3)], '1', '1 cache read (100 % found)'];
        yield 'one read, a miss' => [[self::pool('h1', 'a', 0, 1)], '1', '1 cache read (0 % found)'];
        yield 'writes only' => [[self::pool('h1', 'a', 0, 0, 1200, 3)], '1,200', '1,200 cache writes'];
        yield 'one write' => [[self::pool('h1', 'a', 0, 0, 1, 9)], '1', '1 cache write'];
        yield 'deletes only' => [[self::pool('h1', 'a', 0, 0, 0, 4)], '4', '4 cache deletes'];
        yield 'one delete' => [[self::pool('h1', 'a', 0, 0, 0, 1)], '1', '1 cache delete'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('provideBrief')]
    public function testTheBrief(array $rows, string $count, string $clause): void
    {
        $brief = $this->build($rows)->brief;

        static::assertNotNull($brief);
        static::assertSame([$count, $clause, '', '', null], [$brief->count, $brief->clause, $brief->figures, $brief->standout, $brief->tone]);
    }

    public function testTheBriefCountsTheLowPools(): void
    {
        $rows = [self::pool('h1', 'app.a', 1, 19), self::pool('h2', 'app.b', 2, 30), self::pool('h3', 'app.c', 20, 0)];
        $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, 'reads', 'desc', self::raisedFor($rows));

        static::assertNotNull($view->brief);
        static::assertSame('2 pools have a low hit rate.', $view->brief->standout);
        static::assertSame(
            ['app.b' => ['low hit rate'], 'app.a' => ['low hit rate'], 'app.c' => []],
            array_combine(
                array_map(static fn(Row $row): string => $row->cells['pool']->text, $view->tables[0]->rows),
                array_map(static fn(Row $row): array => array_map(static fn(Tag $tag): string => $tag->text, $row->cells['problems']->tags), $view->tables[0]->rows),
            ),
        );
    }

    public function testTheCheckLinesHaveNeitherPlaceNorLink(): void
    {
        $rows = [self::pool('h1', 'app.cold', 2, 30), self::pool('h2', 'cache.app', 30, 2)];
        $view = self::section()->build($this->reader($rows), Period::OneHour, self::NOW, 'reads', 'desc', self::raisedFor($rows));

        static::assertNotNull($view->checks);
        static::assertSame('no pool with a low hit rate', $view->checks->emptyText);
        static::assertCount(1, $view->checks->lines);
        $line = $view->checks->lines[0];
        static::assertSame(['cache.low_hit_rate', 'warn', '1 pool with a low hit rate', 'app.cold found 6.3 % of its 32 reads.'], [$line->id, $line->mark->tone, $line->title, $line->sentence]);
        static::assertSame(['', null, []], [$line->where, $line->path, $line->query]);
    }

    public function testWithoutRaisedCheckThereIsNoLine(): void
    {
        $view = $this->build([self::pool('h1', 'app.cold', 2, 30)]);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('no pool with a low hit rate', $view->checks->emptyText);
    }

    public function testTheChecksReadNothingMore(): void
    {
        $rows = [self::pool('h1', 'app.cold', 2, 30), self::pool('h2', 'app.colder', 1, 30)];
        $reader = $this->reader($rows);

        $view = self::section()->build($reader, Period::OneHour, self::NOW, 'pool', 'asc', self::raisedFor($rows));

        static::assertNotNull($view->checks);
        static::assertCount(1, $view->checks->lines);
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
    }

    public function testTheGuide(): void
    {
        $guide = $this->build([self::pool('h1', 'cache.app', 1, 1)])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [['Hit rate.', 'The share of reads the cache could answer. A miss means the work was done again. A pool under 50 % on 20 reads or more is flagged: it costs more than it saves.', []]],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        $view = $this->build([]);

        static::assertTrue($view->isEmpty());
        static::assertNull($view->brief);
    }

    private static function section(): CacheSection
    {
        return new CacheSection(true, new ApplicationChecks([new CacheHitRateCheck()]));
    }

    private static function raised(): RaisedChecks
    {
        return new RaisedChecks([], [], Period::OneHour);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private static function raisedFor(array $rows): RaisedChecks
    {
        $check = new CacheHitRateCheck();
        $keys = [];
        foreach ($rows as $row) {
            foreach ($check->metrics() as $metric) {
                if ($row->count($metric) > 0) {
                    $keys[$metric][$row->hash] = new MetricValue($row->count($metric), 0.0, 0.0);
                }
            }
        }

        return new RaisedChecks($check->raisedBy($keys) ? [$check->id() => $check] : [], [], Period::OneHour);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function build(array $rows, ?ReportRow $other = null, Period $period = Period::OneHour): ScreenView
    {
        return self::section()->build($this->reader($rows, $other), $period, self::NOW, 'reads', 'desc', self::raised());
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function reader(array $rows, ?ReportRow $other = null): FixedReader
    {
        $totals = [];
        foreach ([...$rows, ...($other === null ? [] : [$other])] as $row) {
            foreach ($row->metrics as $type => $value) {
                $totals[$type] = new MetricValue(($totals[$type]->count ?? 0) + $value->count, 0.0, 0.0);
            }
        }

        return new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows), [], $totals));
    }

    private static function pool(string $hash, string $name, int $hits, int $misses, int $writes = 0, int $deletes = 0): ReportRow
    {
        $metrics = array_filter([
            Metric::CACHE_HIT => $hits,
            Metric::CACHE_MISS => $misses,
            Metric::CACHE_WRITE => $writes,
            Metric::CACHE_DELETE => $deletes,
        ]);

        return new ReportRow($hash, $name, $name, array_map(static fn(int $count): MetricValue => new MetricValue($count, 0.0, 0.0), $metrics));
    }
}
