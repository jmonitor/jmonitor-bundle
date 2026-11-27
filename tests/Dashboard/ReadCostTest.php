<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\ActionGuard;
use Jmonitor\JmonitorBundle\Dashboard\Assets;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\OverviewScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\PageRenderer;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\StatementRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

final class ReadCostTest extends KernelTestCase
{
    private const NOW = 1700003600;

    /** @var array<string, int> screen id => SQL statements read to display it once, the flush aside */
    private const BUDGET = [
        'requests' => 8,
        'queries' => 15,
        'exceptions' => 14,
        'users' => 8,
    ];

    /** SQL statements the Overview reads, whatever the checks raised */
    private const OVERVIEW_BUDGET = 4;

    /** @var array<string, int> section id => SQL statements read to display it once */
    private const SECTION_BUDGET = [
        'messages' => 2,
        'tasks' => 2,
        'commands' => 2,
        'cache' => 2,
        'http' => 4,
    ];

    /** @var array<string, list<string>> composed screen id => the types of its blocks, in order */
    private const COMPOSED_BLOCKS = [
        'cache-http' => [Block::LEAD, Block::CHECKS, Block::SECTION, Block::SECTION, Block::GUIDE],
        'background' => [Block::LEAD, Block::CHECKS, Block::CARDS, Block::SECTION, Block::SECTION, Block::SECTION, Block::GUIDE],
    ];

    private const QUERY_ID = 'a1b2c3d4e5f6a7b8';
    private const REPEATED_QUERY_ID = 'b2c3d4e5f6a7b8c9';
    private const SLOW_QUERY_ID = 'c3d4e5f6a7b8c9d0';
    private const LABELS = [
        self::QUERY_ID => 'SELECT * FROM demo_items',
        self::REPEATED_QUERY_ID => 'SELECT * FROM demo_items WHERE id = ?',
        self::SLOW_QUERY_ID => 'SELECT * FROM demo_orders WHERE customer = ?',
    ];

    /** @var list<string> */
    private static array $varDirs = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove(self::$varDirs);
        self::$varDirs = [];
    }

    public function testEachScreenReadsWithinItsBudget(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $reader = new DbalReader($connection);
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);

        $screens = array_filter($registry->available(), static fn(ScreenInterface $screen): bool => $screen instanceof StorageScreenInterface);
        $ids = array_map(static fn(ScreenInterface $screen): string => $screen->id(), $screens);
        static::assertSame([], array_values(array_diff(array_keys(self::BUDGET), $ids)), 'A screen with a budget is not available in the test kernel.');

        foreach ($screens as $screen) {
            $budget = self::BUDGET[$screen->id()] ?? static::fail(sprintf('The "%s" screen has no read budget.', $screen->id()));

            foreach ($screen->sorts() as $sort => $direction) {
                $recorder->statements = [];
                $view = $screen->build($reader, Period::OneHour, self::NOW, $sort, $direction);

                static::assertFalse($view->isEmpty(), sprintf('The seeded database shows nothing on the "%s" screen.', $screen->id()));
                static::assertLessThanOrEqual($budget, \count($recorder->statements), sprintf(
                    "The \"%s\" screen sorted by %s reads:\n%s",
                    $screen->id(),
                    $sort,
                    implode("\n", array_column($recorder->statements, 'sql')),
                ));
            }
        }
    }

    public function testTheBudgetOfRequestsIsMeasuredWithARouteThatRepeatsAQuery(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $screen = $registry->get('requests');
        \assert($screen instanceof RequestsScreen);
        $recorder->statements = [];

        $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, 'total', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame(['queries.repeated'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
        static::assertCount(self::BUDGET['requests'], $recorder->statements);
    }

    public function testTheBudgetOfQueriesIsMeasuredWithASlowAndARepeatedQuery(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $screen = $registry->get('queries');
        \assert($screen instanceof QueriesScreen);
        $reads = [];

        foreach (['total', 'count', 'avg', 'max', 'problems'] as $sort) {
            foreach (['desc', 'asc'] as $direction) {
                $recorder->statements = [];
                $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, $sort, $direction);

                static::assertNotNull($view->checks);
                static::assertSame(['queries.slow', 'queries.repeated'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
                $reads[$sort . ' ' . $direction] = \count($recorder->statements);
            }
        }

        static::assertSame(
            ['total desc' => 15, 'total asc' => 15, 'count desc' => 15, 'count asc' => 15, 'avg desc' => 15, 'avg asc' => 15, 'max desc' => 15, 'max asc' => 15, 'problems desc' => 11, 'problems asc' => 15],
            $reads,
        );
    }

    public function testEachSectionReadsWithinItsBudget(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $reader = new DbalReader($connection);
        $raised = self::checks()->raised($reader, Period::OneHour, self::NOW);

        $sections = array_merge(...array_map(static fn(ComposedScreen $screen): array => $screen->sections(), self::composedScreens()));
        $ids = array_map(static fn(SectionInterface $section): string => $section->id(), $sections);
        static::assertSame([], array_values(array_diff(array_keys(self::SECTION_BUDGET), $ids)), 'A section with a budget is not available in the test kernel.');

        foreach ($sections as $section) {
            $budget = self::SECTION_BUDGET[$section->id()] ?? static::fail(sprintf('The "%s" section has no read budget.', $section->id()));

            foreach ($section->sorts() as $sort => $direction) {
                $recorder->statements = [];
                $view = $section->build($reader, Period::OneHour, self::NOW, $sort, $direction, $raised);

                static::assertFalse($view->isEmpty(), sprintf('The seeded database shows nothing in the "%s" section.', $section->id()));
                static::assertLessThanOrEqual($budget, \count($recorder->statements), sprintf(
                    "The \"%s\" section sorted by %s reads:\n%s",
                    $section->id(),
                    $sort,
                    implode("\n", array_column($recorder->statements, 'sql')),
                ));
            }
        }
    }

    public function testEachComposedScreenReadsWithinTheSumOfItsSections(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $builder = new ScreenBuilder(new DbalReader($connection), true, new MockClock('@' . self::NOW), checks: self::checks());
        $screens = self::composedScreens();

        static::assertSame(array_keys(self::COMPOSED_BLOCKS), array_map(static fn(ComposedScreen $screen): string => $screen->id(), $screens));

        foreach ($screens as $screen) {
            $sections = $screen->sections();
            $budget = 1 + array_sum(array_map(static fn(SectionInterface $section): int => self::SECTION_BUDGET[$section->id()] ?? 0, $sections));

            foreach ($sections as $section) {
                foreach ($section->sorts() as $sort => $direction) {
                    $recorder->statements = [];
                    $page = $builder->create($screen, Period::OneHour->label(), $section->id() . '.' . $sort, $direction);

                    static::assertSame($section->id() . '.' . $sort, $page->sort);
                    static::assertSame(self::COMPOSED_BLOCKS[$screen->id()], array_map(static fn(Block $block): string => $block->type, $page->blocks), sprintf('The "%s" screen does not show each of its sections with its figures.', $screen->id()));
                    foreach (array_filter($page->blocks, static fn(Block $block): bool => $block->type === Block::SECTION) as $block) {
                        static::assertInstanceOf(Section::class, $block->content);
                        static::assertNull($block->content->empty, sprintf('The seeded database shows nothing in the "%s" section.', $block->content->id));
                    }
                    static::assertLessThanOrEqual($budget, \count($recorder->statements), sprintf(
                        "The \"%s\" screen sorted by %s.%s reads:\n%s",
                        $screen->id(),
                        $section->id(),
                        $sort,
                        implode("\n", array_column($recorder->statements, 'sql')),
                    ));
                }
            }
        }
    }

    public function testTheBudgetOfCacheAndHttpIsMeasuredWithItsThreeChecks(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [...self::units(), new Entry(Entry::TYPE_REQUEST, 'GET app_cold', $startedAt, 10.0, ['status' => 200], [
            new Entry(Entry::TYPE_CACHE, 'app.cold', $startedAt, 0.0, ['n' => 25, 'h' => 2, 'mi' => 23]),
        ])];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $builder = new ScreenBuilder(new DbalReader($connection), true, new MockClock('@' . self::NOW), checks: self::checks());
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $reads = [];

        foreach (['cache.reads', 'http.total', 'http.problems'] as $sort) {
            $recorder->statements = [];
            $page = $builder->create($registry->get('cache-http'), Period::OneHour->label(), $sort, 'desc');

            $checks = $page->blocks[1]->content;
            static::assertInstanceOf(CheckList::class, $checks);
            static::assertSame(['http.failures', 'cache.low_hit_rate', 'http.errors'], array_map(static fn(CheckLine $line): string => $line->id, $checks->lines), $sort);
            $reads[$sort] = \count($recorder->statements);
        }

        static::assertSame(['cache.reads' => 7, 'http.total' => 7, 'http.problems' => 7], $reads);

        $screen = $registry->get('cache-http');
        \assert($screen instanceof ComposedScreen);
        $raised = self::checks()->raised(new DbalReader($connection), Period::OneHour, self::NOW);
        $sections = [];
        foreach ($screen->sections() as $section) {
            $recorder->statements = [];
            $section->build(new DbalReader($connection), Period::OneHour, self::NOW, (string) array_key_first($section->sorts()), 'desc', $raised);
            $sections[$section->id()] = \count($recorder->statements);
        }

        static::assertSame(['cache' => self::SECTION_BUDGET['cache'], 'http' => self::SECTION_BUDGET['http']], $sections);
    }

    public function testTheBudgetOfBackgroundIsMeasuredWithItsThreeChecks(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [
            ...self::units(),
            new Entry(Entry::TYPE_COMMAND, 'app:fail', $startedAt, 10.0, ['exit' => 1, 'memory' => 2048]),
            new Entry(Entry::TYPE_MESSAGE, 'App\\Message\\Resize', $startedAt, 5.0, ['memory' => 2048, 'failed' => 1]),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $builder = new ScreenBuilder(new DbalReader($connection), true, new MockClock('@' . self::NOW), checks: self::checks());
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $screen = $registry->get('background');
        \assert($screen instanceof ComposedScreen);
        $reads = [];

        foreach (['messages.handled', 'tasks.next', 'commands.last'] as $sort) {
            $recorder->statements = [];
            $page = $builder->create($screen, Period::OneHour->label(), $sort, 'desc');

            $checks = $page->blocks[1]->content;
            static::assertInstanceOf(CheckList::class, $checks);
            static::assertSame(['messages.failed', 'tasks.failed', 'commands.failed'], array_map(static fn(CheckLine $line): string => $line->id, $checks->lines), $sort);
            $reads[$sort] = \count($recorder->statements);
        }

        static::assertSame(['messages.handled' => 7, 'tasks.next' => 7, 'commands.last' => 7], $reads);

        $raised = self::checks()->raised(new DbalReader($connection), Period::OneHour, self::NOW);
        $sections = [];
        foreach ($screen->sections() as $section) {
            $recorder->statements = [];
            $section->build(new DbalReader($connection), Period::OneHour, self::NOW, (string) array_key_first($section->sorts()), 'desc', $raised);
            $sections[$section->id()] = \count($recorder->statements);
        }

        static::assertSame(['messages' => 2, 'tasks' => 2, 'commands' => 2], $sections);
    }

    public function testTheBudgetOfExceptionsIsMeasuredWithItsFourChecks(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $thrown = [new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, $startedAt, 0.0, ['n' => 1])];
        $units = [
            ...self::units(),
            new Entry(Entry::TYPE_REQUEST, 'GET app_boom', $startedAt, 10.0, ['status' => 500], $thrown),
            new Entry(Entry::TYPE_COMMAND, 'app:fail', $startedAt, 10.0, ['exit' => 1, 'memory' => 2048], $thrown),
            new Entry(Entry::TYPE_MESSAGE, 'App\\Message\\Resize', $startedAt, 5.0, ['memory' => 2048, 'failed' => 1], $thrown),
            new Entry(Entry::TYPE_TASK, (new TaskKey('default', '28af6db0', 'app:purge', 'every 1 hour'))->toString(), $startedAt, 12.0, ['failed' => 1], $thrown),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $screen = self::screen('exceptions');
        \assert($screen instanceof ExceptionsScreen);
        $reads = [];

        foreach ($screen->sorts() as $sort => $direction) {
            $recorder->statements = [];
            $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, $sort, $direction);

            static::assertNotNull($view->checks);
            static::assertSame(['requests.server_errors', 'messages.failed', 'tasks.failed', 'commands.failed'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines), $sort);
            $reads[$sort] = \count($recorder->statements);
        }

        static::assertSame(['count' => self::BUDGET['exceptions'], 'last' => self::BUDGET['exceptions'], 'class' => self::BUDGET['exceptions']], $reads);
    }

    public function testTheExceptionsScreenWithoutCheckReadsSixStatements(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $screen = self::screen('exceptions');
        $recorder->statements = [];

        $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, 'count', 'desc');

        static::assertFalse($view->isEmpty());
        static::assertNull($view->checks);
        static::assertCount(6, $recorder->statements);
    }

    public function testTheBudgetOfUsersIsMeasuredWithBothChecksOfRequests(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [
            ...self::units(),
            new Entry(Entry::TYPE_REQUEST, 'GET app_boom', $startedAt, 10.0, ['status' => 500], [new Entry(Entry::TYPE_USER, 'admin', $startedAt, 0.0, ['n' => 1])]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_slow', $startedAt, 1500.0, ['status' => 200], [new Entry(Entry::TYPE_USER, 'editor', $startedAt, 0.0, ['n' => 1])]),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $screen = self::screen('users');
        \assert($screen instanceof UsersScreen);
        $reads = [];

        foreach ($screen->sorts() as $sort => $direction) {
            $recorder->statements = [];
            $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, $sort, $direction);

            static::assertNotNull($view->checks);
            static::assertSame(['requests.server_errors', 'requests.slow'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines), $sort);
            $reads[$sort] = \count($recorder->statements);
        }

        static::assertSame(array_fill_keys(array_keys($screen->sorts()), self::BUDGET['users']), $reads);
    }

    public function testTheUsersScreenWithoutProblemReadsSevenStatements(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $screen = self::screen('users');
        $recorder->statements = [];

        $view = $screen->build(new DbalReader($connection), Period::OneHour, self::NOW, 'requests', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertCount(7, $recorder->statements);
    }

    public function testTheRestrictedReadsNameTheCulpritAndTheTotalOfRequests(): void
    {
        [$connection] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [
            ...self::units(),
            new Entry(Entry::TYPE_REQUEST, 'GET app_boom', $startedAt, 10.0, ['status' => 500], [
                new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, $startedAt, 0.0, ['n' => 1]),
                new Entry(Entry::TYPE_USER, 'admin', $startedAt, 0.0, ['n' => 1]),
            ]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_slow', $startedAt, 1500.0, ['status' => 200], [new Entry(Entry::TYPE_USER, 'editor', $startedAt, 0.0, ['n' => 1])]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_anonymous', $startedAt, 10.0, ['status' => 200]),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $reader = new DbalReader($connection);
        $sentences = static fn(?CheckList $checks): array => array_map(static fn(CheckLine $line): string => $line->id . ': ' . $line->sentence, $checks->lines ?? []);

        $exceptions = self::screen('exceptions')->build($reader, Period::OneHour, self::NOW, 'count', 'desc');
        $users = self::screen('users')->build($reader, Period::OneHour, self::NOW, 'requests', 'desc');

        static::assertSame(['requests.server_errors: GET app_boom answered with a 5xx status once.'], $sentences($exceptions->checks));
        static::assertSame(
            ['requests.server_errors: GET app_boom answered with a 5xx status once.', 'requests.slow: GET app_slow took 1.0 s or more once, 1.5 s at worst.'],
            $sentences($users->checks),
        );
        static::assertSame('2 signed-in users made 3 of the 4 requests in the last hour.', $users->lead?->sentence);
        static::assertSame('The other request came from a visitor who was not signed in.', $users->tables[0]->footnote);
    }

    public function testTheOverviewWithoutCheckReadsFourStatements(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [
            new Entry(Entry::TYPE_REQUEST, 'GET app_list', $startedAt, 40.0, ['status' => 200, 'memory' => 2048, 'sql_n' => 1, 'sql_ms' => 1.0, 'http_n' => 1, 'http_ms' => 10.0], [
                new Entry(Entry::TYPE_QUERY, self::QUERY_ID, $startedAt, 1.0, ['n' => 1, 'm' => 1.0]),
                new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, $startedAt, 0.0, ['n' => 1]),
                new Entry(Entry::TYPE_USER, 'admin', $startedAt, 0.0, ['n' => 1]),
                new Entry(Entry::TYPE_HTTP, 'api.example.com', $startedAt, 10.0, ['n' => 1, 'm' => 10.0]),
                new Entry(Entry::TYPE_CACHE, 'cache.app', $startedAt, 0.0, ['n' => 4, 'h' => 3, 'mi' => 1]),
                new Entry(Entry::TYPE_SENT, 'App\\Message\\SendInvoice', $startedAt, 0.0, ['n' => 1]),
            ]),
            new Entry(Entry::TYPE_COMMAND, 'app:work', $startedAt, 120.0, ['exit' => 0, 'memory' => 4096]),
            new Entry(Entry::TYPE_MESSAGE, 'App\\Message\\SendInvoice', $startedAt, 15.0, ['memory' => 4096]),
            new Entry(Entry::TYPE_TASK, (new TaskKey('default', '28af6db0', 'app:work', 'every 1 second'))->toString(), $startedAt, 12.0),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $recorder->statements = [];

        $read = self::overview()->read(new DbalReader($connection), Period::OneHour, self::NOW);

        static::assertFalse($read->isEmpty());
        static::assertNull(self::overview()->view($read, null, Period::OneHour)->alerts);
        static::assertSame(1, $read->count(Metric::REQUEST_DURATION));
        static::assertCount(self::OVERVIEW_BUDGET, $recorder->statements, implode("\n", array_column($recorder->statements, 'sql')));
    }

    public function testTheBudgetOfTheOverviewIsMeasuredWithItsTenChecks(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        $startedAt = (float) (self::NOW - 30);
        $units = [
            ...self::units(),
            new Entry(Entry::TYPE_REQUEST, 'GET app_boom', $startedAt, 10.0, ['status' => 500]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_slow', $startedAt, 1500.0, ['status' => 200]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_cold', $startedAt, 10.0, ['status' => 200], [
                new Entry(Entry::TYPE_CACHE, 'app.cold', $startedAt, 0.0, ['n' => 25, 'h' => 2, 'mi' => 23]),
            ]),
            new Entry(Entry::TYPE_COMMAND, 'app:fail', $startedAt, 10.0, ['exit' => 1, 'memory' => 2048]),
            new Entry(Entry::TYPE_MESSAGE, 'App\\Message\\Resize', $startedAt, 5.0, ['memory' => 2048, 'failed' => 1]),
        ];
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate($units), self::NOW, self::LABELS);
        $recorder->statements = [];

        $read = self::overview()->read(new DbalReader($connection), Period::OneHour, self::NOW);
        $alerts = self::overview()->view($read, null, Period::OneHour)->alerts;

        static::assertSame(
            ['requests.server_errors', 'messages.failed', 'tasks.failed', 'commands.failed', 'http.failures', 'requests.slow', 'queries.slow', 'queries.repeated', 'cache.low_hit_rate', 'http.errors'],
            array_map(static fn(Alert $alert): string => $alert->id, $alerts->alerts ?? []),
        );
        static::assertSame('1 server error', $alerts?->alerts[0]->title);
        static::assertCount(self::OVERVIEW_BUDGET, $recorder->statements, implode("\n", array_column($recorder->statements, 'sql')));
    }

    public function testAStackScreenReadsNoStatement(): void
    {
        [$connection, $recorder] = StatementRecorder::connect(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new DbalStorage($connection, 604800))->store((new Aggregator(1000))->aggregate(self::units()), self::NOW, self::LABELS);
        $twig = static::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $renderer = new PageRenderer(
            static::getContainer()->get(ScreenRegistry::class),
            new ScreenBuilder(new DbalReader($connection), true, new MockClock('@' . self::NOW), checks: self::checks()),
            null,
            $twig,
            new Assets(),
            new ActionGuard(),
            null,
        );
        $urls = new FakeHostUrls();

        $requests = $renderer->page(Request::create('/'), 'requests', $urls)->html;

        static::assertNotSame([], $recorder->statements);
        static::assertStringContainsString('data-jm-slot="nav-application"><span class="jm-nav__badge', $requests);

        $displays = [
            'stack' => static fn(): string => $renderer->page(Request::create('/'), 'stack', $urls)->html,
            'the fragment of stack' => static fn(): string => (string) $renderer->fragment(Request::create('/'), 'stack', $urls)->getContent(),
            'stack/php' => static fn(): string => $renderer->page(Request::create('/'), 'stack/php', $urls)->html,
        ];
        foreach ($displays as $display => $render) {
            $recorder->statements = [];

            $html = $render();

            static::assertStringContainsString('data-jm-component="php"', $html, $display);
            static::assertSame([], array_column($recorder->statements, 'sql'), $display);
        }
    }

    private static function overview(): OverviewScreen
    {
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $screen = $registry->get('overview');
        \assert($screen instanceof OverviewScreen);

        return $screen;
    }

    private static function screen(string $id): StorageScreenInterface
    {
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);
        $screen = $registry->get($id);
        \assert($screen instanceof StorageScreenInterface);

        return $screen;
    }

    private static function checks(): ApplicationChecks
    {
        $checks = static::getContainer()->get(ApplicationChecks::class);
        \assert($checks instanceof ApplicationChecks);

        return $checks;
    }

    /** @return list<ComposedScreen> */
    private static function composedScreens(): array
    {
        $registry = static::getContainer()->get(ScreenRegistry::class);
        \assert($registry instanceof ScreenRegistry);

        return array_values(array_filter($registry->available(), static fn(ScreenInterface $screen): bool => $screen instanceof ComposedScreen));
    }

    /** @return list<Entry> units whose children feed every family a screen reads */
    private static function units(): array
    {
        $startedAt = (float) (self::NOW - 30);

        return [
            new Entry(Entry::TYPE_REQUEST, 'GET app_list', $startedAt, 40.0, ['status' => 200, 'memory' => 2048, 'sql_n' => 13, 'sql_ms' => 3.0, 'http_n' => 2, 'http_ms' => 30.0], [
                new Entry(Entry::TYPE_QUERY, self::QUERY_ID, $startedAt, 1.0, ['n' => 1, 'm' => 1.0]),
                new Entry(Entry::TYPE_QUERY, self::REPEATED_QUERY_ID, $startedAt, 2.0, ['n' => 12, 'm' => 0.5]),
                new Entry(Entry::TYPE_QUERY, self::SLOW_QUERY_ID, $startedAt, 150.0, ['n' => 1, 'm' => 150.0, 'sl' => 1]),
                new Entry(Entry::TYPE_NPLUS1, self::REPEATED_QUERY_ID, $startedAt, 2.0, ['n' => 12]),
                new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, $startedAt, 0.0, ['n' => 1]),
                new Entry(Entry::TYPE_USER, 'admin', $startedAt, 0.0, ['n' => 1]),
                new Entry(Entry::TYPE_HTTP, 'api.example.com', $startedAt, 30.0, ['n' => 2, 'm' => 20.0, 'c4' => 1, 'c5' => 1, 'cf' => 1]),
                new Entry(Entry::TYPE_CACHE, 'cache.app', $startedAt, 0.0, ['n' => 4, 'h' => 2, 'mi' => 1, 'w' => 1, 'de' => 1]),
                new Entry(Entry::TYPE_SENT, 'App\\Message\\SendInvoice', $startedAt, 0.0, ['n' => 1]),
            ]),
            new Entry(Entry::TYPE_COMMAND, 'app:work', $startedAt, 120.0, ['exit' => 0, 'memory' => 4096, 'sql_n' => 2, 'sql_ms' => 1.5]),
            new Entry(Entry::TYPE_MESSAGE, 'App\\Message\\SendInvoice', $startedAt, 15.0, ['memory' => 4096, 'sql_n' => 1, 'sql_ms' => 0.5]),
            new Entry(Entry::TYPE_TASK, (new TaskKey('default', '28af6db0', 'app:work', 'every 1 second'))->toString(), $startedAt, 12.0, ['failed' => 1]),
        ];
    }

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, withSecurity: true, withScheduler: true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }
}
