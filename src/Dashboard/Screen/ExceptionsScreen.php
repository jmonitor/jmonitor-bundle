<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
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
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class ExceptionsScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const OTHER_EXCEPTIONS = 'Other exceptions';

    /** @var array<string, string> kind of place => the check of a failure there, in the order they are asked */
    private const CHECKS = [
        Entry::TYPE_REQUEST => 'requests.server_errors',
        Entry::TYPE_MESSAGE => 'messages.failed',
        Entry::TYPE_TASK => 'tasks.failed',
        Entry::TYPE_COMMAND => 'commands.failed',
    ];

    /** @var array<string, string> kind of place => its words in the lead, in the order they are told */
    private const KINDS = [
        Entry::TYPE_REQUEST => 'requests',
        Entry::TYPE_COMMAND => 'commands',
        Entry::TYPE_MESSAGE => 'messages',
        Entry::TYPE_TASK => 'scheduled tasks',
    ];

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'exceptions';
    }

    public function label(): string
    {
        return 'Exceptions';
    }

    public function heading(): string
    {
        return 'Errors per exception class';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function space(): Space
    {
        return Space::Application;
    }

    public function sorts(): array
    {
        return ['count' => 'desc', 'last' => 'desc', 'class' => 'asc'];
    }

    public function emptyState(): array
    {
        return [
            'title' => 'No exceptions recorded',
            'text' => 'An exception behind a 5xx response, a failed command or a failed message shows up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Exception, seriesMetric: Metric::EXCEPTION_COUNT));
        $where = $reader->read(new ReportQuery($period, $now, Family::ExceptionWhere));

        $classes = self::sorted($report->rows, $sort, $direction);

        if ($classes === [] && $report->other === null) {
            return new ScreenView([], [self::table([], 0)]);
        }

        $places = self::places($where);
        $rows = [];
        foreach (\array_slice($classes, 0, self::LIMIT) as $class) {
            $rows[] = self::row($class, $now, new Cell($class->key, kind: 'code'), ['data-jm-exception' => $class->key]);

            foreach ($places[$class->key] ?? [] as [$place, $key]) {
                $rows[] = self::row($place, $now, Cell::entryPoint($key->unitType, $key->unitKey), [
                    'data-jm-exception' => $class->key,
                    'data-jm-place' => $key->unitType . ' ' . ($key->unitType === Entry::TYPE_TASK ? TaskKey::parse($key->unitKey)->label ?? $key->unitKey : $key->unitKey),
                ], nested: true);
            }
        }
        if ($report->other !== null) {
            $rows[] = self::row($report->other, $now, new Cell(self::OTHER_EXCEPTIONS), ['data-jm-exception' => $report->other->key], muted: true);
        }

        $raised = $this->checks->raised($reader, $period, $now);
        [$kinds, $known] = self::kinds($where);
        $unread = $where->other !== null;
        $ids = array_values(array_filter(self::CHECKS, static fn(string $kind): bool => $unread || isset($kinds[$kind]), \ARRAY_FILTER_USE_KEY));
        $lines = $this->checks->lines($this->checks->findings($reader, $period, $now, $raised, $ids), $this->id(), $period);

        $count = $report->totals[Metric::EXCEPTION_COUNT]->count ?? 0;
        $classCount = $report->keyCount + ($report->other === null ? 0 : 1);

        return new ScreenView(
            self::cards($report, $where, $count, $classCount, $now),
            [self::table($rows, max(0, \count($classes) - self::LIMIT))],
            self::lead($report, $count, $classCount, $unread || !$known ? null : $kinds, $period),
            $lines === [] ? null : new CheckList($lines, title: 'Worth a look'),
            new Guide([
                new GuideItem('Class, then place.', 'The class says what went wrong, the place says where. One class in many places points to a shared cause, such as a service that is down.'),
                new GuideItem('New or usual?', 'Switch the period to 24h or 7d. An exception that appears there every hour is a known one; one that only shows in the last hour started recently.'),
                new GuideItem('An empty screen is the goal.', 'When nothing was thrown, this screen says so and shows nothing else.'),
            ]),
            $raised,
        );
    }

    /** @param list<Row> $rows */
    private static function table(array $rows, int $remaining): Table
    {
        return new Table(
            'exceptions',
            'By class',
            [
                new Column('exception', 'Exception', false, 'class'),
                new Column('count', 'Count', sort: 'count'),
                new Column('last', 'Last seen', sort: 'last'),
            ],
            $rows,
            $remaining,
            'class',
            'classes',
            'each with where it was thrown',
            footnote: 'JMonitor counts exceptions. It keeps no stack trace: the trace is in your logs or your error tracker.',
        );
    }

    /**
     * @return array<string, list<array{ReportRow, EntryPointKey}>> class => its places, the most counted first
     */
    private static function places(Report $where): array
    {
        $places = [];

        foreach (self::sorted($where->rows, 'count', 'desc', Metric::EXCEPTION_WHERE_COUNT) as $row) {
            $key = EntryPointKey::parse($row->key);

            if ($key !== null) {
                $places[$key->subject][] = [$row, $key];
            }
        }

        return $places;
    }

    /**
     * @return array{array<string, int>, bool} kind of place => exceptions thrown there, and whether every place read is of a known kind
     */
    private static function kinds(Report $where): array
    {
        $kinds = [];
        $known = true;

        foreach ($where->rows as $row) {
            $key = EntryPointKey::parse($row->key);

            if ($key === null || !isset(self::KINDS[$key->unitType])) {
                $known = false;

                continue;
            }

            if ($row->count(Metric::EXCEPTION_WHERE_COUNT) > 0) {
                $kinds[$key->unitType] = ($kinds[$key->unitType] ?? 0) + $row->count(Metric::EXCEPTION_WHERE_COUNT);
            }
        }

        return [$kinds, $known];
    }

    /** @param array<string, int>|null $kinds null when the places read do not tell them all */
    private static function lead(Report $report, int $count, int $classCount, ?array $kinds, Period $period): Lead
    {
        $periodText = strtolower(Format::period($period));

        $sentence = match (true) {
            $count === 1 => sprintf('1 exception in the %s.', $periodText),
            $report->other !== null && $report->keyCount === 0 => sprintf('%s exceptions in the %s.', Format::number($count), $periodText),
            $report->other !== null => sprintf('%s exceptions in the %s, of %s classes or more.', Format::number($count), $periodText, Format::number($classCount)),
            $classCount === 1 => sprintf('%s exceptions in the %s, all of one class.', Format::number($count), $periodText),
            default => sprintf('%s exceptions in the %s, of %s classes.', Format::number($count), $periodText, Format::number($classCount)),
        };

        $told = array_intersect_key(self::KINDS, $kinds ?? []);
        $detail = match (true) {
            $kinds === null || $told === [] => '',
            \count($told) === 1 => 'All thrown in ' . reset($told) . '.',
            default => implode(', ', array_map(static fn(string $kind, string $words): string => Format::number($kinds[$kind]) . ' in ' . $words, array_keys($told), $told)) . '.',
        };

        return new Lead($sentence, $detail, $report->series, 'exceptions', Format::periodStart($period));
    }

    /** @return list<Card> */
    private static function cards(Report $report, Report $where, int $count, int $classCount, int $now): array
    {
        $last = 0.0;
        foreach ([...$report->rows, ...($report->other === null ? [] : [$report->other])] as $row) {
            $last = max($last, $row->max(Metric::EXCEPTION_COUNT));
        }

        return [
            new Card('Exceptions', Format::number($count), 'Errors your code did not recover from.'),
            new Card('Classes', Format::number($classCount) . ($report->other === null ? '' : '+'), 'Distinct kinds of error.'),
            new Card(
                'Places',
                $where->other === null ? Format::number($where->keyCount) : Format::number($where->keyCount + 1) . '+',
                'Routes, commands, messages or tasks that threw one.',
            ),
            new Card('Last one', Format::ago((int) $last, $now), 'Still happening, or already over?'),
        ];
    }

    /** @param array<string, string> $attributes */
    private static function row(ReportRow $row, int $now, Cell $exception, array $attributes, bool $muted = false, bool $nested = false): Row
    {
        $metric = $nested ? Metric::EXCEPTION_WHERE_COUNT : Metric::EXCEPTION_COUNT;
        $count = $row->count($metric);

        return new Row($row->hash, [
            'exception' => $exception,
            'count' => new Cell(Format::number($count)),
            'last' => new Cell(Format::ago((int) $row->max($metric), $now)),
        ], $attributes + ['data-jm-count' => (string) $count], $muted, $nested);
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return list<ReportRow>
     */
    private static function sorted(array $rows, string $sort, string $direction, string $metric = Metric::EXCEPTION_COUNT): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * match ($sort) {
            'class' => strcmp($a->key, $b->key),
            'last' => $a->max($metric) <=> $b->max($metric),
            default => $a->count($metric) <=> $b->count($metric),
        } ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
