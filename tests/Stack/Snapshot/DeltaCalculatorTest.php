<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DeltaCalculatorTest extends TestCase
{
    public function testFirstSampleHasNoDelta(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 5);

        self::assertNull($calculator->track('mysql.status', ['Questions' => 100], 1_000));
    }

    public function testSecondSampleWithinTheWindowGivesADelta(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 5);
        $calculator->track('mysql.status', ['Questions' => 100], 1_000);

        $delta = $calculator->track('mysql.status', ['Questions' => 250], 1_010);

        self::assertNotNull($delta);
        self::assertSame(150, $delta->getValue('Questions'));
        self::assertSame(10, $delta->elapsed);
    }

    public function testNestedDataIsFlattened(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 1);
        $calculator->track('redis', ['stats' => ['keyspace_hits' => 10]], 1_000);

        $delta = $calculator->track('redis', ['stats' => ['keyspace_hits' => 30]], 1_010);

        self::assertSame(20, $delta?->getValue('stats.keyspace_hits'));
    }

    public function testSamplesOfTheSameSecondOrTooOldGiveNoDelta(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 5, maxElapsed: 300);
        $calculator->track('mysql.status', ['Questions' => 100], 1_000);

        self::assertNull($calculator->track('mysql.status', ['Questions' => 120], 1_000), 'same second');
        self::assertNull($calculator->track('mysql.status', ['Questions' => 900], 2_000), 'too old');
    }

    public function testASampleTooCloseIsComparedWithTheReferenceWithoutReplacingIt(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 5);
        $calculator->track('mysql.status', ['Questions' => 100], 1_000);

        $close = $calculator->track('mysql.status', ['Questions' => 120], 1_002);
        self::assertSame(20, $close?->getValue('Questions'));
        self::assertSame(10.0, $close->getPerSec('Questions'));

        $delta = $calculator->track('mysql.status', ['Questions' => 250], 1_010);

        self::assertSame(150, $delta?->getValue('Questions'));
        self::assertSame(10, $delta->elapsed);
    }

    public function testAPreviousSampleOfAnotherShapeIsReplaced(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('jmonitor.stack.previous.php')->set(['timestamp' => 'yesterday', 'values' => 'not an array']));
        $calculator = new DeltaCalculator($cache, minElapsed: 5);

        self::assertNull($calculator->track('php', ['hits' => 1], 1_000));
        self::assertSame(4, $calculator->track('php', ['hits' => 5], 1_010)?->getValue('hits'));
    }

    public function testEachNameHasItsOwnPreviousSample(): void
    {
        $calculator = new DeltaCalculator(new ArrayAdapter(), minElapsed: 1);
        $calculator->track('mysql.status', ['Questions' => 100], 1_000);
        $calculator->track('redis', ['ops' => 5], 1_000);

        self::assertNull($calculator->track('php', ['hits' => 1], 1_010));
        self::assertSame(1, $calculator->track('redis', ['ops' => 6], 1_010)?->getValue('ops'));
    }
}
