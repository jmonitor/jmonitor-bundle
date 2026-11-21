<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTask;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
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
 * Duration figures are -1 for a task that never ran, which sorts it below every task that did.
 *
 * @phpstan-type Task array{key: string, label: string, schedule: ?string, trigger: ?string, next: ?int, runs: int, total: float, avg: float, max: float, failed: int, last: int, lastFailed: int}
 */
final class SchedulerScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const NONE = '—';

    public function __construct(
        private readonly bool $available,
        private readonly DeclaredTasks $declared,
    ) {}

    public function id(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return 'Scheduler';
    }

    public function heading(): string
    {
        return 'Scheduled tasks, their last and next run';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return [
            'next' => 'asc',
            'task' => 'asc',
            'last' => 'desc',
            'runs' => 'desc',
            'avg' => 'desc',
            'max' => 'desc',
            'failed' => 'desc',
        ];
    }

    public function emptyState(): array
    {
        return [
            'title' => 'No scheduled tasks',
            'text' => 'Tasks declared in a schedule show up here with their next run, and their runs once a worker consumes the schedule.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Task, seriesMetric: Metric::TASK_DURATION));
        $declared = $this->declared->all($now);

        $tasks = [];
        foreach ($declared as $task) {
            $identity = (new TaskKey($task->schedule, $task->id, $task->label, $task->trigger))->identity();
            $tasks[$identity] = self::declaredTask($identity, $task);
        }
        foreach ($report->rows as $row) {
            $key = TaskKey::parse($row->key);
            $identity = $key?->identity() ?? $row->key;
            $ran = self::ranTask($key === null ? $row->hash : hash('xxh128', $identity), $row, $key);
            $tasks[$identity] = isset($tasks[$identity]) ? self::withRuns($tasks[$identity], $ran) : $ran;
        }

        $tasks = self::sorted(array_values($tasks), $sort, $direction);
        $rows = array_map(static fn(array $task): Row => self::row($task, $now), \array_slice($tasks, 0, self::LIMIT));
        $other = $report->other === null ? null : self::ranTask($report->other->hash, $report->other, null);
        if ($other !== null) {
            $rows[] = self::row($other, $now, true);
        }

        return new ScreenView(self::cards($declared, [...$tasks, ...($other === null ? [] : [$other])], $report->series, $period, $now), [new Table('tasks', 'Tasks', [
            new Column('task', 'Task', false, 'task'),
            new Column('schedule', 'Schedule', false),
            new Column('trigger', 'Trigger', false),
            new Column('next', 'Next run', sort: 'next'),
            new Column('last', 'Last run', sort: 'last'),
            new Column('runs', 'Runs', sort: 'runs'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('failed', 'Failed', sort: 'failed'),
        ], $rows, max(0, \count($tasks) - self::LIMIT), 'task')]);
    }

    /**
     * @param list<DeclaredTask> $declared
     * @param list<Task>         $tasks
     * @param list<int>          $series
     *
     * @return list<Card>
     */
    private static function cards(array $declared, array $tasks, array $series, Period $period, int $now): array
    {
        $runs = array_sum(array_column($tasks, 'runs'));
        $failed = array_sum(array_column($tasks, 'failed'));

        $next = null;
        foreach ($declared as $task) {
            if ($task->nextRun !== null && ($next === null || $task->nextRun < $next->nextRun)) {
                $next = $task;
            }
        }

        return [
            new Card('Runs', Format::number($runs), Format::period($period), series: $series, wide: true),
            new Card('Tasks', Format::number(\count($declared)), 'Declared in a schedule'),
            new Card('Failed runs', Format::number($failed), 'Ended with an exception', $failed > 0 ? 'bad' : null),
            $next === null
                ? new Card('Next run', self::NONE, 'No upcoming run')
                : new Card('Next run', Format::in($next->nextRun, $now), $next->label),
        ];
    }

    /** @return Task */
    private static function declaredTask(string $identity, DeclaredTask $task): array
    {
        return [
            'key' => hash('xxh128', $identity),
            'label' => $task->label,
            'schedule' => $task->schedule,
            'trigger' => $task->trigger,
            'next' => $task->nextRun,
            'runs' => 0,
            'total' => 0.0,
            'avg' => -1.0,
            'max' => -1.0,
            'failed' => 0,
            'last' => 0,
            'lastFailed' => 0,
        ];
    }

    /** @return Task */
    private static function ranTask(string $rowKey, ReportRow $row, ?TaskKey $key): array
    {
        $runs = $row->count(Metric::TASK_DURATION);
        $total = $row->sum(Metric::TASK_DURATION);

        return [
            'key' => $rowKey,
            'label' => $key->label ?? $row->key,
            'schedule' => $key?->schedule,
            'trigger' => $key?->trigger,
            'next' => null,
            'runs' => $runs,
            'total' => $total,
            'avg' => $runs === 0 ? -1.0 : $total / $runs,
            'max' => $runs === 0 ? -1.0 : $row->max(Metric::TASK_DURATION),
            'failed' => $row->count(Metric::TASK_FAILED),
            'last' => (int) $row->max(Metric::TASK_LAST_RUN),
            'lastFailed' => (int) $row->max(Metric::TASK_FAILED),
        ];
    }

    /**
     * @param Task $task
     * @param Task $ran
     *
     * @return Task
     */
    private static function withRuns(array $task, array $ran): array
    {
        $runs = $task['runs'] + $ran['runs'];
        $total = $task['total'] + $ran['total'];

        return [
            'runs' => $runs,
            'total' => $total,
            'avg' => $runs === 0 ? -1.0 : $total / $runs,
            'max' => max($task['max'], $ran['max']),
            'failed' => $task['failed'] + $ran['failed'],
            'last' => max($task['last'], $ran['last']),
            'lastFailed' => max($task['lastFailed'], $ran['lastFailed']),
        ] + $task;
    }

    /** @param Task $task */
    private static function row(array $task, int $now, bool $other = false): Row
    {
        $ran = $task['runs'] > 0;
        $none = new Cell(self::NONE, 'zero');
        $duration = static fn(float $ms): Cell => $ran ? new Cell(Format::duration($ms)) : $none;
        $lastFailed = $task['failed'] > 0 && $task['lastFailed'] >= $task['last'];

        return new Row($task['key'], [
            'task' => $other ? new Cell('Other tasks') : new Cell($task['label'], kind: 'code'),
            'schedule' => $task['schedule'] === null ? $none : new Cell($task['schedule']),
            'trigger' => $task['trigger'] === null ? $none : new Cell($task['trigger']),
            'next' => $task['next'] === null ? $none : new Cell(Format::in($task['next'], $now)),
            'last' => match (true) {
                !$ran => $none,
                $lastFailed => new Cell(Format::ago($task['last'], $now) . ' · failed', 'bad'),
                default => new Cell(Format::ago($task['last'], $now)),
            },
            'runs' => new Cell(Format::number($task['runs']), $ran ? null : 'zero'),
            'avg' => $duration($task['avg']),
            'max' => $duration($task['max']),
            'failed' => new Cell(Format::number($task['failed']), $task['failed'] > 0 ? 'bad' : 'zero'),
        ], [
            'data-jm-task' => $other ? Aggregator::OTHER : $task['label'],
            'data-jm-runs' => (string) $task['runs'],
        ], $other);
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<Task>
     */
    private static function sorted(array $tasks, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($tasks, static fn(array $a, array $b): int => $sign * match ($sort) {
            'task' => strcmp($a['label'], $b['label']),
            'last', 'runs', 'avg', 'max', 'failed' => $a[$sort] <=> $b[$sort],
            default => ($a['next'] ?? \PHP_INT_MAX) <=> ($b['next'] ?? \PHP_INT_MAX),
        } ?: strcmp($a['label'], $b['label']) ?: strcmp($a['key'], $b['key']));

        return $tasks;
    }
}
