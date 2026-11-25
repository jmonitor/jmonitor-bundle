<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpErrorsCheckTest extends TestCase
{
    private const NOW = 1800000000;
    private const CLIENT = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const SERVER = Metric::HTTP_STATUS_PREFIX . '5xx';

    public function testTheCheckDeclaresItself(): void
    {
        $check = new HttpErrorsCheck();

        static::assertSame('http.errors', $check->id());
        static::assertSame(Severity::Warning, $check->severity());
        static::assertSame('cache-http', $check->screen());
        static::assertSame('Cache & HTTP', $check->screenLabel());
        static::assertSame([self::CLIENT, self::SERVER], $check->metrics());
        static::assertNotInstanceOf(KeyedCheckInterface::class, $check);
    }

    public function testItReadsTheHostsWithTheMostServerErrors(): void
    {
        static::assertEquals(
            new ReportQuery(Period::OneDay, self::NOW, Family::Http, self::SERVER, 'count', true, 100),
            (new HttpErrorsCheck())->query(Period::OneDay, self::NOW),
        );
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one 4xx' => [[self::row('api.example.com', 1, 0)], 1, 0, '1 error response', 'api.example.com answered with a 4xx or 5xx status once.'];
        yield 'one 5xx' => [[self::row('api.example.com', 0, 1)], 0, 1, '1 error response', 'api.example.com answered with a 4xx or 5xx status once.'];
        yield 'the two statuses add up' => [[self::row('api.example.com', 2, 3)], 2, 3, '5 error responses', 'api.example.com answered with a 4xx or 5xx status 5 times.'];
        yield 'the sum wins over either status' => [
            [self::row('api.example.com', 3, 0), self::row('pay.example.com', 2, 2), self::row('cdn.example.com', 0, 0)],
            5,
            2,
            '7 error responses',
            'pay.example.com answered with a 4xx or 5xx status 4 times. 1 other host did too.',
        ];
        yield 'several other hosts' => [
            [self::row('a.example.com', 1, 0), self::row('b.example.com', 0, 6), self::row('c.example.com', 0, 1)],
            1,
            7,
            '8 error responses',
            'b.example.com answered with a 4xx or 5xx status 6 times. 2 other hosts did too.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('pay.example.com', 0, 2), self::row('api.example.com', 2, 0)],
            2,
            2,
            '4 error responses',
            'api.example.com answered with a 4xx or 5xx status 2 times. 1 other host did too.',
        ];
        yield 'thousands' => [[self::row('api.example.com', 1000, 234)], 1000, 234, '1,234 error responses', 'api.example.com answered with a 4xx or 5xx status 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheHostWithTheMostErrorResponses(array $rows, int $clientErrors, int $serverErrors, string $title, string $sentence): void
    {
        $totals = [self::CLIENT => new MetricValue($clientErrors, 0.0, 0.0), self::SERVER => new MetricValue($serverErrors, 0.0, 0.0)];

        $finding = (new HttpErrorsCheck())->finding(new Report($rows), $totals, Period::OneHour);

        static::assertSame('http.errors', $finding->id);
        static::assertSame(Severity::Warning, $finding->severity);
        static::assertSame('cache-http', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('4xx: the other site refused the call, often a bad key, a wrong address or a limit reached. 5xx: the other site failed.', $finding->why);
        static::assertSame('Look in your logs for the status and the address of the call: a 4xx is fixed on your side, a 5xx on theirs.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheTitleCountsAStatusAlone(): void
    {
        $finding = (new HttpErrorsCheck())->finding(new Report(), [self::SERVER => new MetricValue(2, 0.0, 0.0)], Period::OneHour);

        static::assertSame('2 error responses', $finding->title);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('api.example.com', 1, 1)], self::row(Aggregator::OTHER, 20, 30), 2);

        $finding = (new HttpErrorsCheck())->finding($report, [self::CLIENT => new MetricValue(21, 0.0, 0.0), self::SERVER => new MetricValue(31, 0.0, 0.0)], Period::OneHour);

        static::assertSame('api.example.com answered with a 4xx or 5xx status 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('api.example.com', 0, 0)]), new Report([], self::row(Aggregator::OTHER, 1, 2), 1)] as $report) {
            $finding = (new HttpErrorsCheck())->finding($report, [self::CLIENT => new MetricValue(1, 0.0, 0.0), self::SERVER => new MetricValue(2, 0.0, 0.0)], Period::SevenDays);

            static::assertSame('3 error responses', $finding->title);
            static::assertSame('Answers with a 4xx or 5xx status from another site in the last 7 days.', $finding->sentence);
        }
    }

    private static function row(string $host, int $clientErrors, int $serverErrors): ReportRow
    {
        return new ReportRow(md5($host), $host, null, [
            Metric::HTTP_DURATION => new MetricValue(10, 400.0, 90.0),
            self::CLIENT => new MetricValue($clientErrors, 0.0, 0.0),
            self::SERVER => new MetricValue($serverErrors, 0.0, 0.0),
        ]);
    }
}
