<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\RecordedScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\Alerts;
use Jmonitor\JmonitorBundle\Dashboard\View\Map;
use Jmonitor\JmonitorBundle\Dashboard\View\MapBox;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Place;
use Jmonitor\JmonitorBundle\Dashboard\View\Places;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Dashboard\View\Status;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;

final class OverviewScreen implements RecordedScreenInterface
{
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';
    private const HTTP_4XX = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const HTTP_5XX = Metric::HTTP_STATUS_PREFIX . '5xx';

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
        return new OverviewRead(
            $this->checks->raised($reader, $period, $now, self::MORE),
            $reader->read(new ReportQuery($period, $now, Family::Request, seriesMetric: Metric::REQUEST_DURATION, metrics: [Metric::REQUEST_DURATION])),
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
            return new ScreenView(
                map: $stack === null ? null : new Map(runsOn: $stack),
                places: self::places(),
                status: new Status($this->label()),
                alerts: self::alerts([], $stack->alerts ?? []),
            );
        }

        $empty = $read->isEmpty();

        return new ScreenView(
            raised: $read->raised,
            map: new Map(
                array_values(array_filter([
                    $this->requestsBox($read),
                    $this->messagesBox($read),
                    $this->tasksBox($read),
                    $this->commandsBox($read),
                ])),
                array_values(array_filter([
                    $this->exceptionsBox($read),
                    $this->usersBox($read),
                ])),
                $stack,
                array_values(array_filter([
                    $this->queriesBox($read),
                    $this->cacheBox($read),
                    $this->httpBox($read),
                ])),
            ),
            places: $empty ? self::places() : null,
            status: new Status($this->label(), self::pill($read, $empty)),
            alerts: self::alerts($this->checks->alerts($read->raised, self::CHECKS, $period), $stack->alerts ?? []),
        );
    }

    /**
     * @param list<Alert> $application
     * @param list<Alert> $stack
     *
     * @return ?Alerts critical, then warning, the application before the stack at each level
     */
    private static function alerts(array $application, array $stack): ?Alerts
    {
        $rank = static fn(Alert $alert): int => match ($alert->mark->tone) {
            'bad' => 0,
            'warn' => 1,
            default => 2,
        };
        $alerts = [];

        foreach ([0, 1] as $level) {
            foreach ([$application, $stack] as $source) {
                foreach ($source as $alert) {
                    if ($rank($alert) === $level) {
                        $alerts[] = $alert;
                    }
                }
            }
        }

        return $alerts === [] ? null : new Alerts($alerts);
    }

    private static function pill(OverviewRead $read, bool $empty): Mark
    {
        $errors = $read->count(self::SERVER_ERRORS);

        return match (true) {
            $errors > 0 => new Mark('bad', $errors === 1 ? '1 server error' : Format::number($errors) . ' server errors'),
            $read->requestCount() > 0 => new Mark('ok', 'Answers normally'),
            !$empty => new Mark('unknown', 'No request'),
            default => new Mark('unknown', 'No data yet'),
        };
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

    private function requestsBox(OverviewRead $read): MapBox
    {
        $errors = $read->count(self::SERVER_ERRORS);
        $slow = $read->count(Metric::REQUEST_SLOW);

        return self::box(
            'Requests',
            $read->requestCount(),
            [
                ...($errors > 0 ? [new Tag(Format::number($errors) . ' × 5xx', 'bad')] : []),
                ...($slow > 0 ? [new Tag(Format::number($slow) . ' slow', 'warn')] : []),
            ],
            'requests',
            series: $read->requests->series,
        );
    }

    private function messagesBox(OverviewRead $read): ?MapBox
    {
        $messages = $this->sections['messages'] ?? null;

        if (!$messages instanceof MessagesSection || !$messages->recordsMessages()) {
            return null;
        }

        return self::box('Messages', $read->count(Metric::MESSAGE_DURATION), self::failed($read->count(Metric::MESSAGE_FAILED)), 'background', 'messages');
    }

    private function tasksBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasSection('tasks')) {
            return null;
        }

        return self::box('Scheduled tasks', $read->count(Metric::TASK_DURATION), self::failed($read->count(Metric::TASK_FAILED)), 'background', 'tasks');
    }

    private function commandsBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasSection('commands')) {
            return null;
        }

        return self::box('Commands', $read->count(Metric::COMMAND_DURATION), self::failed($read->count(Metric::COMMAND_FAILED)), 'background', 'commands');
    }

    private function exceptionsBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasScreen('exceptions')) {
            return null;
        }

        return self::box('Exceptions', $read->count(Metric::EXCEPTION_COUNT), [], 'exceptions');
    }

    private function usersBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasScreen('users')) {
            return null;
        }

        return self::box('User requests', $read->count(Metric::USER_DURATION), [], 'users');
    }

    private function queriesBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasScreen('queries')) {
            return null;
        }

        $slow = $read->count(Metric::QUERY_SLOW);

        return self::box(
            'SQL queries',
            $read->count(Metric::QUERY_DURATION),
            [
                ...($slow > 0 ? [new Tag(Format::number($slow) . ' slow', 'warn')] : []),
                ...($read->raised->has('queries.repeated') ? [new Tag('N+1', 'warn')] : []),
            ],
            'queries',
        );
    }

    private function cacheBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasSection('cache')) {
            return null;
        }

        return self::box(
            'Cache',
            $read->count(Metric::CACHE_HIT) + $read->count(Metric::CACHE_MISS),
            $read->raised->has('cache.low_hit_rate') ? [new Tag('low hit rate', 'warn')] : [],
            'cache-http',
            'cache',
        );
    }

    private function httpBox(OverviewRead $read): ?MapBox
    {
        if (!$this->hasSection('http')) {
            return null;
        }

        $failed = $read->count(Metric::HTTP_FAILED);
        $server = $read->count(self::HTTP_5XX);
        $client = $read->count(self::HTTP_4XX);

        return self::box(
            'Other sites and APIs',
            $read->count(Metric::HTTP_DURATION),
            [
                ...($failed > 0 ? [new Tag(Format::number($failed) . ' no answer', 'bad')] : []),
                ...($server > 0 ? [new Tag(Format::number($server) . ' × 5xx', 'bad')] : []),
                ...($client > 0 ? [new Tag(Format::number($client) . ' × 4xx', 'warn')] : []),
            ],
            'cache-http',
            'http',
        );
    }

    /**
     * @param list<Tag> $tags
     * @param list<int> $series
     */
    private static function box(string $label, int $count, array $tags, string $path, ?string $anchor = null, array $series = []): MapBox
    {
        if ($count <= 0) {
            return new MapBox($label, '0', path: $path, anchor: $anchor);
        }

        $tones = array_map(static fn(Tag $tag): string => $tag->tone, $tags);
        $tone = \in_array('bad', $tones, true) ? 'bad' : (\in_array('warn', $tones, true) ? 'warn' : null);

        return new MapBox($label, Format::number($count), $tags, $path, $anchor, $tone, $series);
    }

    /** @return list<Tag> */
    private static function failed(int $count): array
    {
        return $count > 0 ? [new Tag(Format::number($count) . ' failed', 'bad')] : [];
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
