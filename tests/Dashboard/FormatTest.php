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

    public function testSql(): void
    {
        static::assertSame('45 ms · 12 q', Format::sql(45.0, 12.0));
        static::assertSame('15 ms · 0.8 q', Format::sql(15.0, 0.75));
        static::assertSame('<1 ms · 1.5 q', Format::sql(0.5, 1.5));
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

    public function testAgo(): void
    {
        $now = 1800000000;

        static::assertSame('just now', Format::ago($now, $now));
        static::assertSame('just now', Format::ago($now - 4, $now));
        static::assertSame('just now', Format::ago($now + 30, $now));
        static::assertSame('5 s ago', Format::ago($now - 5, $now));
        static::assertSame('42 s ago', Format::ago($now - 42, $now));
        static::assertSame('1 min ago', Format::ago($now - 60, $now));
        static::assertSame('7 min ago', Format::ago($now - 7 * 60 - 59, $now));
        static::assertSame('3 h ago', Format::ago($now - 3 * 3600 - 3599, $now));
        static::assertSame('2 d ago', Format::ago($now - 2 * 86400 - 5, $now));
    }

    public function testIn(): void
    {
        $now = 1800000000;

        static::assertSame('now', Format::in($now, $now));
        static::assertSame('now', Format::in($now + 4, $now));
        static::assertSame('now', Format::in($now - 30, $now));
        static::assertSame('in 5 s', Format::in($now + 5, $now));
        static::assertSame('in 12 s', Format::in($now + 12, $now));
        static::assertSame('in 1 min', Format::in($now + 60, $now));
        static::assertSame('in 5 min', Format::in($now + 5 * 60 + 59, $now));
        static::assertSame('in 3 h', Format::in($now + 3 * 3600 + 3599, $now));
        static::assertSame('in 2 d', Format::in($now + 2 * 86400 + 5, $now));
    }
}
