<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class RequestsScreenFactory
{
    public const SORTS = ['total', 'route', 'requests', 'avg', 'p95', 'max', 'errors', 'slow', 'memory'];

    public function __construct(
        private readonly ?ReaderInterface $reader,
        private readonly bool $recordingEnabled,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function create(?string $period, ?string $sort, ?string $direction, bool $storageFailed = false): RequestsScreen
    {
        $period = Period::fromLabel($period ?? '') ?? Period::OneHour;
        $sort = \in_array($sort, self::SORTS, true) ? $sort : 'total';
        $direction = \in_array($direction, ['asc', 'desc'], true) ? $direction : self::defaultDirection($sort);

        if ($this->reader === null) {
            return new RequestsScreen(ScreenState::NoConnection, $period, $sort, $direction);
        }

        if ($storageFailed) {
            return new RequestsScreen(ScreenState::StorageError, $period, $sort, $direction);
        }

        try {
            $now = $this->clock->now()->getTimestamp();
            $summary = $this->reader->summary($period, $now);
            $routes = $this->reader->routes($period, $now);
            $series = $this->reader->requestSeries($period, $now);
        } catch (\Throwable $e) {
            $this->logger?->error('JMonitor could not read its data: ' . $e->getMessage(), ['exception' => $e]);

            return new RequestsScreen(ScreenState::StorageError, $period, $sort, $direction);
        }

        if ($summary->requests === 0) {
            $state = $this->recordingEnabled ? ScreenState::Empty : ScreenState::RecordingDisabled;

            return new RequestsScreen($state, $period, $sort, $direction, $summary);
        }

        return new RequestsScreen(ScreenState::Ready, $period, $sort, $direction, $summary, self::sorted($routes, $sort, $direction), $series);
    }

    public static function defaultDirection(string $sort): string
    {
        return $sort === 'route' ? 'asc' : 'desc';
    }

    /**
     * @param list<RouteStats> $routes
     *
     * @return list<RouteStats>
     */
    private static function sorted(array $routes, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($routes, static fn(RouteStats $a, RouteStats $b): int
            => $sign * self::compare($a, $b, $sort) ?: strcmp($a->key, $b->key));

        return $routes;
    }

    private static function compare(RouteStats $a, RouteStats $b, string $sort): int
    {
        if ($sort === 'route') {
            return strcmp($a->route, $b->route);
        }

        return self::value($a, $sort) <=> self::value($b, $sort);
    }

    private static function value(RouteStats $route, string $sort): int|float
    {
        return match ($sort) {
            'requests' => $route->requests,
            'avg' => $route->avgMs,
            'p95' => $route->p95Ms,
            'max' => $route->maxMs,
            'errors' => $route->errors,
            'slow' => $route->slow,
            'memory' => $route->peakMemoryBytes,
            default => $route->totalMs,
        };
    }
}
