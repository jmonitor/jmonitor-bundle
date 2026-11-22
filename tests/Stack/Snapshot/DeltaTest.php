<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Snapshot\Delta;
use PHPUnit\Framework\TestCase;

final class DeltaTest extends TestCase
{
    public function testFlattenUsesDottedKeys(): void
    {
        self::assertSame(
            ['a' => 1, 'b.c' => 2, 'b.d.e' => '3'],
            Delta::flatten(['a' => 1, 'b' => ['c' => 2, 'd' => ['e' => '3']]]),
        );
    }

    public function testValueAndPerSecond(): void
    {
        $delta = new Delta(['Questions' => '1000', 'Uptime' => 50], ['Questions' => '1150', 'Uptime' => 60], 10);

        self::assertSame(150, $delta->getValue('Questions'));
        self::assertSame(15.0, $delta->getPerSec('Questions'));
        self::assertSame(10, $delta->elapsed);
    }

    public function testCounterResetGivesNoValue(): void
    {
        $delta = new Delta(['Questions' => 1000], ['Questions' => 12], 10);

        self::assertNull($delta->getValue('Questions'), 'a counter that went down was reset: no delta');
        self::assertNull($delta->getPerSec('Questions'));
    }

    public function testMissingOrNonNumericValuesGiveNull(): void
    {
        $delta = new Delta(['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y'], 10);

        self::assertNull($delta->getValue('missing'));
        self::assertNull($delta->getValue('b'));
        self::assertSame(1, $delta->getValue('a'));
    }

    public function testRoundTripsThroughArrays(): void
    {
        $delta = new Delta(['a' => 1], ['a' => 3], 20);
        $copy = Delta::fromArray($delta->toArray());

        self::assertSame(2, $copy->getValue('a'));
        self::assertSame(20, $copy->elapsed);
    }
}
