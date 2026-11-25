<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlowQueriesCheckTest extends TestCase
{
    private const NOW = 1800000000;
    private const SQL = 'SELECT * FROM demo_items WHERE name LIKE ?';

    public function testTheCheckDeclaresItself(): void
    {
        $check = new SlowQueriesCheck(100);

        static::assertSame('queries.slow', $check->id());
        static::assertSame(Severity::Warning, $check->severity());
        static::assertSame('queries', $check->screen());
        static::assertSame('Queries', $check->screenLabel());
        static::assertSame([Metric::QUERY_SLOW], $check->metrics());
    }

    public function testItReadsTheQueryRunSlowMostOften(): void
    {
        static::assertEquals(
            new ReportQuery(Period::OneHour, self::NOW, Family::Query, Metric::QUERY_SLOW, 'count', true, 1),
            (new SlowQueriesCheck(100))->query(Period::OneHour, self::NOW),
        );
    }

    /**
     * @return iterable<string, array{int, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'once' => [1, 1, '1 slow query', 'The one slow most often took 100 ms or more once, 850 ms at worst.'];
        yield 'several times' => [4, 9, '9 slow queries', 'The one slow most often took 100 ms or more 4 times, 850 ms at worst.'];
        yield 'thousands' => [1234, 2345, '2,345 slow queries', 'The one slow most often took 100 ms or more 1,234 times, 850 ms at worst.'];
    }

    #[DataProvider('culprits')]
    public function testTheCulpritIsTheFirstLineAndShowsItsText(int $slow, int $total, string $title, string $sentence): void
    {
        $finding = (new SlowQueriesCheck(100))->finding(new Report([self::row(self::SQL, $slow)]), [Metric::QUERY_SLOW => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('queries.slow', $finding->id);
        static::assertSame(Severity::Warning, $finding->severity);
        static::assertSame('queries', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame(self::SQL, $finding->code);
        static::assertSame('A query counts as slow from 100 ms (recording.sql.slow_threshold). Every request that runs it waits for it.', $finding->why);
        static::assertSame('Run it with EXPLAIN and look at the indexes of the tables in its WHERE and ORDER BY.', $finding->action);
    }

    public function testACulpritWithoutItsTextSaysSoAndHasNoCode(): void
    {
        $finding = (new SlowQueriesCheck(100))->finding(new Report([self::row(null, 2)]), [Metric::QUERY_SLOW => new MetricValue(2, 0.0, 0.0)], Period::OneHour);

        static::assertSame('The one slow most often took 100 ms or more 2 times, 850 ms at worst. Its text is not stored.', $finding->sentence);
        static::assertNull($finding->code);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row(self::SQL, 0)]), new Report([], self::row(null, 2, Aggregator::OTHER), 1)] as $report) {
            $finding = (new SlowQueriesCheck(250))->finding($report, [Metric::QUERY_SLOW => new MetricValue(2, 0.0, 0.0)], Period::OneDay);

            static::assertSame('2 slow queries', $finding->title);
            static::assertSame('Queries that took 250 ms or more in the last 24 hours.', $finding->sentence);
            static::assertNull($finding->code);
        }
    }

    private static function row(?string $sql, int $slow, string $key = '0123456789abcdef'): ReportRow
    {
        return new ReportRow(md5($key), $key, $sql, [
            Metric::QUERY_DURATION => new MetricValue(20, 1200.0, 850.0),
            Metric::QUERY_SLOW => new MetricValue($slow, 0.0, 0.0),
        ]);
    }
}
