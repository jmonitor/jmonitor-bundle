<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpFailuresCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new HttpFailuresCheck();

        static::assertSame('http.failures', $check->id());
        static::assertSame(Severity::Critical, $check->severity());
        static::assertSame('cache-http', $check->screen());
        static::assertSame('Cache & HTTP', $check->screenLabel());
        static::assertSame([Metric::HTTP_FAILED], $check->metrics());
        static::assertNotInstanceOf(KeyedCheckInterface::class, $check);
    }

    public function testItReadsTheHostsWithTheMostCallsWithoutAnAnswer(): void
    {
        static::assertEquals(
            new ReportQuery(Period::SixHours, self::NOW, Family::Http, Metric::HTTP_FAILED, 'count', true, 100),
            (new HttpFailuresCheck())->query(Period::SixHours, self::NOW),
        );
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one host, once' => [[self::row('api.example.com', 1)], 1, '1 call without an answer', 'api.example.com did not answer once.'];
        yield 'one host, several times' => [[self::row('api.example.com', 4)], 4, '4 calls without an answer', 'api.example.com did not answer 4 times.'];
        yield 'the most failures wins' => [
            [self::row('api.example.com', 1), self::row('pay.example.com', 3), self::row('cdn.example.com', 0)],
            4,
            '4 calls without an answer',
            'pay.example.com did not answer 3 times. 1 other host did not either.',
        ];
        yield 'several other hosts' => [
            [self::row('a.example.com', 2), self::row('b.example.com', 5), self::row('c.example.com', 1)],
            8,
            '8 calls without an answer',
            'b.example.com did not answer 5 times. 2 other hosts did not either.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('pay.example.com', 2), self::row('api.example.com', 2)],
            4,
            '4 calls without an answer',
            'api.example.com did not answer 2 times. 1 other host did not either.',
        ];
        yield 'thousands' => [[self::row('api.example.com', 1234)], 1234, '1,234 calls without an answer', 'api.example.com did not answer 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheHostWithTheMostCallsWithoutAnAnswer(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new HttpFailuresCheck())->finding(new Report($rows), [Metric::HTTP_FAILED => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('http.failures', $finding->id);
        static::assertSame(Severity::Critical, $finding->severity);
        static::assertSame('cache-http', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('No answer means a timeout or a network error: your code waited, then had to do without the other site.', $finding->why);
        static::assertSame('Check that the host can be reached from your server, and set a timeout on the client so that a slow host cannot hold your requests.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('api.example.com', 2)], self::row(Aggregator::OTHER, 50), 2);

        $finding = (new HttpFailuresCheck())->finding($report, [Metric::HTTP_FAILED => new MetricValue(52, 0.0, 0.0)], Period::OneHour);

        static::assertSame('api.example.com did not answer 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('api.example.com', 0)]), new Report([], self::row(Aggregator::OTHER, 3), 1)] as $report) {
            $finding = (new HttpFailuresCheck())->finding($report, [Metric::HTTP_FAILED => new MetricValue(3, 0.0, 0.0)], Period::SixHours);

            static::assertSame('3 calls without an answer', $finding->title);
            static::assertSame('Outgoing calls that got no answer in the last 6 hours.', $finding->sentence);
        }
    }

    private static function row(string $host, int $failures): ReportRow
    {
        return new ReportRow(md5($host), $host, null, [
            Metric::HTTP_DURATION => new MetricValue(10, 400.0, 90.0),
            Metric::HTTP_FAILED => new MetricValue($failures, 0.0, 0.0),
        ]);
    }
}
