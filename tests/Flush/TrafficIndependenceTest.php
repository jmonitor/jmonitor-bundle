<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Storage\Dbal\AggregatesTable;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\KeysTable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class TrafficIndependenceTest extends TestCase
{
    private const QUERY_IDS = 2000;
    private const QUERIES_PER_REQUEST = 20;
    private const ROUTES = 20;
    private const MAX_SLOT_BYTES = 16_777_216;
    private const MAX_STATEMENTS_PER_FLUSH = 50;

    private const PLAIN = 'plain';
    private const WEB = 'web';
    private const EXCEPTION_STORM = 'exception-storm';

    private const HEAVY_USERS = 200;
    private const USERS = 5000;
    private const HEAVY_HOSTS = 50;
    private const HOSTS = 300;
    private const HEAVY_POOLS = 20;
    private const LIGHT_POOLS = 40;
    private const EXCEPTION_EVERY = 100;
    private const EXCEPTION_CLASSES = 10;
    private const HTTP_MS = 5.0;

    private string $dir;

    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-traffic-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        (new Filesystem())->remove($this->dir);
    }

    public function testWriteStatementsFollowFlushesNotRequests(): void
    {
        $run = $this->simulate(1000, self::PLAIN);

        static::assertLessThanOrEqual(8, $run['flushes']);
        static::assertLessThanOrEqual($run['flushes'] * 12, $run['statements']);
        static::assertSame(60000, $this->storedCount($run['connection'], Metric::REQUEST_DURATION));
    }

    public function testWebCapturesKeepWritesKeysAndFilesBounded(): void
    {
        $busy = $this->simulate(1000, self::WEB);
        $quiet = $this->simulate(100, self::WEB);

        static::assertLessThanOrEqual(8, $busy['flushes']);
        static::assertSame($quiet['flushes'], $busy['flushes']);
        static::assertSame($quiet['statements'], $busy['statements']);
        static::assertLessThanOrEqual($busy['flushes'] * self::MAX_STATEMENTS_PER_FLUSH, $busy['statements']);

        $connection = $busy['connection'];
        static::assertSame(60000, $this->storedCount($connection, Metric::REQUEST_DURATION));
        static::assertSame(60000 * self::QUERIES_PER_REQUEST * 2, $this->storedCount($connection, Metric::QUERY_DURATION));
        static::assertSame(60000, $this->storedCount($connection, Metric::USER_DURATION));
        static::assertSame(60000, $this->storedCount($connection, Metric::HTTP_DURATION));
        static::assertSame(60000 * 2, $this->storedCount($connection, Metric::CACHE_HIT));
        static::assertSame(60000, $this->storedCount($connection, Metric::CACHE_WRITE));
        static::assertSame(600, $this->storedCount($connection, Metric::EXCEPTION_COUNT));
        static::assertSame(600, $this->storedCount($connection, Metric::EXCEPTION_WHERE_COUNT));

        foreach (['query' => 201, 'user' => 201, 'http' => 51, 'cache' => 21, 'exception' => 51, 'exception_where' => 101] as $family => $bound) {
            static::assertLessThanOrEqual($bound, $this->storedKeys($connection, Family::from($family)), $family);
        }

        static::assertLessThanOrEqual(self::MAX_SLOT_BYTES, $busy['largestSlot']);
        static::assertGreaterThan(0, $busy['labelFiles']);
        static::assertLessThanOrEqual(LabelStore::MAX_FILES, $busy['labelFiles']);
    }

    public function testExceptionKeysStayBoundedWhenEveryRequestFails(): void
    {
        $run = $this->simulate(1000, self::EXCEPTION_STORM);

        static::assertLessThanOrEqual(8, $run['flushes']);
        static::assertSame(60000, $this->storedCount($run['connection'], Metric::EXCEPTION_COUNT));
        static::assertLessThanOrEqual(51, $this->storedKeys($run['connection'], Family::Exception));
        static::assertLessThanOrEqual(101, $this->storedKeys($run['connection'], Family::ExceptionWhere));
    }

    /**
     * Each request draws its keys from cycles, so that the share of each key within a flush does not depend on the
     * number of requests. Users, hosts, pools and the failures of the storm each have a heavy set, the size of the cap
     * of their family, ahead of a long tail.
     *
     * @param self::PLAIN|self::WEB|self::EXCEPTION_STORM $traffic
     *
     * @return array{flushes: int, statements: int, largestSlot: int, labelFiles: int, connection: Connection}
     */
    private function simulate(int $requestsPerSecond, string $traffic): array
    {
        $dir = $this->dir . '/' . $requestsPerSecond . '-' . $traffic;
        mkdir($dir);

        $counter = new StatementCounter();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new CountingMiddleware($counter)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dir . '/db.sqlite'], $configuration);
        $this->connections[] = $connection;

        $clock = new MockClock('@1000000');
        $directory = new BufferDirectory($dir, 'app');
        $buffer = new FileBuffer($directory, 10, $clock, self::MAX_SLOT_BYTES);
        $labels = new LabelStore($directory);
        $hotKeys = new HotKeys($directory, $clock);
        $flusher = new Flusher(
            $buffer,
            new Aggregator(500, $hotKeys),
            new DbalStorage($connection, 31_536_000),
            new FlushLock($directory, 10, $clock),
            $clock,
            null,
            300,
            $hotKeys,
            null,
            new LabelSync($labels, $hotKeys),
        );

        $ids = [];
        if ($traffic === self::WEB) {
            for ($i = 0; $i < self::QUERY_IDS; ++$i) {
                $text = 'SELECT * FROM table_' . $i . ' WHERE id = ?';
                $ids[$i] = SqlNormalizer::id($text);
                $labels->write($ids[$i], $text);
            }
        }

        $flushes = 0;
        $largestSlot = 0;
        $request = 0;
        for ($second = 0; $second < 60; ++$second) {
            $now = (float) $clock->now()->getTimestamp();
            for ($i = 0; $i < $requestsPerSecond; ++$i, ++$request) {
                $buffer->append(match ($traffic) {
                    self::PLAIN => new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % self::ROUTES), $now, 10.0 + ($i % 50), ['status' => 200]),
                    self::WEB => self::webRequest($request, $i, $second, $now, $ids),
                    self::EXCEPTION_STORM => self::failedRequest($request, $now),
                });
            }
            $largestSlot = max($largestSlot, $this->largestSlot($directory));
            $clock->sleep(1);
            if ($flusher->flushIfDue()) {
                ++$flushes;
            }
        }

        $clock->sleep(10);
        if ($flusher->flush()) {
            ++$flushes;
        }

        return [
            'flushes' => $flushes,
            'statements' => $counter->count,
            'largestSlot' => $largestSlot,
            'labelFiles' => \count(glob($directory->path() . '/labels/' . str_repeat('[0-9a-f]', 16)) ?: []),
            'connection' => $connection,
        ];
    }

    /**
     * Every hundredth request fails, with a class that depends on its second only and always the same route.
     *
     * @param array<int, string> $ids
     */
    private static function webRequest(int $request, int $i, int $second, float $now, array $ids): Entry
    {
        $children = [];
        $sqlMs = 0.0;
        for ($j = 0; $j < self::QUERIES_PER_REQUEST; ++$j) {
            $id = (($request * self::QUERIES_PER_REQUEST + $j) * 7) % self::QUERY_IDS;
            $duration = 0.1 + $id / 1000;
            $attributes = ['n' => 2, 'm' => $duration / 2];
            if ($id % 50 === 0) {
                $attributes['sl'] = 1;
            }
            $children[] = new Entry(Entry::TYPE_QUERY, $ids[$id], $now, $duration, $attributes);
            $sqlMs += $duration;
        }

        $half = intdiv($request, 2);
        $heavy = $request % 2 === 0;
        $user = $heavy ? $half % self::HEAVY_USERS : self::HEAVY_USERS + $half % (self::USERS - self::HEAVY_USERS);
        $host = $heavy ? $half % self::HEAVY_HOSTS : self::HEAVY_HOSTS + $half % (self::HOSTS - self::HEAVY_HOSTS);
        $heavyPool = $request % self::HEAVY_POOLS;

        $children[] = new Entry(Entry::TYPE_USER, 'user' . $user . '@example.com', $now, 0.0, ['n' => 1]);
        $children[] = new Entry(Entry::TYPE_CACHE, $heavyPool === 0 ? 'cache.app' : 'app.pool_' . $heavyPool, $now, 0.0, ['n' => 2, 'h' => 2]);
        $children[] = new Entry(Entry::TYPE_CACHE, 'app.pool_' . (self::HEAVY_POOLS + $request % self::LIGHT_POOLS), $now, 0.0, ['n' => 1, 'mi' => 1, 'w' => 1]);
        $children[] = new Entry(Entry::TYPE_HTTP, 'api' . $host . '.example.com', $now, self::HTTP_MS, ['n' => 1, 'm' => self::HTTP_MS]);

        $status = 200;
        if ($request % self::EXCEPTION_EVERY === 0) {
            $status = 500;
            $children[] = new Entry(Entry::TYPE_EXCEPTION, 'App\Exception\Failure' . ($second % self::EXCEPTION_CLASSES), $now, 0.0, ['n' => 1]);
        }

        return new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % self::ROUTES), $now, 10.0 + ($i % 50), [
            'status' => $status,
            'sql_ms' => round($sqlMs, 3),
            'sql_n' => 2 * self::QUERIES_PER_REQUEST,
            'http_ms' => self::HTTP_MS,
            'http_n' => 1,
        ], $children);
    }

    /**
     * Half of the failures share the hundred frequent couples of a class and a route, which cover the fifty frequent
     * classes; the other half spreads over a thousand rare couples.
     */
    private static function failedRequest(int $request, float $now): Entry
    {
        $half = intdiv($request, 2);
        if ($request % 2 === 0) {
            $couple = $half % 100;
            [$class, $route] = ['App\Exception\Frequent' . ($couple % 50), intdiv($couple, 50)];
        } else {
            $couple = $half % 1000;
            [$class, $route] = ['App\Exception\Rare' . ($couple % 100), 2 + intdiv($couple, 100)];
        }

        return new Entry(Entry::TYPE_REQUEST, 'GET route_' . $route, $now, 10.0, ['status' => 500], [
            new Entry(Entry::TYPE_EXCEPTION, $class, $now, 0.0, ['n' => 1]),
        ]);
    }

    private function largestSlot(BufferDirectory $directory): int
    {
        $sizes = array_map(static fn(string $file): int => (int) filesize($file), glob($directory->path() . '/*.ndjson') ?: []);

        return $sizes === [] ? 0 : max($sizes);
    }

    private function storedCount(Connection $connection, string $metric): int
    {
        return (int) $connection->fetchOne(
            'SELECT SUM(entry_count) FROM ' . AggregatesTable::NAME . ' WHERE metric_type = ? AND period = 3600',
            [$metric],
        );
    }

    private function storedKeys(Connection $connection, Family $family): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . KeysTable::NAME . ' k WHERE EXISTS (SELECT 1 FROM ' . AggregatesTable::NAME
            . ' a WHERE a.key_hash = k.key_hash AND a.metric_type LIKE ?)',
            [$family->value . '.%'],
        );
    }
}

final class StatementCounter
{
    public int $count = 0;
}

final class CountingMiddleware implements Middleware
{
    public function __construct(private readonly StatementCounter $counter) {}

    public function wrap(Driver $driver): Driver
    {
        $counter = $this->counter;

        return new class ($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new CountingConnection(parent::connect($params), $this->counter);
            }
        };
    }
}

final class CountingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly StatementCounter $counter)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new CountingStatement(parent::prepare($sql), $this->counter);
    }

    public function query(string $sql): Result
    {
        ++$this->counter->count;

        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        ++$this->counter->count;

        return (int) parent::exec($sql);
    }
}

final class CountingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly StatementCounter $counter)
    {
        parent::__construct($statement);
    }

    public function execute($params = null): Result
    {
        ++$this->counter->count;

        return $params === null ? parent::execute() : parent::execute($params);
    }
}
