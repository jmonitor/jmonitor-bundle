<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
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
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;

final class UsersScreen implements StorageScreenInterface
{
    private const LIMIT = 100;
    private const SERVER_ERRORS = 'requests.server_errors';
    private const SLOW = 'requests.slow';

    /** @var array<string, array{string, 'count'|'sum'|'max'|'avg'}> sort id => sort metric and field */
    private const SORTS = [
        'requests' => [Metric::USER_DURATION, 'count'],
        'total' => [Metric::USER_DURATION, 'sum'],
        'avg' => [Metric::USER_DURATION, 'avg'],
        'problems' => [Metric::USER_ERROR, 'count'],
    ];

    public function __construct(
        private readonly bool $available,
        private readonly ApplicationChecks $checks,
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

    public function space(): Space
    {
        return Space::Application;
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
        $sort = isset(self::SORTS[$sort]) ? $sort : 'requests';
        $descending = $direction !== 'asc';
        [$metric, $field] = self::SORTS[$sort];
        $report = $reader->read(new ReportQuery($period, $now, Family::User, $metric, $field, $descending, self::LIMIT, Metric::USER_DURATION));

        $listed = $sort === 'problems' ? self::byProblems($report->rows, $descending) : $report->rows;
        $shown = [...$listed, ...($report->other === null ? [] : [$report->other])];

        if ($shown === []) {
            return new ScreenView([], [self::table([], 0, null, null)]);
        }

        $raised = $this->checks->raised($reader, $period, $now);
        $requests = $report->totals[Metric::USER_DURATION]->count ?? 0;
        $errors = $report->totals[Metric::USER_ERROR]->count ?? 0;
        $slow = $report->totals[Metric::USER_SLOW]->count ?? 0;

        $ids = [];
        if ($raised->has(self::SERVER_ERRORS) && $errors > 0) {
            $ids[] = self::SERVER_ERRORS;
        }
        if ($raised->has(self::SLOW) && $slow > 0) {
            $ids[] = self::SLOW;
        }

        $query = $ids === [] ? null : $this->checks->query($ids[0], $period, $now);
        $reports = [];

        if ($query !== null) {
            $requestFamily = $reader->read($query);
            $reports = array_fill_keys($ids, $requestFamily);
            $all = $requestFamily->totals[Metric::REQUEST_DURATION]->count ?? null;
        } else {
            $all = $reader->totals($period, $now, [Metric::REQUEST_DURATION])->types[Metric::REQUEST_DURATION]->count ?? null;
        }

        if ($all !== null && $all < max(1, $requests)) {
            $all = null;
        }

        $findings = $this->checks->findings($reader, $period, $now, $raised, $ids, $reports);
        $periodText = strtolower(Format::period($period));
        $largest = max(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_DURATION), $shown));
        $rows = array_map(static fn(ReportRow $row): Row => self::row($row, $requests, $largest, $row === $report->other), $shown);

        return new ScreenView(
            $this->cards($report, $requests, $all, $slow, $errors, $periodText),
            [self::table(
                $rows,
                max(0, $report->keyCount - \count($report->rows)),
                $sort === 'requests' && $descending ? 'the most active first' : null,
                $all === null ? null : self::visitors($all - $requests),
            )],
            self::lead($report, $requests, $all, $slow, $errors, $period, $periodText),
            new CheckList(
                $this->checks->lines($findings, $this->id(), $period),
                'Nothing to flag',
                'No signed-in user met a server error or a slow request in the ' . $periodText . '.',
                'Worth a look',
            ),
            new Guide([
                new GuideItem('One user far above the others.', 'Often a script, an integration or a mobile app that polls too much. Sometimes an abuse.'),
                new GuideItem('A user with errors or slow pages.', 'Someone is having a bad time right now. The Problems column says who, before they write to you.'),
                new GuideItem('Who is counted.', 'A user is whoever Symfony Security knows as signed in. The name shown is its identifier.'),
            ]),
            $raised,
        );
    }

    /** @param list<Row> $rows */
    private static function table(array $rows, int $remaining, ?string $note, ?string $footnote): Table
    {
        return new Table('users', 'Users', [
            new Column('user', 'User', false),
            new Column('requests', 'Requests', sort: 'requests'),
            new Column('total', 'Total time', sort: 'total'),
            new Column('avg', 'Avg', sort: 'avg'),
            new Column('problems', 'Problems', false, 'problems'),
        ], $rows, $remaining, 'user', note: $note, footnote: $footnote);
    }

    private static function visitors(int $requests): ?string
    {
        return match (true) {
            $requests <= 0 => null,
            $requests === 1 => 'The other request came from a visitor who was not signed in.',
            default => sprintf('The other %s requests came from visitors who were not signed in.', Format::number($requests)),
        };
    }

    private static function users(Report $report): string
    {
        $count = $report->keyCount + ($report->other === null ? 0 : 1);
        $users = $count === 1 ? '1 signed-in user' : Format::number($count) . ' signed-in users';

        return $report->other === null ? $users : $users . ' or more';
    }

    private static function lead(Report $report, int $requests, ?int $all, int $slow, int $errors, Period $period, string $periodText): Lead
    {
        $made = match (true) {
            $all !== null && $all > 1 => sprintf('%s of the %s requests', Format::number($requests), Format::number($all)),
            $requests === 1 => '1 request',
            default => Format::number($requests) . ' requests',
        };
        $sentence = sprintf('%s made %s in the %s.', self::users($report), $made, $periodText);

        $met = [];
        if ($errors > 0) {
            $met[] = $errors === 1 ? '1 server error' : Format::number($errors) . ' server errors';
        }
        if ($slow > 0) {
            $met[] = $slow === 1 ? '1 slow request' : Format::number($slow) . ' slow requests';
        }
        $detail = $met === [] ? 'None of them met a server error or a slow request.' : 'They met ' . implode(' and ', $met) . '.';

        if ($report->keyCount >= 2 && $report->other === null && $report->keyCount <= \count($report->rows) && $requests > 0) {
            $most = max(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_DURATION), $report->rows));
            $detail .= ' The most active one made ' . Format::percent($most / $requests) . ' of their requests.';
        }

        return new Lead($sentence, $detail, $report->series, 'requests', Format::periodStart($period));
    }

    /** @return list<Card> */
    private function cards(Report $report, int $requests, ?int $all, int $slow, int $errors, string $periodText): array
    {
        $users = $report->keyCount + ($report->other === null ? 0 : 1);

        return [
            new Card('Active users', Format::number($users) . ($report->other === null ? '' : '+'), 'Signed-in users who made at least one request.'),
            new Card('Their requests', Format::number($requests), $all === null ? '' : Format::percent($requests / $all) . ' of all the requests of the ' . $periodText . '.'),
            new Card('Slow requests', Format::number($slow), 'Requests of a signed-in user that took ' . Format::duration($this->slowThresholdMs) . ' or more.', $slow > 0 ? 'warn' : null),
            new Card('Server errors', Format::number($errors), 'Responses with a 5xx status sent to a signed-in user.', $errors > 0 ? 'bad' : null),
        ];
    }

    private static function row(ReportRow $row, int $requests, int $largest, bool $other): Row
    {
        $count = $row->count(Metric::USER_DURATION);
        $total = $row->sum(Metric::USER_DURATION);

        return new Row($row->hash, [
            'user' => new Cell($other ? 'Other users' : $row->key),
            'requests' => Cell::bar(
                Format::number($count) . ' · ' . Format::percent($requests > 0 ? $count / $requests : 0),
                $largest > 0 ? $count / $largest : 0.0,
            ),
            'total' => new Cell(Format::duration($total)),
            'avg' => new Cell(Format::duration($count === 0 ? 0 : $total / $count)),
            'problems' => Cell::tags(self::tags($row)),
        ], [
            'data-jm-user' => $row->key,
            'data-jm-requests' => (string) $count,
        ], $other);
    }

    /** @return list<Tag> */
    private static function tags(ReportRow $row): array
    {
        $tags = [];

        if ($row->count(Metric::USER_ERROR) > 0) {
            $tags[] = new Tag(Format::number($row->count(Metric::USER_ERROR)) . ' × 5xx', 'bad');
        }

        if ($row->count(Metric::USER_SLOW) > 0) {
            $tags[] = new Tag(Format::number($row->count(Metric::USER_SLOW)) . ' slow', 'warn');
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
        $problems = static fn(ReportRow $row): array => [$row->count(Metric::USER_ERROR), $row->count(Metric::USER_SLOW)];

        usort($rows, static fn(ReportRow $a, ReportRow $b): int => $sign * ($problems($a) <=> $problems($b)) ?: strcmp($a->key, $b->key));

        return $rows;
    }
}
