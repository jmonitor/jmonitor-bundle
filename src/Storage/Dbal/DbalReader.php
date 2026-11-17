<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\RequestSummary;
use Jmonitor\JmonitorBundle\Storage\Read\RouteStats;

final class DbalReader implements ReaderInterface
{
    private const SERIES_BUCKETS = 60;

    public function __construct(private readonly Connection $connection) {}

    public function summary(Period $period, int $now): RequestSummary
    {
        $requests = 0;
        $total = 0.0;
        $max = 0.0;
        $errors = 0;
        $counts = [];

        foreach ($this->collect($period, $now) as $route) {
            $requests += $route['requests'];
            $total += $route['total'];
            $max = max($max, $route['max']);
            $errors += $route['errors'];
            foreach ($route['counts'] as $bound => $count) {
                $counts[$bound] = ($counts[$bound] ?? 0) + $count;
            }
        }

        if ($requests === 0) {
            return new RequestSummary(0, 0.0, 0.0, 0.0);
        }

        return new RequestSummary($requests, $total / $requests, Percentile::estimate($counts, 0.95, $max), $errors / $requests);
    }

    public function routes(Period $period, int $now): array
    {
        $routes = [];

        foreach ($this->collect($period, $now) as $route) {
            if ($route['requests'] === 0) {
                continue;
            }

            [$method, $name] = array_pad(explode(' ', $route['key'], 2), 2, '');

            $routes[] = new RouteStats(
                $route['key'],
                $method,
                $name,
                $route['requests'],
                $route['total'] / $route['requests'],
                Percentile::estimate($route['counts'], 0.95, $route['max']),
                $route['max'],
                $route['total'],
                $route['errors'],
                $route['slow'],
                (int) $route['memory'],
            );
        }

        return $routes;
    }

    public function requestSeries(Period $period, int $now): array
    {
        $size = $period->bucketSeconds();
        $current = $period->bucketFor($now);
        $series = array_fill(0, self::SERIES_BUCKETS, 0);

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT bucket, SUM(entry_count) AS requests FROM ' . AggregatesTable::NAME
                . ' WHERE period = ? AND metric_type = ? AND bucket > ? GROUP BY bucket',
                [$period->value, Metric::REQUEST_DURATION, $current - $period->value],
            );
        } catch (TableNotFoundException) {
            return $series;
        }

        foreach ($rows as $row) {
            $index = self::SERIES_BUCKETS - 1 - intdiv($current - (int) $row['bucket'], $size);
            if (isset($series[$index])) {
                $series[$index] += (int) $row['requests'];
            }
        }

        return $series;
    }

    /**
     * @return array<string, array{key: string, requests: int, total: float, max: float, errors: int, slow: int, memory: float, counts: array<string, int>}>
     */
    private function collect(Period $period, int $now): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT metric_type, key_hash, MAX(metric_key) AS metric_key, SUM(entry_count) AS entry_count,'
                . ' SUM(value_sum) AS value_sum, MAX(value_max) AS value_max FROM ' . AggregatesTable::NAME
                . ' WHERE period = ? AND bucket > ? GROUP BY metric_type, key_hash',
                [$period->value, $period->bucketFor($now) - $period->value],
            );
        } catch (TableNotFoundException) {
            return [];
        }

        $routes = [];

        foreach ($rows as $row) {
            $hash = (string) $row['key_hash'];
            $routes[$hash] ??= [
                'key' => (string) $row['metric_key'],
                'requests' => 0,
                'total' => 0.0,
                'max' => 0.0,
                'errors' => 0,
                'slow' => 0,
                'memory' => 0.0,
                'counts' => [],
            ];

            $type = (string) $row['metric_type'];
            $count = (int) $row['entry_count'];

            if ($type === Metric::REQUEST_DURATION) {
                $routes[$hash]['requests'] = $count;
                $routes[$hash]['total'] = (float) $row['value_sum'];
                $routes[$hash]['max'] = (float) $row['value_max'];
            } elseif ($type === Metric::REQUEST_MEMORY) {
                $routes[$hash]['memory'] = (float) $row['value_max'];
            } elseif ($type === Metric::REQUEST_SLOW) {
                $routes[$hash]['slow'] = $count;
            } elseif ($type === Metric::REQUEST_STATUS_PREFIX . '5xx') {
                $routes[$hash]['errors'] = $count;
            } elseif (str_starts_with($type, Metric::REQUEST_DURATION_LE_PREFIX)) {
                $routes[$hash]['counts'][substr($type, strlen(Metric::REQUEST_DURATION_LE_PREFIX))] = $count;
            }
        }

        return $routes;
    }
}
