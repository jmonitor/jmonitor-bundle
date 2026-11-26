<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheHitRateCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new CacheHitRateCheck();

        static::assertInstanceOf(KeyedCheckInterface::class, $check);
        static::assertSame('cache.low_hit_rate', $check->id());
        static::assertSame(Severity::Warning, $check->severity());
        static::assertSame('cache-http', $check->screen());
        static::assertSame('Cache & HTTP', $check->screenLabel());
        static::assertSame([Metric::CACHE_HIT, Metric::CACHE_MISS], $check->metrics());
        static::assertSame(20, CacheHitRateCheck::MIN_READS);
    }

    public function testItReadsEveryPool(): void
    {
        static::assertEquals(new ReportQuery(Period::SixHours, self::NOW, Family::Cache), (new CacheHitRateCheck())->query(Period::SixHours, self::NOW));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function rates(): iterable
    {
        yield 'no read' => [0, 0, false];
        yield '19 reads, none found' => [0, 19, false];
        yield '20 reads, half found' => [10, 20, false];
        yield '20 reads, 9 found' => [9, 20, true];
        yield '20 reads, none found' => [0, 20, true];
        yield '21 reads, 10 found' => [10, 21, true];
        yield '21 reads, 11 found' => [11, 21, false];
        yield 'many reads, all found' => [5000, 5000, false];
    }

    #[DataProvider('rates')]
    public function testARateIsLowFromTwentyReadsWithLessThanHalfFound(int $hits, int $reads, bool $low): void
    {
        static::assertSame($low, CacheHitRateCheck::isLow($hits, $reads));
    }

    /**
     * @return iterable<string, array{array<string, array<string, MetricValue>>, bool}>
     */
    public static function keys(): iterable
    {
        yield 'no key' => [[], false];
        yield '19 reads, none found' => [self::keyed(['cache.app' => [0, 19]]), false];
        yield '20 reads, 10 found' => [self::keyed(['cache.app' => [10, 10]]), false];
        yield '20 reads, 9 found' => [self::keyed(['cache.app' => [9, 11]]), true];
        yield 'a pool without read' => [self::keyed(['cache.app' => [0, 0]]), false];
        yield 'misses only' => [[Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(20, 0.0, 0.0)]], true];
        yield 'hits only' => [[Metric::CACHE_HIT => [md5('cache.app') => new MetricValue(40, 0.0, 0.0)]], false];
        yield 'one low pool among good ones' => [self::keyed(['cache.app' => [90, 10], 'cache.users' => [3, 30], 'cache.rates' => [0, 5]]), true];
        yield 'the pools are judged one by one, not together' => [self::keyed(['cache.app' => [0, 15], 'cache.users' => [0, 15]]), false];
    }

    /**
     * @param array<string, array<string, MetricValue>> $keys
     */
    #[DataProvider('keys')]
    public function testItIsRaisedByOnePoolUnderTheThreshold(array $keys, bool $raised): void
    {
        static::assertSame($raised, (new CacheHitRateCheck())->raisedBy($keys));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one pool' => [[self::row('cache.app', 9, 11)], '1 pool with a low hit rate', 'cache.app found 45 % of its 20 reads.'];
        yield 'nothing found' => [[self::row('cache.app', 0, 1234)], '1 pool with a low hit rate', 'cache.app found 0 % of its 1,234 reads.'];
        yield 'the lowest rate wins' => [
            [self::row('cache.app', 9, 11), self::row('cache.users', 1, 29), self::row('cache.rates', 50, 10)],
            '2 pools with a low hit rate',
            'cache.users found 3.3 % of its 30 reads. 1 other pool is under 50 % too.',
        ];
        yield 'several other pools' => [
            [self::row('cache.a', 9, 11), self::row('cache.b', 2, 38), self::row('cache.c', 0, 19), self::row('cache.d', 4, 16)],
            '3 pools with a low hit rate',
            'cache.b found 5 % of its 40 reads. 2 other pools are under 50 % too.',
        ];
        yield 'a tie on the rate goes to the most reads' => [
            [self::row('cache.a', 1, 19), self::row('cache.b', 2, 38)],
            '2 pools with a low hit rate',
            'cache.b found 5 % of its 40 reads. 1 other pool is under 50 % too.',
        ];
        yield 'then to the smallest key' => [
            [self::row('cache.b', 2, 38), self::row('cache.a', 2, 38)],
            '2 pools with a low hit rate',
            'cache.a found 5 % of its 40 reads. 1 other pool is under 50 % too.',
        ];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsThePoolWithTheLowestRate(array $rows, string $title, string $sentence): void
    {
        $finding = (new CacheHitRateCheck())->finding(new Report($rows), self::totals(), Period::OneHour);

        static::assertSame('cache.low_hit_rate', $finding->id);
        static::assertSame(Severity::Warning, $finding->severity);
        static::assertSame('cache-http', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A miss means the work was done again, then written to the cache. Under 50 %, a pool costs more than it saves.', $finding->why);
        static::assertSame('Look at how its keys are built and how long its items live: a key that changes on every request never hits.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('cache.app', 9, 11)], self::row(Aggregator::OTHER, 0, 500), 2);

        $finding = (new CacheHitRateCheck())->finding($report, self::totals(), Period::OneHour);

        static::assertSame('1 pool with a low hit rate', $finding->title);
        static::assertSame('cache.app found 45 % of its 20 reads.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingSaysWhatALowRateIs(): void
    {
        foreach ([
            new Report(),
            new Report([self::row('cache.app', 10, 10), self::row('cache.users', 0, 19)]),
            new Report([], self::row(Aggregator::OTHER, 0, 300), 1),
        ] as $report) {
            $finding = (new CacheHitRateCheck())->finding($report, self::totals(), Period::SixHours);

            static::assertSame('Low cache hit rate', $finding->title);
            static::assertSame('Pools that found less than half of 20 reads or more in the last 6 hours.', $finding->sentence);
        }
    }

    /**
     * @param array<string, array{int, int}> $pools pool => [hits, misses]
     *
     * @return array<string, array<string, MetricValue>>
     */
    private static function keyed(array $pools): array
    {
        $keys = [];

        foreach ($pools as $pool => [$hits, $misses]) {
            $keys[Metric::CACHE_HIT][md5($pool)] = new MetricValue($hits, 0.0, 0.0);
            $keys[Metric::CACHE_MISS][md5($pool)] = new MetricValue($misses, 0.0, 0.0);
        }

        return $keys;
    }

    /** @return array<string, MetricValue> */
    private static function totals(): array
    {
        return [Metric::CACHE_HIT => new MetricValue(100, 0.0, 0.0), Metric::CACHE_MISS => new MetricValue(900, 0.0, 0.0)];
    }

    private static function row(string $pool, int $hits, int $misses): ReportRow
    {
        return new ReportRow(md5($pool), $pool, null, [
            Metric::CACHE_HIT => new MetricValue($hits, 0.0, 0.0),
            Metric::CACHE_MISS => new MetricValue($misses, 0.0, 0.0),
            Metric::CACHE_WRITE => new MetricValue($misses, 0.0, 0.0),
        ]);
    }
}
