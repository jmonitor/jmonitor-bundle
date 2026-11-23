<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    public function testItKeepsItsKeyAndAddress(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');

        self::assertSame('db.internal:3306', $server->key);
        self::assertSame('db.internal:3306', $server->address);
    }

    public function testTheInstancesThatReachItShareIt(): void
    {
        $server = new Server('db.internal:3306', 'db.internal:3306');
        $main = Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'main', server: $server);
        $down = Instance::unavailable(Component::Symfony, 'Connection refused', 'replica', server: $server);

        self::assertSame($server, $main->server);
        self::assertSame($server, $down->server);
        self::assertNull(Instance::found(Component::Php, new CollectorSet(Component::Php, []))->server);
    }
}
