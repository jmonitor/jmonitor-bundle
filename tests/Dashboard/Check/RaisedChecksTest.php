<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\NavBadge;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RaisedChecksTest extends TestCase
{
    private const NOW = 1800000000;
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';

    /**
     * @return iterable<string, array{list<string>, string, Period, ?NavBadge}>
     */
    public static function badges(): iterable
    {
        yield '1 warning' => [[Metric::REQUEST_SLOW], 'requests', Period::OneHour, new NavBadge('1 warning', 'warn', '1 warning in the last hour')];
        yield '2 warnings' => [[Metric::QUERY_SLOW, Metric::NPLUS1_CALLS], 'queries', Period::SixHours, new NavBadge('2 warnings', 'warn', '2 warnings in the last 6 hours')];
        yield '1 critical' => [[self::SERVER_ERRORS], 'requests', Period::OneHour, new NavBadge('1 critical', 'bad', '1 critical in the last hour')];
        yield '2 critical' => [[self::SERVER_ERRORS, Metric::REQUEST_SLOW], 'requests', Period::SevenDays, new NavBadge('2 critical', 'bad', '1 critical, 1 warning in the last 7 days')];
        yield 'none' => [[], 'requests', Period::OneHour, null];
        yield 'none on its screen' => [[Metric::REQUEST_SLOW, self::SERVER_ERRORS], 'queries', Period::OneHour, null];
        yield 'a screen without check' => [[Metric::REQUEST_SLOW, Metric::QUERY_SLOW], 'exceptions', Period::OneHour, null];
    }

    /**
     * @param list<string> $metrics
     */
    #[DataProvider('badges')]
    public function testTheBadgeCountsTheChecksOfItsScreenAndNamesTheHighestSeverity(array $metrics, string $screen, Period $period, ?NavBadge $badge): void
    {
        $raised = self::checks()->raised(new FixedReader(totals: array_fill_keys($metrics, new MetricValue(1, 0.0, 0.0))), $period, self::NOW);

        static::assertEquals($badge, $raised->badge($screen));
    }

    public function testTheBadgeOfRequestsDoesNotCountTheChecksOfQueries(): void
    {
        $raised = self::checks()->raised(new FixedReader(totals: array_fill_keys([self::SERVER_ERRORS, Metric::NPLUS1_CALLS], new MetricValue(4, 0.0, 0.0))), Period::OneHour, self::NOW);

        static::assertEquals(new NavBadge('1 critical', 'bad', '1 critical in the last hour'), $raised->badge('requests'));
        static::assertEquals(new NavBadge('1 warning', 'warn', '1 warning in the last hour'), $raised->badge('queries'));
    }

    public function testTheBadgeOfCacheAndHttpCountsItsThreeChecks(): void
    {
        $checks = new ApplicationChecks([
            new ServerErrorsCheck(),
            new HttpFailuresCheck(),
            new HttpErrorsCheck(),
            new CacheHitRateCheck(),
            new FailedMessagesCheck(),
        ]);
        $totals = array_fill_keys([self::SERVER_ERRORS, Metric::HTTP_FAILED, Metric::HTTP_STATUS_PREFIX . '4xx', Metric::CACHE_HIT, Metric::CACHE_MISS, Metric::MESSAGE_FAILED], new MetricValue(1, 0.0, 0.0));
        $keys = [Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(20, 0.0, 0.0)]];

        $raised = $checks->raised(new FixedReader(totals: $totals, keyTotals: $keys), Period::OneHour, self::NOW);

        static::assertEquals(new NavBadge('3 critical', 'bad', '1 critical, 2 warnings in the last hour'), $raised->badge('cache-http'));
        static::assertEquals(new NavBadge('1 critical', 'bad', '1 critical in the last hour'), $raised->badge('requests'));
        static::assertEquals(new NavBadge('1 critical', 'bad', '1 critical in the last hour'), $raised->badge('background'));
    }

    public function testTheBadgeOfCacheAndHttpLeavesOutAPoolThatIsNotLow(): void
    {
        $checks = new ApplicationChecks([new HttpErrorsCheck(), new CacheHitRateCheck()]);
        $totals = array_fill_keys([Metric::HTTP_STATUS_PREFIX . '5xx', Metric::CACHE_MISS], new MetricValue(19, 0.0, 0.0));
        $keys = [Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(19, 0.0, 0.0)]];

        $raised = $checks->raised(new FixedReader(totals: $totals, keyTotals: $keys), Period::SixHours, self::NOW);

        static::assertEquals(new NavBadge('1 warning', 'warn', '1 warning in the last 6 hours'), $raised->badge('cache-http'));
    }

    public function testTheTotalsOfARaisedCheckAreThoseOfItsMetrics(): void
    {
        $totals = [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0), Metric::REQUEST_SLOW => new MetricValue(0, 0.0, 0.0)];
        $raised = self::checks()->raised(new FixedReader(totals: $totals), Period::OneHour, self::NOW);

        static::assertEquals([self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0)], $raised->totals('requests.server_errors'));
        static::assertSame([], $raised->totals('requests.slow'));
        static::assertSame([], $raised->totals('unknown'));
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(500), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
    }
}
