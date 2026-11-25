<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\Finding;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationChecksTest extends TestCase
{
    private const NOW = 1800000000;
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';

    /** @var array<string, string> metric type => the check it raises */
    private const RAISED_BY = [
        self::SERVER_ERRORS => 'requests.server_errors',
        Metric::REQUEST_SLOW => 'requests.slow',
        Metric::QUERY_SLOW => 'queries.slow',
        Metric::NPLUS1_CALLS => 'queries.repeated',
    ];

    public function testRaisedReadsTheTotalsOnce(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)]);

        $raised = self::checks()->raised($reader, Period::SixHours, self::NOW);

        static::assertSame([[Period::SixHours, self::NOW, array_keys(self::RAISED_BY)]], $reader->totalRequests);
        static::assertSame([], $reader->queries);
        static::assertTrue($raised->has('requests.server_errors'));
    }

    public function testRaisedStillReadsTheTotalsOnce(): void
    {
        $reader = new FixedReader();
        $checks = self::allChecks();

        $checks->raised($reader, Period::OneDay, self::NOW);

        static::assertSame([[Period::OneDay, self::NOW, [
            self::SERVER_ERRORS,
            Metric::REQUEST_SLOW,
            Metric::QUERY_SLOW,
            Metric::NPLUS1_CALLS,
            Metric::HTTP_FAILED,
            Metric::HTTP_STATUS_PREFIX . '4xx',
            Metric::HTTP_STATUS_PREFIX . '5xx',
            Metric::CACHE_HIT,
            Metric::CACHE_MISS,
            Metric::MESSAGE_FAILED,
            Metric::TASK_FAILED,
            Metric::COMMAND_FAILED,
        ]]], $reader->totalRequests);
        static::assertSame([[Metric::CACHE_HIT, Metric::CACHE_MISS]], $reader->keyedRequests);
        static::assertSame([], $reader->queries);
        static::assertSame(['requests', 'queries', 'cache-http', 'background'], $checks->screens());
    }

    public function testAKeyedCheckIsRaisedByItsKeysNotByItsTotal(): void
    {
        $checks = new ApplicationChecks([new ServerErrorsCheck(), new CacheHitRateCheck()]);
        $low = [Metric::CACHE_HIT => new MetricValue(1, 0.0, 0.0), Metric::CACHE_MISS => new MetricValue(40, 0.0, 0.0)];
        $high = [Metric::CACHE_HIT => new MetricValue(500, 0.0, 0.0), Metric::CACHE_MISS => new MetricValue(11, 0.0, 0.0)];
        $lowPool = [
            Metric::CACHE_HIT => [md5('cache.users') => new MetricValue(9, 0.0, 0.0), md5('cache.app') => new MetricValue(491, 0.0, 0.0)],
            Metric::CACHE_MISS => [md5('cache.users') => new MetricValue(11, 0.0, 0.0)],
        ];

        static::assertFalse($checks->raised(new FixedReader(totals: $low), Period::OneHour, self::NOW)->has('cache.low_hit_rate'));
        static::assertTrue($checks->raised(new FixedReader(totals: $high, keyTotals: $lowPool), Period::OneHour, self::NOW)->has('cache.low_hit_rate'));

        $raised = $checks->raised(new FixedReader(totals: $high + [self::SERVER_ERRORS => new MetricValue(1, 0.0, 0.0)], keyTotals: $lowPool), Period::OneHour, self::NOW);

        static::assertEquals($high, $raised->totals('cache.low_hit_rate'));
        static::assertTrue($raised->has('requests.server_errors'));
    }

    public function testAKeyedCheckOnlyReceivesTheKeysOfItsMetrics(): void
    {
        $spy = new class implements KeyedCheckInterface {
            /** @var list<array<string, array<string, MetricValue>>> */
            public array $received = [];

            public function id(): string
            {
                return 'spy';
            }

            public function severity(): Severity
            {
                return Severity::Warning;
            }

            public function screen(): string
            {
                return 'cache-http';
            }

            public function screenLabel(): string
            {
                return 'Cache & HTTP';
            }

            public function metrics(): array
            {
                return [Metric::CACHE_MISS];
            }

            public function query(Period $period, int $now): ReportQuery
            {
                return new ReportQuery($period, $now, Family::Cache);
            }

            public function finding(Report $report, array $totals, Period $period): Finding
            {
                return new Finding('spy', Severity::Warning, '', '', '', '', 'cache-http');
            }

            public function raisedBy(array $keys): bool
            {
                $this->received[] = $keys;

                return false;
            }
        };
        $keys = [
            Metric::CACHE_HIT => [md5('cache.app') => new MetricValue(3, 0.0, 0.0)],
            Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(30, 0.0, 0.0)],
        ];
        $reader = new FixedReader(totals: [Metric::CACHE_MISS => new MetricValue(30, 0.0, 0.0)], keyTotals: $keys);

        $raised = (new ApplicationChecks([new CacheHitRateCheck(), $spy]))->raised($reader, Period::OneHour, self::NOW);

        static::assertSame([[Metric::CACHE_MISS => $keys[Metric::CACHE_MISS]]], $spy->received);
        static::assertFalse($raised->has('spy'));
        static::assertTrue($raised->has('cache.low_hit_rate'));
    }

    public function testTheTotalsOfAMetricSharedByTwoChecksAreAskedOnce(): void
    {
        $reader = new FixedReader();

        (new ApplicationChecks([new SlowRequestsCheck(500), new ServerErrorsCheck(), new SlowRequestsCheck(800)]))->raised($reader, Period::OneHour, self::NOW);

        static::assertSame([[Period::OneHour, self::NOW, [Metric::REQUEST_SLOW, self::SERVER_ERRORS]]], $reader->totalRequests);
    }

    public function testWithoutChecksNothingIsRead(): void
    {
        $reader = new FixedReader();

        $raised = (new ApplicationChecks([]))->raised($reader, Period::OneHour, self::NOW);

        static::assertSame([], $reader->totalRequests);
        static::assertNull($raised->badge('requests'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function metrics(): iterable
    {
        foreach (self::RAISED_BY as $metric => $id) {
            yield $id => [$metric, $id];
        }
    }

    #[DataProvider('metrics')]
    public function testACheckIsRaisedFromOneEntry(string $metric, string $id): void
    {
        $raised = self::checks()->raised(new FixedReader(totals: [$metric => new MetricValue(1, 0.0, 0.0)]), Period::OneHour, self::NOW);

        foreach (self::RAISED_BY as $other) {
            static::assertSame($other === $id, $raised->has($other), $other);
        }
    }

    #[DataProvider('metrics')]
    public function testACheckIsNotRaisedAtZero(string $metric, string $id): void
    {
        $raised = self::checks()->raised(new FixedReader(totals: [$metric => new MetricValue(0, 12.0, 12.0)]), Period::OneHour, self::NOW);

        static::assertFalse($raised->has($id));
    }

    public function testFindingsOnlyReadForRaisedChecks(): void
    {
        $reader = new FixedReader(totals: [self::SERVER_ERRORS => new MetricValue(2, 0.0, 0.0)]);
        $checks = self::checks();
        $raised = $checks->raised($reader, Period::OneHour, self::NOW);

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, array_values(self::RAISED_BY));

        static::assertSame(['requests.server_errors'], array_map(static fn(Finding $finding): string => $finding->id, $findings));
        static::assertEquals([new ReportQuery(Period::OneHour, self::NOW, Family::Request)], $reader->queries);
    }

    public function testAKnownReportIsNotReadAgain(): void
    {
        $reader = new FixedReader(totals: [Metric::NPLUS1_CALLS => new MetricValue(3, 40.0, 20.0)]);
        $checks = self::checks();
        $raised = $checks->raised($reader, Period::OneHour, self::NOW);
        $key = (new EntryPointKey(Entry::TYPE_REQUEST, 'GET app_list', '0123456789abcdef'))->toString();
        $report = new Report([new ReportRow(md5($key), $key, null, [Metric::NPLUS1_CALLS => new MetricValue(3, 40.0, 20.0)])], keyCount: 1);

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, ['queries.repeated'], ['queries.repeated' => $report]);

        static::assertSame([], $reader->queries);
        static::assertCount(1, $findings);
        static::assertSame('GET app_list runs the same query 13 times on average, 20 at worst.', $findings[0]->sentence);
    }

    public function testFindingsComeMostSevereFirstThenInTheAskedOrder(): void
    {
        $reader = self::allRaised();
        $checks = self::checks();
        $raised = $checks->raised($reader, Period::OneHour, self::NOW);

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, ['queries.repeated', 'requests.slow', 'requests.server_errors', 'queries.slow']);

        static::assertSame(
            ['requests.server_errors', 'queries.repeated', 'requests.slow', 'queries.slow'],
            array_map(static fn(Finding $finding): string => $finding->id, $findings),
        );
    }

    public function testAnUnknownOrRepeatedIdIsIgnored(): void
    {
        $reader = self::allRaised();
        $checks = self::checks();
        $raised = $checks->raised($reader, Period::OneHour, self::NOW);

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, ['cache.low_hit_rate', 'requests.slow', 'requests.slow']);

        static::assertSame(['requests.slow'], array_map(static fn(Finding $finding): string => $finding->id, $findings));
        static::assertCount(1, $reader->queries);
    }

    public function testARaisedCheckWithoutCulpritStillTellsItsCount(): void
    {
        $reader = new FixedReader(new Report(), totals: [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0)]);
        $checks = self::checks();

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $checks->raised($reader, Period::OneHour, self::NOW), ['requests.server_errors']);

        static::assertCount(1, $findings);
        static::assertSame('3 server errors', $findings[0]->title);
        static::assertSame('Responses with a 5xx status in the last hour.', $findings[0]->sentence);
    }

    public function testTheOtherKeyIsNeverTheCulprit(): void
    {
        $other = new ReportRow(md5(Aggregator::OTHER), Aggregator::OTHER, null, [self::SERVER_ERRORS => new MetricValue(9, 0.0, 0.0)]);
        $reader = new FixedReader(new Report([], $other, 1), totals: [self::SERVER_ERRORS => new MetricValue(9, 0.0, 0.0)]);
        $checks = self::checks();

        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $checks->raised($reader, Period::OneHour, self::NOW), ['requests.server_errors']);

        static::assertSame('Responses with a 5xx status in the last hour.', $findings[0]->sentence);
    }

    public function testALineOnItsOwnScreenHasNoLink(): void
    {
        $lines = self::checks()->lines([
            new Finding('requests.server_errors', Severity::Critical, '2 server errors', 'GET app_list answered with a 5xx status 2 times.', 'Why.', 'Do.', 'requests'),
            new Finding('requests.slow', Severity::Warning, '1 slow request', 'GET app_list took 500 ms or more once, 730 ms at worst.', 'Why slow.', 'Do slow.', 'requests', 'SELECT 1'),
        ], 'requests', Period::SixHours);

        static::assertEquals([
            new CheckLine('requests.server_errors', new Mark('bad', 'Critical'), 'GET app_list answered with a 5xx status 2 times.', 'Why.', '', null, null, [], '2 server errors', 'Do.'),
            new CheckLine('requests.slow', new Mark('warn', 'Warning'), 'GET app_list took 500 ms or more once, 730 ms at worst.', 'Why slow.', '', null, null, [], '1 slow request', 'Do slow.', 'SELECT 1'),
        ], $lines);
    }

    public function testALineElsewhereLinksToItsScreenWithThePeriod(): void
    {
        $lines = self::checks()->lines([
            new Finding('queries.repeated', Severity::Warning, 'Repeated query (N+1)', 'GET app_list runs the same query 25 times on average, 40 at worst.', 'Why.', 'Do.', 'queries'),
        ], 'requests', Period::SixHours);

        static::assertEquals([
            new CheckLine('queries.repeated', new Mark('warn', 'Warning'), 'GET app_list runs the same query 25 times on average, 40 at worst.', 'Why.', 'Queries', 'queries', null, ['period' => '6h'], 'Repeated query (N+1)', 'Do.'),
        ], $lines);
    }

    public function testTheQueryOfACheckIsTheOneOfItsClass(): void
    {
        static::assertEquals((new RepeatedQueriesCheck(10))->query(Period::OneDay, self::NOW), self::checks()->query('queries.repeated', Period::OneDay, self::NOW));
        static::assertEquals((new ServerErrorsCheck())->query(Period::OneHour, self::NOW), self::checks()->query('requests.server_errors', Period::OneHour, self::NOW));
        static::assertNull(self::checks()->query('http.failures', Period::OneHour, self::NOW));
    }

    public function testTheScreensOfTheChecksAreListedOnce(): void
    {
        static::assertSame(['requests', 'queries'], self::checks()->screens());
        static::assertSame([], (new ApplicationChecks([]))->screens());
    }

    public function testASeverityHasItsWord(): void
    {
        static::assertSame('Critical', Severity::Critical->label());
        static::assertSame('Warning', Severity::Warning->label());
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(500), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
    }

    private static function allChecks(): ApplicationChecks
    {
        return new ApplicationChecks([
            new ServerErrorsCheck(),
            new SlowRequestsCheck(500),
            new SlowQueriesCheck(100),
            new RepeatedQueriesCheck(10),
            new HttpFailuresCheck(),
            new HttpErrorsCheck(),
            new CacheHitRateCheck(),
            new FailedMessagesCheck(),
            new FailedTasksCheck(),
            new FailedCommandsCheck(),
        ]);
    }

    private static function allRaised(): FixedReader
    {
        return new FixedReader(totals: array_map(static fn(): MetricValue => new MetricValue(1, 0.0, 0.0), self::RAISED_BY));
    }
}
