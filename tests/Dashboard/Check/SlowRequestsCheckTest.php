<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlowRequestsCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new SlowRequestsCheck(500);

        static::assertSame('requests.slow', $check->id());
        static::assertSame(Severity::Warning, $check->severity());
        static::assertSame('requests', $check->screen());
        static::assertSame('Requests', $check->screenLabel());
        static::assertSame([Metric::REQUEST_SLOW], $check->metrics());
    }

    public function testItReadsEveryRoute(): void
    {
        static::assertEquals(new ReportQuery(Period::OneDay, self::NOW, Family::Request), (new SlowRequestsCheck(500))->query(Period::OneDay, self::NOW));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one route, once' => [[self::row('GET app_list', 1, 730.0)], 1, '1 slow request', 'GET app_list took 500 ms or more once, 730 ms at worst.'];
        yield 'one route, several times' => [[self::row('GET app_list', 3, 2400.0)], 3, '3 slow requests', 'GET app_list took 500 ms or more 3 times, 2.4 s at worst.'];
        yield 'the most slow requests wins' => [
            [self::row('GET app_list', 1, 9000.0), self::row('GET app_report', 6, 1200.0), self::row('GET app_home', 0, 90.0)],
            7,
            '7 slow requests',
            'GET app_report took 500 ms or more 6 times, 1.2 s at worst. 1 other route did too.',
        ];
        yield 'several other routes' => [
            [self::row('GET app_a', 1, 600.0), self::row('GET app_b', 2, 800.0), self::row('GET app_c', 1, 700.0)],
            4,
            '4 slow requests',
            'GET app_b took 500 ms or more 2 times, 800 ms at worst. 2 other routes did too.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('GET app_z', 2, 600.0), self::row('GET app_a', 2, 700.0)],
            4,
            '4 slow requests',
            'GET app_a took 500 ms or more 2 times, 700 ms at worst. 1 other route did too.',
        ];
        yield 'a tie keeps the smallest key in first place' => [
            [self::row('GET app_a', 2, 700.0), self::row('GET app_z', 2, 600.0)],
            4,
            '4 slow requests',
            'GET app_a took 500 ms or more 2 times, 700 ms at worst. 1 other route did too.',
        ];
        yield 'thousands' => [[self::row('GET app_list', 1234, 2400.0)], 1234, '1,234 slow requests', 'GET app_list took 500 ms or more 1,234 times, 2.4 s at worst.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheRouteWithTheMostSlowRequests(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new SlowRequestsCheck(500))->finding(new Report($rows), [Metric::REQUEST_SLOW => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('requests.slow', $finding->id);
        static::assertSame(Severity::Warning, $finding->severity);
        static::assertSame('requests', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A request counts as slow from 500 ms (recording.slow_threshold). Someone waited that long for the answer.', $finding->why);
        static::assertSame('Look at where the average of the route goes in the table: your PHP code, SQL or another site.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheThresholdIsWrittenAsADuration(): void
    {
        $finding = (new SlowRequestsCheck(1500))->finding(new Report(), [Metric::REQUEST_SLOW => new MetricValue(2, 0.0, 0.0)], Period::SevenDays);

        static::assertSame('Requests that took 1.5 s or more in the last 7 days.', $finding->sentence);
        static::assertSame('A request counts as slow from 1.5 s (recording.slow_threshold). Someone waited that long for the answer.', $finding->why);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('GET app_list', 1, 600.0)], self::row(Aggregator::OTHER, 40, 9000.0), 2);

        $finding = (new SlowRequestsCheck(500))->finding($report, [Metric::REQUEST_SLOW => new MetricValue(41, 0.0, 0.0)], Period::OneHour);

        static::assertSame('41 slow requests', $finding->title);
        static::assertSame('GET app_list took 500 ms or more once, 600 ms at worst.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('GET app_list', 0, 90.0)]), new Report([], self::row(Aggregator::OTHER, 1, 900.0), 1)] as $report) {
            $finding = (new SlowRequestsCheck(500))->finding($report, [Metric::REQUEST_SLOW => new MetricValue(1, 0.0, 0.0)], Period::OneHour);

            static::assertSame('1 slow request', $finding->title);
            static::assertSame('Requests that took 500 ms or more in the last hour.', $finding->sentence);
        }
    }

    private static function row(string $key, int $slow, float $max): ReportRow
    {
        return new ReportRow(md5($key), $key, null, [
            Metric::REQUEST_DURATION => new MetricValue(10, 400.0, $max),
            Metric::REQUEST_SLOW => new MetricValue($slow, 0.0, 0.0),
        ]);
    }
}
