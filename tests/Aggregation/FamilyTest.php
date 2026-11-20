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
        static::assertSame([300, 200, 100, 50, 100, 200, 50, 20], array_map(static fn(Family $f): int => $f->cap(), Family::cases()));
        static::assertSame([false, true, true, false, false, true, true, false], array_map(static fn(Family $f): bool => $f->isOpen(), Family::cases()));
        static::assertSame(
            ['request.duration', 'query.duration', 'nplus1.duration', 'exception.count', 'exception_where.count', 'user.duration', 'http.duration', 'cache.hit'],
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
        static::assertSame(250.0, Family::Http->weight(['http.duration' => [5, 250.0, 120.0], 'http.failed' => [3, 0.0, 0.0]]));
    }

    public function testTheWeightOfACachePoolIsItsNumberOfOperations(): void
    {
        static::assertSame(10.0, Family::Cache->weight([
            'cache.hit' => [4, 0.0, 0.0],
            'cache.miss' => [3, 0.0, 0.0],
            'cache.write' => [2, 0.0, 0.0],
            'cache.delete' => [1, 0.0, 0.0],
        ]));
        static::assertSame(2.0, Family::Cache->weight(['cache.delete' => [2, 0.0, 0.0]]));
        static::assertSame(0.0, Family::Cache->weight([]));
    }

    public function testAUserWithErrorsOutweighsABusierOne(): void
    {
        $busy = ['user.duration' => [30, 3000.0, 200.0]];
        $failing = ['user.duration' => [5, 500.0, 200.0], 'user.error' => [3, 0.0, 0.0]];
        $slow = ['user.duration' => [5, 9000.0, 3000.0], 'user.slow' => [3, 0.0, 0.0]];

        static::assertSame(30.0, Family::User->weight($busy));
        static::assertSame(35.0, Family::User->weight($failing));
        static::assertSame(35.0, Family::User->weight($slow));
        static::assertLessThan(Family::User->weight($failing), Family::User->weight($busy));
        static::assertSame(0.0, Family::User->weight([]));
    }
}
