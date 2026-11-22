<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use PHPUnit\Framework\TestCase;

final class DetectionTest extends TestCase
{
    public function testFoundWrapsTheSetInOneImplicitInstance(): void
    {
        $set = new CollectorSet(Component::Php, []);

        $detection = Detection::found($set);

        self::assertTrue($detection->isFound());
        self::assertCount(1, $detection->instances);
        self::assertSame($set, $detection->instances[0]->collectors);
        self::assertSame(Component::Php, $detection->instances[0]->component);
        self::assertNull($detection->instances[0]->id);
        self::assertNull($detection->error);
    }

    public function testInstancesKeepsTheGivenOrder(): void
    {
        $detection = Detection::instances([
            Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'default', 'shop'),
            Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'legacy', 'erp'),
        ]);

        self::assertTrue($detection->isFound());
        self::assertSame(['default', 'legacy'], array_map(static fn(Instance $instance): ?string => $instance->id, $detection->instances));
    }

    public function testUnavailableHasNoInstanceAndAReason(): void
    {
        $detection = Detection::unavailable('No Symfony kernel found.');

        self::assertFalse($detection->isFound());
        self::assertSame([], $detection->instances);
        self::assertSame('No Symfony kernel found.', $detection->error);
    }
}
