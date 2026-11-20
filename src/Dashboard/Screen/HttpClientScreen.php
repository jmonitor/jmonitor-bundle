<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenInterface;
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

final class HttpClientScreen implements ScreenInterface
{
    private const LIMIT = 100;
    private const CLIENT_ERRORS = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const SERVER_ERRORS = Metric::HTTP_STATUS_PREFIX . '5xx';

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'total' => [Metric::HTTP_DURATION, 'sum'],
        'calls' => [Metric::HTTP_DURATION, 'count'],
        'avg' => [Metric::HTTP_DURATION, 'avg'],
        'max' => [Metric::HTTP_DURATION, 'max'],
        '4xx' => [self::CLIENT_ERRORS, 'count'],
        '5xx' => [self::SERVER_ERRORS, 'count'],
        'failed' => [Metric::HTTP_FAILED, 'count'],
    ];

    public function __construct(private readonly bool $available) {}

    public function id(): string
    {
        return 'http-client';
    }

    public function label(): string
    {
        return 'HTTP client';
    }

    public function heading(): string
    {
        return 'Outgoing calls made with the Symfony HttpClient, per host';
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
            'title' => 'No outgoing calls recorded yet',
            'text' => 'Calls made with the Symfony HttpClient show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction): ScreenView
    {
        [$metric, $field] = self::SORTS[$sort] ?? self::SORTS['total'];
        $report = $reader->read(new ReportQuery($period, $now, Family::Http, $metric, $field, $direction !== 'asc', self::LIMIT, Metric::HTTP_DURATION));

        $rows = array_map(self::row(...), $report->rows);
        if ($report->other !== null) {
            $rows[] = self::row($report->other, true);
        }

        return new ScreenView(self::cards($report, $period), [new Table('hosts', 'Hosts', [
            new Column('host', 'Host', false),
            new Column('calls', 'Calls', sort: 'calls'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('4xx', '4xx', sort: '4xx'),
            new Column('5xx', '5xx', sort: '5xx'),
            new Column('failed', 'Failed', sort: 'failed'),
        ], $rows, max(0, $report->keyCount - \count($report->rows)), 'host')]);
    }

    /** @return list<Card> */
    private static function cards(Report $report, Period $period): array
    {
        $calls = $report->totals[Metric::HTTP_DURATION]->count ?? 0;
        $total = $report->totals[Metric::HTTP_DURATION]->sum ?? 0.0;
        $errors = ($report->totals[self::CLIENT_ERRORS]->count ?? 0) + ($report->totals[self::SERVER_ERRORS]->count ?? 0);
        $failures = $report->totals[Metric::HTTP_FAILED]->count ?? 0;

        return [
            new Card('Calls', Format::number($calls), Format::period($period), series: $report->series, wide: true),
            new Card('Time in calls', Format::duration($total), 'Avg ' . Format::duration($calls === 0 ? 0 : $total / $calls) . ' per call'),
            new Card('Error responses', Format::number($errors), 'Responses with a 4xx or 5xx status', $errors > 0 ? 'warn' : null),
            new Card('Network failures', Format::number($failures), 'Calls that got no response', $failures > 0 ? 'bad' : null),
        ];
    }

    private static function row(ReportRow $row, bool $other = false): Row
    {
        $calls = $row->count(Metric::HTTP_DURATION);
        $total = $row->sum(Metric::HTTP_DURATION);
        $clientErrors = $row->count(self::CLIENT_ERRORS);
        $serverErrors = $row->count(self::SERVER_ERRORS);
        $failures = $row->count(Metric::HTTP_FAILED);

        return new Row($row->hash, [
            'host' => new Cell($other ? 'Other hosts' : $row->key),
            'calls' => new Cell(Format::number($calls)),
            'total' => new Cell(Format::duration($total)),
            'avg' => new Cell(Format::duration($calls === 0 ? 0 : $total / $calls)),
            'max' => new Cell(Format::duration($row->max(Metric::HTTP_DURATION))),
            '4xx' => new Cell(Format::number($clientErrors), $clientErrors > 0 ? 'warn' : 'zero'),
            '5xx' => new Cell(Format::number($serverErrors), $serverErrors > 0 ? 'bad' : 'zero'),
            'failed' => new Cell(Format::number($failures), $failures > 0 ? 'bad' : 'zero'),
        ], [
            'data-jm-host' => $row->key,
            'data-jm-calls' => (string) $calls,
        ], $other);
    }
}
