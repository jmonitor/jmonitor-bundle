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

    public function testMemoryKeepsTheLargestValueWhenMerging(): void
    {
        foreach ([[1000, 5000, 3000], [5000, 1000, 3000]] as $values) {
            $entries = array_map(fn(int $memory): Entry => $this->request(5.0, ['status' => 200, 'memory' => $memory]), $values);

            $memory = $this->find((new Aggregator(1000))->aggregate($entries), Period::OneHour, Metric::REQUEST_MEMORY, 'GET app_home');

            static::assertNotNull($memory);
            static::assertSame(3, $memory->count);
            static::assertSame(9000.0, $memory->sum);
            static::assertSame(5000.0, $memory->max);
        }
    }

    public function testDistinctBucketsAndKeysStaySeparate(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000039.999, 10.0, ['status' => 200]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000040.0, 20.0, ['status' => 200]),
            new Entry(Entry::TYPE_REQUEST, 'GET app_other', 1700000040.0, 40.0, ['status' => 200]),
        ]);

        $hour = array_values(array_filter($aggregates, static fn(Aggregate $a): bool => $a->period === Period::OneHour && $a->type === Metric::REQUEST_DURATION));
        $seen = array_map(static fn(Aggregate $a): array => [$a->bucket, $a->key, $a->count, $a->sum], $hour);
        usort($seen, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        static::assertSame([
            [1699999980, 'GET app_home', 1, 10.0],
            [1700000040, 'GET app_home', 1, 20.0],
            [1700000040, 'GET app_other', 1, 40.0],
        ], $seen);

        $day = $this->find($aggregates, Period::OneDay, Metric::REQUEST_DURATION, 'GET app_home');
        static::assertNotNull($day);
        static::assertSame(2, $day->count);
    }

    public function testStatusesOutsideTheHttpRangeAreNotCounted(): void
    {
        foreach ([199, 600] as $status) {
            $aggregates = (new Aggregator(1000))->aggregate([$this->request(5.0, ['status' => $status, 'memory' => 1])]);

            static::assertSame(0, $this->countStatusRows($aggregates, Period::OneHour), (string) $status);
        }
    }

    public function testNonFiniteValuesAreDropped(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([
            $this->request(\INF, ['status' => 200, 'memory' => 1]),
            $this->request(\NAN, ['status' => 200, 'memory' => 1]),
            $this->request(5.0, ['status' => 200, 'memory' => '1e999']),
        ]);

        $duration = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET app_home');
        static::assertNotNull($duration);
        static::assertSame(1, $duration->count);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_MEMORY, 'GET app_home'));
    }

    public function testMatchesAStraightforwardAggregation(): void
    {
        $entries = [];
        $keys = ['GET app_home', 'POST app_cart', 'GET (unmatched)', 'OTHER app|pipe'];
        $starts = [1699999199.5, 1699999200.0, 1700000039.999, 1700000040.0, 1700006399.0, 1700006400.0, 1700524800.0, 1700524799.0];
        $durations = [0.0, 0.4, 10.0, 10.000001, 25.0, 99.9, 500.0, 999.999, 1000.0, 2500.5, 10000.0, 10000.1, 65000.0, \INF];
        $statuses = [200, 204, 199, 301, 404, 500, 599, 600, 'abc', null, '503'];
        $memories = [null, 2_097_152, 4_194_304, 'abc', -1, 1_048_576, '1e999'];

        $i = 0;
        foreach ($starts as $start) {
            foreach ($keys as $k => $key) {
                foreach ($durations as $d => $duration) {
                    $attributes = ['status' => $statuses[($i + $d) % \count($statuses)]];
                    $memory = $memories[($i + $k) % \count($memories)];
                    if ($memory !== null) {
                        $attributes['memory'] = $memory;
                    }
                    $entries[] = new Entry(Entry::TYPE_REQUEST, $key, $start + $d / 100, $duration, $attributes);
                    ++$i;
                }
            }
        }
        $entries[] = new Entry('command', 'app:import', 1700000045.0, 50.0, ['status' => 200, 'memory' => 1]);

        $actual = self::canonical((new Aggregator(1000))->aggregate($entries));
        $expected = self::canonical(self::referenceAggregate($entries, 1000));

        static::assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $id => [$count, $sum, $max]) {
            static::assertSame($count, $actual[$id][0], $id);
            static::assertEqualsWithDelta($sum, $actual[$id][1], abs($sum) * 1e-12, $id);
            static::assertSame($max, $actual[$id][2], $id);
        }
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<Aggregate>
     */
    private static function referenceAggregate(array $entries, int $slowThreshold): array
    {
        $merged = [];
        $add = static function (Period $period, int $bucket, string $type, string $key, float $value) use (&$merged): void {
            $id = $period->value . '|' . $bucket . '|' . $type . '|' . $key;
            $merged[$id] ??= new Aggregate($period, $bucket, $type, $key, 0, 0.0, $value);
            ++$merged[$id]->count;
            $merged[$id]->sum += $value;
            $merged[$id]->max = max($merged[$id]->max, $value);
        };

        foreach ($entries as $entry) {
            if ($entry->type !== Entry::TYPE_REQUEST || !is_finite($entry->durationMs)) {
                continue;
            }

            foreach (Period::cases() as $period) {
                $bucket = $period->bucketFor($entry->startedAt);
                $add($period, $bucket, Metric::REQUEST_DURATION, $entry->key, $entry->durationMs);
                $add($period, $bucket, Metric::REQUEST_DURATION_LE_PREFIX . Metric::durationBound($entry->durationMs), $entry->key, 1.0);

                $memory = $entry->attributes['memory'] ?? null;
                if (is_numeric($memory) && $memory >= 0 && is_finite((float) $memory)) {
                    $add($period, $bucket, Metric::REQUEST_MEMORY, $entry->key, (float) $memory);
                }

                $status = $entry->attributes['status'] ?? null;
                if (is_numeric($status) && $status >= 200 && $status <= 599) {
                    $add($period, $bucket, Metric::REQUEST_STATUS_PREFIX . intdiv((int) $status, 100) . 'xx', $entry->key, 1.0);
                }

                if ($entry->durationMs >= $slowThreshold) {
                    $add($period, $bucket, Metric::REQUEST_SLOW, $entry->key, 1.0);
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<Aggregate> $aggregates
     *
     * @return array<string, array{int, float, float}>
     */
    private static function canonical(array $aggregates): array
    {
        $canonical = [];
        foreach ($aggregates as $aggregate) {
            $id = $aggregate->period->value . '|' . $aggregate->bucket . '|' . $aggregate->type . '|' . $aggregate->key;
            static::assertArrayNotHasKey($id, $canonical);
            $canonical[$id] = [$aggregate->count, $aggregate->sum, $aggregate->max];
        }
        ksort($canonical, \SORT_STRING);

        return $canonical;
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
