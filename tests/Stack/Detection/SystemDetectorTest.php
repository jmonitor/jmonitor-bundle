<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\ServerCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\SystemDetector;
use PHPUnit\Framework\TestCase;

final class SystemDetectorTest extends TestCase
{
    public function testLinuxIsReadByTheServerCollector(): void
    {
        $detector = new SystemDetector('Linux');
        $detection = $detector->detect();

        self::assertSame(Component::System, $detector->component());
        self::assertTrue($detection->isFound());
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertSame(Component::System, $collectors->component);
        self::assertSame(['system'], $collectors->names());

        $spec = $collectors->get('system');
        self::assertNotNull($spec);
        self::assertInstanceOf(ServerCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertSame(CostClass::Immediate, $spec->cost);
        self::assertFalse($spec->trackDelta);
        self::assertFalse($spec->shared);
    }

    public function testOtherSystemsAreUnavailable(): void
    {
        $detection = (new SystemDetector('Windows'))->detect();

        self::assertFalse($detection->isFound());
        self::assertSame('Server metrics are only read on Linux, this server runs on Windows.', $detection->error);
    }
}
