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
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
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
        $run = $this->simulate(1000, false);

        static::assertLessThanOrEqual(8, $run['flushes']);
        static::assertLessThanOrEqual($run['flushes'] * 12, $run['statements']);
        static::assertSame(60000, $this->totalRequestsStored($run['connection']));
    }

    public function testSqlQueriesKeepWritesKeysAndFilesBounded(): void
    {
        $busy = $this->simulate(1000, true);
        $quiet = $this->simulate(100, true);

        static::assertLessThanOrEqual(8, $busy['flushes']);
        static::assertSame($quiet['flushes'], $busy['flushes']);
        static::assertSame($quiet['statements'], $busy['statements']);
        static::assertLessThanOrEqual($busy['flushes'] * self::MAX_STATEMENTS_PER_FLUSH, $busy['statements']);
        static::assertSame(60000, $this->totalRequestsStored($busy['connection']));
        static::assertSame(60000 * self::QUERIES_PER_REQUEST * 2, $this->totalQueriesStored($busy['connection']));
        static::assertLessThanOrEqual(201, $this->storedQueryKeys($busy['connection']));
        static::assertLessThanOrEqual(self::MAX_SLOT_BYTES, $busy['largestSlot']);
        static::assertGreaterThan(0, $busy['labelFiles']);
        static::assertLessThanOrEqual(LabelStore::MAX_FILES, $busy['labelFiles']);
    }

    /**
     * Each request draws its queries from a cycle over every query id, so that the share of each id within a
     * flush does not depend on the number of requests.
     *
     * @return array{flushes: int, statements: int, largestSlot: int, labelFiles: int, connection: Connection}
     */
    private function simulate(int $requestsPerSecond, bool $withQueries): array
    {
        $dir = $this->dir . '/' . $requestsPerSecond . ($withQueries ? '-sql' : '');
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
        if ($withQueries) {
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
                $children = [];
                $sqlMs = 0.0;
                foreach ($withQueries ? range(0, self::QUERIES_PER_REQUEST - 1) : [] as $j) {
                    $id = (($request * self::QUERIES_PER_REQUEST + $j) * 7) % self::QUERY_IDS;
                    $duration = 0.1 + $id / 1000;
                    $attributes = ['n' => 2, 'm' => $duration / 2];
                    if ($id % 50 === 0) {
                        $attributes['sl'] = 1;
                    }
                    $children[] = new Entry(Entry::TYPE_QUERY, $ids[$id], $now, $duration, $attributes);
                    $sqlMs += $duration;
                }

                $attributes = ['status' => 200];
                if ($children !== []) {
                    $attributes += ['sql_ms' => round($sqlMs, 3), 'sql_n' => 2 * self::QUERIES_PER_REQUEST];
                }
                $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % self::ROUTES), $now, 10.0 + ($i % 50), $attributes, $children));
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

    private function largestSlot(BufferDirectory $directory): int
    {
        $sizes = array_map(static fn(string $file): int => (int) filesize($file), glob($directory->path() . '/*.ndjson') ?: []);

        return $sizes === [] ? 0 : max($sizes);
    }

    private function totalRequestsStored(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            "SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE metric_type = 'request.duration' AND period = 3600",
        );
    }

    private function totalQueriesStored(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            "SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE metric_type = 'query.duration' AND period = 3600",
        );
    }

    private function storedQueryKeys(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . KeysTable::NAME . ' k WHERE EXISTS (SELECT 1 FROM ' . AggregatesTable::NAME
            . " a WHERE a.key_hash = k.key_hash AND a.metric_type LIKE 'query.%')",
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
