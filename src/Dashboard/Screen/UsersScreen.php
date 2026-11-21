<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\StorageScreenInterface;
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

final class UsersScreen implements StorageScreenInterface
{
    private const LIMIT = 100;

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'requests' => [Metric::USER_DURATION, 'count'],
        'total' => [Metric::USER_DURATION, 'sum'],
        'avg' => [Metric::USER_DURATION, 'avg'],
        'slow' => [Metric::USER_SLOW, 'count'],
        'errors' => [Metric::USER_ERROR, 'count'],
    ];

    public function __construct(
        private readonly bool $available,
        private readonly int $slowThresholdMs,
    ) {}

    public function id(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users';
    }

    public function heading(): string
    {
        return 'Activity per signed-in user';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return array_fill_keys(array_keys(self::SORTS), 'desc');
    }

    public function emptyState(): array
    {
        return [
            'title' => 'No signed-in activity yet',
            'text' => 'Requests made by an authenticated user show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        [$metric, $field] = self::SORTS[$sort] ?? self::SORTS['requests'];
        $report = $reader->read(new ReportQuery($period, $now, Family::User, $metric, $field, $direction !== 'asc', self::LIMIT, Metric::USER_DURATION));

        $rows = array_map(self::row(...), $report->rows);
        if ($report->other !== null) {
            $rows[] = self::row($report->other, true);
        }

        return new ScreenView($this->cards($report, $period), [new Table('users', 'Users', [
            new Column('user', 'User', false),
            new Column('requests', 'Requests', sort: 'requests'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('slow', 'Slow', sort: 'slow'),
            new Column('errors', '5xx', sort: 'errors'),
        ], $rows, max(0, $report->keyCount - \count($report->rows)), 'user')]);
    }

    /** @return list<Card> */
    private function cards(Report $report, Period $period): array
    {
        $slow = $report->totals[Metric::USER_SLOW]->count ?? 0;
        $errors = $report->totals[Metric::USER_ERROR]->count ?? 0;

        return [
            new Card('Active users', Format::number($report->keyCount), Format::period($period), series: $report->series, wide: true),
            new Card('Requests', Format::number($report->totals[Metric::USER_DURATION]->count ?? 0), 'By signed-in users'),
            new Card('Slow requests', Format::number($slow), $this->slowThresholdMs . ' ms or more', $slow > 0 ? 'warn' : null),
            new Card('5xx errors', Format::number($errors), 'Responses with a 5xx status', $errors > 0 ? 'bad' : null),
        ];
    }

    private static function row(ReportRow $row, bool $other = false): Row
    {
        $requests = $row->count(Metric::USER_DURATION);
        $total = $row->sum(Metric::USER_DURATION);
        $slow = $row->count(Metric::USER_SLOW);
        $errors = $row->count(Metric::USER_ERROR);

        return new Row($row->hash, [
            'user' => new Cell($other ? 'Other users' : $row->key),
            'requests' => new Cell(Format::number($requests)),
            'total' => new Cell(Format::duration($total)),
            'avg' => new Cell(Format::duration($requests === 0 ? 0 : $total / $requests)),
            'slow' => new Cell(Format::number($slow), $slow > 0 ? 'warn' : 'zero'),
            'errors' => new Cell(Format::number($errors), $errors > 0 ? 'bad' : 'zero'),
        ], [
            'data-jm-user' => $row->key,
            'data-jm-requests' => (string) $requests,
        ], $other);
    }
}
