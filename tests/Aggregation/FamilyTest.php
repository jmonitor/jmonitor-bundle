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
}
