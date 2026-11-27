<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Dashboard\View\Tile;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Route array{row: ReportRow, cell: Cell, requests: int, total: float, avg: float, p95: float, errors: int, slow: int, nPlusOne: bool}
 * @phpstan-type Totals array{requests: int, total: float, errors: int, slow: int, p95: float}
 */
final class RequestsScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const SERVER_ERRORS = 'requests.server_errors';
    private const SLOW = 'requests.slow';
    private const REPEATED = 'queries.repeated';
    private const FASTER = '95 % of the requests are faster.';

    public function __construct(
        private readonly ApplicationChecks $checks,
        private readonly int $slowThresholdMs,
    ) {}

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

    public function space(): Space
    {
        return Space::Application;
    }

    public function sorts(): array
    {
        return [
            'total' => 'desc',
            'route' => 'asc',
            'requests' => 'desc',
            'avg' => 'desc',
            'p95' => 'desc',
            'problems' => 'desc',
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

        $listed = array_values(array_filter($report->rows, static fn(ReportRow $row): bool => $row->count(Metric::REQUEST_DURATION) > 0));
        $otherRow = $report->other !== null && $report->other->count(Metric::REQUEST_DURATION) > 0 ? $report->other : null;

        if ($listed === [] && $otherRow === null) {
            return new ScreenView([], [self::table([], 0)]);
        }

        $raised = $this->checks->raised($reader, $period, $now);
        $repeating = [];

        if ($raised->has(self::REPEATED)) {
            $query = $this->checks->query(self::REPEATED, $period, $now);

            if ($query !== null) {
                $repeating = self::repeatingRoutes($reader->read($query));
            }
        }

        $routes = array_map(static fn(ReportRow $row): array => self::route($row, isset($repeating[$row->key])), $listed);
        $other = $otherRow === null ? null : self::route($otherRow, false);

        $top = \array_slice(self::sorted($routes, $sort, $direction), 0, self::LIMIT);
        $largest = max(array_map(static fn(array $route): float => $route['total'], [...$top, ...($other === null ? [] : [$other])]));
        $rows = array_map(static fn(array $route): Row => self::row($route, $largest, false), $top);
        if ($other !== null) {
            $rows[] = self::row($other, $largest, true);
        }

        return new ScreenView(
            tables: [self::table($rows, max(0, \count($routes) - self::LIMIT))],
            raised: $raised,
            tiles: $this->tiles(self::totals([...$report->rows, ...($report->other === null ? [] : [$report->other])]), $report->series),
        );
    }

    /** @param list<Row> $rows */
    private static function table(array $rows, int $remaining): Table
    {
        return new Table('routes', 'Routes', [
            new Column('route', 'Route', false, 'route'),
            new Column('requests', 'Requests', sort: 'requests'),
            new Column('total', 'Total time', sort: 'total', definition: 'Requests × average.'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('p95', 'p95', sort: 'p95', definition: self::FASTER),
            new Column('split', 'Where the average goes', false, legend: true),
            new Column('problems', 'Problems', false, 'problems'),
        ], $rows, $remaining, 'route', titled: false);
    }

    /**
     * @return array<string, true> the keys of the routes that repeat a query
     */
    private static function repeatingRoutes(Report $repeats): array
    {
        $routes = [];

        foreach ($repeats->rows as $row) {
            $key = EntryPointKey::parse($row->key);

            if ($key !== null && $key->unitType === Entry::TYPE_REQUEST) {
                $routes[$key->unitKey] = true;
            }
        }

        return $routes;
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return Totals
     */
    private static function totals(array $rows): array
    {
        $totals = ['requests' => 0, 'total' => 0.0, 'errors' => 0, 'slow' => 0];
        $max = 0.0;
        $counts = [];

        foreach ($rows as $row) {
            $totals['requests'] += $row->count(Metric::REQUEST_DURATION);
            $totals['total'] += $row->sum(Metric::REQUEST_DURATION);
            $max = max($max, $row->max(Metric::REQUEST_DURATION));
            $totals['errors'] += $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx');
            $totals['slow'] += $row->count(Metric::REQUEST_SLOW);
            foreach ($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX) as $bound => $count) {
                $counts[$bound] = ($counts[$bound] ?? 0) + $count;
            }
        }

        return $totals + ['p95' => Percentile::estimate($counts, 0.95, $max)];
    }

    /**
     * @param Totals    $totals
     * @param list<int> $series
     *
     * @return list<Tile>
     */
    private function tiles(array $totals, array $series): array
    {
        return [
            new Tile('Requests', Format::number($totals['requests']), series: $series, namesPage: true),
            new Tile('Average', Format::duration($totals['total'] / $totals['requests']), 'The mean response time.'),
            new Tile('p95', Format::duration($totals['p95']), self::FASTER),
            $this->problem('Server errors', $totals['errors'], 'Responses with a 5xx status.', self::SERVER_ERRORS),
            $this->problem('Slow requests', $totals['slow'], Format::duration($this->slowThresholdMs) . ' or more.', self::SLOW),
        ];
    }

    private function problem(string $label, int $count, string $definition, string $check): Tile
    {
        $severity = $count > 0 ? $this->checks->severity($check) : null;

        return new Tile(
            $label,
            Format::number($count),
            $definition,
            $severity === null ? null : new Tag($severity->label(), $severity->tone()),
            sort: $severity === null ? null : 'problems',
        );
    }

    /** @return Route */
    private static function route(ReportRow $row, bool $nPlusOne): array
    {
        $requests = $row->count(Metric::REQUEST_DURATION);
        $total = $row->sum(Metric::REQUEST_DURATION);

        return [
            'row' => $row,
            'cell' => RouteCells::route($row),
            'requests' => $requests,
            'total' => $total,
            'avg' => $total / $requests,
            'p95' => Percentile::estimate($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX), 0.95, $row->max(Metric::REQUEST_DURATION)),
            'errors' => $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx'),
            'slow' => $row->count(Metric::REQUEST_SLOW),
            'nPlusOne' => $nPlusOne,
        ];
    }

    /** @param Route $route */
    private static function row(array $route, float $largest, bool $other): Row
    {
        return new Row($route['row']->hash, [
            'route' => $other ? new Cell('Other routes') : $route['cell'],
            'requests' => RouteCells::requests($route['row']),
            'total' => RouteCells::total($route['row'], $largest),
            'avg' => new Cell(Format::duration($route['avg'])),
            'p95' => new Cell(Format::duration($route['p95']), $route['slow'] * 20 > $route['requests'] ? 'warn' : null),
            'split' => RouteCells::split($route['row']),
            'problems' => Cell::tags(self::tags($route)),
        ], [
            'data-jm-route' => $route['row']->key,
            'data-jm-requests' => (string) $route['requests'],
        ], $other);
    }

    /**
     * @param Route $route
     *
     * @return list<Tag>
     */
    private static function tags(array $route): array
    {
        $tags = [];

        if ($route['errors'] > 0) {
            $tags[] = new Tag(Format::number($route['errors']) . ' × 5xx', 'bad', 'exceptions');
        }

        if ($route['slow'] > 0) {
            $tags[] = new Tag(Format::number($route['slow']) . ' slow', 'warn');
        }

        if ($route['nPlusOne']) {
            $tags[] = new Tag('N+1', 'warn', 'queries');
        }

        return $tags;
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
            'requests', 'avg', 'p95' => $a[$sort] <=> $b[$sort],
            'problems' => [$a['errors'], $a['slow'], $a['nPlusOne']] <=> [$b['errors'], $b['slow'], $b['nPlusOne']],
            default => $a['total'] <=> $b['total'],
        };
    }
}
