<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ComposedScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRegistry;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Section;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\StatementRecorder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class ReadCostTest extends KernelTestCase
{
    private const NOW = 1700003600;

    /** @var array<string, int> screen id => SQL statements read to display it once, the flush aside */
    private const BUDGET = [
        'requests' => 8,
        'queries' => 15,
        'exceptions' => 5,
        'users' => 5,
    ];

    /** @var array<string, int> section id => SQL statements read to display it once */
    private const SECTION_BUDGET = [
        'messages' => 3,
        'tasks' => 3,
        'commands' => 3,
        'cache' => 2,
        'http' => 5,
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

        $sections = array_merge(...array_map(static fn(ComposedScreen $screen): array => $screen->sections(), self::composedScreens()));
        $ids = array_map(static fn(SectionInterface $section): string => $section->id(), $sections);
        static::assertSame([], array_values(array_diff(array_keys(self::SECTION_BUDGET), $ids)), 'A section with a budget is not available in the test kernel.');

        foreach ($sections as $section) {
            $budget = self::SECTION_BUDGET[$section->id()] ?? static::fail(sprintf('The "%s" section has no read budget.', $section->id()));

            foreach ($section->sorts() as $sort => $direction) {
                $recorder->statements = [];
                $view = $section->build($reader, Period::OneHour, self::NOW, $sort, $direction, new RaisedChecks([], [], Period::OneHour));

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
        $builder = new ScreenBuilder(new DbalReader($connection), true, new MockClock('@' . self::NOW));
        $screens = self::composedScreens();

        static::assertSame(['cache-http', 'background'], array_map(static fn(ComposedScreen $screen): string => $screen->id(), $screens));

        foreach ($screens as $screen) {
            $sections = $screen->sections();
            $budget = array_sum(array_map(static fn(SectionInterface $section): int => self::SECTION_BUDGET[$section->id()] ?? 0, $sections));

            foreach ($sections as $section) {
                foreach ($section->sorts() as $sort => $direction) {
                    $recorder->statements = [];
                    $page = $builder->create($screen, Period::OneHour->label(), $section->id() . '.' . $sort, $direction);

                    static::assertSame($section->id() . '.' . $sort, $page->sort);
                    static::assertCount(\count($sections), $page->blocks, sprintf('The "%s" screen does not show each of its sections with its figures.', $screen->id()));
                    foreach ($page->blocks as $block) {
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
