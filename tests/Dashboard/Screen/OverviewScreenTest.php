<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RequestCulprit;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewRead;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\Section\CacheSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\CommandsSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedGlance;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedSchedules;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

final class OverviewScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';
    private const HTTP_4XX = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const HTTP_5XX = Metric::HTTP_STATUS_PREFIX . '5xx';

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

    public function testItReadsTheTotalsAndTheRequestFamilyWithItsCurve(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3, 30.0)]);

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
            Metric::MESSAGE_DURATION,
            Metric::MESSAGE_SENT,
            Metric::TASK_DURATION,
            Metric::COMMAND_DURATION,
            Metric::EXCEPTION_COUNT,
            Metric::USER_DURATION,
            Metric::QUERY_DURATION,
            Metric::HTTP_DURATION,
        ]]], $reader->totalRequests);
        static::assertEquals([new ReportQuery(Period::SixHours, self::NOW, Family::Request, seriesMetric: Metric::REQUEST_DURATION, metrics: [
            Metric::REQUEST_DURATION,
            Metric::REQUEST_SQL_TIME,
            Metric::REQUEST_SQL_COUNT,
            Metric::REQUEST_HTTP_TIME,
            self::SERVER_ERRORS,
            Metric::REQUEST_SLOW,
        ])], $reader->queries);
    }

    public function testTheReadOfRequestsHoldsWhatBothChecksOfRequestsLookAt(): void
    {
        $reader = $this->reader([self::route('GET app_a')]);
        $this->screen()->read($reader, Period::OneHour, self::NOW);

        static::assertSame([], array_diff(RequestCulprit::query(Period::OneHour, self::NOW)->metrics, $reader->queries[0]->metrics));
        static::assertSame(Family::Request, RequestCulprit::query(Period::OneHour, self::NOW)->family);
    }

    public function testTheRequestFamilyIsReadOnceAndHandedToBothChecks(): void
    {
        $reader = $this->reader(
            [self::route('GET app_boom', 4, 40.0, errors: 2), self::route('GET app_slow', 2, 3000.0, max: 2000.0, slow: 2)],
            [self::SERVER_ERRORS => self::counted(2), Metric::REQUEST_SLOW => self::counted(2)],
        );

        $view = $this->view($reader);

        static::assertCount(1, $reader->queries);
        static::assertSame(Family::Request, $reader->queries[0]->family);
        static::assertCount(1, $reader->totalRequests);
        static::assertSame(
            [
                'requests.server_errors: GET app_boom answered with a 5xx status 2 times.',
                'requests.slow: GET app_slow took 1.0 s or more 2 times, 2.0 s at worst.',
            ],
            array_map(static fn(CheckLine $line): string => $line->id . ': ' . $line->sentence, self::lines($view)),
        );
    }

    public function testEachOtherRaisedCheckReadsItsCulprit(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [
            Metric::QUERY_SLOW => self::counted(1),
            Metric::MESSAGE_FAILED => self::counted(1),
            Metric::HTTP_FAILED => self::counted(1),
        ]);

        $this->screen()->read($reader, Period::OneHour, self::NOW);

        static::assertSame([Family::Request, Family::Message, Family::Query, Family::Http], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
    }

    public function testARaisedCheckWithoutCulpritShowsItsCountAlone(): void
    {
        $view = $this->view($this->reader([self::route('GET app_a', 3)], [self::SERVER_ERRORS => self::counted(2)]));

        $line = self::lines($view)[0];
        static::assertSame('requests.server_errors', $line->id);
        static::assertSame('2 server errors', $line->title);
        static::assertSame('Responses with a 5xx status in the last hour.', $line->sentence);
        static::assertEquals(new Mark('bad', 'Critical'), $line->mark);
    }

    public function testALineOfTheApplicationLinksToItsScreen(): void
    {
        $line = self::lines($this->view($this->reader([self::route('GET app_a')], [Metric::MESSAGE_FAILED => self::counted(1)]), Period::SixHours))[0];

        static::assertSame('Background', $line->where);
        static::assertSame('background', $line->path);
        static::assertSame(['period' => '6h'], $line->query);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, array<string, MetricValue>, list<CheckLine>, string, string}>
     */
    public static function leads(): iterable
    {
        $fine = 'Last hour. Follow any box to its detail.';
        $look = 'Last hour. Start with the list below, then follow any box to its detail.';

        yield 'requests without server error, no line' => [[self::route('GET app_a', 3)], [], [], 'Your application answers normally. Nothing is worth a look.', $fine];
        yield 'one server error' => [[self::route('GET app_a', 3, errors: 1)], [self::SERVER_ERRORS => self::counted(1)], [], '1 server error in 3 requests. 1 thing is worth a look.', $look];
        yield 'several server errors' => [
            [self::route('GET app_a', 10, errors: 4, slow: 1)],
            [self::SERVER_ERRORS => self::counted(4), Metric::REQUEST_SLOW => self::counted(1)],
            [],
            '4 server errors in 10 requests. 2 things are worth a look.',
            $look,
        ];
        yield 'one request, one server error' => [[self::route('GET app_a', 1, errors: 1)], [self::SERVER_ERRORS => self::counted(1)], [], '1 server error in 1 request. 1 thing is worth a look.', $look];
        yield 'more server errors than requests read' => [[self::route('GET app_a', 1, errors: 1)], [self::SERVER_ERRORS => self::counted(2)], [], '2 server errors in 2 requests. 1 thing is worth a look.', $look];
        yield 'no request' => [[], [Metric::EXCEPTION_COUNT => self::counted(1)], [], 'No request in the last hour. Nothing is worth a look.', $fine];
        yield 'no request, the lines of the stack' => [[], [Metric::EXCEPTION_COUNT => self::counted(1)], [self::stackLine('php.opcache', 'info'), self::stackLine('php.memory', 'warn')], 'No request in the last hour. 2 things are worth a look.', $look];
        yield 'a line of the stack only' => [[self::route('GET app_a', 2)], [], [self::stackLine('php.opcache', 'info')], 'Your application answers normally. 1 thing is worth a look.', $look];
    }

    /**
     * @param list<ReportRow>            $rows
     * @param array<string, MetricValue> $totals
     * @param list<CheckLine>            $stackLines
     */
    #[DataProvider('leads')]
    public function testTheLeadSumsUpTheApplication(array $rows, array $totals, array $stackLines, string $sentence, string $detail): void
    {
        $view = $this->view($this->reader($rows, $totals), stack: new StackGlance(lines: $stackLines));

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
        static::assertSame($detail, $view->lead->detail);
        static::assertSame([], $view->lead->series);
    }

    public function testTheLeadNamesThePeriod(): void
    {
        $view = $this->view($this->reader([], [Metric::EXCEPTION_COUNT => self::counted(1)]), Period::SevenDays);

        static::assertSame('No request in the last 7 days. Nothing is worth a look.', $view->lead?->sentence);
        static::assertSame('Last 7 days. Follow any box to its detail.', $view->lead?->detail);
    }

    public function testTheLinesComeMostSevereFirstTheApplicationBeforeTheStack(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3, errors: 1, slow: 1)], [
            self::SERVER_ERRORS => self::counted(1),
            Metric::REQUEST_SLOW => self::counted(1),
            Metric::NPLUS1_CALLS => self::counted(1),
        ]);
        $stack = new StackGlance(lines: [
            self::stackLine('mysql.down', 'bad'),
            self::stackLine('php.memory', 'warn'),
            self::stackLine('php.opcache', 'info'),
        ]);

        $view = $this->view($reader, stack: $stack);

        static::assertSame(
            ['requests.server_errors', 'mysql.down', 'requests.slow', 'queries.repeated', 'php.memory', 'php.opcache'],
            array_map(static fn(CheckLine $line): string => $line->id, self::lines($view)),
        );
        static::assertSame('Worth a look', $view->checks?->title);
        static::assertSame('Stack · PHP', self::lines($view)[4]->where);
        static::assertSame('', self::lines($view)[4]->action);
    }

    public function testCheckedAndFineFollowsTheBoxesWithAValueUnderTheLines(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3, errors: 1)], [
            self::SERVER_ERRORS => self::counted(1),
            Metric::MESSAGE_DURATION => new MetricValue(4, 40.0, 20.0),
            Metric::QUERY_DURATION => new MetricValue(12, 30.0, 4.0),
            Metric::HTTP_DURATION => new MetricValue(2, 50.0, 30.0),
        ]);
        $stack = new StackGlance([self::card('php', 'ok'), self::card('symfony', 'info'), self::card('server', 'ok')]);

        $checks = $this->view($reader, stack: $stack)->checks;

        static::assertNotNull($checks);
        static::assertSame('Checked and fine: no slow request, no failed message, no slow query, no repeated query, no call without an answer, no error response, 2 of 3 stack components healthy.', $checks->footnote);
    }

    public function testWithoutLineCheckedAndFineIsTheEmptyText(): void
    {
        $reader = $this->reader([self::route('GET app_a', 3)], [
            Metric::CACHE_HIT => self::counted(8),
            Metric::CACHE_MISS => self::counted(2),
            Metric::TASK_DURATION => self::counted(1),
            Metric::COMMAND_DURATION => self::counted(1),
        ]);

        $checks = $this->view($reader, Period::SixHours)->checks;

        static::assertNotNull($checks);
        static::assertSame([], $checks->lines);
        static::assertSame('Nothing to flag', $checks->emptyTitle);
        static::assertSame('No server error, no slow request, no failed scheduled run, no failed command run, no pool with a low hit rate in the last 6 hours.', $checks->emptyText);
        static::assertSame('', $checks->footnote);
    }

    public function testTheHealthyComponentsAreOnlyToldWithCards(): void
    {
        $checks = $this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance(notice: new Notice('bad', 'The live stack could not be read')))->checks;

        static::assertSame('No server error, no slow request in the last hour.', $checks?->emptyText);

        $checks = $this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance([self::card('php', 'warn')]))->checks;

        static::assertSame('No server error, no slow request, 0 of 1 stack component healthy in the last hour.', $checks?->emptyText);
    }

    public function testNothingCheckedLeavesNeitherFootnoteNorText(): void
    {
        $checks = $this->view($this->reader([], [Metric::EXCEPTION_COUNT => self::counted(1)]), withStack: false)->checks;

        static::assertNotNull($checks);
        static::assertSame([], $checks->lines);
        static::assertSame('', $checks->emptyText);
        static::assertSame('', $checks->footnote);

        $checks = $this->view($this->reader([], [Metric::EXCEPTION_COUNT => self::counted(1)]), stack: new StackGlance(lines: [self::stackLine('php.opcache', 'info')]))->checks;

        static::assertSame('', $checks?->footnote);
    }

    /**
     * @return iterable<string, array{string, list<ReportRow>, array<string, MetricValue>, int, MapBox}>
     */
    public static function boxes(): iterable
    {
        $series = array_fill(0, 60, 0);

        yield 'requests' => ['Requests', [self::route('GET app_a', 1284)], [], 0, new MapBox('Requests', '1,284', 'No server error, no slow request.', [], 'requests', null, null, $series)];
        yield 'requests with a server error' => ['Requests', [self::route('GET app_a', 3, errors: 1)], [self::SERVER_ERRORS => self::counted(1)], 0, new MapBox('Requests', '3', '', [new Tag('1 × 5xx', 'bad')], 'requests', null, 'bad', $series)];
        yield 'requests with slow ones and server errors' => ['Requests', [self::route('GET app_a', 9, errors: 3, slow: 2)], [self::SERVER_ERRORS => self::counted(3), Metric::REQUEST_SLOW => self::counted(2)], 0, new MapBox('Requests', '9', '', [new Tag('3 × 5xx', 'bad'), new Tag('2 slow', 'warn')], 'requests', null, 'bad', $series)];
        yield 'requests with a slow one' => ['Requests', [self::route('GET app_a', 2, slow: 1)], [Metric::REQUEST_SLOW => self::counted(1)], 0, new MapBox('Requests', '2', '', [new Tag('1 slow', 'warn')], 'requests', null, 'warn', $series)];
        yield 'no request' => ['Requests', [], [], 0, new MapBox('Requests', null, 'No request in the last hour. Open a page of your application, then come back.', [], 'requests')];

        yield 'messages' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(42), Metric::MESSAGE_SENT => self::counted(45)], 0, new MapBox('Messages', '42', 'Handled by Messenger, 45 sent.', [], 'background', 'messages')];
        yield 'messages, one failed' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(1), Metric::MESSAGE_FAILED => self::counted(1)], 0, new MapBox('Messages', '1', 'Handled by Messenger, 0 sent.', [new Tag('1 failed', 'bad')], 'background', 'messages', 'bad')];
        yield 'messages, several failed' => ['Messages', [], [Metric::MESSAGE_DURATION => self::counted(5), Metric::MESSAGE_SENT => self::counted(5), Metric::MESSAGE_FAILED => self::counted(3)], 0, new MapBox('Messages', '5', 'Handled by Messenger, 5 sent.', [new Tag('3 failed', 'bad')], 'background', 'messages', 'bad')];
        yield 'no message' => ['Messages', [], [Metric::MESSAGE_SENT => self::counted(2)], 0, new MapBox('Messages', null, 'No message handled in the last hour.', [], 'background', 'messages')];

        yield 'one run of one task' => ['Scheduled tasks', [], [Metric::TASK_DURATION => self::counted(1)], 1, new MapBox('Scheduled tasks', '1', 'Run of 1 declared task.', [], 'background', 'tasks')];
        yield 'runs of tasks' => ['Scheduled tasks', [], [Metric::TASK_DURATION => self::counted(12), Metric::TASK_FAILED => self::counted(2)], 3, new MapBox('Scheduled tasks', '12', 'Runs of 3 declared tasks.', [new Tag('2 failed', 'bad')], 'background', 'tasks', 'bad')];
        yield 'one task, no run' => ['Scheduled tasks', [], [], 1, new MapBox('Scheduled tasks', null, '1 task declared, none ran in the last hour.', [], 'background', 'tasks')];
        yield 'tasks, no run' => ['Scheduled tasks', [], [], 2, new MapBox('Scheduled tasks', null, '2 tasks declared, none ran in the last hour.', [], 'background', 'tasks')];
        yield 'no task' => ['Scheduled tasks', [], [], 0, new MapBox('Scheduled tasks', null, 'No scheduled run in the last hour.', [], 'background', 'tasks')];

        yield 'commands' => ['Commands', [], [Metric::COMMAND_DURATION => self::counted(12)], 0, new MapBox('Commands', '12', 'Console runs.', [], 'background', 'commands')];
        yield 'commands, one failed' => ['Commands', [], [Metric::COMMAND_DURATION => self::counted(2), Metric::COMMAND_FAILED => self::counted(1)], 0, new MapBox('Commands', '2', 'Console runs.', [new Tag('1 failed', 'bad')], 'background', 'commands', 'bad')];
        yield 'no command' => ['Commands', [], [], 0, new MapBox('Commands', null, 'No command ran in the last hour.', [], 'background', 'commands')];

        yield 'one exception' => ['Exceptions', [], [Metric::EXCEPTION_COUNT => self::counted(1)], 0, new MapBox('Exceptions', '1', 'exception thrown.', [], 'exceptions')];
        yield 'exceptions' => ['Exceptions', [], [Metric::EXCEPTION_COUNT => self::counted(1500)], 0, new MapBox('Exceptions', '1,500', 'exceptions thrown.', [], 'exceptions')];
        yield 'no exception' => ['Exceptions', [], [], 0, new MapBox('Exceptions', null, 'No exception in the last hour.', [], 'exceptions')];

        yield 'one request of a user' => ['Signed-in users', [], [Metric::USER_DURATION => self::counted(1)], 0, new MapBox('Signed-in users', '1', 'request made by signed-in users.', [], 'users')];
        yield 'requests of users' => ['Signed-in users', [], [Metric::USER_DURATION => self::counted(140)], 0, new MapBox('Signed-in users', '140', 'requests made by signed-in users.', [], 'users')];
        yield 'no user' => ['Signed-in users', [], [], 0, new MapBox('Signed-in users', null, 'No request from a signed-in user in the last hour.', [], 'users')];

        yield 'queries' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(3402, 2600.0, 40.0)], 0, new MapBox('SQL queries', '3,402', '2.6 s in total, none slow.', [], 'queries')];
        yield 'queries, one slow' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(20, 400.0, 150.0), Metric::QUERY_SLOW => self::counted(1)], 0, new MapBox('SQL queries', '20', '400 ms in total.', [new Tag('1 slow', 'warn')], 'queries', null, 'warn')];
        yield 'queries, slow and repeated' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(20, 400.0, 150.0), Metric::QUERY_SLOW => self::counted(12), Metric::NPLUS1_CALLS => self::counted(1)], 0, new MapBox('SQL queries', '20', '400 ms in total.', [new Tag('12 slow', 'warn'), new Tag('N+1', 'warn')], 'queries', null, 'warn')];
        yield 'queries, repeated' => ['SQL queries', [], [Metric::QUERY_DURATION => new MetricValue(20, 400.0, 15.0), Metric::NPLUS1_CALLS => self::counted(1)], 0, new MapBox('SQL queries', '20', '400 ms in total, none slow.', [new Tag('N+1', 'warn')], 'queries', null, 'warn')];
        yield 'no query' => ['SQL queries', [], [], 0, new MapBox('SQL queries', null, 'No SQL query in the last hour.', [], 'queries')];

        yield 'cache' => ['Cache', [], [Metric::CACHE_HIT => self::counted(9), Metric::CACHE_MISS => self::counted(1)], 0, new MapBox('Cache', '10', '90 % found in the cache.', [], 'cache-http', 'cache')];
        yield 'cache, only misses' => ['Cache', [], [Metric::CACHE_MISS => self::counted(3)], 0, new MapBox('Cache', '3', '0 % found in the cache.', [], 'cache-http', 'cache')];
        yield 'no cache read' => ['Cache', [], [], 0, new MapBox('Cache', null, 'No cache read in the last hour.', [], 'cache-http', 'cache')];

        yield 'calls' => ['Other sites and APIs', [], [Metric::HTTP_DURATION => new MetricValue(84, 10080.0, 900.0)], 0, new MapBox('Other sites and APIs', '84', '120 ms per call on average.', [], 'cache-http', 'http')];
        yield 'calls with every problem' => ['Other sites and APIs', [], [
            Metric::HTTP_DURATION => new MetricValue(84, 10080.0, 900.0),
            Metric::HTTP_FAILED => self::counted(2),
            self::HTTP_5XX => self::counted(1),
            self::HTTP_4XX => self::counted(3),
        ], 0, new MapBox('Other sites and APIs', '84', '120 ms per call on average.', [new Tag('2 no answer', 'bad'), new Tag('1 × 5xx', 'bad'), new Tag('3 × 4xx', 'warn')], 'cache-http', 'http', 'bad')];
        yield 'calls answered 4xx' => ['Other sites and APIs', [], [Metric::HTTP_DURATION => new MetricValue(2, 20.0, 10.0), self::HTTP_4XX => self::counted(1)], 0, new MapBox('Other sites and APIs', '2', '10 ms per call on average.', [new Tag('1 × 4xx', 'warn')], 'cache-http', 'http', 'warn')];
        yield 'no call' => ['Other sites and APIs', [], [], 0, new MapBox('Other sites and APIs', null, 'No outgoing call in the last hour.', [], 'cache-http', 'http')];
    }

    /**
     * @param list<ReportRow>            $rows
     * @param array<string, MetricValue> $totals
     */
    #[DataProvider('boxes')]
    public function testEachBox(string $label, array $rows, array $totals, int $declared, MapBox $box): void
    {
        $view = $this->view($this->reader($rows, $totals), screen: $this->screen(declared: $declared));

        static::assertEquals($box, self::box($view, $label));
    }

    public function testTheLowHitRateTagFollowsItsCheck(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [Metric::CACHE_HIT => self::counted(2), Metric::CACHE_MISS => self::counted(30)], [
            Metric::CACHE_MISS => [md5('cache.app') => self::counted(30)],
            Metric::CACHE_HIT => [md5('cache.app') => self::counted(2)],
        ]);

        static::assertEquals(
            new MapBox('Cache', '32', '6.3 % found in the cache.', [new Tag('low hit rate', 'warn')], 'cache-http', 'cache', 'warn'),
            self::box($this->view($reader), 'Cache'),
        );
    }

    public function testTheBoxesComeInTheirColumns(): void
    {
        $map = $this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance([self::card('php', 'ok')]))->map;

        static::assertNotNull($map);
        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands'], array_map(static fn(MapBox $box): string => $box->label, $map->incoming));
        static::assertSame('An average request', $map->average?->label);
        static::assertSame(['Exceptions', 'Signed-in users'], array_map(static fn(MapBox $box): string => $box->label, $map->inside));
        static::assertSame(['SQL queries', 'Cache', 'Other sites and APIs'], array_map(static fn(MapBox $box): string => $box->label, $map->outgoing));
        static::assertEquals(new StackGlance([self::card('php', 'ok')]), $map->runsOn);
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
        yield 'users' => [['users'], ['Signed-in users']];
        yield 'everything but requests' => [['messages', 'tasks', 'commands', 'cache', 'http', 'queries', 'exceptions', 'users'], ['Messages', 'Scheduled tasks', 'Commands', 'Exceptions', 'Signed-in users', 'SQL queries', 'Cache', 'Other sites and APIs']];
    }

    /**
     * @param list<string> $off
     * @param list<string> $gone
     */
    #[DataProvider('unavailable')]
    public function testABoxOnlyExistsWhenWhatItOpensIsAvailable(array $off, array $gone): void
    {
        $all = ['Requests', 'Messages', 'Scheduled tasks', 'Commands', 'An average request', 'Exceptions', 'Signed-in users', 'SQL queries', 'Cache', 'Other sites and APIs'];

        $view = $this->view($this->reader([self::route('GET app_a')]), screen: $this->screen(off: $off));

        static::assertSame(array_values(array_diff($all, $gone)), self::labels($view));
    }

    public function testABoxThatIsGoneTakesItsCheckedTextAlong(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [Metric::MESSAGE_DURATION => self::counted(3), Metric::COMMAND_DURATION => self::counted(1)]);

        $checks = $this->view($reader, screen: $this->screen(off: ['messages']))->checks;

        static::assertSame('No server error, no slow request, no failed command run in the last hour.', $checks?->emptyText);
    }

    public function testALineWhoseScreenIsGoneStaysWithItsLinkLeftToTheTemplate(): void
    {
        $reader = $this->reader([self::route('GET app_a')], [Metric::HTTP_FAILED => self::counted(1)]);

        $view = $this->view($reader, screen: $this->screen(off: ['http', 'cache']));

        static::assertSame(['http.failures'], array_map(static fn(CheckLine $line): string => $line->id, self::lines($view)));
        static::assertNull(self::box($view, 'Other sites and APIs'));
    }

    public function testTheAverageSplitsBetweenPhpSqlAndHttp(): void
    {
        $view = $this->view($this->reader([
            self::route('GET app_a', 4, 400.0, sqlRequests: 4, sqlTime: 80.0, sqlCount: 18.0, httpRequests: 2, httpTime: 60.0),
            self::route('GET app_b', 6, 100.0, sqlRequests: 6, sqlTime: 20.0, sqlCount: 6.0),
        ]));
        $map = $view->map;

        static::assertNotNull($map);
        static::assertEquals(new MapBox('An average request', '50 ms', '2.4 SQL queries per request.'), $map->average);
        static::assertEquals(Cell::averageSplit(50.0, 10.0, 2.4, 6.0), $map->split);
        static::assertSame(['php' => 34.0, 'sql' => 10.0, 'http' => 6.0], $map->split?->parts);
    }

    /**
     * @return iterable<string, array{ReportRow, string, array{php: float, sql: float, http: float}}>
     */
    public static function averages(): iterable
    {
        yield 'one query per request' => [self::route('GET app_a', 2, 20.0, sqlRequests: 2, sqlTime: 4.0, sqlCount: 2.0), '1 SQL query per request.', ['php' => 8.0, 'sql' => 2.0, 'http' => 0.0]];
        yield 'PHP only' => [self::route('GET app_a', 2, 20.0), '', ['php' => 10.0, 'sql' => 0.0, 'http' => 0.0]];
        yield 'HTTP only' => [self::route('GET app_a', 2, 20.0, httpRequests: 1, httpTime: 8.0), '', ['php' => 6.0, 'sql' => 0.0, 'http' => 4.0]];
        yield 'SQL and HTTP longer than the average' => [self::route('GET app_a', 1, 10.0, sqlRequests: 1, sqlTime: 8.0, sqlCount: 12.0, httpRequests: 1, httpTime: 6.0), '12 SQL queries per request.', ['php' => 0.0, 'sql' => 8.0, 'http' => 6.0]];
    }

    /**
     * @param array{php: float, sql: float, http: float} $parts
     */
    #[DataProvider('averages')]
    public function testTheLimitsOfTheAverage(ReportRow $route, string $text, array $parts): void
    {
        $map = $this->view($this->reader([$route]))->map;

        static::assertSame($text, $map?->average?->text);
        static::assertSame($parts, $map?->split?->parts);
        foreach ($map?->split?->segments() ?? [] as $segment) {
            static::assertGreaterThanOrEqual(0.0, $segment['width']);
            static::assertLessThanOrEqual(Cell::SPLIT_WIDTH, $segment['x'] + $segment['width']);
        }
    }

    public function testThePendingAverageSaysWhatItWillShow(): void
    {
        $map = $this->view($this->reader([]))->map;

        static::assertEquals(new MapBox('An average request', null, 'This bar will split the time between your PHP code, SQL and outgoing HTTP.'), $map?->average);
        static::assertNull($map?->split);
    }

    public function testTheRouteTableKeepsTheFiveMostExpensive(): void
    {
        $rows = [
            self::route('GET app_a', 10, 100.0),
            self::route('GET app_b', 1, 900.0),
            self::route('GET app_c', 50, 500.0, sqlRequests: 50, sqlTime: 100.0, sqlCount: 150.0),
            self::route('GET app_d', 2, 20.0),
            self::route('GET app_e', 3, 300.0),
            self::route('GET app_f', 4, 400.0),
            self::route('GET app_g', 1, 5.0),
        ];
        $other = self::route('(other)', 30, 3000.0);
        $reader = $this->reader($rows, other: $other);

        $view = $this->view($reader);

        static::assertCount(1, $view->tables);
        $table = $view->tables[0];
        static::assertSame('Where the time goes', $table->title);
        static::assertSame('the 5 routes that cost the most in total', $table->note);
        static::assertSame([['route', 'Route', false, null], ['requests', 'Requests', true, null], ['total', 'Total time', true, null], ['split', 'Where the average goes', false, null]], array_map(static fn($column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns));
        static::assertTrue($table->partsLegend);
        static::assertSame(2, $table->remaining);
        static::assertSame('route', $table->noun);
        static::assertSame(['GET app_b', 'GET app_c', 'GET app_f', 'GET app_e', 'GET app_a'], array_map(static fn($row): string => $row->attributes['data-jm-route'], $table->rows));
        static::assertSame(['100', '56', '44', '33', '11'], array_map(static fn($row): string => $row->cells['total']->data['share'], $table->rows));

        $requests = (new RequestsScreen(self::checks(), 1000))->build($reader, Period::OneHour, self::NOW, 'total', 'desc')->tables[0];
        $byRoute = [];
        foreach ($requests->rows as $row) {
            $byRoute[$row->attributes['data-jm-route']] = $row;
        }
        foreach ($table->rows as $row) {
            $same = $byRoute[$row->attributes['data-jm-route']];
            static::assertEquals($same->cells['route'], $row->cells['route']);
            static::assertEquals($same->cells['requests'], $row->cells['requests']);
            static::assertEquals($same->cells['split'], $row->cells['split']);
            static::assertSame($same->attributes, $row->attributes);
            static::assertSame($same->key, $row->key);
        }
        static::assertEquals(new Cell('900 ms', kind: 'bar', data: ['share' => '100']), $table->rows[0]->cells['total']);
    }

    public function testAFewRoutesAreAllShown(): void
    {
        $table = $this->view($this->reader([self::route('GET app_a', 1, 20.0), self::route('GET app_b', 1, 40.0)]))->tables[0] ?? null;

        static::assertNotNull($table);
        static::assertSame(['GET app_b', 'GET app_a'], array_map(static fn($row): string => $row->attributes['data-jm-route'], $table->rows));
        static::assertSame(0, $table->remaining);
        static::assertSame('the most expensive first', $table->note);
    }

    public function testNoRouteTableWithoutRequest(): void
    {
        static::assertSame([], $this->view($this->reader([]))->tables);
    }

    public function testTheGuideOnlyTellsTheTwoClocksWithAStack(): void
    {
        $terms = static fn(ScreenView $view): array => array_map(static fn(GuideItem $item): string => $item->term, $view->guide->items ?? []);

        $with = $this->view($this->reader([self::route('GET app_a')]), stack: new StackGlance());
        $without = $this->view($this->reader([self::route('GET app_a')]), withStack: false);

        static::assertSame(['Read it left to right.', 'Total time beats speed.', 'Two clocks.'], $terms($with));
        static::assertSame(['Read it left to right.', 'Total time beats speed.'], $terms($without));
        static::assertEquals([
            new GuideItem('Read it left to right.', 'What starts your code, what your code does with the time, then what it waits for. A box with a tag needs a look.'),
            new GuideItem('Total time beats speed.', 'A fast route called often can cost more than a slow one called once. That is where an improvement pays.', ['Requests' => 'requests']),
            new GuideItem('Two clocks.', 'Traffic figures cover the period chosen at the top. The servers are read as they are right now.'),
        ], $with->guide?->items);
        static::assertNull($without->map?->runsOn);
    }

    public function testTheReadHoldsWhatTheViewNeeds(): void
    {
        $report = self::requestReport([self::route('GET app_a', 2, errors: 1)]);
        $reader = new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::Request ? $report : new Report(), totals: [self::SERVER_ERRORS => self::counted(1)]);

        $read = $this->screen(declared: 2)->read($reader, Period::OneHour, self::NOW);

        static::assertSame($report, $read->requests);
        static::assertTrue($read->raised->has('requests.server_errors'));
        static::assertSame(['requests.server_errors'], array_map(static fn($finding): string => $finding->id, $read->findings));
        static::assertSame(2, $read->declaredTasks);
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

    public function testWithoutReadOnlyTheStackIsShown(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')], [self::stackLine('php.memory', 'warn')]);

        $view = $this->screen()->view(null, $stack, Period::OneHour);

        static::assertNull($view->lead);
        static::assertSame(['php.memory'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines ?? []));
        static::assertEquals(new Map(runsOn: $stack), $view->map);
        static::assertSame([], $view->tables);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
    }

    public function testWithoutReadNorStackLineThereIsNoCheckBlock(): void
    {
        $view = $this->screen()->view(null, new StackGlance([self::card('php', 'ok')]), Period::OneHour);

        static::assertNull($view->checks);
        static::assertNull($this->screen()->view(null, null, Period::OneHour)->map);
    }

    public function testNothingInThePeriodShowsEveryBoxPendingAndThePlaces(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')]);

        $view = $this->view($this->reader([]), stack: $stack, screen: $this->screen(declared: 2));

        static::assertEquals(new Lead('JMonitor is installed and recording.', 'Nothing was recorded in the last hour. The figures fill in as your application receives requests. Your servers are already read.'), $view->lead);
        static::assertSame(['Requests', 'Messages', 'Scheduled tasks', 'Commands', 'An average request', 'Exceptions', 'Signed-in users', 'SQL queries', 'Cache', 'Other sites and APIs'], self::labels($view));
        static::assertSame([], array_values(array_filter(self::boxesOf($view), static fn(MapBox $box): bool => $box->value !== null || $box->tags !== [] || $box->tone !== null)));
        static::assertSame('2 tasks declared, none ran in the last hour.', self::box($view, 'Scheduled tasks')?->text);
        static::assertNull($view->map?->split);
        static::assertSame($stack, $view->map?->runsOn);
        static::assertSame([], $view->tables);
        static::assertNull($view->guide);
        static::assertEquals(self::places(), $view->places);
        static::assertSame([], self::lines($view));
        static::assertSame('1 of 1 stack component healthy in the last hour.', $view->checks?->emptyText);
        static::assertNotNull($view->raised);
    }

    public function testTheEmptyDetailOnlyNamesTheServersWhenTheyAreRead(): void
    {
        $detail = fn(?StackGlance $stack, bool $withStack = true): ?string => $this->view($this->reader([]), Period::SixHours, $stack, $withStack)->lead?->detail;
        $nothing = 'Nothing was recorded in the last 6 hours. The figures fill in as your application receives requests.';

        static::assertSame($nothing . ' Your servers are already read.', $detail(new StackGlance([self::card('php', 'warn')])));
        static::assertSame($nothing, $detail(new StackGlance()));
        static::assertSame($nothing, $detail(new StackGlance(lines: [self::stackLine('php.memory', 'warn')])));
        static::assertSame($nothing, $detail(new StackGlance(notice: new Notice('bad', 'The live stack could not be read'))));
        static::assertSame($nothing, $detail(null, false));
    }

    public function testNothingInThePeriodOnlyShowsWorthALookWithWhatTheStackBrings(): void
    {
        $empty = $this->reader([]);

        static::assertNull($this->view($empty, stack: new StackGlance())->checks);
        static::assertNull($this->view($empty, stack: new StackGlance(notice: new Notice('bad', 'The live stack could not be read')))->checks);
        static::assertNull($this->view($empty, withStack: false)->checks);
        static::assertSame(['php.memory'], array_map(static fn(CheckLine $line): string => $line->id, self::lines($this->view($empty, stack: new StackGlance(lines: [self::stackLine('php.memory', 'warn')])))));
        static::assertSame('Worth a look', $this->view($empty, stack: new StackGlance([self::card('php', 'ok')]))->checks?->title);
    }

    public function testWithoutReadTheMapOnlyKeepsRunsOn(): void
    {
        $stack = new StackGlance([self::card('php', 'ok')], [self::stackLine('php.memory', 'warn')]);

        $view = $this->screen()->view(null, $stack, Period::OneHour);

        static::assertEquals(new Map(runsOn: $stack), $view->map);
        static::assertSame([], self::labels($view));
        static::assertNull($view->lead);
        static::assertNull($view->guide);
        static::assertEquals(self::places(), $view->places);

        $view = $this->screen(withStack: false)->view(null, null, Period::OneHour);

        static::assertNull($view->map);
        static::assertNull($view->checks);
        static::assertEquals(self::places(), $view->places);
    }

    public function testAPageWithDataNeitherSaysItIsRecordingNorShowsThePlaces(): void
    {
        $view = $this->view($this->reader([], [Metric::COMMAND_DURATION => self::counted(1)]), stack: new StackGlance([self::card('php', 'ok')]));

        static::assertSame('No request in the last hour. Nothing is worth a look.', $view->lead?->sentence);
        static::assertNull($view->places);
        static::assertNotNull($view->guide);
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
            new Place('Application', 'requests', 'Requests, SQL queries, cache, outgoing HTTP, exceptions, users.', 'What is slow, and why?'),
            new Place('Background', 'background', 'Messenger messages, scheduled tasks and console commands.', 'Did the work behind the scenes run?'),
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
    private function screen(array $off = [], ?StackGlanceInterface $stack = null, bool $withStack = true, int $declared = 0): OverviewScreen
    {
        $checks = self::checks();
        $on = static fn(string $id): bool => !\in_array($id, $off, true);
        $tasks = new DeclaredTasks($declared === 0 ? null : FixedSchedules::locator(['default' => static function () use ($declared): Schedule {
            $schedule = new Schedule();
            for ($i = 0; $i < $declared; ++$i) {
                $schedule->add(RecurringMessage::every(($i + 1) . ' hours', new Ping(), '@' . self::NOW));
            }

            return $schedule;
        }]));

        return new OverviewScreen(
            $checks,
            $tasks,
            [
                new MessagesSection($on('messages'), true, null, $checks),
                new TasksSection($on('tasks'), $tasks, $checks),
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
     * @param list<ReportRow>                           $rows
     * @param array<string, MetricValue>                $totals
     * @param array<string, array<string, MetricValue>> $keyTotals
     */
    private function reader(array $rows, array $totals = [], array $keyTotals = [], ?ReportRow $other = null): FixedReader
    {
        $requests = self::requestReport($rows, $other);

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::Request ? $requests : new Report(), totals: $totals, keyTotals: $keyTotals);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private static function requestReport(array $rows, ?ReportRow $other = null): Report
    {
        $totals = [];
        foreach ([...$rows, ...($other === null ? [] : [$other])] as $row) {
            foreach ($row->metrics as $type => $value) {
                $total = $totals[$type] ?? null;
                $totals[$type] = $total === null ? $value : new MetricValue($total->count + $value->count, $total->sum + $value->sum, max($total->max, $value->max));
            }
        }

        return new Report($rows, $other, \count($rows), array_fill(0, 60, 0), $totals);
    }

    private static function route(
        string $key,
        int $requests = 1,
        float $totalMs = 10.0,
        float $max = 10.0,
        int $errors = 0,
        int $slow = 0,
        int $sqlRequests = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpRequests = 0,
        float $httpTime = 0.0,
    ): ReportRow {
        $metrics = [Metric::REQUEST_DURATION => new MetricValue($requests, $totalMs, $max)];
        if ($errors > 0) {
            $metrics[self::SERVER_ERRORS] = self::counted($errors);
        }
        if ($slow > 0) {
            $metrics[Metric::REQUEST_SLOW] = self::counted($slow);
        }
        if ($sqlRequests > 0) {
            $metrics[Metric::REQUEST_SQL_TIME] = new MetricValue($sqlRequests, $sqlTime, $sqlTime);
            $metrics[Metric::REQUEST_SQL_COUNT] = new MetricValue($sqlRequests, $sqlCount, $sqlCount);
        }
        if ($httpRequests > 0) {
            $metrics[Metric::REQUEST_HTTP_TIME] = new MetricValue($httpRequests, $httpTime, $httpTime);
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
    private static function stackLine(string $id, string $tone): CheckLine
    {
        return new CheckLine($id, new Mark($tone, ucfirst($tone)), 'A point of the stack.', 'Why it matters.', 'Stack · PHP', 'stack/php');
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

    /** @return list<CheckLine> */
    private static function lines(ScreenView $view): array
    {
        return $view->checks->lines ?? [];
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

        return $map === null ? [] : [...$map->incoming, ...($map->average === null ? [] : [$map->average]), ...$map->inside, ...$map->outgoing];
    }
}
