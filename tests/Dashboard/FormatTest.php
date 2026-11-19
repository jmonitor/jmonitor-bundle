<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDuration(): void
    {
        static::assertSame('84 ms', Format::duration(84.4));
        static::assertSame('2.8 s', Format::duration(2840));
        static::assertSame('<1 ms', Format::duration(0.4));
    }

    public function testBytes(): void
    {
        static::assertSame('38 MB', Format::bytes(39845888));
        static::assertSame('512 B', Format::bytes(512));
    }

    public function testNumber(): void
    {
        static::assertSame('48,210', Format::number(48210));
        static::assertSame('13.3', Format::number(40 / 3, 1));
        static::assertSame('10.0', Format::number(10, 1));
    }

    public function testPeriod(): void
    {
        static::assertSame('Last hour', Format::period(Period::OneHour));
        static::assertSame('Last 6 hours', Format::period(Period::SixHours));
        static::assertSame('Last 24 hours', Format::period(Period::OneDay));
        static::assertSame('Last 7 days', Format::period(Period::SevenDays));
    }

    public function testPercent(): void
    {
        static::assertSame('0.4 %', Format::percent(0.004));
        static::assertSame('0 %', Format::percent(0));
    }
}
