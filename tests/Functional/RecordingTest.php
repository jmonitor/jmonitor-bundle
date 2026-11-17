<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class RecordingTest extends WebTestCase
{
    private string $bufferDir;

    /** @var list<string> */
    private static array $varDirs = [];

    protected function setUp(): void
    {
        $this->bufferDir = sys_get_temp_dir() . '/jmonitor-recording-' . bin2hex(random_bytes(6));
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

        $this->flush();
        $reader = new DbalReader($this->connection());
        $routes = $this->indexByKey($reader->routes(Period::OneHour, time()));

        static::assertSame(3, $routes['GET app_hello']->requests);
        static::assertSame(1, $routes['GET app_boom']->errors);
        static::assertSame(1, $routes['GET app_teapot']->requests);
        static::assertSame(1, $routes['GET (unmatched)']->requests);
    }

    public function testNothingIsRecordedWhenDisabled(): void
    {
        $client = static::createClient(['jmonitor' => ['recording' => ['enabled' => false, 'buffer_dir' => $this->bufferDir]]]);

        $client->request('GET', '/hello/world');

        static::assertResponseIsSuccessful();
        static::assertFalse(static::getContainer()->has(RequestRecorder::class));
        static::assertSame([], glob($this->bufferDir . '/*') ?: []);
    }

    public function testWithoutDoctrineTheApplicationStillWorks(): void
    {
        $client = static::createClient(['jmonitor' => $this->recording(), 'doctrine' => false]);

        $client->request('GET', '/hello/world');

        static::assertResponseIsSuccessful();
        static::assertFalse(static::getContainer()->has(RequestRecorder::class));
        static::assertFalse(static::getContainer()->getParameter('jmonitor.storage.available'));
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

        sleep(1);

        static::assertTrue($flusher->flush());
        static::assertNull($flusher->lastFailure());
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * @param list<RouteStats> $routes
     *
     * @return array<string, RouteStats>
     */
    private function indexByKey(array $routes): array
    {
        $indexed = [];
        foreach ($routes as $route) {
            $indexed[$route->key] = $route;
        }

        return $indexed;
    }
}
