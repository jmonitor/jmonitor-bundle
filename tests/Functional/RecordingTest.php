<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class RecordingTest extends WebTestCase
{
    use PollsThePage;

    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-recording-' . bin2hex(random_bytes(6));
        mkdir($this->bufferDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove([$this->bufferDir, ...self::$varDirs]);
        self::$varDirs = [];
    }

    public function testRequestsEndUpInStorage(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        for ($i = 0; $i < 3; ++$i) {
            $client->request('GET', '/hello/world');
            static::assertResponseIsSuccessful();
        }
        $client->request('GET', '/teapot');
        static::assertResponseStatusCodeSame(418);
        $client->request('GET', '/boom');
        static::assertResponseStatusCodeSame(500);
        $client->request('GET', '/nope');
        static::assertResponseStatusCodeSame(404);
        $client->request('POST', '/hello/world', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'ZZZ']);
        static::assertResponseIsSuccessful();

        $this->flush();
        $routes = $this->routes(new DbalReader($this->connection()));

        static::assertSame(3, $routes['GET app_hello']->count(Metric::REQUEST_DURATION));
        static::assertSame(1, $routes['GET app_boom']->count(Metric::REQUEST_STATUS_PREFIX . '5xx'));
        static::assertSame(1, $routes['GET app_teapot']->count(Metric::REQUEST_DURATION));
        static::assertSame(1, $routes['GET (unmatched)']->count(Metric::REQUEST_DURATION));
        static::assertSame(1, $routes['OTHER app_hello']->count(Metric::REQUEST_DURATION));
        static::assertArrayNotHasKey('ZZZ app_hello', $routes);
    }

    public function testAProbeThatFallsOnTheApplicationLeavesNoLineInRequests(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $probe = ['HTTP_X_JMONITOR_PROBE' => '1', 'REMOTE_ADDR' => '127.0.0.1'];

        $client->request('GET', '/nginx_status', server: $probe);
        static::assertResponseStatusCodeSame(404);
        $client->request('GET', '/hello/world');
        $this->flush();

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET app_hello"]');
        static::assertCount(1, $requests->filter('tr[data-jm-route="GET app_hello"]'));
        static::assertCount(0, $requests->filter('tr[data-jm-route="GET (unmatched)"]'));

        $client->request('GET', '/nginx_status', server: ['REMOTE_ADDR' => '127.0.0.1']);
        static::assertResponseStatusCodeSame(404);
        $this->flush();

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET (unmatched)"]');
        static::assertCount(1, $requests->filter('tr[data-jm-route="GET (unmatched)"]'));
    }

    public function testAVisitorBehindAProxyCannotHideBehindTheProbeHeader(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);

        $client->request('GET', '/nginx_status', server: ['HTTP_X_JMONITOR_PROBE' => '1', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
        static::assertResponseStatusCodeSame(404);
        $this->flush();

        $requests = self::requestUntil($client, '/jmonitor/requests', 'tr[data-jm-route="GET (unmatched)"]');
        static::assertCount(1, $requests->filter('tr[data-jm-route="GET (unmatched)"]'));
    }

    public function testTheReaderOfTheKernelSeesEveryFlush(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $client->disableReboot();
        $client->request('GET', '/hello/world');
        $reader = static::getContainer()->get(ReaderInterface::class);
        \assert($reader instanceof ReaderInterface);

        static::assertArrayNotHasKey('GET app_hello', $this->routes($reader));
        $this->flush();
        static::assertSame(1, $this->routes($reader)['GET app_hello']->count(Metric::REQUEST_DURATION));

        $client->request('GET', '/hello/world');
        $this->flush();

        static::assertSame(2, $this->routes($reader)['GET app_hello']->count(Metric::REQUEST_DURATION));
    }

    public function testNothingIsRecordedWhenDisabled(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false, 'buffer_dir' => $this->bufferDir]]]);

        $client->request('GET', '/hello/world');

        static::assertResponseIsSuccessful();
        static::assertFalse(static::getContainer()->has(RequestRecorder::class));
        static::assertSame([], glob($this->bufferDir . '/*') ?: []);
    }

    public function testRecordingSwitchIsReadFromTheEnvironmentAtRuntime(): void
    {
        $config = ['recording' => ['enabled' => '%env(bool:JMONITOR_TEST_RECORDING)%', 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];

        try {
            $this->setEnv('JMONITOR_TEST_RECORDING', '0');
            $client = static::createClient(['jmonitor' => $config]);
            $client->request('GET', '/hello/world');
            $crawler = $client->request('GET', '/jmonitor/requests');

            static::assertTrue(static::getContainer()->has(RequestRecorder::class));
            static::assertSame([], $this->slotFiles());
            static::assertCount(1, $crawler->filter('[data-jm-state="recording_disabled"]'));

            self::ensureKernelShutdown();
            $this->setEnv('JMONITOR_TEST_RECORDING', '1');
            $client = static::createClient(['jmonitor' => $config]);
            $client->request('GET', '/hello/world');
            $crawler = self::requestUntil($client, '/jmonitor/', 'tr[data-jm-route="GET app_hello"]');

            static::assertSame('1', $crawler->filter('tr[data-jm-route="GET app_hello"]')->attr('data-jm-requests'));
        } finally {
            $this->setEnv('JMONITOR_TEST_RECORDING', null);
        }
    }

    public function testDashboardSwitchIsReadFromTheEnvironmentAtRuntime(): void
    {
        $config = ['dashboard' => ['enabled' => '%env(bool:JMONITOR_TEST_DASHBOARD)%'], 'recording' => ['buffer_dir' => $this->bufferDir]];

        try {
            foreach (['0' => 404, '1' => 200] as $value => $status) {
                self::ensureKernelShutdown();
                $this->setEnv('JMONITOR_TEST_DASHBOARD', (string) $value);
                $client = static::createClient(['jmonitor' => $config]);
                $client->request('GET', '/jmonitor/');

                static::assertResponseStatusCodeSame($status);
            }
        } finally {
            $this->setEnv('JMONITOR_TEST_DASHBOARD', null);
        }
    }

    public function testWithoutDoctrineTheApplicationStillWorks(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'doctrine' => false]);

        $client->request('GET', '/hello/world');

        static::assertResponseIsSuccessful();
        static::assertFalse(static::getContainer()->has(RequestRecorder::class));
        static::assertFalse(static::getContainer()->has(Flusher::class));
        static::assertFalse(static::getContainer()->has(StorageInterface::class));
    }

    public function testSchemaDiffIgnoresTheTable(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording()]);
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE app_items (id INTEGER NOT NULL PRIMARY KEY)');

        $client->request('GET', '/hello/world');
        $this->flush();

        static::assertSame(1, (int) $connection->fetchOne('SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE period = 3600 AND metric_type = ?', ['request.duration']));
        $tables = $connection->createSchemaManager()->listTableNames();
        static::assertContains('app_items', $tables);
        static::assertNotContains('jmonitor_aggregates', $tables);
    }

    /**
     * @param array{jmonitor?: array<string, mixed>, doctrine?: bool} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = new TestKernel('test', true, $options['jmonitor'] ?? [], $options['doctrine'] ?? true);
        self::$varDirs[] = $kernel->getVarDir();

        return $kernel;
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return ['recording' => ['enabled' => true, 'flush_interval' => 1, 'buffer_dir' => $this->bufferDir]];
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

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * @return array<string, ReportRow>
     */
    private function routes(ReaderInterface $reader): array
    {
        $indexed = [];
        foreach ($reader->read(new ReportQuery(Period::OneHour, time(), Family::Request))->rows as $row) {
            $indexed[$row->key] = $row;
        }

        return $indexed;
    }
}
