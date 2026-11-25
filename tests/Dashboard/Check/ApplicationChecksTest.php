<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\Finding;
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

    private static function allRaised(): FixedReader
    {
        return new FixedReader(totals: array_map(static fn(): MetricValue => new MetricValue(1, 0.0, 0.0), self::RAISED_BY));
    }
}
