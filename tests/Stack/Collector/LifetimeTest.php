<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use PHPUnit\Framework\TestCase;

final class LifetimeTest extends TestCase
{
    public function testThereAreFiveLifetimes(): void
    {
        self::assertSame(
            [Lifetime::Dynamic, Lifetime::HalfMinute, Lifetime::Minute, Lifetime::Static, Lifetime::Day],
            Lifetime::cases(),
        );
    }

    public function testSeconds(): void
    {
        self::assertSame(10, Lifetime::Dynamic->seconds());
        self::assertSame(30, Lifetime::HalfMinute->seconds());
        self::assertSame(60, Lifetime::Minute->seconds());
        self::assertSame(3600, Lifetime::Static->seconds());
        self::assertSame(86400, Lifetime::Day->seconds());
    }

    public function testAStaleValueIsShownUpToFiveMinutesOldExceptTheLongLivedOnes(): void
    {
        self::assertSame(300, Lifetime::Dynamic->shownFor());
        self::assertSame(300, Lifetime::HalfMinute->shownFor());
        self::assertSame(300, Lifetime::Minute->shownFor());
        self::assertNull(Lifetime::Static->shownFor());
        self::assertNull(Lifetime::Day->shownFor());
    }

    public function testKeptFor(): void
    {
        self::assertSame(300, Lifetime::Dynamic->keptFor());
        self::assertSame(300, Lifetime::HalfMinute->keptFor());
        self::assertSame(300, Lifetime::Minute->keptFor());
        self::assertSame(604800, Lifetime::Static->keptFor());
        self::assertSame(604800, Lifetime::Day->keptFor());
    }
}
