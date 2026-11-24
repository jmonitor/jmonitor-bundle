<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class MysqlStatusCollectorTest extends TestCase
{
    public function testItKeepsTheWholeStatus(): void
    {
        $database = new FakeDatabase(['SHOW GLOBAL STATUS WHERE' => DatabasePayloads::rows(DatabasePayloads::mysqlStatus())]);
        $collector = new MysqlStatusCollector(new DbalLink(static fn() => $database));

        self::assertSame(DatabasePayloads::mysqlStatus(), $collector->collect());
        self::assertSame('mysql', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame("SHOW GLOBAL STATUS LIKE 'Uptime'", $database->queries[0]);
        self::assertSame(2, $database->calls());
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = new FakeDatabase(['SHOW' => new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')]);
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new MysqlStatusCollector($link))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
