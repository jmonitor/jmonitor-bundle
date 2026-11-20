<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use PHPUnit\Framework\TestCase;

class FamilyTest extends TestCase
{
    public function testAMetricBelongsToTheFamilyOfItsPrefix(): void
    {
        static::assertSame(Family::Request, Family::ofMetric('request.status.5xx'));
        static::assertSame(Family::Query, Family::ofMetric('query.slow'));
        static::assertSame(Family::NPlusOne, Family::ofMetric('nplus1.calls'));
        static::assertNull(Family::ofMetric('weird'));
        static::assertNull(Family::ofMetric('weird.thing'));
    }

    public function testCapsAndOpenness(): void
    {
        static::assertSame([300, 200, 100], array_map(static fn(Family $f): int => $f->cap(), Family::cases()));
        static::assertSame([false, true, true], array_map(static fn(Family $f): bool => $f->isOpen(), Family::cases()));
        static::assertSame(
            ['request.duration', 'query.duration', 'nplus1.duration'],
            array_map(static fn(Family $f): string => $f->weightMetric(), Family::cases()),
        );
    }

    public function testWeightIsTheSumForAnOpenFamilyAndTheCountForAClosedOne(): void
    {
        $counters = [
            'request.duration' => [4, 80.0, 30.0],
            'query.duration' => [3, 12.5, 8.0],
            'query.slow' => [1, 0.0, 0.0],
        ];

        static::assertSame(4.0, Family::Request->weight($counters));
        static::assertSame(12.5, Family::Query->weight($counters));
        static::assertSame(0.0, Family::NPlusOne->weight($counters));
    }
}
