<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\Dbal\Middleware;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\ConnectionLogger;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class SqlRecordingTest extends WebTestCase
{
    use PollsThePage;

    private const N_PLUS_ONE_SQL = 'SELECT * FROM demo_items WHERE id = ?';

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-sql-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testAnNPlusOneEndsUpInStorage(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $this->flush();

        $queries = $this->read(Family::Query, Metric::QUERY_DURATION);
        $query = self::rowLabelled($queries, self::N_PLUS_ONE_SQL);
        static::assertSame(12, $query->count(Metric::QUERY_DURATION));

        $id = SqlNormalizer::id(self::N_PLUS_ONE_SQL);
        $nPlusOne = $this->read(Family::NPlusOne, Metric::NPLUS1_DURATION)->rows;
        static::assertCount(1, $nPlusOne);
        static::assertSame('request' . Entry::KEY_SEPARATOR . 'GET app_n_plus_one' . Entry::KEY_SEPARATOR . $id, $nPlusOne[0]->key);
        static::assertSame(12.0, $nPlusOne[0]->max(Metric::NPLUS1_CALLS));

        $route = self::rowKeyed($this->read(Family::Request), 'GET app_n_plus_one');
        static::assertGreaterThanOrEqual(12.0, $route->sum(Metric::REQUEST_SQL_COUNT));
    }

    public function testARepeatedQueryIsWorthALookOnQueriesAndOnItsRoute(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(['sql' => ['slow_threshold' => 60000]])]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $queries = self::requestUntil($client, '/jmonitor/queries', 'tr[data-jm-nplus1="GET app_n_plus_one"][data-jm-repeats-max="12"]');

        static::assertSame(
            ['jm-lead', 'jm-checks', 'jm-cards', 'jm-panel', 'jm-panel', 'jm-guide'],
            $queries->filter('.jm-screen > section')->each(static fn(Crawler $block): string => str_contains((string) $block->attr('class'), 'jm-checks') ? 'jm-checks' : explode(' ', (string) $block->attr('class'))[0]),
        );
        static::assertCount(1, $queries->filter('h1'));
        static::assertMatchesRegularExpression('/^\d+ SQL queries in the last hour, \S+ m?s in total\.$/', $queries->filter('h1.jm-lead__title')->text());
        static::assertSame('None was slow. 1 query is repeated many times inside the same request.', $queries->filter('.jm-lead__detail')->text());
        static::assertSame(['Queries', 'SQL time', 'Slow queries', 'Repeated queries'], $queries->filter('.jm-card__label')->each(static fn(Crawler $label): string => $label->text()));
        static::assertSame(['queries.repeated'], $queries->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $check = $queries->filter('[data-jm-check="queries.repeated"]');
        static::assertSame('warn', $check->attr('data-jm-severity'));
        static::assertSame('Repeated query (N+1) · GET app_n_plus_one runs the same query 12 times on average, 12 at worst.', $check->filter('.jm-check__sentence')->text());
        static::assertCount(0, $check->filter('.jm-check__where'));
        static::assertSame(['N+1'], $queries->filter('tr[data-jm-query][data-jm-executions="12"] .jm-tag--warn')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertStringContainsString('demo_items', $queries->filter('tr[data-jm-query][data-jm-executions="12"]')->text());
        static::assertSame(['queries' => '1 warning in the last hour'], self::badges($queries));

        $requests = $client->request('GET', '/jmonitor/requests');
        static::assertResponseIsSuccessful();

        static::assertSame(['N+1'], $requests->filter('tr[data-jm-route="GET app_n_plus_one"] .jm-tag--warn')->each(static fn(Crawler $tag): string => $tag->text()));
        $check = $requests->filter('[data-jm-check="queries.repeated"]');
        static::assertCount(1, $check);
        static::assertSame('Repeated query (N+1) · GET app_n_plus_one runs the same query 12 times on average, 12 at worst.', $check->filter('.jm-check__sentence')->text());
        static::assertSame('Queries', $check->filter('.jm-check__where a')->text());
        static::assertSame('/jmonitor/queries?period=1h', $check->filter('.jm-check__where a')->attr('href'));
        static::assertSame(['queries' => '1 warning in the last hour'], self::badges($requests));
    }

    public function testASlowQueryShowsItsText(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $recorder = static::getContainer()->get(Recorder::class);
        \assert($recorder instanceof Recorder);

        $token = $recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $recorder->addSql('SELECT * FROM demo_orders WHERE customer_id = 42', 250.0, SqlDialect::Sqlite);
        $recorder->addSql('SELECT * FROM demo_orders WHERE customer_id = 43', 20.0, SqlDialect::Sqlite);
        $recorder->end($token, 'GET app_hello', 300.0);
        $this->flush();

        $crawler = $client->request('GET', '/jmonitor/queries');
        static::assertResponseIsSuccessful();

        static::assertSame('1 was slow. None is repeated inside one request.', $crawler->filter('.jm-lead__detail')->text());
        static::assertSame(['queries.slow'], $crawler->filter('[data-jm-check]')->each(static fn(Crawler $line): string => (string) $line->attr('data-jm-check')));
        $check = $crawler->filter('[data-jm-check="queries.slow"]');
        static::assertSame('1 slow query · The one slow most often took 100 ms or more once, 250 ms at worst.', $check->filter('.jm-check__sentence')->text());
        static::assertSame('SELECT * FROM demo_orders WHERE customer_id = ?', $check->filter('.jm-check__code')->text());
        static::assertSame(['1 slow'], $crawler->filter('tr[data-jm-query][data-jm-executions="2"] .jm-tag--warn')->each(static fn(Crawler $tag): string => $tag->text()));
        static::assertSame(['queries' => '1 warning in the last hour'], self::badges($crawler));
    }

    public function testTheQueriesOfJMonitorAreNeverRecorded(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/hello/world');
        $client->request('GET', '/n-plus-one');
        $this->flush();
        $client->request('GET', '/jmonitor/');
        static::assertResponseIsSuccessful();
        $this->flush();
        $client->request('GET', '/jmonitor/');
        $this->flush();

        $labels = array_map('strval', $this->connection()->fetchFirstColumn('SELECT metric_key FROM ' . KeysTable::NAME));
        static::assertContains('GET app_hello', $labels);
        static::assertContains(self::N_PLUS_ONE_SQL, $labels);
        foreach ($labels as $label) {
            static::assertStringNotContainsString('jmonitor_', $label);
        }
    }

    public function testAQueryOutsideAnyRequestIsNotRecorded(): void
    {
        static::bootKernel(['jmonitor' => $this->recording(), 'debug' => false]);
        $connection = $this->connection();

        $connection->executeQuery('SELECT 1')->fetchOne();
        static::assertSame([], $this->slotFiles());
        $this->flush();
        static::assertSame([], $this->read(Family::Query, Metric::QUERY_DURATION)->rows);

        $before = memory_get_usage();
        for ($i = 0; $i < 10_000; ++$i) {
            $connection->executeQuery('SELECT 1')->fetchOne();
        }

        static::assertLessThanOrEqual(1024 * 1024, memory_get_usage() - $before);
        static::assertSame([], $this->slotFiles());
    }

    public function testAFailingQueryOfTheApplicationIsLeftAlone(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/sql-error');
        static::assertResponseIsSuccessful();
        $class = (string) $client->getResponse()->getContent();
        static::assertTrue(is_a($class, DbalException::class, true), $class);

        $client->request('GET', '/hello/world');
        static::assertResponseIsSuccessful();
        $this->flush();

        $routes = $this->read(Family::Request);
        static::assertSame(1, self::rowKeyed($routes, 'GET app_hello')->count(Metric::REQUEST_DURATION));
        static::assertSame(1.0, self::rowKeyed($routes, 'GET app_sql_error')->sum(Metric::REQUEST_SQL_COUNT));
    }

    public function testSqlRecordingCanBeSwitchedOff(): void
    {
        static::bootKernel(['jmonitor' => $this->recording()]);
        static::assertTrue($this->hasTheMiddleware());
        self::ensureKernelShutdown();

        $client = static::createClient(['jmonitor' => $this->recording(['sql' => ['enabled' => false]])]);
        static::assertFalse($this->hasTheMiddleware());

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $this->flush();

        $report = $this->read(Family::Query, Metric::QUERY_DURATION);
        static::assertSame([], $report->rows);
        static::assertNull($report->other);
    }

    public function testAnOpenUnitIsForgottenWhenTheServicesAreReset(): void
    {
        static::bootKernel(['jmonitor' => $this->recording()]);
        $recorder = static::getContainer()->get(Recorder::class);
        \assert($recorder instanceof Recorder);
        $resetter = static::getContainer()->get('services_resetter');
        \assert($resetter instanceof ResetInterface);

        $recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        static::assertTrue($recorder->isRecording());
        $resetter->reset();

        static::assertFalse($recorder->isRecording());
    }

    public function testALoggerThatNeedsTheConnectionIsOnlyBuiltOnTheFirstFailure(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'debug' => false, 'connection_logger' => true]);

        $client->request('GET', '/n-plus-one');
        static::assertResponseIsSuccessful();
        $this->flush();
        static::assertSame(12, self::rowLabelled($this->read(Family::Query, Metric::QUERY_DURATION), self::N_PLUS_ONE_SQL)->count(Metric::QUERY_DURATION));

        $recorder = static::getContainer()->get(Recorder::class);
        \assert($recorder instanceof Recorder);
        $token = $recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $recorder->addSql('SELECT 1', \INF, SqlDialect::Sqlite);
        $recorder->end($token, 'GET app_hello', 1.0);

        $logger = static::getContainer()->get('logger');
        static::assertInstanceOf(ConnectionLogger::class, $logger);
        static::assertSame($this->connection(), $logger->connection);
        static::assertCount(1, array_filter($logger->records, static fn(string $record): bool => str_starts_with($record, 'error JMonitor could not record a unit of work')));
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, debug?: bool, connection_logger?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', $options['debug'] ?? true, $options['jmonitor'] ?? [], withConnectionLogger: $options['connection_logger'] ?? false);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function recording(array $options = []): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir, ...$options]];
    }

    private function read(Family $family, ?string $sortMetric = null): Report
    {
        return (new DbalReader($this->connection()))->read(new ReportQuery(Period::OneHour, time(), $family, $sortMetric));
    }

    /** @return array<string, string> tab of the Application space => title of its badge */
    private static function badges(Crawler $page): array
    {
        $badges = [];
        $page->filter('nav.jm-subnav[aria-label="Application"] .jm-subnav__item')->each(static function (Crawler $tab) use (&$badges): void {
            $badge = $tab->filter('.jm-nav__badge');
            if ($badge->count() > 0) {
                $badges[basename((string) parse_url((string) $tab->attr('href'), \PHP_URL_PATH))] = (string) $badge->attr('title');
            }
        });

        return $badges;
    }

    private static function rowLabelled(Report $report, string $label): ReportRow
    {
        foreach ($report->rows as $row) {
            if ($row->label === $label) {
                return $row;
            }
        }

        static::fail(sprintf('No row is labelled "%s".', $label));
    }

    private static function rowKeyed(Report $report, string $key): ReportRow
    {
        foreach ($report->rows as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        static::fail(sprintf('No row has the key "%s".', $key));
    }

    private function hasTheMiddleware(): bool
    {
        foreach ($this->connection()->getConfiguration()->getMiddlewares() as $middleware) {
            if ($middleware instanceof Middleware) {
                return true;
            }
        }

        return false;
    }

    private function flush(): void
    {
        $flusher = static::getContainer()->get(Flusher::class);
        \assert($flusher instanceof Flusher);
        $deadline = microtime(true) + 10;

        do {
            static::assertTrue($flusher->flush());
            static::assertNull($flusher->lastFailure());
            if ($this->slotFiles() === []) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        static::fail('The buffer was not drained within 10 s.');
    }

    /**
     * @return list<string>
     */
    private function slotFiles(): array
    {
        return glob($this->bufferDir . '/*/*.ndjson') ?: [];
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
