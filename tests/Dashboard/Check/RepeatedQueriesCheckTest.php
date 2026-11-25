<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepeatedQueriesCheckTest extends TestCase
{
    private const NOW = 1800000000;
    private const QUERY_ID = '0123456789abcdef';

    public function testTheCheckDeclaresItself(): void
    {
        $check = new RepeatedQueriesCheck(10);

        static::assertSame('queries.repeated', $check->id());
        static::assertSame(Severity::Warning, $check->severity());
        static::assertSame('queries', $check->screen());
        static::assertSame('Queries', $check->screenLabel());
        static::assertSame([Metric::NPLUS1_CALLS], $check->metrics());
    }

    public function testItReadsTheRepeatedQueriesAsTheQueriesScreenDoes(): void
    {
        static::assertEquals(
            new ReportQuery(Period::OneHour, self::NOW, Family::NPlusOne, Metric::NPLUS1_DURATION, 'sum', true, 100),
            (new RepeatedQueriesCheck(10))->query(Period::OneHour, self::NOW),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function entryPoints(): iterable
    {
        yield 'request' => [Entry::TYPE_REQUEST, 'GET api.consumptions.list', 'GET api.consumptions.list'];
        yield 'command' => [Entry::TYPE_COMMAND, 'app:import', 'Command app:import'];
        yield 'message' => [Entry::TYPE_MESSAGE, 'App\\Message\\SendInvoice', 'Message App\\Message\\SendInvoice'];
        yield 'task' => [Entry::TYPE_TASK, (new TaskKey('default', '28af6db0', 'app:work', 'every 1 second'))->toString(), 'Task app:work'];
    }

    #[DataProvider('entryPoints')]
    public function testTheCulpritIsNamedByItsEntryPoint(string $unitType, string $unitKey, string $entryPoint): void
    {
        $finding = (new RepeatedQueriesCheck(10))->finding(new Report([self::row($unitType, $unitKey, 9.0, 2, 6.0)], keyCount: 1), self::totals(), Period::OneHour);

        static::assertSame('queries.repeated', $finding->id);
        static::assertSame(Severity::Warning, $finding->severity);
        static::assertSame('queries', $finding->screen);
        static::assertSame('Repeated query (N+1)', $finding->title);
        static::assertSame($entryPoint . ' runs the same query 4.5 times on average, 6 at worst.', $finding->sentence);
        static::assertSame('The code loads a list, then fetches one row per item. Each fetch is a round trip to the database, so the cost grows with the size of the list.', $finding->why);
        static::assertSame('Load the related rows in one query: a join in the first query, or one WHERE id IN (…) for the whole list.', $finding->action);
        static::assertNull($finding->code);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function counts(): iterable
    {
        $first = self::row(Entry::TYPE_REQUEST, 'GET app_list', 150.0, 6, 40.0);
        $second = self::row(Entry::TYPE_COMMAND, 'app:import', 30.0, 2, 20.0);

        yield 'one' => [[$first], 1, 'Repeated query (N+1)', 'GET app_list runs the same query 25 times on average, 40 at worst.'];
        yield 'two' => [[$first, $second], 2, '2 repeated queries (N+1)', 'GET app_list runs the same query 25 times on average, 40 at worst. 1 more is listed on Queries.'];
        yield 'more keys than lines' => [[$first, $second], 7, '7 repeated queries (N+1)', 'GET app_list runs the same query 25 times on average, 40 at worst. 6 more are listed on Queries.'];
        yield 'thousands' => [
            [self::row(Entry::TYPE_REQUEST, 'GET app_list', 3000.0, 2, 1500.0)],
            1235,
            '1,235 repeated queries (N+1)',
            'GET app_list runs the same query 1,500 times on average, 1,500 at worst. 1,234 more are listed on Queries.',
        ];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('counts')]
    public function testTheTitleCountsTheRepeatedQueriesAsTheirCard(array $rows, int $keyCount, string $title, string $sentence): void
    {
        $finding = (new RepeatedQueriesCheck(10))->finding(new Report($rows, keyCount: $keyCount), self::totals(), Period::OneHour);

        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function averages(): iterable
    {
        yield 'under ten, one decimal' => [99.4, '9.9'];
        yield 'rounded up to ten, no decimal' => [99.6, '10'];
        yield 'ten and more, no decimal' => [125.0, '13'];
    }

    #[DataProvider('averages')]
    public function testTheAverageTakesOneDecimalWhenItIsRoundedUnderTen(float $calls, string $average): void
    {
        $finding = (new RepeatedQueriesCheck(10))->finding(new Report([self::row(Entry::TYPE_REQUEST, 'GET app_list', $calls, 10, 20.0)], keyCount: 1), self::totals(), Period::OneHour);

        static::assertSame('GET app_list runs the same query ' . $average . ' times on average, 20 at worst.', $finding->sentence);
    }

    public function testTheCulpritIsTheFirstLineWhoseKeyReads(): void
    {
        $unreadable = new ReportRow(md5('broken'), 'broken', null, [Metric::NPLUS1_CALLS => new MetricValue(9, 900.0, 300.0)]);
        $report = new Report([$unreadable, self::row(Entry::TYPE_COMMAND, 'app:import', 24.0, 2, 14.0)], keyCount: 2);

        $finding = (new RepeatedQueriesCheck(10))->finding($report, self::totals(), Period::OneHour);

        static::assertSame('Command app:import runs the same query 12 times on average, 14 at worst. 1 more is listed on Queries.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsTheThreshold(): void
    {
        $finding = (new RepeatedQueriesCheck(10))->finding(new Report(), self::totals(), Period::SixHours);

        static::assertSame('Repeated queries (N+1)', $finding->title);
        static::assertSame('Queries repeated 10 times or more inside one request in the last 6 hours.', $finding->sentence);

        $other = new ReportRow(md5(Aggregator::OTHER), Aggregator::OTHER, null, [Metric::NPLUS1_CALLS => new MetricValue(4, 60.0, 20.0)]);
        $finding = (new RepeatedQueriesCheck(10))->finding(new Report([], $other, 1), self::totals(), Period::SixHours);

        static::assertSame('Queries repeated 10 times or more inside one request in the last 6 hours.', $finding->sentence);
    }

    /** @return array<string, MetricValue> */
    private static function totals(): array
    {
        return [Metric::NPLUS1_CALLS => new MetricValue(3, 40.0, 20.0)];
    }

    private static function row(string $unitType, string $unitKey, float $calls, int $count, float $max): ReportRow
    {
        $key = (new EntryPointKey($unitType, $unitKey, self::QUERY_ID))->toString();

        return new ReportRow(md5($key), $key, null, [
            Metric::NPLUS1_CALLS => new MetricValue($count, $calls, $max),
            Metric::NPLUS1_DURATION => new MetricValue($count, 12.0, 8.0),
        ]);
    }
}
