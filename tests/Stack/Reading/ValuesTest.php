<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Reading\Values;
use PHPUnit\Framework\TestCase;

final class ValuesTest extends TestCase
{
    public function testScalars(): void
    {
        self::assertSame(12, Values::int('12'));
        self::assertSame(12, Values::int(12.7));
        self::assertNull(Values::int('abc'));
        self::assertNull(Values::int(null));
        self::assertSame(1.5, Values::float('1.5'));
        self::assertSame('8.4.0', Values::string('8.4.0'));
        self::assertSame('12', Values::string(12));
        self::assertNull(Values::string(''));
        self::assertNull(Values::string(['x']));
    }

    public function testBooleans(): void
    {
        self::assertTrue(Values::bool(true));
        self::assertTrue(Values::bool('ON'));
        self::assertTrue(Values::bool('1'));
        self::assertFalse(Values::bool('OFF'));
        self::assertFalse(Values::bool(0));
        self::assertFalse(Values::bool(''));
        self::assertNull(Values::bool(null));
        self::assertNull(Values::bool('maybe'));
    }

    public function testPercent(): void
    {
        self::assertSame(25.0, Values::percent(1, 4));
        self::assertNull(Values::percent(1, 0));
        self::assertNull(Values::percent(null, 4));
    }

    public function testIniBytes(): void
    {
        self::assertSame(134217728, Values::iniBytes('128M'));
        self::assertSame(2048, Values::iniBytes('2K'));
        self::assertSame(1024, Values::iniBytes(1024));
        self::assertFalse(Values::iniBytes('-1'));
        self::assertNull(Values::iniBytes(null));
    }

    public function testValuesOfUnknownShapeGiveNullsWithoutWarning(): void
    {
        set_error_handler(static fn(int $level, string $message): never => throw new \ErrorException($message, 0, $level));

        try {
            self::assertNull(Values::iniBytes('lots'));
            self::assertNull(Values::iniBytes('12Q'));
            self::assertNull(Values::iniBytes('99999999999999999999G'));
            self::assertNull(Values::iniBytes(['128M']));
            self::assertNull(Values::int(INF));
            self::assertNull(Values::int(NAN));
            self::assertNull(Values::int('1e400'));
            self::assertNull(Values::float(INF));
            self::assertNull(Values::float(NAN));
        } finally {
            restore_error_handler();
        }
    }
}
