<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSettingsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeDatabase;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\DatabasePayloads;
use PHPUnit\Framework\TestCase;

final class PostgresqlSettingsCollectorTest extends TestCase
{
    public function testItKeepsEverySetting(): void
    {
        $database = new FakeDatabase(['FROM pg_settings WHERE' => DatabasePayloads::postgresqlSettingsRows()]);
        $collector = new PostgresqlSettingsCollector(new DbalLink(static fn() => $database));

        self::assertSame(DatabasePayloads::postgresqlSettings(), $collector->collect());
        self::assertSame('postgresql.settings', $collector->getName());
        self::assertSame(1, $collector->getVersion());
        self::assertSame('SELECT 1 FROM pg_settings LIMIT 1', $database->queries[0]);
        self::assertSame(2, $database->calls());
    }

    public function testAnUnreachableServerStaysUnreachable(): void
    {
        $database = (new FakeDatabase())->failNext(new \RuntimeException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused'));
        $link = new DbalLink(static fn() => $database);

        $failure = Failures::thrownBy(static fn() => (new PostgresqlSettingsCollector($link))->collect());

        self::assertInstanceOf(UnreachableServerException::class, $failure);
        self::assertSame($failure, Failures::thrownBy(static fn() => $link->fetchAllAssociative('SELECT 1')));
    }
}
