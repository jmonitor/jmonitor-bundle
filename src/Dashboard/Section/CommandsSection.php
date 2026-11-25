<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
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
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Command array{row: ReportRow, runs: int, avg: float, max: float, failed: int, memory: int, last: int}
 */
final class CommandsSection implements SectionInterface
{
    private const LIMIT = 100;
    private const SCREEN = 'background';
    private const CHECK = 'commands.failed';

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'commands';
    }

    public function title(): string
    {
        return 'Commands';
    }

    public function summary(): string
    {
        return 'Runs of bin/console, by cron, a deploy or by hand.';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return [
            'last' => 'desc',
            'command' => 'asc',
            'runs' => 'desc',
            'avg' => 'desc',
            'max' => 'desc',
            'problems' => 'desc',
            'memory' => 'desc',
        ];
    }

    public function emptyState(Period $period): array
    {
        return [
            'title' => 'No command ran in the ' . lcfirst(Format::period($period)) . '.',
            'text' => 'Run a console command: it shows up here as soon as it ends. Internal commands such as cache:clear and messenger:consume are not recorded.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        $report = $reader->read(new ReportQuery($period, $now, Family::Command));

        $commands = self::sorted(array_map(self::command(...), $report->rows), $sort, $direction);
        $rows = array_map(static fn(array $command): Row => self::row($command, $now), \array_slice($commands, 0, self::LIMIT));
        if ($report->other !== null) {
            $rows[] = self::row(self::command($report->other), $now, true);
        }

        $table = new Table('commands', 'Commands', [
            new Column('command', 'Command', false, 'command'),
            new Column('runs', 'Runs', sort: 'runs'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('last', 'Last run', sort: 'last'),
            new Column('split', 'Where the average goes', false),
            new Column('problems', 'Problems', false, 'problems'),
            new Column('memory', 'Memory', sort: 'memory'),
        ], $rows, max(0, \count($commands) - self::LIMIT), 'command', partsLegend: true);

        if ($rows === []) {
            return new ScreenView([], [$table]);
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::CHECK], [self::CHECK => $report]);

        return new ScreenView(
            [],
            [$table],
            checks: new CheckList($this->checks->lines($findings, self::SCREEN, $period), emptyText: 'no failed command run'),
            guide: new Guide([
                new GuideItem('Commands.', 'Internal commands such as cache:clear and messenger:consume are not recorded.'),
            ]),
            brief: self::brief($report),
        );
    }

    private static function brief(Report $report): ?Brief
    {
        $runs = 0;
        $failed = 0;
        foreach ([...$report->rows, ...($report->other === null ? [] : [$report->other])] as $row) {
            $runs += $row->count(Metric::COMMAND_DURATION);
            $failed += $row->count(Metric::COMMAND_FAILED);
        }

        if ($runs === 0) {
            return null;
        }

        $figures = ($runs === 1 ? 'run' : 'runs') . ' of ' . ($report->keyCount === 1 ? '1 command' : Format::number($report->keyCount) . ' commands');

        return new Brief(
            Format::number($runs),
            $runs === 1 ? '1 command run' : Format::number($runs) . ' command runs',
            $failed > 0 ? $figures . ', ' . Format::number($failed) . ' failed' : $figures,
            match ($failed) {
                0 => '',
                1 => '1 command run failed.',
                default => Format::number($failed) . ' command runs failed.',
            },
            $failed > 0 ? 'bad' : null,
        );
    }

    /** @return Command */
    private static function command(ReportRow $row): array
    {
        $runs = $row->count(Metric::COMMAND_DURATION);

        return [
            'row' => $row,
            'runs' => $runs,
            'avg' => $runs === 0 ? 0.0 : $row->sum(Metric::COMMAND_DURATION) / $runs,
            'max' => $row->max(Metric::COMMAND_DURATION),
            'failed' => $row->count(Metric::COMMAND_FAILED),
            'memory' => (int) $row->max(Metric::COMMAND_MEMORY),
            'last' => (int) $row->max(Metric::COMMAND_LAST_RUN),
        ];
    }

    /** @param Command $command */
    private static function row(array $command, int $now, bool $other = false): Row
    {
        $row = $command['row'];
        $runs = $command['runs'];

        return new Row($row->hash, [
            'command' => $other ? new Cell('Other commands') : new Cell($row->key, kind: 'code'),
            'runs' => new Cell(Format::number($runs)),
            'avg' => new Cell(Format::duration($command['avg'])),
            'max' => new Cell(Format::duration($command['max'])),
            'last' => new Cell(Format::ago($command['last'], $now)),
            'split' => $runs === 0 ? new Cell('') : Cell::averageSplit(
                $command['avg'],
                $row->count(Metric::COMMAND_SQL_TIME) > 0 ? $row->sum(Metric::COMMAND_SQL_TIME) / $runs : null,
                $row->sum(Metric::COMMAND_SQL_COUNT) / $runs,
                $row->count(Metric::COMMAND_HTTP_TIME) > 0 ? $row->sum(Metric::COMMAND_HTTP_TIME) / $runs : null,
            ),
            'problems' => Cell::tags($command['failed'] > 0 ? [new Tag(Format::number($command['failed']) . ' failed', 'bad')] : []),
            'memory' => new Cell(Format::bytes($command['memory'])),
        ], [
            'data-jm-command' => $row->key,
            'data-jm-runs' => (string) $runs,
        ], $other);
    }

    /**
     * @param list<Command> $commands
     *
     * @return list<Command>
     */
    private static function sorted(array $commands, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($commands, static fn(array $a, array $b): int => $sign * match ($sort) {
            'command' => strcmp($a['row']->key, $b['row']->key),
            'runs', 'avg', 'max', 'memory' => $a[$sort] <=> $b[$sort],
            'problems' => $a['failed'] <=> $b['failed'],
            default => $a['last'] <=> $b['last'],
        } ?: strcmp($a['row']->key, $b['row']->key));

        return $commands;
    }
}
