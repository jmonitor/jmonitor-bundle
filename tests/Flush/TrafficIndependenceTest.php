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
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TrafficIndependenceTest extends TestCase
{
    private string $dir;
    private string $database;
    private Connection $connection;
    private StatementCounter $counter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-traffic-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->database = $this->dir . '/db.sqlite';
        $this->counter = new StatementCounter();

        $configuration = new Configuration();
        $configuration->setMiddlewares([new CountingMiddleware($this->counter)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->database], $configuration);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testWriteStatementsFollowFlushesNotRequests(): void
    {
        $clock = new MockClock('@1000000');
        $buffer = new FileBuffer($this->dir, 'app', 10, $clock);
        $flusher = new Flusher(
            $buffer,
            new Aggregator(500),
            new DbalStorage($this->connection, 31_536_000),
            new FlushLock($this->dir, 'app', 10, $clock),
            $clock,
            null,
            300,
        );

        $flushes = 0;
        for ($second = 0; $second < 60; ++$second) {
            for ($i = 0; $i < 1000; ++$i) {
                $buffer->append(new Entry(Entry::TYPE_REQUEST, 'GET route_' . ($i % 20), (float) $clock->now()->getTimestamp(), 10.0 + ($i % 50), ['status' => 200]));
            }
            $clock->sleep(1);
            if ($flusher->flushIfDue()) {
                ++$flushes;
            }
        }

        $clock->sleep(10);
        if ($flusher->flush()) {
            ++$flushes;
        }

        static::assertLessThanOrEqual(8, $flushes);
        static::assertLessThanOrEqual($flushes * 12, $this->counter->count);
        static::assertSame(60000, $this->totalRequestsStored());
    }

    private function totalRequestsStored(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT SUM(entry_count) FROM jmonitor_aggregates WHERE metric_type = 'request.duration' AND period = 3600",
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
