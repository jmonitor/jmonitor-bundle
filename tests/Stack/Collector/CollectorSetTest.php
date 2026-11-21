<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use PHPUnit\Framework\TestCase;

final class CollectorSetTest extends TestCase
{
    public function testSpecTakesItsNameFromTheCollector(): void
    {
        $spec = new CollectorSpec(new FakeCollector('symfony.status'), Lifetime::Dynamic, CostClass::Network, trackDelta: true);

        self::assertSame('symfony.status', $spec->name);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertSame(CostClass::Network, $spec->cost);
        self::assertTrue($spec->trackDelta);
    }

    public function testSpecTracksNoDeltaAndIsNotSharedByDefault(): void
    {
        $spec = new CollectorSpec(new FakeCollector('a'), Lifetime::Static, CostClass::Immediate);

        self::assertFalse($spec->trackDelta);
        self::assertFalse($spec->shared);
        self::assertTrue((new CollectorSpec(new FakeCollector('a'), Lifetime::Static, CostClass::Immediate, shared: true))->shared);
    }

    public function testSetExposesNamesAndLookup(): void
    {
        $status = new CollectorSpec(new FakeCollector('symfony.status'), Lifetime::Dynamic, CostClass::Immediate);
        $variables = new CollectorSpec(new FakeCollector('symfony.variables'), Lifetime::Static, CostClass::Immediate);
        $set = new CollectorSet(Component::Symfony, [$status, $variables]);

        self::assertSame(Component::Symfony, $set->component);
        self::assertSame(['symfony.status', 'symfony.variables'], $set->names());
        self::assertSame($variables, $set->get('symfony.variables'));
        self::assertNull($set->get('symfony.slow_queries'));
    }

    public function testALazySetIsBuiltOnFirstUseOnly(): void
    {
        $builds = 0;
        $status = new CollectorSpec(new FakeCollector('symfony.status'), Lifetime::Dynamic, CostClass::Immediate);
        $set = CollectorSet::lazy(Component::Symfony, static function () use (&$builds, $status): CollectorSet {
            ++$builds;

            return new CollectorSet(Component::Symfony, [$status]);
        });

        self::assertSame(0, $builds);
        self::assertSame(['symfony.status'], $set->names());
        self::assertSame($status, $set->get('symfony.status'));
        self::assertSame(1, $builds);
    }

    public function testReleaseReachesTheBuiltSetOnly(): void
    {
        $releases = 0;
        $release = static function () use (&$releases): void {
            ++$releases;
        };
        $set = CollectorSet::lazy(Component::Symfony, static fn(): CollectorSet => new CollectorSet(Component::Symfony, [], $release));

        $set->release();
        self::assertSame(0, $releases, 'nothing was built, so nothing was opened');

        $set->specs();
        $set->release();
        self::assertSame(1, $releases);
    }
}
