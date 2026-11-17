<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase
{
    public function testOneRequestFeedsEveryPeriod(): void
    {
        $entry = new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000045.0, 84.0, ['status' => 200, 'memory' => 1000]);

        $aggregates = (new Aggregator(1000))->aggregate([$entry]);

        static::assertCount(16, $aggregates);
        $duration = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET app_home');
        static::assertNotNull($duration);
        static::assertSame(1700000040, $duration->bucket);
        static::assertSame(1, $duration->count);
        static::assertSame(84.0, $duration->sum);
        static::assertSame(84.0, $duration->max);

        $memory = $this->find($aggregates, Period::SevenDays, Metric::REQUEST_MEMORY, 'GET app_home');
        static::assertNotNull($memory);
        static::assertSame(1000.0, $memory->max);
        static::assertNotNull($this->find($aggregates, Period::OneDay, 'request.duration_le.100', 'GET app_home'));
        static::assertNotNull($this->find($aggregates, Period::SixHours, 'request.status.2xx', 'GET app_home'));
    }

    public function testSameKeyAndBucketAreMerged(): void
    {
        $entries = array_map(
            static fn(float $d): Entry => new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000045.0, $d, ['status' => 200, 'memory' => 1]),
            [10.0, 30.0, 2000.0],
        );

        $aggregates = (new Aggregator(1000))->aggregate($entries);

        $duration = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET app_home');
        static::assertNotNull($duration);
        static::assertSame(3, $duration->count);
        static::assertSame(2040.0, $duration->sum);
        static::assertSame(2000.0, $duration->max);

        $slow = $this->find($aggregates, Period::OneHour, Metric::REQUEST_SLOW, 'GET app_home');
        static::assertNotNull($slow);
        static::assertSame(1, $slow->count);

        foreach (['10', '50', '2500'] as $bound) {
            $le = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION_LE_PREFIX . $bound, 'GET app_home');
            static::assertNotNull($le, $bound);
            static::assertSame(1, $le->count);
        }
    }

    public function testStatusFamilies(): void
    {
        $expected = [204 => '2xx', 302 => '3xx', 404 => '4xx', 503 => '5xx'];

        foreach ($expected as $status => $family) {
            $aggregates = (new Aggregator(1000))->aggregate([$this->request(5.0, ['status' => $status, 'memory' => 1])]);
            static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_STATUS_PREFIX . $family, 'GET app_home'), (string) $status);
            static::assertSame(1, $this->countStatusRows($aggregates, Period::OneHour));
        }

        foreach ([0, 'abc', null] as $unusable) {
            $aggregates = (new Aggregator(1000))->aggregate([$this->request(5.0, ['status' => $unusable, 'memory' => 1])]);
            static::assertSame(0, $this->countStatusRows($aggregates, Period::OneHour));
        }

        $aggregates = (new Aggregator(1000))->aggregate([$this->request(5.0, [])]);
        static::assertSame(0, $this->countStatusRows($aggregates, Period::OneHour));
    }

    public function testUnusableMemoryProducesNoMemoryRow(): void
    {
        foreach ([[], ['memory' => 'abc'], ['memory' => -5]] as $attributes) {
            $aggregates = (new Aggregator(1000))->aggregate([$this->request(5.0, $attributes)]);
            static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_MEMORY, 'GET app_home'));
            static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET app_home'));
        }
    }

    public function testSlowThresholdIsInclusive(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([$this->request(1000.0, ['status' => 200, 'memory' => 1])]);

        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_SLOW, 'GET app_home'));

        $aggregates = (new Aggregator(1000))->aggregate([$this->request(999.0, ['status' => 200, 'memory' => 1])]);

        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_SLOW, 'GET app_home'));
    }

    public function testOverflowBound(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([$this->request(12000.0, ['status' => 200, 'memory' => 1])]);

        static::assertNotNull($this->find($aggregates, Period::OneHour, 'request.duration_le.inf', 'GET app_home'));
    }

    public function testNonRequestEntriesAreIgnored(): void
    {
        $entry = new Entry('command', 'app:foo', 1700000045.0, 50.0, ['status' => 200, 'memory' => 1]);

        static::assertSame([], (new Aggregator(1000))->aggregate([$entry]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(float $durationMs, array $attributes): Entry
    {
        return new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000045.0, $durationMs, $attributes);
    }

    /**
     * @param list<Aggregate> $aggregates
     */
    private function find(array $aggregates, Period $period, string $type, string $key): ?Aggregate
    {
        foreach ($aggregates as $aggregate) {
            if ($aggregate->period === $period && $aggregate->type === $type && $aggregate->key === $key) {
                return $aggregate;
            }
        }

        return null;
    }

    /**
     * @param list<Aggregate> $aggregates
     */
    private function countStatusRows(array $aggregates, Period $period): int
    {
        return \count(array_filter(
            $aggregates,
            static fn(Aggregate $a): bool => $a->period === $period && str_starts_with($a->type, Metric::REQUEST_STATUS_PREFIX),
        ));
    }
}
