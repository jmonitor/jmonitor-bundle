<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlVariablesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class MysqlVariablesCollectorTest extends TestCase
{
    public function testItKeepsTheVariablesAndTheClockOfTheServer(): void
    {
        $variables = DatabasePayloads::mysqlVariables();
        unset($variables['timestamp']);
        $database = new FakeDatabase([
            'SHOW GLOBAL VARIABLES WHERE' => DatabasePayloads::rows($variables),
            'UNIX_TIMESTAMP()' => [['ts' => '1760000000']],
        ]);
        $collector = new MysqlVariablesCollector(new DbalLink(static fn() => $database));

        self::assertSame(DatabasePayloads::mysqlVariables(), $collector->collect());
        self::assertSame('mysql.variables', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame("SHOW GLOBAL VARIABLES LIKE 'version'", $database->queries[0]);
    }

    public function testThePathOfTheSlowLogIsNotKept(): void
    {
        $database = new FakeDatabase(['SHOW GLOBAL VARIABLES WHERE' => DatabasePayloads::rows(['slow_query_log' => 'ON', 'slow_query_log_file' => '/var/lib/mysql/db-1-slow.log', 'version' => '8.4.0'])]);

        $variables = (new MysqlVariablesCollector(new DbalLink(static fn() => $database)))->collect();

        self::assertSame(['slow_query_log' => 'ON', 'version' => '8.4.0', 'timestamp' => null], $variables);
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = new FakeDatabase(['SHOW' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new MysqlVariablesCollector($link))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
