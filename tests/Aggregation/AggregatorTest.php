<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

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

    public function testSqlTotalsOfARequestBecomeItsOwnMetrics(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 80.0, ['sql_ms' => 30.0, 'sql_n' => 4]),
            new Entry(Entry::TYPE_REQUEST, 'GET b', 1700000045.0, 80.0),
        ]);

        $time = $this->find($aggregates, Period::OneHour, Metric::REQUEST_SQL_TIME, 'GET a');
        $count = $this->find($aggregates, Period::OneHour, Metric::REQUEST_SQL_COUNT, 'GET a');
        static::assertNotNull($time);
        static::assertNotNull($count);
        static::assertSame([1, 30.0, 30.0], [$time->count, $time->sum, $time->max]);
        static::assertSame([1, 4.0, 4.0], [$count->count, $count->sum, $count->max]);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_SQL_TIME, 'GET b'));
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_SQL_COUNT, 'GET b'));
    }

    public function testQueryChildrenAreSummedByKey(): void
    {
        $unit = static fn(float $at): Entry => new Entry(Entry::TYPE_REQUEST, 'GET a', $at, 50.0, [], [
            new Entry(Entry::TYPE_QUERY, 'abc', $at, 12.0, ['n' => 3, 'm' => 5.0, 'sl' => 1]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([$unit(1700000045.0), $unit(1700000046.0)]);

        $duration = $this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, 'abc');
        $slow = $this->find($aggregates, Period::OneHour, Metric::QUERY_SLOW, 'abc');
        static::assertNotNull($duration);
        static::assertNotNull($slow);
        static::assertSame([6, 24.0, 5.0], [$duration->count, $duration->sum, $duration->max]);
        static::assertSame([2, 0.0, 0.0], [$slow->count, $slow->sum, $slow->max]);
    }

    public function testTheWeightOfSampledChildrenIsApplied(): void
    {
        $unit = new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, ['cw' => 10], [
            new Entry(Entry::TYPE_QUERY, 'abc', 1700000045.0, 12.0, ['n' => 3, 'm' => 5.0]),
            new Entry(Entry::TYPE_NPLUS1, 'abc', 1700000045.0, 40.0, ['n' => 12]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([$unit]);

        $query = $this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, 'abc');
        $calls = $this->find($aggregates, Period::OneHour, Metric::NPLUS1_CALLS, "request\x1fGET a\x1fabc");
        $duration = $this->find($aggregates, Period::OneHour, Metric::NPLUS1_DURATION, "request\x1fGET a\x1fabc");
        $request = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET a');
        static::assertNotNull($query);
        static::assertNotNull($calls);
        static::assertNotNull($duration);
        static::assertNotNull($request);
        static::assertSame([30, 120.0, 5.0], [$query->count, $query->sum, $query->max]);
        static::assertSame([10, 120.0, 12.0], [$calls->count, $calls->sum, $calls->max]);
        static::assertSame(400.0, $duration->sum);
        static::assertSame(1, $request->count);
    }

    public function testAnNPlusOneIsKeyedByItsEntryPointAndQuery(): void
    {
        $unit = new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000045.0, 50.0, [], [
            new Entry(Entry::TYPE_NPLUS1, 'abc', 1700000045.0, 40.0, ['n' => 12]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([$unit]);

        $keys = array_values(array_unique(array_map(
            static fn(Aggregate $a): string => $a->key,
            array_filter($aggregates, static fn(Aggregate $a): bool => $a->type === Metric::NPLUS1_CALLS),
        )));
        static::assertSame(["request\x1fGET app_home\x1fabc"], $keys);
    }

    public function testAnExceptionIsCountedPerClassAndPerEntryPoint(): void
    {
        $unit = static fn(float $at, string $route): Entry => new Entry(Entry::TYPE_REQUEST, $route, $at, 50.0, ['status' => 500], [
            new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, $at, 0.0, ['n' => 1]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([
            $unit(1700000045.5, 'GET app_boom'),
            $unit(1700000050.25, 'GET app_boom'),
            $unit(1700000047.0, 'POST app_save'),
        ]);

        $count = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_COUNT, \RuntimeException::class);
        $boom = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_WHERE_COUNT, "request\x1fGET app_boom\x1f" . \RuntimeException::class);
        $save = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_WHERE_COUNT, "request\x1fPOST app_save\x1f" . \RuntimeException::class);
        static::assertNotNull($count);
        static::assertNotNull($boom);
        static::assertNotNull($save);
        static::assertSame([3, 0.0, 1700000050.25], [$count->count, $count->sum, $count->max]);
        static::assertSame([2, 0.0, 1700000050.25], [$boom->count, $boom->sum, $boom->max]);
        static::assertSame([1, 0.0, 1700000047.0], [$save->count, $save->sum, $save->max]);
        static::assertSame(Family::Exception, Family::ofMetric($count->type));
        static::assertSame(Family::ExceptionWhere, Family::ofMetric($boom->type));
    }

    public function testAnExceptionReadFromALineIsLastSeenAtTheStartOfItsUnit(): void
    {
        $line = (new Entry(Entry::TYPE_REQUEST, 'GET app_boom', 1700000045.5, 50.0, ['status' => 500], [
            new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, 1700000045.5, 0.0, ['n' => 1]),
        ]))->toLine();
        $entry = Entry::fromLine($line);
        static::assertNotNull($entry);

        $aggregates = (new Aggregator(1000))->aggregate([$entry]);

        $count = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_COUNT, \RuntimeException::class);
        $where = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_WHERE_COUNT, "request\x1fGET app_boom\x1f" . \RuntimeException::class);
        static::assertNotNull($count);
        static::assertNotNull($where);
        static::assertSame([1, 0.0, 1700000045.5], [$count->count, $count->sum, $count->max]);
        static::assertSame([1, 0.0, 1700000045.5], [$where->count, $where->sum, $where->max]);
    }

    public function testAnExceptionWeighsTenWhenSampled(): void
    {
        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET app_boom', 1700000045.0, 50.0, ['status' => 500, 'cw' => 10], [
                new Entry(Entry::TYPE_EXCEPTION, \RuntimeException::class, 1700000045.0, 0.0, ['n' => 1]),
            ]),
        ]);

        $count = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_COUNT, \RuntimeException::class);
        $where = $this->find($aggregates, Period::OneHour, Metric::EXCEPTION_WHERE_COUNT, "request\x1fGET app_boom\x1f" . \RuntimeException::class);
        $request = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET app_boom');
        static::assertNotNull($count);
        static::assertNotNull($where);
        static::assertNotNull($request);
        static::assertSame(10, $count->count);
        static::assertSame(10, $where->count);
        static::assertSame(1, $request->count);
    }

    public function testASignedInRequestCountsForItsUser(): void
    {
        $unit = static fn(string $route, float $duration, int $status, array $users, array $attributes = []): Entry => new Entry(
            Entry::TYPE_REQUEST,
            $route,
            1700000045.0,
            $duration,
            ['status' => $status] + $attributes,
            array_map(static fn(string $user): Entry => new Entry(Entry::TYPE_USER, $user, 1700000045.0, 0.0, ['n' => 1]), $users),
        );

        $aggregates = (new Aggregator(1000))->aggregate([
            $unit('GET app_home', 40.0, 200, ['alice']),
            $unit('GET app_report', 1500.0, 200, ['alice']),
            $unit('GET app_boom', 30.0, 503, ['bob', 'mallory']),
            $unit('GET app_home', 20.0, 500, ['carol'], ['cw' => 10]),
        ]);

        $alice = $this->find($aggregates, Period::OneHour, Metric::USER_DURATION, 'alice');
        $bob = $this->find($aggregates, Period::OneHour, Metric::USER_DURATION, 'bob');
        $carol = $this->find($aggregates, Period::OneHour, Metric::USER_DURATION, 'carol');
        static::assertNotNull($alice);
        static::assertNotNull($bob);
        static::assertNotNull($carol);
        static::assertSame([2, 1540.0, 1500.0], [$alice->count, $alice->sum, $alice->max]);
        static::assertSame([1, 30.0, 30.0], [$bob->count, $bob->sum, $bob->max]);
        static::assertSame([10, 200.0, 20.0], [$carol->count, $carol->sum, $carol->max]);
        static::assertSame(Family::User, Family::ofMetric($alice->type));

        static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::USER_SLOW, 'alice')?->count);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::USER_ERROR, 'alice'));
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::USER_SLOW, 'bob'));
        static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::USER_ERROR, 'bob')?->count);
        static::assertSame(10, $this->find($aggregates, Period::OneHour, Metric::USER_ERROR, 'carol')?->count);

        foreach ([Metric::USER_DURATION, Metric::USER_SLOW, Metric::USER_ERROR] as $type) {
            static::assertNull($this->find($aggregates, Period::OneHour, $type, 'mallory'));
        }
    }

    public function testOutgoingCallsAreCountedPerHostAndOnTheirRequest(): void
    {
        $call = static fn(string $host, float $total, int $calls, float $max, array $counters = []): Entry
            => new Entry(Entry::TYPE_HTTP, $host, 1700000045.0, $total, ['n' => $calls] + ($max > 0.0 ? ['m' => $max] : []) + $counters);

        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 300.0, ['status' => 200, 'http_ms' => 180.0, 'http_n' => 3], [
                $call('api.example.com', 150.0, 2, 100.0, ['c4' => 1]),
                $call('pay.example.com:8443', 30.0, 1, 30.0, ['c5' => 1]),
            ]),
            new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000046.0, 90.0, ['status' => 200, 'http_ms' => 40.0, 'http_n' => 2, 'cw' => 10], [
                $call('api.example.com', 40.0, 2, 0.0, ['cf' => 1]),
            ]),
            new Entry(Entry::TYPE_REQUEST, 'GET b', 1700000047.0, 10.0, ['status' => 200]),
        ]);

        $api = $this->find($aggregates, Period::OneHour, Metric::HTTP_DURATION, 'api.example.com');
        $pay = $this->find($aggregates, Period::OneHour, Metric::HTTP_DURATION, 'pay.example.com:8443');
        static::assertNotNull($api);
        static::assertNotNull($pay);
        static::assertSame([22, 550.0, 100.0], [$api->count, $api->sum, $api->max]);
        static::assertSame([1, 30.0, 30.0], [$pay->count, $pay->sum, $pay->max]);
        static::assertSame(Family::Http, Family::ofMetric($api->type));
        static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::HTTP_STATUS_PREFIX . '4xx', 'api.example.com')?->count);
        static::assertSame(10, $this->find($aggregates, Period::OneHour, Metric::HTTP_FAILED, 'api.example.com')?->count);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::HTTP_STATUS_PREFIX . '5xx', 'api.example.com'));
        static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::HTTP_STATUS_PREFIX . '5xx', 'pay.example.com:8443')?->count);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::HTTP_FAILED, 'pay.example.com:8443'));

        $time = $this->find($aggregates, Period::OneHour, Metric::REQUEST_HTTP_TIME, 'GET a');
        static::assertNotNull($time);
        static::assertSame([2, 220.0, 180.0], [$time->count, $time->sum, $time->max]);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_HTTP_TIME, 'GET b'));
    }

    public function testCacheOperationsAreCountedPerPool(): void
    {
        $pool = static fn(string $name, array $counters, int $operations): Entry
            => new Entry(Entry::TYPE_CACHE, $name, 1700000045.0, 0.0, ['n' => $operations] + $counters);

        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 30.0, ['status' => 200], [
                $pool('cache.app', ['h' => 3, 'mi' => 1, 'w' => 1], 4),
                $pool('app.sessions', ['de' => 2], 1),
            ]),
            new Entry(Entry::TYPE_REQUEST, 'GET b', 1700000046.0, 10.0, ['status' => 200, 'cw' => 10], [
                $pool('cache.app', ['h' => 2], 2),
                $pool('app.empty', [], 1),
                $pool('app.broken', ['h' => 'many', 'mi' => -1, 'w' => \INF], 1),
            ]),
        ]);

        $count = fn(string $metric, string $pool): ?int => $this->find($aggregates, Period::OneHour, $metric, $pool)?->count;
        static::assertSame([23, 1, 1, null], [
            $count(Metric::CACHE_HIT, 'cache.app'),
            $count(Metric::CACHE_MISS, 'cache.app'),
            $count(Metric::CACHE_WRITE, 'cache.app'),
            $count(Metric::CACHE_DELETE, 'cache.app'),
        ]);
        static::assertSame([null, null, null, 2], [
            $count(Metric::CACHE_HIT, 'app.sessions'),
            $count(Metric::CACHE_MISS, 'app.sessions'),
            $count(Metric::CACHE_WRITE, 'app.sessions'),
            $count(Metric::CACHE_DELETE, 'app.sessions'),
        ]);
        $hits = $this->find($aggregates, Period::OneHour, Metric::CACHE_HIT, 'cache.app');
        static::assertNotNull($hits);
        static::assertSame([0.0, 0.0], [$hits->sum, $hits->max]);
        static::assertSame(Family::Cache, Family::ofMetric($hits->type));
        static::assertSame([], array_filter($aggregates, static fn(Aggregate $a): bool => \in_array($a->key, ['app.empty', 'app.broken'], true)));
    }

    public function testCachePoolsBeyondTheCapFoldIntoOtherByTheirOperations(): void
    {
        $children = [];
        for ($i = 1; $i <= 21; ++$i) {
            $children[] = new Entry(Entry::TYPE_CACHE, sprintf('app.pool%02d', $i), 1700000045.0, 0.0, ['n' => 1, 'w' => $i]);
        }
        $children[] = new Entry(Entry::TYPE_CACHE, 'app.reads', 1700000045.0, 0.0, ['n' => 1, 'h' => 1, 'mi' => 1, 'de' => 1]);

        $aggregates = (new Aggregator(1000))->aggregate([new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 10.0, [], $children)]);

        static::assertSame(1 + 2, $this->find($aggregates, Period::OneHour, Metric::CACHE_WRITE, Aggregator::OTHER)?->count);
        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::CACHE_WRITE, 'app.pool03'));
        static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::CACHE_HIT, 'app.reads')?->count);
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::CACHE_HIT, Aggregator::OTHER));
    }

    public function testKeysBeyondTheCapOfAFamilyFoldIntoOther(): void
    {
        $entries = [];
        $total = 0;
        for ($i = 1; $i <= 305; ++$i) {
            for ($j = 0; $j < $i; ++$j) {
                $entries[] = new Entry(Entry::TYPE_REQUEST, sprintf('GET r%03d', $i), 1700000045.0, 10.0);
                ++$total;
            }
        }

        $aggregates = (new Aggregator(1000))->aggregate($entries);

        $durations = array_filter(
            $aggregates,
            static fn(Aggregate $a): bool => $a->period === Period::OneHour && $a->type === Metric::REQUEST_DURATION,
        );
        static::assertCount(301, $durations);
        $other = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, Aggregator::OTHER);
        static::assertNotNull($other);
        static::assertSame(1 + 2 + 3 + 4 + 5, $other->count);
        static::assertSame($total, array_sum(array_map(static fn(Aggregate $a): int => $a->count, $durations)));
        static::assertNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET r001'));
        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET r305'));
    }

    public function testUnknownChildrenAreIgnored(): void
    {
        $unit = new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, [], [
            new Entry('zz', 'abc', 1700000045.0, 12.0, ['n' => 3, 'm' => 5.0]),
            new Entry(Entry::TYPE_QUERY, 'inf', 1700000045.0, \INF, ['n' => 3, 'm' => 5.0]),
            new Entry(Entry::TYPE_QUERY, 'nan', 1700000045.0, 1.0, ['n' => 3, 'm' => \NAN]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([$unit]);

        static::assertSame(
            array_map(static fn(Aggregate $a): string => $a->type, (new Aggregator(1000))->aggregate([new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0)])),
            array_map(static fn(Aggregate $a): string => $a->type, $aggregates),
        );
    }

    public function testAChildOfAnUnknownTypeIsIgnored(): void
    {
        $known = [
            new Entry(Entry::TYPE_QUERY, 'abc', 1700000045.0, 12.0, ['n' => 3, 'm' => 5.0]),
            new Entry(Entry::TYPE_NPLUS1, 'abc', 1700000045.0, 40.0, ['n' => 12]),
        ];
        $unknown = new Entry('zz', 'api.example.com', 1700000045.0, 25.0, ['n' => 2, 'm' => 12.5, 'c5' => 2]);

        $aggregates = (new Aggregator(1000))->aggregate([
            new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, ['cw' => 10], [$known[0], $unknown, $known[1]]),
        ]);

        static::assertSame(
            self::canonical((new Aggregator(1000))->aggregate([new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, ['cw' => 10], $known)])),
            self::canonical($aggregates),
        );
        static::assertSame([], array_filter($aggregates, static fn(Aggregate $a): bool => $a->key === 'api.example.com'));
    }

    public function testHugeCountsAndWeightsDoNotBreakTheFlush(): void
    {
        $hostile = static fn(float $at): Entry => new Entry(Entry::TYPE_REQUEST, 'GET a', $at, 50.0, ['cw' => 1e300], [
            new Entry(Entry::TYPE_QUERY, 'abc', $at, 12.0, ['n' => \PHP_INT_MAX, 'm' => 5.0, 'sl' => \PHP_INT_MAX]),
            new Entry(Entry::TYPE_NPLUS1, 'abc', $at, 40.0, ['n' => \PHP_INT_MAX]),
        ]);

        $aggregates = (new Aggregator(1000))->aggregate([$hostile(1700000045.0), $hostile(1700000046.0)]);

        foreach ($aggregates as $aggregate) {
            static::assertGreaterThanOrEqual(0, $aggregate->count);
            static::assertTrue(is_finite($aggregate->sum));
        }
        $request = $this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET a');
        static::assertNotNull($request);
        static::assertSame(2, $request->count);
    }

    public function testSummingManyMaximalChildrenSaturatesInsteadOfOverflowing(): void
    {
        $heavy = static fn(string $key, int $children): array => array_fill(
            0,
            $children,
            new Entry(Entry::TYPE_QUERY, $key, 1700000045.0, 0.0, ['n' => 1_000_000_000, 'm' => 1.0, 'sl' => 1_000_000_000]),
        );
        $unit = static fn(float $at, array $children): Entry => new Entry(Entry::TYPE_REQUEST, 'GET a', $at, 5.0, ['cw' => 1_000_000], $children);

        $sameMinute = (new Aggregator(1000))->aggregate([$unit(1700000045.0, $heavy('abc', 10_000))]);
        $twoMinutes = (new Aggregator(1000))->aggregate([
            $unit(1700000045.0, $heavy('abc', 9_000)),
            $unit(1700000105.0, $heavy('abc', 9_000)),
        ]);

        $folded = [$unit(1700000045.0, $heavy('x1', 9_000)), $unit(1700000105.0, $heavy('x2', 9_000))];
        for ($i = 0; $i < 200; ++$i) {
            $folded[] = new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 5.0, [], [
                new Entry(Entry::TYPE_QUERY, sprintf('k%03d', $i), 1700000045.0, 1000.0, ['n' => 1, 'm' => 1.0]),
            ]);
        }
        $capped = (new Aggregator(1000))->aggregate($folded);

        foreach ([[$sameMinute, 'abc'], [$twoMinutes, 'abc'], [$capped, Aggregator::OTHER]] as [$aggregates, $key]) {
            foreach ([Metric::QUERY_DURATION, Metric::QUERY_SLOW] as $type) {
                $aggregate = $this->find($aggregates, Period::OneDay, $type, $key);
                static::assertNotNull($aggregate, $type . ' ' . $key);
                static::assertGreaterThan(9_000_000_000_000_000_000, $aggregate->count);
            }
        }
    }

    public function testOpenFamiliesFollowTheRegistry(): void
    {
        $dir = sys_get_temp_dir() . '/jmonitor-aggregator-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $registry = new HotKeys(new BufferDirectory($dir, 'app'), new MockClock('@1700000000'));
            $registry->select(Family::Query, ['known' => 10_000.0], Family::Query->cap());

            $children = [new Entry(Entry::TYPE_QUERY, 'known', 1700000045.0, 1.0, ['n' => 1, 'm' => 1.0])];
            for ($i = 1; $i < 250; ++$i) {
                $children[] = new Entry(Entry::TYPE_QUERY, sprintf('q%03d', $i), 1700000045.0, 10.0 + $i, ['n' => 1, 'm' => 1.0]);
            }

            $aggregates = (new Aggregator(1000, $registry))->aggregate([
                new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, [], $children),
            ]);
        } finally {
            (new Filesystem())->remove($dir);
        }

        $rows = array_filter($aggregates, static fn(Aggregate $a): bool => $a->period === Period::OneHour && $a->type === Metric::QUERY_DURATION);
        static::assertCount(201, $rows);
        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, 'known'));
        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, Aggregator::OTHER));
        static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::REQUEST_DURATION, 'GET a'));
    }

    public function testTheOtherKeyOfTheCaptureTakesNoPlaceInTheRegister(): void
    {
        $dir = sys_get_temp_dir() . '/jmonitor-aggregator-' . bin2hex(random_bytes(6));
        mkdir($dir);

        $children = [new Entry(Entry::TYPE_QUERY, Aggregator::OTHER, 1700000045.0, 5000.0, ['n' => 1, 'm' => 1.0])];
        for ($i = 1; $i <= 200; ++$i) {
            $children[] = new Entry(Entry::TYPE_QUERY, sprintf('q%03d', $i), 1700000045.0, 10.0 + $i, ['n' => 1, 'm' => 1.0]);
        }
        $unit = new Entry(Entry::TYPE_REQUEST, 'GET a', 1700000045.0, 50.0, [], $children);

        try {
            $registry = new HotKeys(new BufferDirectory($dir, 'app'), new MockClock('@1700000000'));
            $batches = [
                'per flush' => (new Aggregator(1000))->aggregate([$unit]),
                'registry' => (new Aggregator(1000, $registry))->aggregate([$unit]),
            ];
        } finally {
            (new Filesystem())->remove($dir);
        }

        foreach ($batches as $name => $aggregates) {
            $rows = array_filter($aggregates, static fn(Aggregate $a): bool => $a->period === Period::OneHour && $a->type === Metric::QUERY_DURATION);
            static::assertCount(201, $rows, $name);
            static::assertNotNull($this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, 'q001'), $name);
            static::assertSame(1, $this->find($aggregates, Period::OneHour, Metric::QUERY_DURATION, Aggregator::OTHER)?->count, $name);
        }
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
