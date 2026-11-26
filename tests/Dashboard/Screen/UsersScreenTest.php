<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsersScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const SERVER_ERRORS = Metric::REQUEST_STATUS_PREFIX . '5xx';

    public function testTheScreenDeclaresItself(): void
    {
        $screen = self::screen();

        static::assertSame('users', $screen->id());
        static::assertSame('Users', $screen->label());
        static::assertSame('Activity per signed-in user', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['requests' => 'desc', 'total' => 'desc', 'avg' => 'desc', 'problems' => 'desc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No signed-in activity yet', 'text' => 'Requests made by an authenticated user show up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new UsersScreen(false, self::checks(), 1000))->isAvailable());
    }

    public function testEverySortIsComputedByTheDatabase(): void
    {
        $sorts = [
            'requests' => [Metric::USER_DURATION, 'count'],
            'total' => [Metric::USER_DURATION, 'sum'],
            'avg' => [Metric::USER_DURATION, 'avg'],
            'problems' => [Metric::USER_ERROR, 'count'],
        ];

        static::assertSame(array_keys(self::screen()->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = self::reader([self::user('h1', 'alice')]);
                self::screen()->build($reader, Period::SixHours, self::NOW, $sort, $direction);

                static::assertCount(1, $reader->queries);
                $query = $reader->queries[0];
                static::assertSame(Family::User, $query->family);
                static::assertSame(Period::SixHours, $query->period);
                static::assertSame(self::NOW, $query->now);
                static::assertSame($metric, $query->sortMetric, $sort);
                static::assertSame($field, $query->sortField, $sort);
                static::assertSame($descending, $query->descending, $sort . ' ' . $direction);
                static::assertSame(100, $query->limit);
                static::assertSame(Metric::USER_DURATION, $query->seriesMetric);
                static::assertSame([], $reader->labelRequests);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedSorts(): iterable
    {
        yield 'slow' => ['slow'];
        yield 'errors' => ['errors'];
        yield 'unknown' => ['unknown'];
    }

    #[DataProvider('removedSorts')]
    public function testARemovedSortFallsBackToTheDefault(string $sort): void
    {
        $reader = self::reader([self::user('h1', 'alice')]);
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, $sort, 'desc');

        static::assertSame([Metric::USER_DURATION, 'count'], [$reader->queries[0]->sortMetric, $reader->queries[0]->sortField]);
        static::assertSame('the most active first', $view->tables[0]->note);
    }

    public function testTheColumnsOfTheTable(): void
    {
        $table = self::build([self::user('h1', 'alice')])->tables[0];

        static::assertSame('users', $table->id);
        static::assertSame('Users', $table->title);
        static::assertSame(['user', 'users'], [$table->nounFor(1), $table->nounFor(2)]);
        static::assertSame(
            [['user', 'User', false, null], ['requests', 'Requests', true, 'requests'], ['total', 'Total time', true, 'total'], ['avg', 'Avg', true, 'avg'], ['problems', 'Problems', false, 'problems']],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
    }

    public function testTheCellsOfAUser(): void
    {
        $table = self::build([self::user('h1', 'alice@example.com', 12, 3000.0, 2, 1), self::user('h2', 'bob', 3, 30.0)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-user' => 'alice@example.com', 'data-jm-requests' => '12'], $row->attributes);
        static::assertSame(
            ['user' => 'alice@example.com', 'requests' => '12 · 80 %', 'total' => '3.0 s', 'avg' => '250 ms', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('text', $row->cells['user']->kind);
        static::assertSame('bar', $row->cells['requests']->kind);
        static::assertSame('100', $row->cells['requests']->data['share']);
        static::assertSame(['3 · 20 %', '25'], [$table->rows[1]->cells['requests']->text, $table->rows[1]->cells['requests']->data['share']]);
    }

    public function testTheTagsComeInOrder(): void
    {
        $rows = self::build([
            self::user('h1', 'alice', 12, 30.0, 2, 1),
            self::user('h2', 'bob', 5, 30.0, 0, 1200),
            self::user('h3', 'carol', 4, 30.0, 3),
            self::user('h4', 'dave'),
        ])->tables[0]->rows;
        $tags = static fn(Row $row): array => array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags);

        static::assertSame('tags', $rows[0]->cells['problems']->kind);
        static::assertSame([['1 × 5xx', 'bad'], ['2 slow', 'warn']], $tags($rows[0]));
        static::assertSame([['1,200 × 5xx', 'bad']], $tags($rows[1]));
        static::assertSame([['3 slow', 'warn']], $tags($rows[2]));
        static::assertSame([], $tags($rows[3]));
    }

    public function testTheBarIsTheShareOfTheLargestCountShownOtherUsersIncluded(): void
    {
        $other = self::user('ho', Aggregator::OTHER, 40, 500.0, 1, 2);
        $rows = self::build([self::user('h1', 'alice', 20), self::user('h2', 'bob', 10)], other: $other, keyCount: 5)->tables[0]->rows;

        static::assertSame(['50', '25', '100'], array_map(static fn(Row $row): string => $row->cells['requests']->data['share'], $rows));
        static::assertSame(['20 · 29 %', '10 · 14 %', '40 · 57 %'], array_map(static fn(Row $row): string => $row->cells['requests']->text, $rows));
        static::assertSame([['2 × 5xx', 'bad'], ['1 slow', 'warn']], array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $rows[2]->cells['problems']->tags));
    }

    public function testOtherUsersComeLastAndTheRestIsCounted(): void
    {
        $other = self::user('ho', Aggregator::OTHER, 50, 500.0);
        $table = self::build([self::user('h1', 'alice'), self::user('h2', 'bob')], other: $other, keyCount: 130)->tables[0];

        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn(Row $row): string => $row->key, $table->rows));
        $last = $table->rows[2];
        static::assertTrue($last->muted);
        static::assertSame('Other users', $last->cells['user']->text);
        static::assertSame('50 · 96 %', $last->cells['requests']->text);
        static::assertSame(['data-jm-user' => Aggregator::OTHER, 'data-jm-requests' => '50'], $last->attributes);
        static::assertSame(128, $table->remaining);
    }

    public function testSortingByProblemsReordersTheUsersRead(): void
    {
        $rows = [
            self::user('h1', 'erin', 1, 20.0, 0, 1),
            self::user('h2', 'dave', 1, 20.0, 5, 1),
            self::user('h3', 'carol', 1, 20.0, 9, 0),
            self::user('h4', 'bob', 1, 20.0, 0, 2),
            self::user('h5', 'alice', 1, 20.0, 0, 1),
        ];
        $other = self::user('ho', Aggregator::OTHER, 1, 20.0, 9, 9);
        $users = static fn(ScreenView $view): array => array_map(static fn(Row $row): string => $row->cells['user']->text, $view->tables[0]->rows);

        static::assertSame(['bob', 'dave', 'alice', 'erin', 'carol', 'Other users'], $users(self::build($rows, other: $other, sort: 'problems')));
        static::assertSame(['carol', 'alice', 'erin', 'dave', 'bob', 'Other users'], $users(self::build($rows, other: $other, sort: 'problems', direction: 'asc')));
        static::assertSame(['erin', 'dave', 'carol', 'bob', 'alice', 'Other users'], $users(self::build($rows, other: $other, sort: 'requests')));
        static::assertSame(['erin', 'dave', 'carol', 'bob', 'alice', 'Other users'], $users(self::build($rows, other: $other, sort: 'avg', direction: 'asc')));
    }

    public function testTheNoteSaysTheMostActiveComeFirstOnTheDefaultSortOnly(): void
    {
        static::assertSame('the most active first', self::build([self::user('h1', 'alice')])->tables[0]->note);
        static::assertNull(self::build([self::user('h1', 'alice')], direction: 'asc')->tables[0]->note);

        foreach (['total', 'avg', 'problems'] as $sort) {
            static::assertNull(self::build([self::user('h1', 'alice')], sort: $sort)->tables[0]->note, $sort);
        }
    }

    /**
     * @return iterable<string, array{?int, ?string}>
     */
    public static function footnotes(): iterable
    {
        yield 'every request was signed in' => [15, null];
        yield 'one visitor request' => [16, 'The other request came from a visitor who was not signed in.'];
        yield 'many visitor requests' => [1215, 'The other 1,200 requests came from visitors who were not signed in.'];
        yield 'total unknown' => [null, null];
        yield 'total below the signed-in requests' => [10, null];
    }

    #[DataProvider('footnotes')]
    public function testTheFootnoteCountsTheRequestsOfVisitors(?int $all, ?string $footnote): void
    {
        $view = self::build([self::user('h1', 'alice', 12), self::user('h2', 'bob', 3)], all: $all);

        static::assertSame($footnote, $view->tables[0]->footnote);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, ?int, ?int, string}>
     */
    public static function sentences(): iterable
    {
        yield 'one user, one request, total unknown' => [[self::user('h1', 'alice')], null, null, null, '1 signed-in user made 1 request in the last hour.'];
        yield 'one user, one request of one' => [[self::user('h1', 'alice')], null, null, 1, '1 signed-in user made 1 request in the last hour.'];
        yield 'one user, one request of many' => [[self::user('h1', 'alice')], null, null, 9, '1 signed-in user made 1 of the 9 requests in the last hour.'];
        yield 'several users' => [[self::user('h1', 'alice', 1200), self::user('h2', 'bob', 3)], null, null, 2000, '2 signed-in users made 1,203 of the 2,000 requests in the last hour.'];
        yield 'total unknown' => [[self::user('h1', 'alice', 5), self::user('h2', 'bob', 3)], null, null, null, '2 signed-in users made 8 requests in the last hour.'];
        yield 'total below the signed-in requests' => [[self::user('h1', 'alice', 5), self::user('h2', 'bob', 3)], null, null, 7, '2 signed-in users made 8 requests in the last hour.'];
        yield 'all requests signed in' => [[self::user('h1', 'alice', 5), self::user('h2', 'bob', 3)], null, null, 8, '2 signed-in users made 8 of the 8 requests in the last hour.'];
        yield 'users beyond those read' => [[self::user('h1', 'alice', 5), self::user('h2', 'bob', 3)], null, 140, 20, '140 signed-in users made 8 of the 20 requests in the last hour.'];
        yield 'the other line' => [[self::user('h1', 'alice', 5), self::user('h2', 'bob', 3)], self::user('ho', Aggregator::OTHER, 4), null, 20, '3 signed-in users or more made 12 of the 20 requests in the last hour.'];
        yield 'the other line alone' => [[], self::user('ho', Aggregator::OTHER, 4), null, 20, '1 signed-in user or more made 4 of the 20 requests in the last hour.'];
    }

    /** @param list<ReportRow> $rows */
    #[DataProvider('sentences')]
    public function testTheLeadSumsUpThePeriod(array $rows, ?ReportRow $other, ?int $keyCount, ?int $all, string $sentence): void
    {
        $view = self::build($rows, other: $other, keyCount: $keyCount, all: $all);

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, ?int, string}>
     */
    public static function details(): iterable
    {
        yield 'one user without problem' => [[self::user('h1', 'alice')], null, null, 'None of them met a server error or a slow request.'];
        yield 'one server error' => [[self::user('h1', 'alice', 3, 20.0, 0, 1)], null, null, 'They met 1 server error.'];
        yield 'slow requests' => [[self::user('h1', 'alice', 3, 20.0, 3)], null, null, 'They met 3 slow requests.'];
        yield 'both' => [[self::user('h1', 'alice', 3, 20.0, 1, 2)], null, null, 'They met 2 server errors and 1 slow request.'];
        yield 'two users all listed' => [[self::user('h1', 'alice', 3), self::user('h2', 'bob', 1)], null, null, 'None of them met a server error or a slow request. The most active one made 75 % of their requests.'];
        yield 'two users all listed, with problems' => [[self::user('h1', 'alice', 1, 20.0, 1), self::user('h2', 'bob', 1, 20.0, 0, 1)], null, null, 'They met 1 server error and 1 slow request. The most active one made 50 % of their requests.'];
        yield 'users beyond those read' => [[self::user('h1', 'alice', 3), self::user('h2', 'bob', 1)], null, 140, 'None of them met a server error or a slow request.'];
        yield 'the other line' => [[self::user('h1', 'alice', 3), self::user('h2', 'bob', 1)], self::user('ho', Aggregator::OTHER, 4), null, 'None of them met a server error or a slow request.'];
    }

    /** @param list<ReportRow> $rows */
    #[DataProvider('details')]
    public function testTheDetailSaysWhatTheyMet(array $rows, ?ReportRow $other, ?int $keyCount, string $detail): void
    {
        $view = self::build($rows, other: $other, keyCount: $keyCount);

        static::assertNotNull($view->lead);
        static::assertSame($detail, $view->lead->detail);
    }

    /**
     * @return iterable<string, array{Period, string, string}>
     */
    public static function periods(): iterable
    {
        yield 'last hour' => [Period::OneHour, '1 h ago', '1 signed-in user made 2 requests in the last hour.'];
        yield 'last 6 hours' => [Period::SixHours, '6 h ago', '1 signed-in user made 2 requests in the last 6 hours.'];
        yield 'last 24 hours' => [Period::OneDay, '24 h ago', '1 signed-in user made 2 requests in the last 24 hours.'];
        yield 'last 7 days' => [Period::SevenDays, '7 d ago', '1 signed-in user made 2 requests in the last 7 days.'];
    }

    #[DataProvider('periods')]
    public function testTheLeadDrawsTheRequestsOfThePeriod(Period $period, string $start, string $sentence): void
    {
        $view = self::build([self::user('h1', 'alice', 2)], series: [1, 0, 1], period: $period);

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
        static::assertSame([1, 0, 1], $view->lead->series);
        static::assertSame('requests', $view->lead->seriesLabel);
        static::assertSame($start, $view->lead->seriesStart);
    }

    public function testTheCards(): void
    {
        $view = self::build(
            [self::user('h1', 'alice', 12, 3000.0, 2, 1), self::user('h2', 'bob', 3, 30.0)],
            keyCount: 140,
            period: Period::OneDay,
            totals: self::totals(250, 9000.0, 4, 2),
            all: 1000,
        );

        static::assertSame(['Active users', 'Their requests', 'Slow requests', 'Server errors'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['140', '250', '4', '2'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(
            ['Signed-in users who made at least one request.', '25 % of all the requests of the last 24 hours.', 'Requests of a signed-in user that took 1.0 s or more.', 'Responses with a 5xx status sent to a signed-in user.'],
            array_map(static fn(Card $card): string => $card->hint, $view->cards),
        );
        static::assertSame([null, null, 'warn', 'bad'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
        static::assertSame([[], [], [], []], array_map(static fn(Card $card): array => $card->series, $view->cards));
        static::assertSame([false, false, false, false], array_map(static fn(Card $card): bool => $card->wide, $view->cards));
    }

    public function testCardsWithoutProblemStayNeutralAndATotalUnknownLeavesNoLine(): void
    {
        $view = self::build([self::user('h1', 'alice')], all: null, threshold: 750);

        static::assertSame(['1', '1', '0', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('', $view->cards[1]->hint);
        static::assertSame('Requests of a signed-in user that took 750 ms or more.', $view->cards[2]->hint);
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testTheActiveUsersCardCountsTheUsersBehindTheOtherLine(): void
    {
        static::assertSame('3+', self::build([self::user('h1', 'alice'), self::user('h2', 'bob')], other: self::user('ho', Aggregator::OTHER))->cards[0]->value);
        static::assertSame('1+', self::build([], other: self::user('ho', Aggregator::OTHER))->cards[0]->value);
        static::assertSame('1,201+', self::build([self::user('h1', 'alice')], other: self::user('ho', Aggregator::OTHER), keyCount: 1200)->cards[0]->value);
    }

    public function testTheChecksOfRequestsShowWhenASignedInUserMetTheirProblem(): void
    {
        $raised = [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0), Metric::REQUEST_SLOW => new MetricValue(2, 0.0, 0.0)];
        $ids = static fn(ScreenView $view): array => array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines ?? []);

        static::assertSame(['requests.server_errors', 'requests.slow'], $ids(self::build([self::user('h1', 'alice', 3, 20.0, 1, 1)], raised: $raised)));
        static::assertSame(['requests.server_errors'], $ids(self::build([self::user('h1', 'alice', 3, 20.0, 0, 1)], raised: $raised)));
        static::assertSame(['requests.slow'], $ids(self::build([self::user('h1', 'alice', 3, 20.0, 1)], raised: $raised)));
        static::assertSame([], $ids(self::build([self::user('h1', 'alice', 3)], raised: $raised)));
        static::assertSame([], $ids(self::build([self::user('h1', 'alice', 3, 20.0, 1, 1)])));

        $line = self::build([self::user('h1', 'alice', 3, 20.0, 0, 1)], raised: $raised)->checks?->lines[0];
        static::assertNotNull($line);
        static::assertSame(['requests', 'Requests', ['period' => '1h']], [$line->path, $line->where, $line->query]);
        static::assertSame('3 server errors', $line->title);
        static::assertSame('GET app_boom answered with a 5xx status 3 times.', $line->sentence);
    }

    public function testWithoutCheckTheBlockSaysWhatWasChecked(): void
    {
        $checks = self::build([self::user('h1', 'alice', 3)], period: Period::SixHours)->checks;

        static::assertNotNull($checks);
        static::assertSame([], $checks->lines);
        static::assertSame('Worth a look', $checks->title);
        static::assertSame('Nothing to flag', $checks->emptyTitle);
        static::assertSame('No signed-in user met a server error or a slow request in the last 6 hours.', $checks->emptyText);
    }

    public function testTheRequestFamilyIsOnlyReadWhenAUserMetAProblem(): void
    {
        $raised = [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0), Metric::REQUEST_SLOW => new MetricValue(2, 0.0, 0.0)];

        foreach ([
            'nothing raised' => [self::user('h1', 'alice', 3, 20.0, 1, 1), []],
            'raised, no user met it' => [self::user('h1', 'alice', 3), $raised],
        ] as $case => [$user, $totals]) {
            $reader = self::reader([$user], raised: $totals, all: 20);
            $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'requests', 'desc');

            static::assertSame([Family::User], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries), $case);
            static::assertCount(2, $reader->totalRequests, $case);
            static::assertSame([Metric::REQUEST_DURATION], $reader->totalRequests[1][2], $case);
            static::assertSame('The other 17 requests came from visitors who were not signed in.', $view->tables[0]->footnote, $case);
        }

        $reader = self::reader([self::user('h1', 'alice', 3, 20.0, 0, 1)], raised: $raised, all: 20);
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'requests', 'desc');

        static::assertSame([Family::User, Family::Request], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertCount(1, $reader->totalRequests);
        static::assertSame('The other 17 requests came from visitors who were not signed in.', $view->tables[0]->footnote);
    }

    public function testTheRequestFamilyIsReadOnceForBothChecks(): void
    {
        $raised = [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0), Metric::REQUEST_SLOW => new MetricValue(2, 0.0, 0.0)];
        $reader = self::reader([self::user('h1', 'alice', 3, 20.0, 2, 1)], raised: $raised, all: 20);
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'requests', 'desc');

        static::assertSame([Family::User, Family::Request], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertCount(1, $reader->totalRequests);
        static::assertSame(['requests.server_errors', 'requests.slow'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines ?? []));
        static::assertNotNull($view->lead);
        static::assertSame('1 signed-in user made 3 of the 20 requests in the last hour.', $view->lead->sentence);
    }

    public function testAnEmptyScreenReadsNoCheck(): void
    {
        $reader = self::reader([], raised: [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0)]);
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'requests', 'desc');

        static::assertTrue($view->isEmpty());
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
        static::assertNull($view->lead);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
        static::assertSame([], $view->cards);
        static::assertFalse(self::build([], other: self::user('ho', Aggregator::OTHER))->isEmpty());
    }

    public function testTheViewCarriesTheRaisedChecks(): void
    {
        $view = self::build([self::user('h1', 'alice', 3, 20.0, 0, 1)], raised: [self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0)]);

        static::assertNotNull($view->raised);
        static::assertTrue($view->raised->has('requests.server_errors'));
        static::assertNull($view->raised->badge('users'));
    }

    public function testTheGuideExplainsTheScreen(): void
    {
        $guide = self::build([self::user('h1', 'alice')])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [
                ['One user far above the others.', 'Often a script, an integration or a mobile app that polls too much. Sometimes an abuse.', []],
                ['A user with errors or slow pages.', 'Someone is having a bad time right now. The Problems column says who, before they write to you.', []],
                ['Who is counted.', 'A user is whoever Symfony Security knows as signed in. The name shown is its identifier.', []],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param list<int>                       $series
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     * @param array<string, MetricValue>      $raised metric type => total, for the checks
     */
    private static function build(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        Period $period = Period::OneHour,
        ?array $totals = null,
        string $sort = 'requests',
        string $direction = 'desc',
        ?int $all = null,
        array $raised = [],
        int $threshold = 1000,
    ): ScreenView {
        return self::screen($threshold)->build(self::reader($rows, $other, $keyCount, $series, $totals, $raised, $all), $period, self::NOW, $sort, $direction);
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param list<int>                       $series
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     * @param array<string, MetricValue>      $raised metric type => total, for the checks
     */
    private static function reader(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        ?array $totals = null,
        array $raised = [],
        ?int $all = null,
    ): FixedReader {
        $shown = [...$rows, ...($other === null ? [] : [$other])];
        $totals ??= self::totals(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_DURATION), $shown)),
            array_sum(array_map(static fn(ReportRow $row): float => $row->sum(Metric::USER_DURATION), $shown)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_SLOW), $shown)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_ERROR), $shown)),
        );
        $users = new Report($rows, $other, $keyCount ?? \count($rows), $series, $shown === [] ? [] : $totals);
        $requestTotals = $all === null ? [] : [Metric::REQUEST_DURATION => new MetricValue($all, 0.0, 0.0)];
        $requests = new Report([new ReportRow('r1', 'GET app_boom', 'GET app_boom', [
            Metric::REQUEST_DURATION => new MetricValue(3, 30.0, 10.0),
            self::SERVER_ERRORS => new MetricValue(3, 0.0, 0.0),
            Metric::REQUEST_SLOW => new MetricValue(2, 0.0, 0.0),
        ])], null, 1, [], $requestTotals);

        return new FixedReader(
            static fn(ReportQuery $query): Report => $query->family === Family::Request ? $requests : $users,
            totals: $raised + $requestTotals,
        );
    }

    /** @return array<string, MetricValue> */
    private static function totals(int $requests, float $totalMs, int $slow, int $errors): array
    {
        return [
            Metric::USER_DURATION => new MetricValue($requests, $totalMs, 0.0),
            Metric::USER_SLOW => new MetricValue($slow, 0.0, 0.0),
            Metric::USER_ERROR => new MetricValue($errors, 0.0, 0.0),
        ];
    }

    private static function screen(int $threshold = 1000): UsersScreen
    {
        return new UsersScreen(true, self::checks($threshold), $threshold);
    }

    private static function checks(int $threshold = 1000): ApplicationChecks
    {
        return new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck($threshold), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10), new FailedMessagesCheck()]);
    }

    private static function user(string $hash, string $identifier, int $requests = 1, float $totalMs = 20.0, int $slow = 0, int $errors = 0): ReportRow
    {
        $metrics = [Metric::USER_DURATION => new MetricValue($requests, $totalMs, $totalMs)];
        if ($slow > 0) {
            $metrics[Metric::USER_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }
        if ($errors > 0) {
            $metrics[Metric::USER_ERROR] = new MetricValue($errors, 0.0, 0.0);
        }

        return new ReportRow($hash, $identifier, $identifier, $metrics);
    }
}
