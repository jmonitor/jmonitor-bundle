<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerErrorsCheckTest extends TestCase
{
    private const NOW = 1800000000;
    private const METRIC = Metric::REQUEST_STATUS_PREFIX . '5xx';

    public function testTheCheckDeclaresItself(): void
    {
        $check = new ServerErrorsCheck();

        static::assertSame('requests.server_errors', $check->id());
        static::assertSame(Severity::Critical, $check->severity());
        static::assertSame('requests', $check->screen());
        static::assertSame('Requests', $check->screenLabel());
        static::assertSame([self::METRIC], $check->metrics());
    }

    public function testItReadsEveryRoute(): void
    {
        static::assertEquals(new ReportQuery(Period::SixHours, self::NOW, Family::Request), (new ServerErrorsCheck())->query(Period::SixHours, self::NOW));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one route, once' => [[self::row('GET app_list', 1)], 1, '1 server error', 'GET app_list answered with a 5xx status once.'];
        yield 'one route, several times' => [[self::row('GET app_list', 4)], 4, '4 server errors', 'GET app_list answered with a 5xx status 4 times.'];
        yield 'the most errors wins' => [
            [self::row('GET app_list', 1), self::row('POST app_save', 3), self::row('GET app_home', 0)],
            4,
            '4 server errors',
            'POST app_save answered with a 5xx status 3 times. 1 other route did too.',
        ];
        yield 'several other routes' => [
            [self::row('GET app_a', 2), self::row('GET app_b', 5), self::row('GET app_c', 1)],
            8,
            '8 server errors',
            'GET app_b answered with a 5xx status 5 times. 2 other routes did too.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('POST app_save', 2), self::row('GET app_list', 2)],
            4,
            '4 server errors',
            'GET app_list answered with a 5xx status 2 times. 1 other route did too.',
        ];
        yield 'thousands' => [[self::row('GET app_list', 1234)], 1234, '1,234 server errors', 'GET app_list answered with a 5xx status 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheRouteWithTheMostServerErrors(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new ServerErrorsCheck())->finding(new Report($rows), [self::METRIC => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('requests.server_errors', $finding->id);
        static::assertSame(Severity::Critical, $finding->severity);
        static::assertSame('requests', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A 5xx status means your code or a server failed: the visitor got an error instead of an answer.', $finding->why);
        static::assertSame('Open Exceptions to see what was thrown, then look for it in your logs.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('GET app_list', 2)], self::row(Aggregator::OTHER, 50), 2);

        $finding = (new ServerErrorsCheck())->finding($report, [self::METRIC => new MetricValue(52, 0.0, 0.0)], Period::OneHour);

        static::assertSame('GET app_list answered with a 5xx status 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('GET app_list', 0)]), new Report([], self::row(Aggregator::OTHER, 3), 1)] as $report) {
            $finding = (new ServerErrorsCheck())->finding($report, [self::METRIC => new MetricValue(3, 0.0, 0.0)], Period::SixHours);

            static::assertSame('3 server errors', $finding->title);
            static::assertSame('Responses with a 5xx status in the last 6 hours.', $finding->sentence);
        }
    }

    private static function row(string $key, int $errors): ReportRow
    {
        return new ReportRow(md5($key), $key, null, [
            Metric::REQUEST_DURATION => new MetricValue(10, 400.0, 90.0),
            self::METRIC => new MetricValue($errors, 0.0, 0.0),
        ]);
    }
}
