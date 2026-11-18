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
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class SqlRecordingTest extends WebTestCase
{
    private const N_PLUS_ONE_SQL = 'SELECT * FROM demo_items WHERE id = ?';

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-sql-recording-' . bin2hex(random_bytes(6));
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

    /**
     * @param array{jmonitor?: array<string, mixed>, debug?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', $options['debug'] ?? true, $options['jmonitor'] ?? []);
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
