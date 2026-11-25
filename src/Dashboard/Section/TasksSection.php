<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTask;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Brief;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckList;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * The average duration is -1 for a task that never ran, which sorts it below every task that did.
 *
 * @phpstan-type Task array{key: string, label: string, trigger: ?string, next: ?int, runs: int, total: float, avg: float, failed: int, last: int, lastFailed: int}
 */
final class TasksSection implements SectionInterface
{
    private const LIMIT = 100;
    private const NONE = '—';
    private const SCREEN = 'background';
    private const CHECK = 'tasks.failed';

    public function __construct(
        private readonly bool $available,
        private readonly DeclaredTasks $declared,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'tasks';
    }

    public function title(): string
    {
        return 'Scheduled tasks';
    }

    public function summary(): string
    {
        return 'What Symfony Scheduler starts by the clock.';
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
            'problems' => 'desc',
        ];
    }

    public function emptyState(Period $period): array
    {
        return [
            'title' => 'No scheduled task is declared.',
            'text' => 'Tasks declared in a schedule show up here with their next run, and their runs once a worker consumes the schedule.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Task));
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

        $table = new Table('tasks', 'Tasks', [
            new Column('task', 'Task', false, 'task'),
            new Column('schedule', 'Schedule', false),
            new Column('next', 'Next run', sort: 'next'),
            new Column('last', 'Last run', sort: 'last'),
            new Column('runs', 'Runs', sort: 'runs'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('problems', 'Problems', false, 'problems'),
        ], $rows, max(0, \count($tasks) - self::LIMIT), 'task');

        if ($rows === []) {
            return new ScreenView([], [$table]);
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::CHECK], [self::CHECK => $report]);

        return new ScreenView(
            [],
            [$table],
            checks: new CheckList($this->checks->lines($findings, self::SCREEN, $period), emptyText: 'no failed scheduled run'),
            guide: new Guide([
                new GuideItem('A task with 0 run.', 'Normal for a daily task seen over one hour. Switch the period to 24h or 7d: if it still shows no run, no worker consumes the schedule.'),
            ]),
            brief: self::brief(\count($declared), [...$tasks, ...($other === null ? [] : [$other])]),
        );
    }

    /** @param list<Task> $tasks */
    private static function brief(int $declared, array $tasks): ?Brief
    {
        $runs = array_sum(array_column($tasks, 'runs'));
        $failed = array_sum(array_column($tasks, 'failed'));

        if ($runs === 0) {
            return null;
        }

        $noun = $runs === 1 ? 'run' : 'runs';
        $figures = match ($declared) {
            0 => $noun,
            1 => $noun . ' of 1 declared task',
            default => $noun . ' of ' . Format::number($declared) . ' declared tasks',
        };

        return new Brief(
            Format::number($runs),
            $runs === 1 ? '1 scheduled run' : Format::number($runs) . ' scheduled runs',
            $failed > 0 ? $figures . ', ' . Format::number($failed) . ' failed' : $figures,
            match ($failed) {
                0 => '',
                1 => '1 scheduled run failed.',
                default => Format::number($failed) . ' scheduled runs failed.',
            },
            $failed > 0 ? 'bad' : null,
        );
    }

    /** @return Task */
    private static function declaredTask(string $identity, DeclaredTask $task): array
    {
        return [
            'key' => hash('xxh128', $identity),
            'label' => $task->label,
            'trigger' => $task->trigger,
            'next' => $task->nextRun,
            'runs' => 0,
            'total' => 0.0,
            'avg' => -1.0,
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
            'trigger' => $key?->trigger,
            'next' => null,
            'runs' => $runs,
            'total' => $total,
            'avg' => $runs === 0 ? -1.0 : $total / $runs,
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
        $lastFailed = $task['failed'] > 0 && $task['lastFailed'] >= $task['last'];

        return new Row($task['key'], [
            'task' => $other ? new Cell('Other tasks') : new Cell($task['label'], kind: 'code'),
            'schedule' => $task['trigger'] === null ? $none : new Cell($task['trigger']),
            'next' => $task['next'] === null ? $none : new Cell(Format::in($task['next'], $now)),
            'last' => match (true) {
                !$ran => $none,
                $lastFailed => new Cell(Format::ago($task['last'], $now) . ' · failed', 'bad'),
                default => new Cell(Format::ago($task['last'], $now)),
            },
            'runs' => new Cell(Format::number($task['runs']), $ran ? null : 'zero'),
            'avg' => $ran ? new Cell(Format::duration($task['avg'])) : $none,
            'problems' => Cell::tags($task['failed'] > 0 ? [new Tag(Format::number($task['failed']) . ' failed', 'bad')] : []),
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
            'last', 'runs', 'avg' => $a[$sort] <=> $b[$sort],
            'problems' => $a['failed'] <=> $b['failed'],
            default => ($a['next'] ?? \PHP_INT_MAX) <=> ($b['next'] ?? \PHP_INT_MAX),
        } ?: strcmp($a['label'], $b['label']) ?: strcmp($a['key'], $b['key']));

        return $tasks;
    }
}
