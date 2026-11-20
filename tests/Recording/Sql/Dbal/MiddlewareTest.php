<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql\Dbal;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\Dbal\Middleware;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use Jmonitor\JmonitorBundle\Tests\Storage\Dbal\ConnectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MiddlewareTest extends TestCase
{
    private string $dir;
    /** @var BufferInterface&object{entries: list<Entry>} */
    private BufferInterface $buffer;
    private LabelStore $labels;
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

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $this->labels = new LabelStore(new BufferDirectory($this->dir, 'app'));
        $this->recorder = new Recorder($this->buffer, null, null, new QueryDigester($this->labels));
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
        $keys = self::childKeys($entry);
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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => [['driver' => 'pdo_sqlite', 'memory' => true]];

        foreach (['mysql' => 'JMONITOR_TEST_MYSQL_URL', 'pgsql' => 'JMONITOR_TEST_PGSQL_URL'] as $name => $variable) {
            if (is_string($url = getenv($variable)) && $url !== '') {
                yield $name => [ConnectionProvider::parse($url)];
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('databases')]
    public function testQueryTextsAreKeptOnEverySupportedDatabase(array $params): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->recorder)]);
        $connection = DriverManager::getConnection($params, $configuration);

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne('SELECT 1');
        $entry = $this->end($token);
        $connection->close();

        static::assertSame([SqlNormalizer::id('SELECT ?')], self::childKeys($entry));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function queriesByDatabase(): iterable
    {
        $queries = [
            'sqlite' => ['SELECT [id] FROM items', 'SELECT [id] FROM items'],
            'mysql' => ['SELECT "secret"', 'SELECT ?'],
            'pgsql' => ['SELECT \'{"a":{"b":1}}\'::jsonb #> \'{a}\'', 'SELECT ?::jsonb #> ?'],
        ];

        foreach (self::databases() as $name => [$params]) {
            yield $name => [$params, ...$queries[$name]];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('queriesByDatabase')]
    public function testAQueryIsReadWithTheRulesOfItsDatabase(array $params, string $sql, string $expected): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->recorder)]);
        $connection = DriverManager::getConnection($params, $configuration);
        if ($params['driver'] === 'pdo_sqlite') {
            $connection->executeStatement('CREATE TABLE items (id INTEGER NOT NULL PRIMARY KEY)');
        }

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne($sql);
        $entry = $this->end($token);
        $connection->close();

        static::assertSame([$expected], array_map($this->labels->read(...), self::childKeys($entry)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function charsets(): iterable
    {
        yield 'GBK' => ['GBK', false];
        yield 'sjis' => ['sjis', false];
        yield 'WIN949' => ['WIN949', false];
        yield 'Big-5' => ['Big-5', false];
        yield 'SHIFT_JIS' => ['SHIFT_JIS', false];
        yield 'utf8mb4' => ['utf8mb4', true];
        yield 'utf-8' => ['utf-8', true];
        yield 'latin1' => ['latin1', true];
    }

    #[DataProvider('charsets')]
    public function testACharsetWhoseCharactersMayEndWithABackslashIsCountedWithoutItsText(string $charset, bool $kept): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->recorder)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'charset' => $charset], $configuration);

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne('SELECT 1');
        $entry = $this->end($token);

        static::assertSame([$kept ? SqlNormalizer::id('SELECT ?') : QueryDigester::OTHER], self::childKeys($entry));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function charsetsOfTwoConnections(): iterable
    {
        yield 'gbk first' => [['gbk', 'utf8mb4']];
        yield 'utf8mb4 first' => [['utf8mb4', 'gbk']];
    }

    /**
     * @param list<string> $charsets
     */
    #[DataProvider('charsetsOfTwoConnections')]
    public function testTheCharsetIsReadOnEveryConnectionOfADriver(array $charsets): void
    {
        $driver = $this->driverIgnoringItsOptions();

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        foreach ($charsets as $charset) {
            $driver->connect(['memory' => true, 'charset' => $charset])->query('SELECT 1 AS ' . $charset);
        }
        $entry = $this->end($token);

        static::assertSame([SqlNormalizer::id('SELECT ? AS utf8mb4'), QueryDigester::OTHER], self::childKeys($entry));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function driverOptions(): iterable
    {
        yield 'an init command setting sjis' => [[1002 => 'SET NAMES sjis'], false];
        yield 'an init command setting a quoted Shift_JIS' => [[1002 => "SET NAMES 'Shift_JIS'"], false];
        yield 'an init command setting windows-932' => [[1002 => 'SET client_encoding TO "windows-932"'], false];
        yield 'an init command setting utf8mb4' => [[1002 => 'SET NAMES utf8mb4'], true];
        yield 'an init command setting a mode' => [[1002 => "SET SESSION sql_mode = 'ANSI'"], true];
        yield 'options that are not strings' => [[1002 => 1, 1003 => ['sjis']], true];
        yield 'options that are not an array' => ['SET NAMES sjis', true];
    }

    #[DataProvider('driverOptions')]
    public function testADriverOptionNamingACharsetWhoseCharactersMayEndWithABackslashDropsTheText(mixed $options, bool $kept): void
    {
        $driver = $this->driverIgnoringItsOptions();

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $driver->connect(['memory' => true, 'driverOptions' => $options])->query('SELECT 1');
        $entry = $this->end($token);

        static::assertSame([$kept ? SqlNormalizer::id('SELECT ?') : QueryDigester::OTHER], self::childKeys($entry));
    }

    public function testAConnectionToAnUnsupportedPlatformIsCountedWithoutItsText(): void
    {
        $connection = $this->connectionBehind(static fn(): AbstractPlatform => new OraclePlatform());

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne('SELECT 1');
        $entry = $this->end($token);

        static::assertSame(1, $entry->attributes['sql_n'] ?? null);
        static::assertSame([QueryDigester::OTHER], self::childKeys($entry));
    }

    public function testAPlatformThatCannotBeReadLeavesTheConnectionWorking(): void
    {
        $connection = $this->connectionBehind(static fn(): AbstractPlatform => throw new \RuntimeException('No platform.'));

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $one = $connection->fetchOne('SELECT 1');
        $entry = $this->end($token);

        static::assertSame(1, $one);
        static::assertSame([QueryDigester::OTHER], self::childKeys($entry));
    }

    public function testAPlatformThatCouldNotBeReadIsLookedUpAgainOnTheNextConnection(): void
    {
        $connection = $this->connectionBehind(static fn(): AbstractPlatform => throw new \RuntimeException('No platform.'));

        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne('SELECT 1');
        $first = $this->end($token);
        $connection->close();
        $token = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $connection->fetchOne('SELECT 1');
        $next = $this->end($token);

        static::assertSame([QueryDigester::OTHER], self::childKeys($first));
        static::assertSame([self::id('SELECT 1')], self::childKeys($next));
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

    /**
     * @param \Closure(): AbstractPlatform $firstPlatform
     */
    private function connectionBehind(\Closure $firstPlatform): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new FirstPlatformMiddleware($firstPlatform), new Middleware($this->recorder)]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
    }

    /**
     * @return DriverInterface the driver of the middleware around a SQLite one that drops the driver options PDO SQLite rejects
     */
    private function driverIgnoringItsOptions(): DriverInterface
    {
        return (new Middleware($this->recorder))->wrap(new class (new SqliteDriver()) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                unset($params['driverOptions']);

                return parent::connect($params);
            }
        });
    }

    /**
     * @return list<string>
     */
    private static function childKeys(Entry $entry): array
    {
        return array_map(static fn(Entry $child): string => $child->key, $entry->children);
    }

    private static function id(string $sql): string
    {
        return SqlNormalizer::id((string) SqlNormalizer::normalize($sql, SqlDialect::Sqlite));
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
