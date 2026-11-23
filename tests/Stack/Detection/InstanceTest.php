<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use PHPUnit\Framework\TestCase;

final class InstanceTest extends TestCase
{
    public function testTheImplicitInstanceHasNoIdAndIsNamedAfterItsComponent(): void
    {
        $instance = Instance::found(Component::Php, new CollectorSet(Component::Php, []));

        self::assertNull($instance->id);
        self::assertNull($instance->detail);
        self::assertSame('php', $instance->key());
        self::assertTrue($instance->isFound());
    }

    public function testANamedInstanceKeepsItsIdAndDetail(): void
    {
        $instance = Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'legacy', 'erp');

        self::assertSame('legacy', $instance->id);
        self::assertSame('erp', $instance->detail);
        self::assertSame('symfony.legacy', $instance->key());
    }

    public function testTheKeyNamesTheComponentAndEncodesTheId(): void
    {
        $implicit = Instance::found(Component::Php, new CollectorSet(Component::Php, []));
        $named = Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'main:replica/1');

        self::assertSame('php', $implicit->key());
        self::assertSame('symfony.main%3Areplica%2F1', $named->key());
        self::assertSame(0, preg_match('#[{}()/\\\\@:]#', $named->key()));
    }

    public function testADotInAnIdIsEncoded(): void
    {
        self::assertSame('symfony.a%2Eb', Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'a.b')->key());
    }

    public function testAnUnavailableInstanceKeepsItsIdentityAndError(): void
    {
        $instance = Instance::unavailable(Component::Symfony, 'Unknown kernel "dwh".', 'dwh');

        self::assertFalse($instance->isFound());
        self::assertNull($instance->collectors);
        self::assertSame('Unknown kernel "dwh".', $instance->error);
        self::assertSame('symfony.dwh', $instance->key());
    }
}
