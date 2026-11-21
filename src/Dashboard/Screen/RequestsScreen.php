<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Route array{row: ReportRow, cell: Cell, requests: int, total: float, avg: float, p95: float, max: float, sql: float, queries: float, hasSql: bool, http: float, hasHttp: bool, errors: int, slow: int, memory: int}
 */
final class RequestsScreen implements ScreenInterface
{
    private const LIMIT = 100;

    public function id(): string
    {
        return 'requests';
    }

    public function label(): string
    {
        return 'Requests';
    }

    public function heading(): string
    {
        return 'Traffic and response times per route';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function sorts(): array
    {
        return [
            'total' => 'desc',
            'route' => 'asc',
            'requests' => 'desc',
            'avg' => 'desc',
            'p95' => 'desc',
            'max' => 'desc',
            'sql' => 'desc',
            'http' => 'desc',
            'errors' => 'desc',
            'slow' => 'desc',
            'memory' => 'desc',
        ];
    }

    public function emptyState(): array
    {
        return [
            'title' => 'Waiting for traffic',
            'text' => 'No requests recorded yet. Browse your application, then come back: data shows up within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Request, seriesMetric: Metric::REQUEST_DURATION));

        $routes = [];
        foreach ($report->rows as $row) {
            if ($row->count(Metric::REQUEST_DURATION) > 0) {
                $routes[] = self::route($row);
            }
        }
        $other = $report->other !== null && $report->other->count(Metric::REQUEST_DURATION) > 0 ? self::route($report->other) : null;

        $cards = self::cards([...$report->rows, ...($report->other === null ? [] : [$report->other])], $period, $report->series);

        $routes = self::sorted($routes, $sort, $direction);
        $rows = array_map(self::row(...), \array_slice($routes, 0, self::LIMIT));
        if ($other !== null) {
            $rows[] = self::row($other, true);
        }

        return new ScreenView($cards, [
            new Table('routes', 'Routes', self::columns(), $rows, max(0, \count($routes) - self::LIMIT), 'route'),
        ]);
    }

    /** @return list<Column> */
    private static function columns(): array
    {
        return [
            new Column('route', 'Route', false, 'route'),
            new Column('requests', 'Requests', sort: 'requests'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('p95', 'p95', sort: 'p95'),
            new Column('max', 'Max', sort: 'max'),
            new Column('sql', 'SQL', sort: 'sql'),
            new Column('http', 'HTTP out', sort: 'http'),
            new Column('errors', '5xx', sort: 'errors'),
            new Column('slow', 'Slow', sort: 'slow'),
            new Column('memory', 'Peak memory', sort: 'memory'),
        ];
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     *
     * @return list<Card>
     */
    private static function cards(array $rows, Period $period, array $series): array
    {
        $requests = 0;
        $total = 0.0;
        $max = 0.0;
        $errors = 0;
        $counts = [];

        foreach ($rows as $row) {
            $requests += $row->count(Metric::REQUEST_DURATION);
            $total += $row->sum(Metric::REQUEST_DURATION);
            $max = max($max, $row->max(Metric::REQUEST_DURATION));
            $errors += $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx');
            foreach ($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX) as $bound => $count) {
                $counts[$bound] = ($counts[$bound] ?? 0) + $count;
            }
        }

        $errorRate = $requests === 0 ? 0.0 : $errors / $requests;

        return [
            new Card('Requests', Format::number($requests), Format::period($period), series: $series, wide: true),
            new Card('Avg duration', Format::duration($requests === 0 ? 0 : $total / $requests), 'Mean response time'),
            new Card('p95', Format::duration(Percentile::estimate($counts, 0.95, $max)), '95 % of requests are faster'),
            new Card('5xx errors', Format::percent($errorRate), 'Share of server errors', $errorRate > 0 ? 'bad' : null),
        ];
    }

    /** @return Route */
    private static function route(ReportRow $row): array
    {
        $requests = $row->count(Metric::REQUEST_DURATION);
        $total = $row->sum(Metric::REQUEST_DURATION);
        $max = $row->max(Metric::REQUEST_DURATION);

        return [
            'row' => $row,
            'cell' => Cell::route($row->key),
            'requests' => $requests,
            'total' => $total,
            'avg' => $total / $requests,
            'p95' => Percentile::estimate($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX), 0.95, $max),
            'max' => $max,
            'sql' => $row->sum(Metric::REQUEST_SQL_TIME) / $requests,
            'queries' => $row->sum(Metric::REQUEST_SQL_COUNT) / $requests,
            'hasSql' => $row->count(Metric::REQUEST_SQL_TIME) > 0,
            'http' => $row->sum(Metric::REQUEST_HTTP_TIME) / $requests,
            'hasHttp' => $row->count(Metric::REQUEST_HTTP_TIME) > 0,
            'errors' => $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx'),
            'slow' => $row->count(Metric::REQUEST_SLOW),
            'memory' => (int) $row->max(Metric::REQUEST_MEMORY),
        ];
    }

    /** @param Route $route */
    private static function row(array $route, bool $other = false): Row
    {
        return new Row($route['row']->hash, [
            'route' => $other ? new Cell('Other routes') : $route['cell'],
            'requests' => new Cell(Format::number($route['requests'])),
            'total' => new Cell(Format::duration($route['total'])),
            'avg' => new Cell(Format::duration($route['avg'])),
            'p95' => new Cell(Format::duration($route['p95']), $route['slow'] * 20 > $route['requests'] ? 'warn' : null),
            'max' => new Cell(Format::duration($route['max']), $route['slow'] > 0 ? 'warn' : null),
            'sql' => $route['hasSql']
                ? new Cell(Format::sql($route['sql'], $route['queries']))
                : new Cell('—', 'zero'),
            'http' => $route['hasHttp'] ? new Cell(Format::duration($route['http'])) : new Cell('—', 'zero'),
            'errors' => new Cell(Format::number($route['errors']), $route['errors'] > 0 ? 'bad' : 'zero'),
            'slow' => new Cell(Format::number($route['slow']), $route['slow'] > 0 ? 'warn' : 'zero'),
            'memory' => new Cell(Format::bytes($route['memory'])),
        ], [
            'data-jm-route' => $route['row']->key,
            'data-jm-requests' => (string) $route['requests'],
        ], $other);
    }

    /**
     * @param list<Route> $routes
     *
     * @return list<Route>
     */
    private static function sorted(array $routes, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($routes, static fn(array $a, array $b): int
            => $sign * self::compare($a, $b, $sort) ?: strcmp($a['row']->key, $b['row']->key));

        return $routes;
    }

    /**
     * @param Route $a
     * @param Route $b
     */
    private static function compare(array $a, array $b, string $sort): int
    {
        return match ($sort) {
            'route' => strcmp($a['cell']->text, $b['cell']->text),
            'requests', 'avg', 'p95', 'max', 'sql', 'http', 'errors', 'slow', 'memory' => $a[$sort] <=> $b[$sort],
            default => $a['total'] <=> $b['total'],
        };
    }
}
