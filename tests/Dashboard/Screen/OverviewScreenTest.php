<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Status;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedGlance;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverviewScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';
    private const HTTP_4XX = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const HTTP_5XX = Metric::HTTP_STATUS_PREFIX . '5xx';

    /** a total for each of the ten checks, enough to raise it */
    private const ALL_RAISED = [
        self::SERVER_ERRORS => 3,
        Metric::REQUEST_SLOW => 2,
        Metric::MESSAGE_FAILED => 1,
        Metric::TASK_FAILED => 1,
        Metric::COMMAND_FAILED => 1,
        Metric::QUERY_SLOW => 4,
        Metric::NPLUS1_CALLS => 1,
        Metric::HTTP_FAILED => 2,
        self::HTTP_4XX => 3,
        Metric::CACHE_MISS => 30,
    ];

    public function testTheScreenDeclaresItself(): void
    {
        $screen = $this->screen();

        static::assertSame('overview', $screen->id());
        static::assertSame('Overview', $screen->label());
        static::assertSame('What needs a look, then the map of your application', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(Space::Overview, $screen->space());
    }

    public function testBuildingOrReadingItLeavesTheStackAlone(): void
    {
        $stack = self::glance(new StackGlance());
        $screen = $this->screen(stack: $stack);

        $screen->read(new FixedReader(), Period::OneHour, self::NOW);

        static::assertSame(0, $stack->calls);
        static::assertEquals(new StackGlance(), $screen->stack());
        static::assertSame(1, $stack->calls);
        static::assertNull($this->screen(withStack: false)->stack());
    }

    public function testItReadsTheTotalsAndTheRequestFamilyRestrictedToItsDuration(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3)]);

        $this->screen()->read($reader, Period::SixHours, self::NOW);

        static::assertSame([[Period::SixHours, self::NOW, [
            self::SERVER_ERRORS,
            Metric::REQUEST_SLOW,
            Metric::QUERY_SLOW,
            Metric::NPLUS1_CALLS,
            Metric::HTTP_FAILED,
            self::HTTP_4XX,
            self::HTTP_5XX,
            Metric::CACHE_HIT,
            Metric::CACHE_MISS,
            Metric::MESSAGE_FAILED,
            Metric::TASK_FAILED,
            Metric::COMMAND_FAILED,
            Metric::REQUEST_DURATION,
            Metric::MESSAGE_DURATION,
            Metric::MESSAGE_SENT,
            Metric::TASK_DURATION,
            Metric::COMMAND_DURATION,
            Metric::EXCEPTION_COUNT,
            Metric::USER_DURATION,
            Metric::QUERY_DURATION,
            Metric::HTTP_DURATION,
        ]]], $reader->totalRequests);
        static::assertEquals([new ReportQuery(Period::SixHours, self::NOW, Family::Request, seriesMetric: Metric::REQUEST_DURATION, metrics: [Metric::REQUEST_DURATION])], $reader->queries);
    }

    public function testItReadsNoCulpritWhenEveryCheckIsRaised(): void
    {
        $reader = $this->reader([self::route('GET app_a', 9)], self::allRaised(), self::lowHitRate());

        $view = $this->view($reader);

        static::assertCount(10, $view->alerts->alerts ?? []);
        static::assertCount(1, $reader->totalRequests);
        static::assertSame([Family::Request], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, array<string, MetricValue>, Mark}>
     */
    public static function pills(): iterable
    {
        yield 'requests without server error' => [[self::route('GET app_a', 3)], [], new Mark('ok', 'Answers normally')];
        yield 'one server error' => [[self::route('GET app_a', 3, errors: 1)], [self::SERVER_ERRORS => self::counted(1)], new Mark('bad', '1 server error')];
        yield 'server errors' => [[self::route('GET app_a', 3000, errors: 1200)], [self::SERVER_ERRORS => self::counted(1200)], new Mark('bad', '1,200 server errors')];
        yield 'server errors counted by the totals only' => [[], [self::SERVER_ERRORS => self::counted(2)], new Mark('bad', '2 server errors')];
        yield 'server errors seen by the routes only' => [[self::route('GET app_a', 3, errors: 2)], [], new Mark('ok', 'Answers normally')];
        yield 'no request, a command' => [[], [Metric::COMMAND_DURATION => self::counted(1)], new Mark('unknown', 'No request')];
        yield 'nothing' => [[], [], new Mark('unknown', 'No data yet')];
    }

    /**
     * @param list<ReportRow>            $rows
     * @param array<string, MetricValue> $totals
     */
    #[DataProvider('pills')]
    public function testThePillSaysHowTheApplicationAnswers(array $rows, array $totals, Mark $pill): void
    {
        static::assertEquals(new Status('Overview', $pill), $this->view($this->reader($rows, $totals))->status);
    }

    public function testThePillTheAlertAndTheTagSayTheSameNumberOfServerErrors(): void
    {
        $view = $this->view($this->reader([self::route('GET app_a', 40, errors: 3)], [self::SERVER_ERRORS => self::counted(12)]));

        static::assertSame('12 server errors', $view->status?->mark?->label);
        static::assertSame('12 server errors', $view->alerts?->alerts[0]->title);
        static::assertEquals([new Tag('12 × 5xx', 'bad')], self::box($view, 'Requests')?->tags);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, array<string, MetricValue>, Mark, string, list<Tag>}>
     */
    public static function racesBetweenTheTwoReads(): iterable
    {
        yield 'requests recorded between the totals and the routes' => [
            [self::route('GET app_a', 3, errors: 2)],
            [Metric::REQUEST_DURATION => self::counted(1)],
            new Mark('ok', 'Answers normally'),
            '1',
            [],
        ];
        yield 'requests purged between the totals and the routes' => [
            [],
            [Metric::REQUEST_DURATION => self::counted(5), self::SERVER_ERRORS => self::counted(2)],
            new Mark('bad', '2 server errors'),
            '5',
            [new Tag('2 × 5xx', 'bad')],
        ];
    }

    /**
     * @param list<ReportRow>            $routes
     * @param array<string, MetricValue> $totals
     * @param list<Tag>                  $tags
     */
    #[DataProvider('racesBetweenTheTwoReads')]
    public function testThePillAndTheRequestsBoxOnlyReadTheTotals(array $routes, array $totals, Mark $pill, string $value, array $tags): void
    {
        $report = self::requestReport($routes);
        $reader = new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::Request ? $report : new Report(), totals: $totals);

        $view = $this->view($reader);

        static::assertEquals($pill, $view->status?->mark);
        static::assertSame($value, self::box($view, 'Requests')?->value);
        static::assertEquals($tags, self::box($view, 'Requests')?->tags);
        static::assertSame($report->series, self::box($view, 'Requests')?->series);
    }

    public function testThePageHasNoLeadTableGuideNorCheckList(): void
    {
        $view = $this->view($this->reader([self::route('GET app_a', 3, errors: 1)], [self::SERVER_ERRORS => self::counted(1)]), stack: new StackGlance([self::card('php', 'ok')]));

        static::assertNull($view->lead);
        static::assertSame([], $view->tables);
        static::assertNull($view->guide);
        static::assertNull($view->checks);
        static::assertSame([], $view->cards);
    }

    public function testTheAlertsComeMostSevereFirstTheApplicationBeforeTheStack(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3, errors: 1, slow: 1)], [
            self::SERVER_ERRORS => self::counted(1),
            Metric::REQUEST_SLOW => self::counted(1),
            Metric::NPLUS1_CALLS => self::counted(1),
            Metric::MESSAGE_FAILED => self::counted(2),
        ]);
        $stack = new StackGlance(alerts: [
            self::stackAlert('php.memory', 'warn'),
            self::stackAlert('mysql.down', 'bad'),
        ]);

        $view = $this->view($reader, stack: $stack);

        static::assertSame(
            ['requests.server_errors', 'messages.failed', 'mysql.down', 'requests.slow', 'queries.repeated', 'php.memory'],
            array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []),
        );
    }

    public function testNoInfoPointOfTheStackIsShown(): void
    {
        $view = $this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance(alerts: [self::stackAlert('php.opcache', 'info'), self::stackAlert('php.memory', 'warn')]));

        static::assertSame(['php.memory'], array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []));
    }

    public function testAnAlertOfTheApplicationLinksToItsScreenWithThePeriod(): void
    {
        $alerts = $this->view($this->reader([self::route('GET app_a')], [Metric::MESSAGE_FAILED => self::counted(3)]), Period::SixHours)->alerts;

        static::assertEquals([new Alert('messages.failed', new Mark('bad', 'Critical'), '3 messages failed', 'Background', 'background', ['period' => '6h'])], $alerts?->alerts);
    }

    public function testWithNothingWorthALookThereIsNoAlertBlock(): void
    {
        static::assertNull($this->view($this->reader([self::route('GET app_a')]))->alerts);
        static::assertNull($this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance(alerts: [self::stackAlert('php.opcache', 'info')]))->alerts);
    }

    /**
     * @return iterable<string, array{string, list<ReportRow>, array<string, MetricValue>, MapBox}>
     */
    public static function boxes(): iterable
    {
        $series = array_fill(0, 60, 0);

        yield 'requests' => ['Requests', [self::route('GET app_a', 1284)], [], new MapBox('Requests', '1,284', [], 'requests', null, null, $series)];
        yield 'requests with a server error' => ['Requests', [self::route('GET app_a', 3, errors: 1)], [self::SERVER_ERRORS => self::counted(1)], new MapBox('Requests', '3', [new Tag('1 × 5xx', 'bad')], 'requests', null, 'bad', $series)];
        yield 'requests with slow ones and server errors' => ['Requests', [self::route('GET app_a', 9)], [self::SERVER_ERRORS => self::counted(3), Metric::REQUEST_SLOW => self::counted(2)], new MapBox('Requests', '9', [new Tag('3 × 5xx', 'bad'), new Tag('2 slow', 'warn')], 'requests', null, 'bad', $series)];
        yield 'requests with a slow one' => ['Requests', [self::route('GET app_a', 2)], [Metric::REQUEST_SLOW => self::counted(1)], new MapBox('Requests', '2', [new Tag('1 slow', 'warn')], 'requests', null, 'warn', $series)];
        yield 'requests whose routes alone saw problems' => ['Requests', [self::route('GET app_a', 9, errors: 2, slow: 2)], [], new MapBox('Requests', '9', [], 'requests', null, null, $series)];
        yield 'no request' => ['Requests', [], [], new MapBox('Requests', '0', [], 'requests')];

        yield 'messages' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(42), Metric::MESSAGE_SENT => self::counted(45)], new MapBox('Messages', '42', [], 'background', 'messages')];
        yield 'messages, one failed' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(1), Metric::MESSAGE_FAILED => self::counted(1)], new MapBox('Messages', '1', [new Tag('1 failed', 'bad')], 'background', 'messages', 'bad')];
        yield 'messages, several failed' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(5), Metric::MESSAGE_FAILED => self::counted(3)], new MapBox('Messages', '5', [new Tag('3 failed', 'bad')], 'background', 'messages', 'bad')];
        yield 'messages sent, none handled' => ['Messages', [], [Metric::MESSAGE_SENT => self::counted(1045)], new MapBox('Messages', '0', [], 'background', 'messages')];
        yield 'no message' => ['Messages', [], [], new MapBox('Messages', '0', [], 'background', 'messages')];

        yield 'runs of tasks' => ['Scheduled tasks', [], [Metric::TASK_DURATION => self::counted(12), Metric::TASK_FAILED => self::counted(2)], new MapBox('Scheduled tasks', '12', [new Tag('2 failed', 'bad')], 'background', 'tasks', 'bad')];
        yield 'one run' => ['Scheduled tasks', [], [Metric::TASK_DURATION => self::counted(1)], new MapBox('Scheduled tasks', '1', [], 'background', 'tasks')];
        yield 'no run' => ['Scheduled tasks', [], [], new MapBox('Scheduled tasks', '0', [], 'background', 'tasks')];

        yield 'commands' => ['Commands', [], [Metric::COMMAND_DURATION => self::counted(12)], new MapBox('Commands', '12', [], 'background', 'commands')];
        yield 'commands, one failed' => ['Commands', [], [Metric::COMMAND_DURATION => self::counted(2), Metric::COMMAND_FAILED => self::counted(1)], new MapBox('Commands', '2', [new Tag('1 failed', 'bad')], 'background', 'commands', 'bad')];
        yield 'no command' => ['Commands', [], [], new MapBox('Commands', '0', [], 'background', 'commands')];

        yield 'exceptions' => ['Exceptions', [], [Metric::EXCEPTION_COUNT => self::counted(1500)], new MapBox('Exceptions', '1,500', [], 'exceptions')];
        yield 'no exception' => ['Exceptions', [], [], new MapBox('Exceptions', '0', [], 'exceptions')];

        yield 'requests of users' => ['User requests', [], [Metric::USER_DURATION => self::counted(140)], new MapBox('User requests', '140', [], 'users')];
        yield 'no user' => ['User requests', [], [], new MapBox('User requests', '0', [], 'users')];

        yield 'queries' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(3402, 2600.0, 40.0)], new MapBox('SQL queries', '3,402', [], 'queries')];
        yield 'queries, slow and repeated' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(20, 400.0, 150.0), Metric::QUERY_SLOW => self::counted(12), Metric::NPLUS1_CALLS => self::counted(1)], new MapBox('SQL queries', '20', [new Tag('12 slow', 'warn'), new Tag('N+1', 'warn')], 'queries', null, 'warn')];
        yield 'queries, repeated' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(20, 400.0, 15.0), Metric::NPLUS1_CALLS => self::counted(1)], new MapBox('SQL queries', '20', [new Tag('N+1', 'warn')], 'queries', null, 'warn')];
        yield 'no query' => ['SQL queries', [], [], new MapBox('SQL queries', '0', [], 'queries')];

        yield 'cache' => ['Cache', [], [Metric::CACHE_HIT => self::counted(9), Metric::CACHE_MISS => self::counted(1)], new MapBox('Cache', '10', [], 'cache-http', 'cache')];
        yield 'cache, only misses' => ['Cache', [], [Metric::CACHE_MISS => self::counted(3)], new MapBox('Cache', '3', [], 'cache-http', 'cache')];
        yield 'no cache read' => ['Cache', [], [], new MapBox('Cache', '0', [], 'cache-http', 'cache')];

        yield 'calls' => ['Other sites and APIs', [], [Metric::HTTP_DURATION => new MetricValue(84, 10080.0, 900.0)], new MapBox('Other sites and APIs', '84', [], 'cache-http', 'http')];
        yield 'calls with every problem' => ['Other sites and APIs', [], [
            Metric::HTTP_DURATION => new MetricValue(84, 10080.0, 900.0),
            Metric::HTTP_FAILED => self::counted(2),
            self::HTTP_5XX => self::counted(1),
            self::HTTP_4XX => self::counted(3),
        ], new MapBox('Other sites and APIs', '84', [new Tag('2 no answer', 'bad'), new Tag('1 × 5xx', 'bad'), new Tag('3 × 4xx', 'warn')], 'cache-http', 'http', 'bad')];
        yield 'calls answered 4xx' => ['Other sites and APIs', [], [Metric::HTTP_DURATION => new MetricValue(2, 20.0, 10.0), self::HTTP_4XX => self::counted(1)], new MapBox('Other sites and APIs', '2', [new Tag('1 × 4xx', 'warn')], 'cache-http', 'http', 'warn')];
        yield 'no call' => ['Other sites and APIs', [], [], new MapBox('Other sites and APIs', '0', [], 'cache-http', 'http')];
    }

    /**
     * @param list<ReportRow>            $rows
     * @param array<string, MetricValue> $totals
     */
    #[DataProvider('boxes')]
    public function testEachBox(string $label, array $rows, array $totals, MapBox $box): void
    {
        static::assertEquals($box, self::box($this->view($this->reader($rows, $totals)), $label));
    }

    public function testABoxAtZeroCarriesNeitherTagNorTone(): void
    {
        $view = $this->view($this->reader([], [
            Metric::EXCEPTION_COUNT => self::counted(1),
            Metric::MESSAGE_FAILED => self::counted(2),
            Metric::TASK_FAILED => self::counted(1),
            Metric::COMMAND_FAILED => self::counted(1),
            Metric::QUERY_SLOW => self::counted(4),
            Metric::HTTP_FAILED => self::counted(1),
            self::SERVER_ERRORS => self::counted(3),
        ]));

        $zero = array_values(array_filter(self::boxesOf($view), static fn(MapBox $box): bool => $box->value === '0'));

        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands', 'User requests', 'SQL queries', 'Cache', 'Other sites and APIs'], array_map(static fn(MapBox $box): string => $box->label, $zero));
        static::assertSame([], array_filter($zero, static fn(MapBox $box): bool => $box->tags !== [] || $box->tone !== null || $box->series !== []));
    }

    public function testTheLowHitRateTagFollowsItsCheck(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [Metric::CACHE_HIT => self::counted(2), Metric::CACHE_MISS => self::counted(30)], self::lowHitRate());

        static::assertEquals(new MapBox('Cache', '32', [new Tag('low hit rate', 'warn')], 'cache-http', 'cache', 'warn'), self::box($this->view($reader), 'Cache'));
    }

    public function testTheMessagesBoxNeedsTheMessagesRecorded(): void
    {
        $checks = self::checks();
        $queues = new class implements BlockSourceInterface {
            public function blocks(ScreenRequest $request): array
            {
                return [];
            }
        };
        $unrecorded = new MessagesSection(true, false, $queues, $checks);
        $screen = new OverviewScreen($checks, [$unrecorded], [], null);

        static::assertTrue($unrecorded->isAvailable());
        static::assertNull(self::box($this->view($this->reader([self::route('GET app_a')], [Metric::MESSAGE_DURATION => self::counted(2)]), screen: $screen, withStack: false), 'Messages'));
    }

    public function testTheBoxesComeInTheirColumns(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')]);
        $map = $this->view($this->reader([self::route('GET app_a')]), stack: $stack)->map;

        static::assertNotNull($map);
        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands'], array_map(static fn(MapBox $box): string => $box->label, $map->incoming));
        static::assertSame(['Exceptions', 'User requests'], array_map(static fn(MapBox $box): string => $box->label, $map->inside));
        static::assertSame(['SQL queries', 'Cache', 'Other sites and APIs'], array_map(static fn(MapBox $box): string => $box->label, $map->outgoing));
        static::assertSame($stack, $map->runsOn);
        static::assertNull($this->view($this->reader([self::route('GET app_a')]), withStack: false)->map?->runsOn);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function unavailable(): iterable
    {
        yield 'messages' => [['messages'], ['Messages']];
        yield 'tasks' => [['tasks'], ['Scheduled tasks']];
        yield 'commands' => [['commands'], ['Commands']];
        yield 'cache' => [['cache'], ['Cache']];
        yield 'http' => [['http'], ['Other sites and APIs']];
        yield 'queries' => [['queries'], ['SQL queries']];
        yield 'exceptions' => [['exceptions'], ['Exceptions']];
        yield 'users' => [['users'], ['User requests']];
        yield 'everything but requests' => [['messages', 'tasks', 'commands', 'cache', 'http', 'queries', 'exceptions', 'users'], ['Messages', 'Scheduled tasks', 'Commands', 'Exceptions', 'User requests', 'SQL queries', 'Cache', 'Other sites and APIs']];
    }

    /**
     * @param list<string> $off
     * @param list<string> $gone
     */
    #[DataProvider('unavailable')]
    public function testABoxOnlyExistsWhenWhatItOpensIsAvailable(array $off, array $gone): void
    {
        $all = ['Requests', 'Messages', 'Scheduled tasks', 'Commands', 'Exceptions', 'User requests', 'SQL queries', 'Cache', 'Other sites and APIs'];

        $view = $this->view($this->reader([self::route('GET app_a')]), screen: $this->screen(off: $off));

        static::assertSame(array_values(array_diff($all, $gone)), self::labels($view));
    }

    public function testAnAlertWhoseScreenIsGoneStaysWithItsLinkLeftToTheTemplate(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [Metric::HTTP_FAILED => self::counted(1)]);

        $view = $this->view($reader, screen: $this->screen(off: ['http', 'cache']));

        static::assertSame([['http.failures', 'cache-http']], array_map(static fn(Alert $alert): array => [$alert->id, $alert->path], $view->alerts->alerts ?? []));
        static::assertNull(self::box($view, 'Other sites and APIs'));
    }

    public function testTheReadHoldsWhatTheViewNeeds(): void
    {
        $report = self::requestReport([self::route('GET app_a', 2)]);
        $reader = new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::Request ? $report : new Report(), totals: [Metric::REQUEST_DURATION => self::counted(2), self::SERVER_ERRORS => self::counted(1)]);

        $read = $this->screen()->read($reader, Period::OneHour, self::NOW);

        static::assertSame($report, $read->requests);
        static::assertSame(2, $read->count(Metric::REQUEST_DURATION));
        static::assertTrue($read->raised->has('requests.server_errors'));
        static::assertFalse($read->isEmpty());
    }

    /**
     * @return iterable<string, array{list<ReportRow>, array<string, MetricValue>, bool}>
     */
    public static function emptiness(): iterable
    {
        yield 'nothing' => [[], [], true];
        yield 'a request' => [[self::route('GET app_a')], [], false];
        yield 'a command' => [[], [Metric::COMMAND_DURATION => self::counted(1)], false];
        yield 'a cache read' => [[], [Metric::CACHE_MISS => self::counted(1)], false];
        yield 'an exception' => [[], [Metric::EXCEPTION_COUNT => self::counted(1)], false];
        yield 'a message sent' => [[], [Metric::MESSAGE_SENT => self::counted(1)], false];
        yield 'server errors alone' => [[], [self::SERVER_ERRORS => self::counted(2)], false];
        yield 'a slow query alone' => [[], [Metric::QUERY_SLOW => self::counted(1)], false];
        yield 'a count at zero' => [[], [Metric::MESSAGE_DURATION => new MetricValue(0, 0.0, 0.0)], true];
    }

    /**
     * @param list<ReportRow>            $rows
     * @param array<string, MetricValue> $totals
     */
    #[DataProvider('emptiness')]
    public function testTheReadIsEmptyWhenNothingIsCounted(array $rows, array $totals, bool $empty): void
    {
        static::assertSame($empty, $this->screen()->read($this->reader($rows, $totals), Period::OneHour, self::NOW)->isEmpty());
    }

    public function testWithoutReadTheTitleStandsWithoutPillAboveTheStack(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')], [self::stackAlert('php.opcache', 'info'), self::stackAlert('php.memory', 'warn'), self::stackAlert('mysql.down', 'bad')]);

        $view = $this->screen()->view(null, $stack, Period::OneHour);

        static::assertEquals(new Status('Overview'), $view->status);
        static::assertSame(['mysql.down', 'php.memory'], array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []));
        static::assertEquals(new Map(runsOn: $stack), $view->map);
        static::assertEquals(self::places(), $view->places);
        static::assertSame([], self::labels($view));
        static::assertNull($view->lead);
        static::assertNull($view->checks);
        static::assertSame([], $view->tables);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
    }

    public function testWithoutReadNorStackOnlyTheTitleAndThePlacesRemain(): void
    {
        $view = $this->screen(withStack: false)->view(null, null, Period::OneHour);

        static::assertEquals(new Status('Overview'), $view->status);
        static::assertNull($view->alerts);
        static::assertNull($view->map);
        static::assertEquals(self::places(), $view->places);
        static::assertNull($this->screen()->view(null, new StackGlance([self::card('php', 'ok')]), Period::OneHour)->alerts);
    }

    public function testNothingInThePeriodShowsEveryBoxAtZeroAndThePlaces(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')], [self::stackAlert('php.memory', 'warn')]);

        $view = $this->view($this->reader([]), stack: $stack);

        static::assertEquals(new Status('Overview', new Mark('unknown', 'No data yet')), $view->status);
        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands', 'Exceptions', 'User requests', 'SQL queries', 'Cache', 'Other sites and APIs'], self::labels($view));
        static::assertSame([], array_values(array_filter(self::boxesOf($view), static fn(MapBox $box): bool => $box->value !== '0' || $box->tags !== [] || $box->tone !== null || $box->series !== [])));
        static::assertSame($stack, $view->map?->runsOn);
        static::assertSame(['php.memory'], array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []));
        static::assertEquals(self::places(), $view->places);
        static::assertNotNull($view->raised);
    }

    public function testServerErrorsCountedWithoutRequestsAreNotAnEmptyPeriod(): void
    {
        $view = $this->view($this->reader([], [self::SERVER_ERRORS => self::counted(2)]));

        static::assertNull($view->places);
        static::assertEquals(new Status('Overview', new Mark('bad', '2 server errors')), $view->status);
        static::assertSame(['requests.server_errors'], array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []));
    }

    public function testNoDataYetNeverComesWithACardOfTheApplication(): void
    {
        foreach (self::ALL_RAISED as $metric => $count) {
            $view = $this->view($this->reader([], [$metric => self::counted($count)], $metric === Metric::CACHE_MISS ? self::lowHitRate() : []));

            static::assertNotNull($view->alerts, $metric);
            static::assertNotSame('No data yet', $view->status?->mark?->label, $metric);
            static::assertNull($view->places, $metric);
        }
    }

    public function testAPageWithDataDoesNotShowThePlaces(): void
    {
        $view = $this->view($this->reader([], [Metric::COMMAND_DURATION => self::counted(1)]), stack: new StackGlance([self::card('php', 'ok')]));

        static::assertNull($view->places);
        static::assertEquals(new Status('Overview', new Mark('unknown', 'No request')), $view->status);
    }

    public function testAStackThatCouldNotBeReadLeavesTheApplicationWhole(): void
    {
        $stack = new StackGlance(notice: new Notice('bad', 'The stack could not be read'));

        $view = $this->view($this->reader([self::route('GET app_a', 3)], [self::SERVER_ERRORS => self::counted(1)]), stack: $stack);

        static::assertEquals(new Status('Overview', new Mark('bad', '1 server error')), $view->status);
        static::assertSame(['requests.server_errors'], array_map(static fn(Alert $alert): string => $alert->id, $view->alerts->alerts ?? []));
        static::assertCount(9, self::labels($view));
        static::assertSame($stack, $view->map?->runsOn);
    }

    public function testTheViewHandsItsRaisedChecksOn(): void
    {
        $screen = $this->screen();
        $read = $screen->read($this->reader([self::route('GET app_a')], [self::SERVER_ERRORS => self::counted(1)]), Period::OneHour, self::NOW);

        static::assertSame($read->raised, $screen->view($read, null, Period::OneHour)->raised);
    }

    private static function places(): Places
    {
        return new Places([
            new Place('Overview', '', 'This page. What needs a look comes first.', 'Is everything fine?'),
            new Place('Application', 'requests', 'Requests, SQL queries, cache, outgoing HTTP, exceptions, users, background work.', 'What is slow, and why?'),
            new Place('Stack', 'stack', 'The server, PHP, Symfony, the web server, the databases and Redis.', 'Are the servers healthy right now?'),
        ]);
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([
            new ServerErrorsCheck(),
            new SlowRequestsCheck(1000),
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

    /**
     * @param list<string> $off the ids of the sections and screens that are not available
     */
    private function screen(array $off = [], ?StackGlanceInterface $stack = null, bool $withStack = true): OverviewScreen
    {
        $checks = self::checks();
        $on = static fn(string $id): bool => !\in_array($id, $off, true);

        return new OverviewScreen(
            $checks,
            [
                new MessagesSection($on('messages'), true, null, $checks),
                new TasksSection($on('tasks'), new DeclaredTasks(), $checks),
                new CommandsSection($on('commands'), $checks),
                new CacheSection($on('cache'), $checks),
                new HttpSection($on('http'), $checks),
            ],
            [
                new QueriesScreen($on('queries'), $checks, 100, 10),
                new ExceptionsScreen($on('exceptions'), $checks),
                new UsersScreen($on('users'), $checks, 1000),
            ],
            $withStack ? ($stack ?? self::glance(new StackGlance())) : null,
        );
    }

    private function view(FixedReader $reader, Period $period = Period::OneHour, ?StackGlance $stack = null, bool $withStack = true, ?OverviewScreen $screen = null): ScreenView
    {
        $screen ??= $this->screen(withStack: $withStack);

        return $screen->view($screen->read($reader, $period, self::NOW), $withStack ? $stack ?? new StackGlance() : null, $period);
    }

    /**
     * @param list<ReportRow>                           $rows      the routes of the Request family, whose requests the totals count too
     * @param array<string, MetricValue>                $totals
     * @param array<string, array<string, MetricValue>> $keyTotals
     */
    private function reader(array $rows, array $totals = [], array $keyTotals = []): FixedReader
    {
        $requests = self::requestReport($rows);
        if ($rows !== []) {
            $totals += [Metric::REQUEST_DURATION => $requests->totals[Metric::REQUEST_DURATION]];
        }

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::Request ? $requests : new Report(), totals: $totals, keyTotals: $keyTotals);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private static function requestReport(array $rows): Report
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach ($row->metrics as $type => $value) {
                $total = $totals[$type] ?? null;
                $totals[$type] = $total === null ? $value : new MetricValue($total->count + $value->count, $total->sum + $value->sum, max($total->max, $value->max));
            }
        }

        return new Report($rows, null, \count($rows), array_fill(0, 60, 0), $totals);
    }

    private static function route(string $key, int $requests = 1, int $errors = 0, int $slow = 0): ReportRow
    {
        $metrics = [Metric::REQUEST_DURATION => new MetricValue($requests, 10.0 * $requests, 10.0)];
        if ($errors > 0) {
            $metrics[self::SERVER_ERRORS] = self::counted($errors);
        }
        if ($slow > 0) {
            $metrics[Metric::REQUEST_SLOW] = self::counted($slow);
        }

        return new ReportRow(md5($key), $key, $key, $metrics);
    }

    private static function counted(int $count): MetricValue
    {
        return new MetricValue($count, 0.0, 0.0);
    }

    /**
     * @param 'bad'|'warn'|'info' $tone
     */
    private static function stackAlert(string $id, string $tone): Alert
    {
        return new Alert($id, new Mark($tone, ucfirst($tone)), 'A point of the stack', 'PHP', 'stack/php');
    }

    /** @return array<string, MetricValue> */
    private static function allRaised(): array
    {
        return array_map(static fn(int $count): MetricValue => self::counted($count), self::ALL_RAISED);
    }

    /** @return array<string, array<string, MetricValue>> */
    private static function lowHitRate(): array
    {
        return [
            Metric::CACHE_MISS => [md5('cache.app') => self::counted(30)],
            Metric::CACHE_HIT => [md5('cache.app') => self::counted(2)],
        ];
    }

    /**
     * @param 'ok'|'warn'|'bad'|'info' $tone
     */
    private static function card(string $id, string $tone): ComponentCard
    {
        return new ComponentCard($id, ucfirst($id), new Mark($tone, ucfirst($tone)), [new Vital('Memory', '41 %')], '1.0', 'stack/' . $id);
    }

    private static function glance(StackGlance $glance): FixedGlance
    {
        return new FixedGlance($glance);
    }

    private static function box(ScreenView $view, string $label): ?MapBox
    {
        foreach (self::boxesOf($view) as $box) {
            if ($box->label === $label) {
                return $box;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function labels(ScreenView $view): array
    {
        return array_map(static fn(MapBox $box): string => $box->label, self::boxesOf($view));
    }

    /** @return list<MapBox> */
    private static function boxesOf(ScreenView $view): array
    {
        $map = $view->map;

        return $map === null ? [] : [...$map->incoming, ...$map->inside, ...$map->outgoing];
    }
}
