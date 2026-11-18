<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

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
    }

    public function testPercent(): void
    {
        static::assertSame('0.4 %', Format::percent(0.004));
        static::assertSame('0 %', Format::percent(0));
    }
}
