<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql\Dbal;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\Dbal\Middleware;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MiddlewareTest extends TestCase
{
    private string $dir;
    /** @var BufferInterface&object{entries: list<Entry>} */
    private BufferInterface $buffer;
    private Recorder $recorder;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-middleware-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];

            public function append(Entry $entry): void
            {
                $this->entries[] = $entry;
            }

            public function drain(callable $consumer): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $this->recorder = new Recorder($this->buffer, null, null, new QueryDigester(new LabelStore(new BufferDirectory($this->dir, 'app'))));
        $this->connection = self::connection($this->recorder);
        $this->connection->executeStatement('CREATE TABLE items (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(20))');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAPreparedStatementIsCountedOnEveryExecution(): void
    {
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $statement = $this->connection->prepare('SELECT * FROM items WHERE id = ?');
        for ($id = 1; $id <= 3; ++$id) {
            $statement->bindValue(1, $id);
            $statement->executeQuery()->fetchAllAssociative();
        }

        static::assertSame(3, $this->end($token)->attributes['sql_n'] ?? null);
    }

    public function testStatementsAndQueriesAreCounted(): void
    {
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $this->connection->executeStatement("INSERT INTO items (id, name) VALUES (1, 'a')");
        $this->connection->executeStatement('INSERT INTO items (id, name) VALUES (?, ?)', [2, 'b']);
        $this->connection->executeQuery('SELECT * FROM items')->fetchAllAssociative();
        $this->connection->executeQuery('SELECT * FROM items WHERE id = ?', [1])->fetchAllAssociative();

        static::assertSame(4, $this->end($token)->attributes['sql_n'] ?? null);
    }

    public function testATransactionEndIsRecordedUnderItsOwnText(): void
    {
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO items (id, name) VALUES (1, 'a')");
        $this->connection->commit();
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        $entry = $this->end($token);

        static::assertSame(3, $entry->attributes['sql_n'] ?? null);
        $keys = array_map(static fn(Entry $child): string => $child->key, $entry->children);
        static::assertContains(self::id('COMMIT'), $keys);
        static::assertContains(self::id('ROLLBACK'), $keys);
        static::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM items'));
    }

    public function testATransactionEndReturnsWhatItReturnsWithoutTheMiddleware(): void
    {
        $expected = self::transactionEnds(self::connection(null));

        $outside = self::transactionEnds($this->connection);
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $inside = self::transactionEnds($this->connection);

        static::assertSame($expected, $outside);
        static::assertSame($expected, $inside);
        static::assertSame(2, $this->end($token)->attributes['sql_n'] ?? null);
    }

    public function testAFailingQueryKeepsItsExceptionAndIsCounted(): void
    {
        $expected = self::failure(self::connection(null));
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));

        $actual = self::failure($this->connection);

        static::assertInstanceOf(DbalException::class, $actual);
        static::assertSame($expected::class, $actual::class);
        static::assertSame(1, $this->end($token)->attributes['sql_n'] ?? null);
    }

    public function testNothingIsRecordedOutsideAUnit(): void
    {
        $this->connection->executeQuery('SELECT 1')->fetchOne();
        $this->connection->executeStatement("INSERT INTO items (id, name) VALUES (1, 'a')");

        static::assertSame([], $this->buffer->entries);

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        static::assertArrayNotHasKey('sql_n', $this->end($token)->attributes);
    }

    public function testNothingIsRecordedWhileTheRecorderIsPaused(): void
    {
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $this->recorder->pause();
        $this->connection->executeQuery('SELECT 1')->fetchOne();
        $this->recorder->resume();

        static::assertArrayNotHasKey('sql_n', $this->end($token)->attributes);
    }

    private function end(int $token): Entry
    {
        $this->recorder->end($token, 'GET app_test', 1.0);
        $entry = array_pop($this->buffer->entries);
        static::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    /**
     * @return array{mixed, mixed} what commit() then rollBack() return: true under DBAL 3, null under DBAL 4
     */
    private static function transactionEnds(Connection $connection): array
    {
        $connection->beginTransaction();
        $committed = $connection->commit();
        $connection->beginTransaction();
        $rolledBack = $connection->rollBack();

        return [$committed, $rolledBack];
    }

    private static function failure(Connection $connection): \Throwable
    {
        try {
            $connection->executeQuery('SELECT * FROM missing_table');
        } catch (\Throwable $e) {
            return $e;
        }

        static::fail('The query on a missing table did not fail.');
    }

    private static function id(string $sql): string
    {
        return SqlNormalizer::id((string) SqlNormalizer::normalize($sql));
    }

    private static function connection(?Recorder $recorder): Connection
    {
        $configuration = new Configuration();
        if ($recorder !== null) {
            $configuration->setMiddlewares([new Middleware($recorder)]);
        }

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
    }
}
