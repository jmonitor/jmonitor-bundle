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
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
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

final class HttpSection implements SectionInterface
{
    private const LIMIT = 100;
    private const SCREEN = 'cache-http';
    private const FAILURES = 'http.failures';
    private const ERRORS = 'http.errors';
    private const CLIENT_ERRORS = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const SERVER_ERRORS = Metric::HTTP_STATUS_PREFIX . '5xx';

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'total' => [Metric::HTTP_DURATION, 'sum'],
        'calls' => [Metric::HTTP_DURATION, 'count'],
        'avg' => [Metric::HTTP_DURATION, 'avg'],
        'max' => [Metric::HTTP_DURATION, 'max'],
        'problems' => [Metric::HTTP_FAILED, 'count'],
    ];

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
    ) {}

    public function id(): string
    {
        return 'http';
    }

    public function title(): string
    {
        return 'Outgoing HTTP';
    }

    public function summary(): string
    {
        return 'Calls your code makes to other sites and APIs through the Symfony HTTP client.';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function sorts(): array
    {
        return array_fill_keys(array_keys(self::SORTS), 'desc');
    }

    public function emptyState(Period $period): array
    {
        return [
            'title' => 'No outgoing call in the ' . lcfirst(Format::period($period)) . '.',
            'text' => 'Calls made through the http_client service or a scoped client show up here within a few seconds.',
        ];
    }

    public function build(ReaderInterface $reader, Period $period, int $now, string $sort, string $direction, RaisedChecks $raised): ScreenView
    {
        $sort = isset(self::SORTS[$sort]) ? $sort : 'total';
        $descending = $direction !== 'asc';
        [$metric, $field] = self::SORTS[$sort];
        $report = $reader->read(new ReportQuery($period, $now, Family::Http, $metric, $field, $descending, self::LIMIT));

        $listed = $sort === 'problems' ? self::byProblems($report->rows, $descending) : $report->rows;
        $shown = [...$listed, ...($report->other === null ? [] : [$report->other])];
        $largest = $shown === [] ? 0.0 : max(array_map(static fn(ReportRow $row): float => $row->sum(Metric::HTTP_DURATION), $shown));
        $rows = array_map(static fn(ReportRow $row): Row => self::row($row, $largest, $row === $report->other), $shown);

        $table = new Table(
            'hosts',
            'Hosts',
            self::columns(),
            $rows,
            max(0, $report->keyCount - \count($report->rows)),
            'host',
            note: $sort === 'total' && $descending ? 'the most expensive first' : null,
            footnote: 'A host appears here the first time your code calls it.',
        );

        if ($rows === []) {
            return new ScreenView([], [$table]);
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, [self::FAILURES, self::ERRORS], [self::FAILURES => $report, self::ERRORS => $report]);

        return new ScreenView(
            self::cards($report),
            [$table],
            checks: new CheckList($this->checks->lines($findings, self::SCREEN, $period), emptyText: 'no call without an answer, no error response'),
            guide: new Guide([
                new GuideItem(
                    'Time in outgoing calls.',
                    'Your request waits while the other site answers. A slow host makes your own routes slow: look at the HTTP part of their bars.',
                    ['Requests' => 'requests'],
                ),
                new GuideItem('Three kinds of problem.', '4xx: the other side refused the call. 5xx: the other side failed. No answer: a timeout or a network error.'),
            ]),
            brief: self::brief($report),
        );
    }

    /** @return list<Column> */
    private static function columns(): array
    {
        return [
            new Column('host', 'Host', false),
            new Column('calls', 'Calls', sort: 'calls'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('max', 'Max', sort: 'max'),
            new Column('problems', 'Problems', false, 'problems'),
        ];
    }

    /** @return list<Card> */
    private static function cards(Report $report): array
    {
        $calls = $report->totals[Metric::HTTP_DURATION]->count ?? 0;
        $total = $report->totals[Metric::HTTP_DURATION]->sum ?? 0.0;
        $errors = self::errors($report);
        $failures = self::failures($report);

        return [
            new Card('Calls', Format::number($calls), self::hosts($report)),
            new Card('Time in calls', Format::duration($total), Format::duration($calls === 0 ? 0 : $total / $calls) . ' per call on average.'),
            new Card('Error responses', Format::number($errors), 'Answers with a 4xx or 5xx status.', $errors > 0 ? 'warn' : null),
            new Card('No answer', Format::number($failures), 'Timeouts and network errors.', $failures > 0 ? 'bad' : null),
        ];
    }

    private static function hosts(Report $report): string
    {
        $hosts = $report->keyCount + ($report->other === null ? 0 : 1);

        return 'To ' . ($hosts === 1 ? '1 host' : Format::number($hosts) . ' hosts') . ($report->other === null ? '.' : ' or more.');
    }

    private static function brief(Report $report): Brief
    {
        $calls = $report->totals[Metric::HTTP_DURATION]->count ?? 0;
        $errors = self::errors($report);
        $failures = self::failures($report);
        $standouts = [];

        if ($failures > 0) {
            $standouts[] = ($failures === 1 ? '1 call' : Format::number($failures) . ' calls') . ' got no answer.';
        }

        if ($errors > 0) {
            $standouts[] = ($errors === 1 ? '1 error response' : Format::number($errors) . ' error responses') . '.';
        }

        return new Brief(
            Format::number($calls),
            $calls === 1 ? '1 outgoing call' : Format::number($calls) . ' outgoing calls',
            standout: implode(' ', $standouts),
        );
    }

    private static function errors(Report $report): int
    {
        return ($report->totals[self::CLIENT_ERRORS]->count ?? 0) + ($report->totals[self::SERVER_ERRORS]->count ?? 0);
    }

    private static function failures(Report $report): int
    {
        return $report->totals[Metric::HTTP_FAILED]->count ?? 0;
    }

    private static function row(ReportRow $row, float $largest, bool $other): Row
    {
        $calls = $row->count(Metric::HTTP_DURATION);
        $total = $row->sum(Metric::HTTP_DURATION);

        return new Row($row->hash, [
            'host' => new Cell($other ? 'Other hosts' : $row->key),
            'calls' => new Cell(Format::number($calls)),
            'total' => Cell::bar(Format::duration($total), $largest > 0 ? $total / $largest : 0.0),
            'avg' => new Cell(Format::duration($calls === 0 ? 0 : $total / $calls)),
            'max' => new Cell(Format::duration($row->max(Metric::HTTP_DURATION))),
            'problems' => Cell::tags(self::tags($row)),
        ], [
            'data-jm-host' => $row->key,
            'data-jm-calls' => (string) $calls,
        ], $other);
    }

    /** @return list<Tag> */
    private static function tags(ReportRow $row): array
    {
        $tags = [];

        if ($row->count(Metric::HTTP_FAILED) > 0) {
            $tags[] = new Tag(Format::number($row->count(Metric::HTTP_FAILED)) . ' no answer', 'bad');
        }

        if ($row->count(self::SERVER_ERRORS) > 0) {
            $tags[] = new Tag(Format::number($row->count(self::SERVER_ERRORS)) . ' × 5xx', 'bad');
        }

        if ($row->count(self::CLIENT_ERRORS) > 0) {
            $tags[] = new Tag(Format::number($row->count(self::CLIENT_ERRORS)) . ' × 4xx', 'warn');
        }

        return $tags;
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return list<ReportRow>
     */
    private static function byProblems(array $rows, bool $descending): array
    {
        $sign = $descending ? -1 : 1;
        $problems = static fn(ReportRow $row): array => [$row->count(Metric::HTTP_FAILED), $row->count(self::SERVER_ERRORS), $row->count(self::CLIENT_ERRORS)];

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * ($problems($a) <=> $problems($b)) ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
