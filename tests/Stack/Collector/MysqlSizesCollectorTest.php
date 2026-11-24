<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionException;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSizesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class MysqlSizesCollectorTest extends TestCase
{
    public function testItKeepsTheSizesOfTheDatabase(): void
    {
        $database = new FakeDatabase(['SUM(DATA_LENGTH)' => [['data_length' => '52428800', 'index_length' => '10485760']]]);
        $collector = new MysqlSizesCollector(new DbalLink(static fn() => $database), 'shop');

        self::assertSame(DatabasePayloads::mysqlSizes(), $collector->collect());
        self::assertSame('mysql.sizes', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame('SELECT 1 FROM information_schema.TABLES LIMIT 1', $database->queries[0]);
        self::assertSame(2, $database->calls());
    }

    public function testASchemaTheUserCannotSeeGivesNulls(): void
    {
        $database = new FakeDatabase(['SUM(DATA_LENGTH)' => [['data_length' => null, 'index_length' => null]]]);

        self::assertSame(['data_length' => null, 'index_length' => null], (new MysqlSizesCollector(new DbalLink(static fn() => $database), 'shop'))->collect());
    }

    public function testADeniedReadIsAMissingPrerequisiteThatNamesTheTable(): void
    {
        $denied = new ConnectionException(new class ("SELECT command denied to user 'jmonitor'@'10.0.0.7' for table 'TABLES'", '42000', 1142) extends AbstractException {}, null);
        $database = new FakeDatabase(['information_schema' => $denied], connected: true);

        $failure = Failures::thrownBy(static fn() => (new MysqlSizesCollector(new DbalLink(static fn() => $database), 'shop'))->collect());

        self::assertInstanceOf(MissingPrerequisiteException::class, $failure);
        self::assertSame('The database user of this connection may not read information_schema.TABLES.', $failure->getMessage());
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = new FakeDatabase(['information_schema' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new MysqlSizesCollector($link, 'shop'))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
