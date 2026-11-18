<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\RequestsScreenFactory;
use Jmonitor\JmonitorBundle\Dashboard\ScreenState;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RequestsScreenFactoryTest extends TestCase
{
    public function testInvalidParametersFallBackToDefaults(): void
    {
        $screen = $this->factory($this->reader([$this->route('app_a', totalMs: 10)]))->create('abc', 'x', 'sideways');

        static::assertSame(Period::OneHour, $screen->period);
        static::assertSame('total', $screen->sort);
        static::assertSame('desc', $screen->direction);
    }

    public function testDefaultSortIsTotalTimeDescending(): void
    {
        $reader = $this->reader([
            $this->route('app_a', totalMs: 120),
            $this->route('app_b', totalMs: 1500),
            $this->route('app_c', totalMs: 30),
        ]);

        $screen = $this->factory($reader)->create(null, null, null);

        static::assertSame([1500.0, 120.0, 30.0], array_map(static fn(RouteStats $route): float => $route->totalMs, $screen->routes));
    }

    public function testEverySortKey(): void
    {
        $reader = $this->reader([
            $this->route('app_b', 'POST', 40, 12.5, 30.0, 90.0, 500.0, 2, 0, 4096),
            $this->route('app_a', 'GET', 7, 80.0, 210.0, 400.0, 560.0, 0, 3, 1024),
            $this->route('app_d', 'GET', 120, 3.2, 9.0, 15.0, 384.0, 1, 0, 2048),
            $this->route('app_c', 'DELETE', 1, 900.0, 900.0, 900.0, 900.0, 0, 1, 8192),
        ]);
        $columns = [
            'total' => static fn(RouteStats $r): float => $r->totalMs,
            'route' => static fn(RouteStats $r): string => $r->route,
            'requests' => static fn(RouteStats $r): int => $r->requests,
            'avg' => static fn(RouteStats $r): float => $r->avgMs,
            'p95' => static fn(RouteStats $r): float => $r->p95Ms,
            'max' => static fn(RouteStats $r): float => $r->maxMs,
            'errors' => static fn(RouteStats $r): int => $r->errors,
            'slow' => static fn(RouteStats $r): int => $r->slow,
            'memory' => static fn(RouteStats $r): int => $r->peakMemoryBytes,
        ];

        static::assertSame(RequestsScreenFactory::SORTS, array_keys($columns));

        foreach ($columns as $sort => $column) {
            foreach (['asc', 'desc'] as $direction) {
                $screen = $this->factory($reader)->create('1h', $sort, $direction);
                $values = array_map($column, $screen->routes);
                $expected = $values;
                $direction === 'asc' ? sort($expected) : rsort($expected);

                static::assertSame($sort, $screen->sort);
                static::assertSame($direction, $screen->direction);
                static::assertCount(4, $values);
                static::assertSame($expected, $values, sprintf('%s %s', $sort, $direction));
            }
        }

        static::assertSame('asc', $this->factory($reader)->create('1h', 'route', null)->direction);
    }

    public function testStates(): void
    {
        $withData = $this->reader([$this->route('app_a', totalMs: 10)]);
        $empty = $this->reader([]);

        static::assertSame(ScreenState::StorageError, $this->factory($withData)->create(null, null, null, true)->state);
        static::assertSame(ScreenState::NoConnection, $this->factory(null)->create(null, null, null)->state);
        static::assertSame(ScreenState::RecordingDisabled, $this->factory($empty, false)->create(null, null, null)->state);
        static::assertSame(ScreenState::Ready, $this->factory($withData, false)->create(null, null, null)->state);
        static::assertSame(ScreenState::StorageError, $this->factory($this->reader([], fail: true))->create(null, null, null)->state);
        static::assertSame(ScreenState::Empty, $this->factory($empty)->create(null, null, null)->state);

        $ready = $this->factory($withData)->create('6h', null, null);
        static::assertSame(ScreenState::Ready, $ready->state);
        static::assertSame(Period::SixHours, $ready->period);
        static::assertSame(1, $ready->summary?->requests);
        static::assertCount(60, $ready->series);
    }

    private function factory(?ReaderInterface $reader, bool $recordingEnabled = true): RequestsScreenFactory
    {
        return new RequestsScreenFactory($reader, $recordingEnabled, new MockClock('@1800000000'));
    }

    /**
     * @param list<RouteStats> $routes
     */
    private function reader(array $routes, bool $fail = false): ReaderInterface
    {
        return new class ($routes, $fail) implements ReaderInterface {
            /**
             * @param list<RouteStats> $routes
             */
            public function __construct(private readonly array $routes, private readonly bool $fail) {}

            public function summary(Period $period, int $now): RequestSummary
            {
                $this->failIfAsked();
                $requests = array_sum(array_map(static fn(RouteStats $route): int => $route->requests, $this->routes));

                return new RequestSummary($requests, 0.0, 0.0, 0.0);
            }

            public function routes(Period $period, int $now): array
            {
                $this->failIfAsked();

                return $this->routes;
            }

            public function requestSeries(Period $period, int $now): array
            {
                $this->failIfAsked();

                return array_fill(0, 60, 0);
            }

            public function read(ReportQuery $query): Report
            {
                return new Report();
            }

            public function labels(array $keys): array
            {
                return [];
            }

            private function failIfAsked(): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Storage is down');
                }
            }
        };
    }

    private function route(
        string $name,
        string $method = 'GET',
        int $requests = 1,
        float $avgMs = 10.0,
        float $p95Ms = 10.0,
        float $maxMs = 10.0,
        float $totalMs = 10.0,
        int $errors = 0,
        int $slow = 0,
        int $peakMemoryBytes = 1024,
    ): RouteStats {
        return new RouteStats($method . ' ' . $name, $method, $name, $requests, $avgMs, $p95Ms, $maxMs, $totalMs, $errors, $slow, $peakMemoryBytes);
    }
}
