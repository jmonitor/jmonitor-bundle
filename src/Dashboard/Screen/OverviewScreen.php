<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\RecordedScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Lead;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Box array{MapBox, list<string>}
 */
final class OverviewScreen implements RecordedScreenInterface
{
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';
    private const HTTP_4XX = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const HTTP_5XX = Metric::HTTP_STATUS_PREFIX . '5xx';
    private const ROUTES = 5;

    /** the types the boxes read beside those of the checks */
    private const MORE = [
        Metric::MESSAGE_DURATION,
        Metric::MESSAGE_SENT,
        Metric::TASK_DURATION,
        Metric::COMMAND_DURATION,
        Metric::EXCEPTION_COUNT,
        Metric::USER_DURATION,
        Metric::QUERY_DURATION,
        Metric::HTTP_DURATION,
    ];

    private const REQUEST_TYPES = [
        Metric::REQUEST_DURATION,
        Metric::REQUEST_SQL_TIME,
        Metric::REQUEST_SQL_COUNT,
        Metric::REQUEST_HTTP_TIME,
        self::SERVER_ERRORS,
        Metric::REQUEST_SLOW,
    ];

    /** the checks in the order of the boxes that tell them */
    private const CHECKS = [
        'requests.server_errors',
        'requests.slow',
        'messages.failed',
        'tasks.failed',
        'commands.failed',
        'queries.slow',
        'queries.repeated',
        'cache.low_hit_rate',
        'http.failures',
        'http.errors',
    ];

    /** @var array<string, SectionInterface> by id */
    private readonly array $sections;

    /** @var array<string, ScreenInterface> by id */
    private readonly array $screens;

    /**
     * @param iterable<SectionInterface> $sections those a box leads to
     * @param iterable<ScreenInterface>  $screens  those a box leads to
     */
    public function __construct(
        private readonly ApplicationChecks $checks,
        private readonly DeclaredTasks $tasks,
        iterable $sections,
        iterable $screens,
        private readonly ?StackGlanceInterface $stack = null,
    ) {
        $byId = [];
        foreach ($sections as $section) {
            $byId[$section->id()] = $section;
        }
        $this->sections = $byId;

        $byId = [];
        foreach ($screens as $screen) {
            $byId[$screen->id()] = $screen;
        }
        $this->screens = $byId;
    }

    public function id(): string
    {
        return 'overview';
    }

    public function label(): string
    {
        return 'Overview';
    }

    public function heading(): string
    {
        return 'What needs a look, then the map of your application';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function space(): Space
    {
        return Space::Overview;
    }

    public function read(ReaderInterface $reader, Period $period, int $now): OverviewRead
    {
        $raised = $this->checks->raised($reader, $period, $now, self::MORE);
        $requests = $reader->read(new ReportQuery($period, $now, Family::Request, seriesMetric: Metric::REQUEST_DURATION, metrics: self::REQUEST_TYPES));
        $findings = $this->checks->findings($reader, $period, $now, $raised, self::CHECKS, [
            'requests.server_errors' => $requests,
            'requests.slow' => $requests,
        ]);

        return new OverviewRead(
            $raised,
            $requests,
            $findings,
            $this->hasSection('tasks') ? \count($this->tasks->all($now)) : 0,
            [...self::MORE, Metric::CACHE_HIT, Metric::CACHE_MISS],
        );
    }

    public function stack(): ?StackGlance
    {
        return $this->stack?->glance();
    }

    /** @param ?OverviewRead $read null when the application could not be read */
    public function view(?OverviewRead $read, ?StackGlance $stack, Period $period): ScreenView
    {
        if ($read === null) {
            $lines = $stack->lines ?? [];

            return new ScreenView(
                checks: $lines === [] ? null : new CheckList($lines, 'Nothing to flag', '', 'Worth a look'),
                map: $stack === null ? null : new Map(runsOn: $stack),
                places: self::places(),
            );
        }

        $periodText = strtolower(Format::period($period));
        $incoming = array_values(array_filter([
            $this->requestsBox($read, $periodText),
            $this->messagesBox($read, $periodText),
            $this->tasksBox($read, $periodText),
            $this->commandsBox($read, $periodText),
        ]));
        $inside = array_values(array_filter([
            $this->exceptionsBox($read, $periodText),
            $this->usersBox($read, $periodText),
        ]));
        $outgoing = array_values(array_filter([
            $this->queriesBox($read, $periodText),
            $this->cacheBox($read, $periodText),
            $this->httpBox($read, $periodText),
        ]));

        $checked = [];
        foreach ([...$incoming, ...$inside, ...$outgoing] as [$box, $ids]) {
            if ($box->value !== null) {
                array_push($checked, ...$this->checks->passed($read->raised, $ids));
            }
        }
        $servers = $stack !== null && $stack->cards !== [];
        $healthy = $servers ? self::healthy($stack->cards) : null;

        $lines = self::ordered($this->checks->lines($read->findings, $this->id(), $period), $stack->lines ?? []);
        $requests = $read->requestCount();
        $empty = $read->isEmpty();

        return new ScreenView(
            [],
            self::routes($read),
            $empty ? self::firstRun($servers, $periodText) : self::lead($read, \count($lines), $period, $periodText),
            $empty && $lines === [] && !$servers ? null : new CheckList(
                $lines,
                'Nothing to flag',
                $lines !== [] ? '' : self::checkedNow($checked, $healthy, $periodText),
                'Worth a look',
                $lines === [] || ($checked === [] && $healthy === null) ? '' : 'Checked and fine: ' . implode(', ', [...$checked, ...($healthy === null ? [] : [$healthy])]) . '.',
            ),
            $empty ? null : self::guide($stack !== null),
            $read->raised,
            map: new Map(
                array_map(static fn(array $box): MapBox => $box[0], $incoming),
                self::averageBox($read),
                $requests === 0 ? null : RouteCells::averageSplit($read->requests->totals),
                array_map(static fn(array $box): MapBox => $box[0], $inside),
                $stack,
                array_map(static fn(array $box): MapBox => $box[0], $outgoing),
            ),
            places: $empty ? self::places() : null,
        );
    }

    /**
     * @param list<CheckLine> $application
     * @param list<CheckLine> $stack
     *
     * @return list<CheckLine> critical, then warning, then the rest, the application before the stack at each level
     */
    private static function ordered(array $application, array $stack): array
    {
        $rank = static fn(CheckLine $line): int => match ($line->mark->tone) {
            'bad' => 0,
            'warn' => 1,
            default => 2,
        };
        $lines = [];

        foreach ([0, 1, 2] as $level) {
            foreach ([$application, $stack] as $source) {
                foreach ($source as $line) {
                    if ($rank($line) === $level) {
                        $lines[] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $checked what the application passed in the period
     * @param ?string      $healthy the healthy components, read now
     */
    private static function checkedNow(array $checked, ?string $healthy, string $periodText): string
    {
        $sentences = [];

        if ($checked !== []) {
            $sentences[] = ucfirst(implode(', ', $checked)) . ' in the ' . $periodText . '.';
        }

        if ($healthy !== null) {
            $sentences[] = ucfirst($healthy) . ' right now.';
        }

        return implode(' ', $sentences);
    }

    /** @param non-empty-list<ComponentCard> $cards */
    private static function healthy(array $cards): string
    {
        $healthy = \count(array_filter($cards, static fn(ComponentCard $card): bool => $card->mark->tone === 'ok'));
        $count = \count($cards);

        return sprintf('%d of %d stack %s healthy', $healthy, $count, $count === 1 ? 'component' : 'components');
    }

    private static function lead(OverviewRead $read, int $lines, Period $period, string $periodText): Lead
    {
        $errors = $read->count(self::SERVER_ERRORS);
        $requests = max($read->requestCount(), $errors);

        if ($errors > 0) {
            $first = sprintf('%s in %s.', self::plural($errors, 'server error', 'server errors'), self::plural($requests, 'request', 'requests'));
        } elseif ($requests > 0) {
            $first = 'Your application answers normally.';
        } else {
            $first = 'No request in the ' . $periodText . '.';
        }

        $second = match ($lines) {
            0 => 'Nothing is worth a look.',
            1 => '1 thing is worth a look.',
            default => Format::number($lines) . ' things are worth a look.',
        };

        return new Lead(
            $first . ' ' . $second,
            Format::period($period) . ($lines === 0 ? '. Follow any box to its detail.' : '. Start with the list below, then follow any box to its detail.'),
        );
    }

    private static function firstRun(bool $servers, string $periodText): Lead
    {
        return new Lead(
            'JMonitor is installed and recording.',
            'Nothing was recorded in the ' . $periodText . '. The figures fill in as your application receives requests.' . ($servers ? ' Your servers are already read.' : ''),
        );
    }

    private static function places(): Places
    {
        return new Places([
            new Place('Overview', '', 'This page. What needs a look comes first.', 'Is everything fine?'),
            new Place('Application', 'requests', 'Requests, SQL queries, cache, outgoing HTTP, exceptions, users.', 'What is slow, and why?'),
            new Place('Background', 'background', 'Messenger messages, scheduled tasks and console commands.', 'Did the work behind the scenes run?'),
            new Place('Stack', 'stack', 'The server, PHP, Symfony, the web server, the databases and Redis.', 'Are the servers healthy right now?'),
        ]);
    }

    /** @return list<Table> */
    private static function routes(OverviewRead $read): array
    {
        $routes = array_values(array_filter($read->requests->rows, static fn(ReportRow $row): bool => $row->count(Metric::REQUEST_DURATION) > 0));

        if ($routes === []) {
            return [];
        }

        usort($routes, static fn(ReportRow $a, ReportRow $b): int => $b->sum(Metric::REQUEST_DURATION) <=> $a->sum(Metric::REQUEST_DURATION) ?: strcmp($a->key, $b->key));
        $shown = \array_slice($routes, 0, self::ROUTES);
        $largest = max(array_map(static fn(ReportRow $row): float => $row->sum(Metric::REQUEST_DURATION), $shown));
        $remaining = \count($routes) - \count($shown);

        return [new Table(
            'routes',
            'Where the time goes',
            [
                new Column('route', 'Route', false),
                new Column('requests', 'Requests'),
                new Column('total', 'Total time'),
                new Column('split', 'Where the average goes', false),
            ],
            array_map(static fn(ReportRow $row): Row => new Row($row->hash, [
                'route' => RouteCells::route($row),
                'requests' => RouteCells::requests($row),
                'total' => RouteCells::total($row, $largest),
                'split' => RouteCells::split($row),
            ], [
                'data-jm-route' => $row->key,
                'data-jm-requests' => (string) $row->count(Metric::REQUEST_DURATION),
            ]), $shown),
            $remaining,
            'route',
            note: 'the most expensive first',
            partsLegend: true,
        )];
    }

    private static function guide(bool $withStack): Guide
    {
        return new Guide([
            new GuideItem('Read it left to right.', 'What starts your code, what your code does with the time, then what it waits for. A box with a tag needs a look.'),
            new GuideItem('Total time beats speed.', 'A fast route called often can cost more than a slow one called once. That is where an improvement pays.', ['Requests' => 'requests']),
            ...($withStack ? [new GuideItem('Two clocks.', 'Traffic figures cover the period chosen at the top. The servers are read as they are right now.')] : []),
        ]);
    }

    private static function averageBox(OverviewRead $read): MapBox
    {
        $totals = $read->requests->totals;
        $requests = $totals[Metric::REQUEST_DURATION]->count ?? 0;

        if ($requests === 0) {
            return new MapBox('An average request', null, 'This bar will split the time between your PHP code, SQL and outgoing HTTP.');
        }

        $text = '';

        if (($totals[Metric::REQUEST_SQL_TIME]->count ?? 0) > 0) {
            $queries = round(($totals[Metric::REQUEST_SQL_COUNT]->sum ?? 0.0) / $requests, 1);
            $text = match (true) {
                $queries <= 0.0 => '',
                $queries === 1.0 => '1 SQL query per request.',
                default => Format::number($queries, $queries < 10 ? 1 : 0) . ' SQL queries per request.',
            };
        }

        return new MapBox('An average request', Format::duration(($totals[Metric::REQUEST_DURATION]->sum ?? 0.0) / $requests), $text);
    }

    /** @return Box */
    private function requestsBox(OverviewRead $read, string $periodText): array
    {
        $requests = $read->requestCount();
        $errors = $read->count(self::SERVER_ERRORS);
        $slow = $read->count(Metric::REQUEST_SLOW);
        $tags = [
            ...($errors > 0 ? [new Tag(Format::number($errors) . ' × 5xx', 'bad')] : []),
            ...($slow > 0 ? [new Tag(Format::number($slow) . ' slow', 'warn')] : []),
        ];

        return [
            self::box(
                'Requests',
                $requests,
                $tags === [] ? 'No server error, no slow request.' : '',
                'No request in the ' . $periodText . '. Open a page of your application, then come back.',
                $tags,
                'requests',
                series: $read->requests->series,
            ),
            ['requests.server_errors', 'requests.slow'],
        ];
    }

    /** @return ?Box */
    private function messagesBox(OverviewRead $read, string $periodText): ?array
    {
        $messages = $this->sections['messages'] ?? null;

        if (!$messages instanceof MessagesSection || !$messages->recordsMessages()) {
            return null;
        }

        return [
            self::box(
                'Messages',
                $read->count(Metric::MESSAGE_DURATION),
                'Handled by Messenger, ' . Format::number($read->count(Metric::MESSAGE_SENT)) . ' sent.',
                'No message handled in the ' . $periodText . '.',
                self::failed($read->count(Metric::MESSAGE_FAILED)),
                'background',
                'messages',
            ),
            ['messages.failed'],
        ];
    }

    /** @return ?Box */
    private function tasksBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasSection('tasks')) {
            return null;
        }

        $runs = $read->count(Metric::TASK_DURATION);
        $declared = $read->declaredTasks;
        $tasks = $declared === 1 ? 'task' : 'tasks';

        return [
            self::box(
                'Scheduled tasks',
                $runs,
                $declared === 0 ? 'Scheduled runs.' : sprintf('%s of %s declared %s.', $runs === 1 ? 'Run' : 'Runs', Format::number($declared), $tasks),
                $declared === 0 ? 'No scheduled run in the ' . $periodText . '.' : sprintf('%s %s declared, none ran in the %s.', Format::number($declared), $tasks, $periodText),
                self::failed($read->count(Metric::TASK_FAILED)),
                'background',
                'tasks',
            ),
            ['tasks.failed'],
        ];
    }

    /** @return ?Box */
    private function commandsBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasSection('commands')) {
            return null;
        }

        return [
            self::box(
                'Commands',
                $read->count(Metric::COMMAND_DURATION),
                'Console runs.',
                'No command ran in the ' . $periodText . '.',
                self::failed($read->count(Metric::COMMAND_FAILED)),
                'background',
                'commands',
            ),
            ['commands.failed'],
        ];
    }

    /** @return ?Box */
    private function exceptionsBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasScreen('exceptions')) {
            return null;
        }

        $count = $read->count(Metric::EXCEPTION_COUNT);

        return [self::box('Exceptions', $count, $count === 1 ? 'exception thrown.' : 'exceptions thrown.', 'No exception in the ' . $periodText . '.', [], 'exceptions'), []];
    }

    /** @return ?Box */
    private function usersBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasScreen('users')) {
            return null;
        }

        $count = $read->count(Metric::USER_DURATION);

        return [
            self::box('Signed-in users', $count, $count === 1 ? 'request made by signed-in users.' : 'requests made by signed-in users.', 'No request from a signed-in user in the ' . $periodText . '.', [], 'users'),
            [],
        ];
    }

    /** @return ?Box */
    private function queriesBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasScreen('queries')) {
            return null;
        }

        $slow = $read->count(Metric::QUERY_SLOW);
        $total = Format::duration($read->total(Metric::QUERY_DURATION)->sum);
        $tags = [
            ...($slow > 0 ? [new Tag(Format::number($slow) . ' slow', 'warn')] : []),
            ...($read->raised->has('queries.repeated') ? [new Tag('N+1', 'warn')] : []),
        ];

        return [
            self::box(
                'SQL queries',
                $read->count(Metric::QUERY_DURATION),
                $slow > 0 ? $total . ' in total.' : $total . ' in total, none slow.',
                'No SQL query in the ' . $periodText . '.',
                $tags,
                'queries',
            ),
            ['queries.slow', 'queries.repeated'],
        ];
    }

    /** @return ?Box */
    private function cacheBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasSection('cache')) {
            return null;
        }

        $hits = $read->count(Metric::CACHE_HIT);
        $reads = $hits + $read->count(Metric::CACHE_MISS);

        return [
            self::box(
                'Cache',
                $reads,
                Format::percent($reads === 0 ? 0.0 : $hits / $reads) . ' found in the cache.',
                'No cache read in the ' . $periodText . '.',
                $read->raised->has('cache.low_hit_rate') ? [new Tag('low hit rate', 'warn')] : [],
                'cache-http',
                'cache',
            ),
            ['cache.low_hit_rate'],
        ];
    }

    /** @return ?Box */
    private function httpBox(OverviewRead $read, string $periodText): ?array
    {
        if (!$this->hasSection('http')) {
            return null;
        }

        $calls = $read->total(Metric::HTTP_DURATION);
        $failed = $read->count(Metric::HTTP_FAILED);
        $server = $read->count(self::HTTP_5XX);
        $client = $read->count(self::HTTP_4XX);

        return [
            self::box(
                'Other sites and APIs',
                $calls->count,
                Format::duration($calls->count === 0 ? 0.0 : $calls->sum / $calls->count) . ' per call on average.',
                'No outgoing call in the ' . $periodText . '.',
                [
                    ...($failed > 0 ? [new Tag(Format::number($failed) . ' no answer', 'bad')] : []),
                    ...($server > 0 ? [new Tag(Format::number($server) . ' × 5xx', 'bad')] : []),
                    ...($client > 0 ? [new Tag(Format::number($client) . ' × 4xx', 'warn')] : []),
                ],
                'cache-http',
                'http',
            ),
            ['http.failures', 'http.errors'],
        ];
    }

    /**
     * @param string    $pending its text while it counts nothing
     * @param list<Tag> $tags
     * @param list<int> $series
     */
    private static function box(string $label, int $count, string $text, string $pending, array $tags, string $path, ?string $anchor = null, array $series = []): MapBox
    {
        if ($count <= 0) {
            return new MapBox($label, null, $pending, path: $path, anchor: $anchor);
        }

        $tones = array_map(static fn(Tag $tag): string => $tag->tone, $tags);
        $tone = \in_array('bad', $tones, true) ? 'bad' : (\in_array('warn', $tones, true) ? 'warn' : null);

        return new MapBox($label, Format::number($count), $text, $tags, $path, $anchor, $tone, $series);
    }

    /** @return list<Tag> */
    private static function failed(int $count): array
    {
        return $count > 0 ? [new Tag(Format::number($count) . ' failed', 'bad')] : [];
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? '1 ' . $singular : Format::number($count) . ' ' . $plural;
    }

    private function hasSection(string $id): bool
    {
        return isset($this->sections[$id]) && $this->sections[$id]->isAvailable();
    }

    private function hasScreen(string $id): bool
    {
        return isset($this->screens[$id]) && $this->screens[$id]->isAvailable();
    }
}
