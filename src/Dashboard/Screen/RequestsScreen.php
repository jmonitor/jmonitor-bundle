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
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Route array{row: ReportRow, cell: Cell, requests: int, total: float, avg: float, p95: float, max: float, sql: float, queries: float, hasSql: bool, http: float, hasHttp: bool, errors: int, slow: int, nPlusOne: bool, memory: int}
 * @phpstan-type Totals array{requests: int, total: float, max: float, errors: int, slow: int, p95: float}
 */
final class RequestsScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const SERVER_ERRORS = 'requests.server_errors';
    private const SLOW = 'requests.slow';
    private const REPEATED = 'queries.repeated';
    private const UNMATCHED = '(unmatched)';

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
            'max' => 'desc',
            'problems' => 'desc',
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

        $listed = array_values(array_filter($report->rows, static fn(ReportRow $row): bool => $row->count(Metric::REQUEST_DURATION) > 0));
        $otherRow = $report->other !== null && $report->other->count(Metric::REQUEST_DURATION) > 0 ? $report->other : null;

        if ($listed === [] && $otherRow === null) {
            return new ScreenView([], [new Table('routes', 'Routes', self::columns(), [], 0, 'route')]);
        }

        $raised = $this->checks->raised($reader, $period, $now);
        $repeating = [];
        $repeats = null;

        if ($raised->has(self::REPEATED)) {
            $query = $this->checks->query(self::REPEATED, $period, $now);

            if ($query !== null) {
                $repeats = $reader->read($query);
                $repeating = self::repeatingRoutes($repeats);
            }
        }

        $routes = array_map(static fn(ReportRow $row): array => self::route($row, isset($repeating[$row->key])), $listed);
        $other = $otherRow === null ? null : self::route($otherRow, false);

        $ids = [self::SERVER_ERRORS, self::SLOW];
        $reports = [self::SERVER_ERRORS => $report, self::SLOW => $report];

        $unread = $repeats !== null && ($repeats->other !== null || $repeats->keyCount > \count($repeats->rows));

        if ($repeats !== null && ($unread || array_filter($routes, static fn(array $route): bool => $route['nPlusOne']) !== [])) {
            $ids[] = self::REPEATED;
            $reports[self::REPEATED] = $repeats;
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, $ids, $reports);
        $totals = self::totals([...$report->rows, ...($report->other === null ? [] : [$report->other])]);
        $periodText = strtolower(Format::period($period));

        $top = \array_slice(self::sorted($routes, $sort, $direction), 0, self::LIMIT);
        $largest = max(array_map(static fn(array $route): float => $route['total'], [...$top, ...($other === null ? [] : [$other])]));
        $rows = array_map(static fn(array $route): Row => self::row($route, $largest, false), $top);
        if ($other !== null) {
            $rows[] = self::row($other, $largest, true);
        }
        $unmatched = array_filter($top, static fn(array $route): bool => $route['cell']->text === self::UNMATCHED) !== [];

        return new ScreenView(
            $this->cards($totals, $periodText),
            [new Table(
                'routes',
                'Routes',
                self::columns(),
                $rows,
                max(0, \count($routes) - self::LIMIT),
                'route',
                note: $sort === 'total' && $direction === 'desc' ? 'the most expensive first' : null,
                partsLegend: true,
                footnote: $unmatched ? self::UNMATCHED . ': requests that matched no route.' : null,
            )],
            self::lead($totals, $routes, $report->series, $period, $periodText),
            new CheckList(
                $this->checks->lines($findings, $this->id(), $period),
                'Nothing to flag',
                'No server error, no slow request and no route that repeats a query in the ' . $periodText . '.',
                'Worth a look',
            ),
            self::guide(),
            $raised,
        );
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
            new Column('split', 'Where the average goes', false),
            new Column('problems', 'Problems', false, 'problems'),
            new Column('memory', 'Memory', sort: 'memory'),
        ];
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
        $totals = ['requests' => 0, 'total' => 0.0, 'max' => 0.0, 'errors' => 0, 'slow' => 0];
        $counts = [];

        foreach ($rows as $row) {
            $totals['requests'] += $row->count(Metric::REQUEST_DURATION);
            $totals['total'] += $row->sum(Metric::REQUEST_DURATION);
            $totals['max'] = max($totals['max'], $row->max(Metric::REQUEST_DURATION));
            $totals['errors'] += $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx');
            $totals['slow'] += $row->count(Metric::REQUEST_SLOW);
            foreach ($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX) as $bound => $count) {
                $counts[$bound] = ($counts[$bound] ?? 0) + $count;
            }
        }

        return $totals + ['p95' => Percentile::estimate($counts, 0.95, $totals['max'])];
    }

    /**
     * @param Totals $totals
     *
     * @return list<Card>
     */
    private function cards(array $totals, string $periodText): array
    {
        $errorRate = $totals['requests'] === 0 ? 0.0 : $totals['errors'] / $totals['requests'];

        return [
            new Card('Average', Format::duration($totals['requests'] === 0 ? 0 : $totals['total'] / $totals['requests']), 'The mean response time.'),
            new Card('p95', Format::duration($totals['p95']), '95 % of requests are faster. What the unlucky visitors feel.'),
            new Card('Slowest', Format::duration($totals['max']), 'The single worst request of the ' . $periodText . '.'),
            new Card('Server errors', Format::percent($errorRate), 'Responses with a 5xx status: your code or a server failed.', $errorRate > 0 ? 'bad' : null),
            new Card('Slow requests', Format::number($totals['slow']), 'Took ' . Format::duration($this->slowThresholdMs) . ' or more. The limit is yours to set.', $totals['slow'] > 0 ? 'warn' : null),
        ];
    }

    /**
     * @param Totals      $totals
     * @param list<Route> $routes
     * @param list<int>   $series
     */
    private static function lead(array $totals, array $routes, array $series, Period $period, string $periodText): Lead
    {
        $sentence = $totals['requests'] === 1
            ? sprintf('1 request in the %s, answered in %s.', $periodText, Format::duration($totals['total']))
            : sprintf('%s requests in the %s, 95 %% answered in under %s.', Format::number($totals['requests']), $periodText, Format::duration($totals['p95']));

        $detail = match ($totals['errors']) {
            0 => 'No server error',
            1 => '1 server error',
            default => Format::number($totals['errors']) . ' server errors',
        } . ', ' . match ($totals['slow']) {
            0 => 'no slow request',
            1 => '1 slow request',
            default => Format::number($totals['slow']) . ' slow requests',
        } . '.';

        if (\count($routes) >= 2 && $totals['total'] > 0) {
            $detail .= ' The most expensive route takes ' . Format::percent(max(array_map(static fn(array $route): float => $route['total'], $routes)) / $totals['total']) . ' of the total time.';
        }

        return new Lead($sentence, $detail, $series, 'requests', Format::periodStart($period));
    }

    private static function guide(): Guide
    {
        return new Guide([
            new GuideItem('Total time.', 'Requests × average. The table is sorted by it because it shows where an improvement pays the most.'),
            new GuideItem('Avg, p95, max.', 'The average hides the bad moments. p95 leaves out the slowest 5 %, max is the single worst request.'),
            new GuideItem(
                'Where the average goes.',
                'What an average request of the route waits for: your PHP code, SQL, then calls to other sites. A long SQL part sends you to Queries, a long HTTP part to Cache & HTTP.',
                ['Queries' => 'queries', 'Cache & HTTP' => 'cache-http'],
            ),
        ]);
    }

    /** @return Route */
    private static function route(ReportRow $row, bool $nPlusOne): array
    {
        $requests = $row->count(Metric::REQUEST_DURATION);
        $total = $row->sum(Metric::REQUEST_DURATION);
        $max = $row->max(Metric::REQUEST_DURATION);
        $hasSql = $row->count(Metric::REQUEST_SQL_TIME) > 0;
        $hasHttp = $row->count(Metric::REQUEST_HTTP_TIME) > 0;

        return [
            'row' => $row,
            'cell' => Cell::route($row->key, true),
            'requests' => $requests,
            'total' => $total,
            'avg' => $total / $requests,
            'p95' => Percentile::estimate($row->countsByPrefix(Metric::REQUEST_DURATION_LE_PREFIX), 0.95, $max),
            'max' => $max,
            'sql' => $hasSql ? $row->sum(Metric::REQUEST_SQL_TIME) / $requests : 0.0,
            'queries' => $row->sum(Metric::REQUEST_SQL_COUNT) / $requests,
            'hasSql' => $hasSql,
            'http' => $hasHttp ? $row->sum(Metric::REQUEST_HTTP_TIME) / $requests : 0.0,
            'hasHttp' => $hasHttp,
            'errors' => $row->count(Metric::REQUEST_STATUS_PREFIX . '5xx'),
            'slow' => $row->count(Metric::REQUEST_SLOW),
            'nPlusOne' => $nPlusOne,
            'memory' => (int) $row->max(Metric::REQUEST_MEMORY),
        ];
    }

    /** @param Route $route */
    private static function row(array $route, float $largest, bool $other): Row
    {
        return new Row($route['row']->hash, [
            'route' => $other ? new Cell('Other routes') : $route['cell'],
            'requests' => new Cell(Format::number($route['requests'])),
            'total' => Cell::bar(Format::duration($route['total']), $largest > 0 ? $route['total'] / $largest : 0.0),
            'avg' => new Cell(Format::duration($route['avg'])),
            'p95' => new Cell(Format::duration($route['p95']), $route['slow'] * 20 > $route['requests'] ? 'warn' : null),
            'max' => new Cell(Format::duration($route['max']), $route['slow'] > 0 ? 'warn' : null),
            'split' => Cell::split(
                ['php' => max(0.0, $route['avg'] - $route['sql'] - $route['http']), 'sql' => $route['sql'], 'http' => $route['http']],
                self::splitText($route),
                self::queries($route) ?? '',
            ),
            'problems' => Cell::tags(self::tags($route)),
            'memory' => new Cell(Format::bytes($route['memory'])),
        ], [
            'data-jm-route' => $route['row']->key,
            'data-jm-requests' => (string) $route['requests'],
        ], $other);
    }

    /** @param Route $route */
    private static function splitText(array $route): string
    {
        if (!$route['hasSql'] && !$route['hasHttp']) {
            return 'PHP only';
        }

        $text = $route['hasSql'] && $route['sql'] >= $route['http']
            ? 'SQL ' . Format::duration($route['sql'])
            : 'HTTP out ' . Format::duration($route['http']);

        $queries = self::queries($route);

        return $queries === null ? $text : $text . ' · ' . $queries;
    }

    /** @param Route $route */
    private static function queries(array $route): ?string
    {
        if (!$route['hasSql']) {
            return null;
        }

        $queries = round($route['queries'], 1);

        return Format::number($queries, $queries < 10 ? 1 : 0) . ' queries';
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
            $tags[] = new Tag(Format::number($route['errors']) . ' × 5xx', 'bad');
        }

        if ($route['slow'] > 0) {
            $tags[] = new Tag(Format::number($route['slow']) . ' slow', 'warn');
        }

        if ($route['nPlusOne']) {
            $tags[] = new Tag('N+1', 'warn');
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
            'requests', 'avg', 'p95', 'max', 'memory' => $a[$sort] <=> $b[$sort],
            'problems' => [$a['errors'], $a['slow'], $a['nPlusOne']] <=> [$b['errors'], $b['slow'], $b['nPlusOne']],
            default => $a['total'] <=> $b['total'],
        };
    }
}
