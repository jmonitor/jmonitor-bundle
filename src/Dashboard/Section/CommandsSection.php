<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

/**
 * @phpstan-type Command array{row: ReportRow, runs: int, avg: float, max: float, failed: int, memory: int, last: int, sql: float, queries: float, hasSql: bool}
 */
final class CommandsSection implements SectionInterface
{
    private const LIMIT = 100;

    public function __construct(private readonly bool $available) {}

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
            'failed' => 'desc',
            'memory' => 'desc',
            'sql' => 'desc',
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
        $report = $reader->read(new ReportQuery($period, $now, Family::Command, seriesMetric: Metric::COMMAND_DURATION));

        $commands = self::sorted(array_map(self::command(...), $report->rows), $sort, $direction);
        $rows = array_map(static fn(array $command): Row => self::row($command, $now), \array_slice($commands, 0, self::LIMIT));
        if ($report->other !== null) {
            $rows[] = self::row(self::command($report->other), $now, true);
        }

        return new ScreenView(self::cards($report, $period), [new Table('commands', 'Commands', [
            new Column('command', 'Command', false, 'command'),
            new Column('runs', 'Runs', sort: 'runs'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('failed', 'Failed', sort: 'failed'),
            new Column('memory', 'Peak memory', sort: 'memory'),
            new Column('last', 'Last run', sort: 'last'),
            new Column('sql', 'SQL', sort: 'sql'),
        ], $rows, max(0, \count($commands) - self::LIMIT), 'command')]);
    }

    /** @return list<Card> */
    private static function cards(Report $report, Period $period): array
    {
        $runs = 0;
        $total = 0.0;
        $failed = 0;
        foreach ([...$report->rows, ...($report->other === null ? [] : [$report->other])] as $row) {
            $runs += $row->count(Metric::COMMAND_DURATION);
            $total += $row->sum(Metric::COMMAND_DURATION);
            $failed += $row->count(Metric::COMMAND_FAILED);
        }

        return [
            new Card('Runs', Format::number($runs), Format::period($period), series: $report->series, wide: true),
            new Card('Failed runs', Format::number($failed), 'Ended with a non-zero exit code', $failed > 0 ? 'bad' : null),
            new Card('Avg duration', Format::duration($runs === 0 ? 0 : $total / $runs), 'Mean run time'),
            new Card('Commands', Format::number($report->keyCount), 'Distinct commands'),
        ];
    }

    /** @return Command */
    private static function command(ReportRow $row): array
    {
        $runs = $row->count(Metric::COMMAND_DURATION);
        $perRun = static fn(float $sum): float => $runs === 0 ? 0.0 : $sum / $runs;

        return [
            'row' => $row,
            'runs' => $runs,
            'avg' => $perRun($row->sum(Metric::COMMAND_DURATION)),
            'max' => $row->max(Metric::COMMAND_DURATION),
            'failed' => $row->count(Metric::COMMAND_FAILED),
            'memory' => (int) $row->max(Metric::COMMAND_MEMORY),
            'last' => (int) $row->max(Metric::COMMAND_LAST_RUN),
            'sql' => $perRun($row->sum(Metric::COMMAND_SQL_TIME)),
            'queries' => $perRun($row->sum(Metric::COMMAND_SQL_COUNT)),
            'hasSql' => $row->count(Metric::COMMAND_SQL_TIME) > 0,
        ];
    }

    /** @param Command $command */
    private static function row(array $command, int $now, bool $other = false): Row
    {
        return new Row($command['row']->hash, [
            'command' => $other ? new Cell('Other commands') : new Cell($command['row']->key, kind: 'code'),
            'runs' => new Cell(Format::number($command['runs'])),
            'avg' => new Cell(Format::duration($command['avg'])),
            'max' => new Cell(Format::duration($command['max'])),
            'failed' => new Cell(Format::number($command['failed']), $command['failed'] > 0 ? 'bad' : 'zero'),
            'memory' => new Cell(Format::bytes($command['memory'])),
            'last' => new Cell(Format::ago($command['last'], $now)),
            'sql' => $command['hasSql'] ? new Cell(Format::sql($command['sql'], $command['queries'])) : new Cell('—', 'zero'),
        ], [
            'data-jm-command' => $command['row']->key,
            'data-jm-runs' => (string) $command['runs'],
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
            'runs', 'avg', 'max', 'failed', 'memory', 'sql' => $a[$sort] <=> $b[$sort],
            default => $a['last'] <=> $b['last'],
        } ?: strcmp($a['row']->key, $b['row']->key));

        return $commands;
    }
}
