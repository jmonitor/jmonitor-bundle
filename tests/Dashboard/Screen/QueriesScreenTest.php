<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Screen\QueriesScreen;
use Jmonitor\JmonitorBundle\Dashboard\ScreenBuilder;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class QueriesScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const SQL = 'SELECT * FROM demo_items WHERE id = ?';
    private const QUERY_ID = '0123456789abcdef';

    public function testTheScreenDeclaresItself(): void
    {
        $screen = $this->screen();

        static::assertSame('queries', $screen->id());
        static::assertSame('Queries', $screen->label());
        static::assertSame('SQL queries run through Doctrine DBAL', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['total' => 'desc', 'count' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'problems' => 'desc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No queries recorded yet', 'text' => 'SQL queries run through Doctrine DBAL show up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutSqlRecording(): void
    {
        static::assertFalse((new QueriesScreen(false, self::checks(), 100, 10))->isAvailable());
    }

    public function testEverySortReadsTheQueryFamilyInTheDatabase(): void
    {
        $sorts = [
            'total' => [Metric::QUERY_DURATION, 'sum'],
            'count' => [Metric::QUERY_DURATION, 'count'],
            'avg' => [Metric::QUERY_DURATION, 'avg'],
            'max' => [Metric::QUERY_DURATION, 'max'],
            'problems' => [Metric::QUERY_SLOW, 'count'],
        ];

        static::assertSame(array_keys($this->screen()->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = $this->reader([$this->query('h1', self::SQL)]);
                $this->screen()->build($reader, Period::SixHours, self::NOW, $sort, $direction);

                static::assertCount(1, $reader->queries, $sort . ' ' . $direction);
                $query = $reader->queries[0];
                static::assertSame(Family::Query, $query->family, $sort);
                static::assertSame(Period::SixHours, $query->period);
                static::assertSame(self::NOW, $query->now);
                static::assertSame($metric, $query->sortMetric, $sort);
                static::assertSame($field, $query->sortField, $sort);
                static::assertSame($descending, $query->descending, $sort . ' ' . $direction);
                static::assertSame(100, $query->limit);
                static::assertSame(Metric::QUERY_DURATION, $query->seriesMetric);
                static::assertCount(1, $reader->totalRequests);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedSorts(): iterable
    {
        yield 'slow' => ['slow'];
        yield 'unknown' => ['nope'];
    }

    #[DataProvider('removedSorts')]
    public function testARemovedSortFallsBackToTheDefault(string $sort): void
    {
        $reader = $this->reader([$this->query('h1', 'SELECT 1')]);
        $page = (new ScreenBuilder($reader, true, new MockClock('@' . self::NOW)))->create($this->screen(), '1h', $sort, 'asc');

        static::assertSame('total', $page->sort);
        static::assertSame('desc', $page->direction);
        static::assertSame(Metric::QUERY_DURATION, $reader->queries[0]->sortMetric);
        static::assertSame('sum', $reader->queries[0]->sortField);
        static::assertTrue($reader->queries[0]->descending);
    }

    public function testTheCellsOfAQuery(): void
    {
        $table = $this->table($this->build([$this->query('h1', self::SQL, 12, 60.0, 30.0, 2)]));
        $row = $table->rows[0];

        static::assertSame('queries', $table->id);
        static::assertSame('Queries', $table->title);
        static::assertSame('query', $table->noun);
        static::assertSame(
            [
                ['query', 'Query', false, null],
                ['executions', 'Executions', true, 'count'],
                ['total', 'Total time', true, 'total'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['problems', 'Problems', false, 'problems'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertFalse($table->partsLegend);
        static::assertNull($table->footnote);
        static::assertSame('h1', $row->key);
        static::assertSame(['data-jm-query' => 'h1', 'data-jm-executions' => '12'], $row->attributes);
        static::assertFalse($row->muted);
        static::assertSame(
            ['query' => self::SQL, 'executions' => '12', 'total' => '60 ms', 'avg' => '5 ms', 'max' => '30 ms', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('sql', $row->cells['query']->kind);
        static::assertSame('bar', $row->cells['total']->kind);
        static::assertSame('tags', $row->cells['problems']->kind);
        static::assertSame([['2 slow', 'warn']], self::tags($row));
        static::assertSame('warn', $row->cells['max']->tone);
        static::assertNull($row->cells['total']->tone);
    }

    public function testAQueryWithoutSlowExecutionIsNotHighlighted(): void
    {
        $row = $this->table($this->build([$this->query('h1', self::SQL, 3, 6.0, 3.0)]))->rows[0];

        static::assertSame([], $row->cells['problems']->tags);
        static::assertNull($row->cells['max']->tone);
    }

    public function testAQueryWhoseTextIsNotStoredYetSaysSo(): void
    {
        $row = $this->table($this->build([$this->query('h1', null)]))->rows[0];

        static::assertSame('(query text unavailable)', $row->cells['query']->text);
        static::assertSame('text', $row->cells['query']->kind);
        static::assertSame('h1', $row->attributes['data-jm-query']);
    }

    public function testOtherQueriesComeLastAndTheRestIsCounted(): void
    {
        $other = new ReportRow('ho', Aggregator::OTHER, null, [Metric::QUERY_DURATION => new MetricValue(50, 500.0, 40.0)]);
        $table = $this->table($this->build([$this->query('h1', 'SELECT 1'), $this->query('h2', 'SELECT 2')], other: $other, keyCount: 130));

        static::assertCount(3, $table->rows);
        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn(Row $row): string => $row->key, $table->rows));
        static::assertTrue($table->rows[2]->muted);
        static::assertSame('Other queries', $table->rows[2]->cells['query']->text);
        static::assertSame('text', $table->rows[2]->cells['query']->kind);
        static::assertSame('50', $table->rows[2]->cells['executions']->text);
        static::assertSame(128, $table->remaining);
    }

    public function testTheRemainingRowsFollowTheKeysOfTheSortMetric(): void
    {
        $table = $this->table($this->build([$this->query('h1', 'SELECT 1', slow: 1)], 'problems', keyCount: 1));

        static::assertSame(0, $table->remaining);
    }

    public function testTheNoteSaysTheMostExpensiveComeFirstOnTheDefaultSortOnly(): void
    {
        $rows = [$this->query('h1', 'SELECT 1')];

        static::assertSame('the most expensive first', $this->table($this->build($rows, 'total', 'desc'))->note);
        static::assertNull($this->table($this->build($rows, 'total', 'asc'))->note);
        static::assertNull($this->table($this->build($rows, 'count', 'desc'))->note);
        static::assertNull($this->table($this->build($rows, 'problems', 'desc'))->note);
    }

    public function testTheBarIsTheShareOfTheLargestTotalShown(): void
    {
        $rows = [$this->query('h1', 'SELECT 1', 4, 200.0), $this->query('h2', 'SELECT 2', 1, 50.0)];
        $other = new ReportRow('ho', Aggregator::OTHER, null, [Metric::QUERY_DURATION => new MetricValue(9, 400.0, 100.0)]);

        $table = $this->table($this->build($rows, other: $other));
        static::assertSame(['50', '13', '100'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));
        static::assertSame(['200 ms', '50 ms', '400 ms'], array_map(static fn(Row $row): string => $row->cells['total']->text, $table->rows));

        static::assertSame(['100', '25'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $this->table($this->build($rows))->rows));

        $table = $this->table($this->build([$this->query('h1', 'SELECT 1', 2, 0.0, 0.0), $this->query('h2', 'SELECT 2', 1, 0.0, 0.0)]));
        static::assertSame(['0', '0'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));
    }

    public function testTheTagsSaySlowThenNPlusOne(): void
    {
        $other = new ReportRow('ho', Aggregator::OTHER, self::SQL, [
            Metric::QUERY_DURATION => new MetricValue(50, 500.0, 400.0),
            Metric::QUERY_SLOW => new MetricValue(3, 0.0, 0.0),
        ]);
        $view = $this->build(
            [$this->query('h1', self::SQL, 12, 1500.0, 150.0, 1234), $this->query('h2', 'SELECT 2', 3, 300.0, 150.0, 1), $this->query('h3', self::SQL), $this->query('h4', 'SELECT 4')],
            other: $other,
            nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)],
            labels: [self::QUERY_ID => self::SQL],
        );

        static::assertSame(
            [
                [['1,234 slow', 'warn'], ['N+1', 'warn']],
                [['1 slow', 'warn']],
                [['N+1', 'warn']],
                [],
                [['3 slow', 'warn']],
            ],
            array_map(self::tags(...), $this->table($view)->rows),
        );
    }

    public function testARepeatedQueryWithoutItsTextIsNotTagged(): void
    {
        $view = $this->build(
            [$this->query('h1', self::SQL), $this->query(self::QUERY_ID, null)],
            nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)],
        );

        static::assertSame([[], []], array_map(self::tags(...), $this->table($view)->rows));
        static::assertSame('(query text unavailable)', $view->tables[1]->rows[0]->cells['query']->text);
    }

    /**
     * @return iterable<string, array{int, int, int, string, string}>
     */
    public static function leads(): iterable
    {
        yield 'one query, none slow, none repeated' => [1, 0, 0, '1 SQL query in the last hour, 4 ms in total.', 'None was slow. None is repeated inside one request.'];
        yield 'one slow, one repeated' => [12, 1, 1, '12 SQL queries in the last hour, 4 ms in total.', '1 was slow. 1 query is repeated many times inside the same request.'];
        yield 'several slow, several repeated' => [1234, 17, 3, '1,234 SQL queries in the last hour, 4 ms in total.', '17 were slow. 3 queries are repeated many times inside the same request.'];
    }

    #[DataProvider('leads')]
    public function testTheLeadSumsUpThePeriod(int $executions, int $slow, int $repeated, string $sentence, string $detail): void
    {
        $nPlusOne = [];
        for ($i = 1; $i <= $repeated; ++$i) {
            $nPlusOne[] = $this->nPlusOne('n' . $i, 'GET app_' . $i, self::QUERY_ID);
        }

        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: $nPlusOne, totals: self::totals($executions, 4.0, $slow));

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
        static::assertSame($detail, $view->lead->detail);
    }

    /**
     * @return iterable<string, array{Period, string, string}>
     */
    public static function seriesStarts(): iterable
    {
        yield '1 hour' => [Period::OneHour, '1 h ago', 'last hour'];
        yield '6 hours' => [Period::SixHours, '6 h ago', 'last 6 hours'];
        yield '24 hours' => [Period::OneDay, '24 h ago', 'last 24 hours'];
        yield '7 days' => [Period::SevenDays, '7 d ago', 'last 7 days'];
    }

    #[DataProvider('seriesStarts')]
    public function testTheLeadDrawsTheQueriesOfThePeriod(Period $period, string $start, string $periodText): void
    {
        $series = range(1, 60);
        $view = $this->build([$this->query('h1', 'SELECT 1', 3, 1500.0)], series: $series, period: $period);

        static::assertNotNull($view->lead);
        static::assertSame('3 SQL queries in the ' . $periodText . ', 1.5 s in total.', $view->lead->sentence);
        static::assertSame($series, $view->lead->series);
        static::assertSame('queries', $view->lead->seriesLabel);
        static::assertSame($start, $view->lead->seriesStart);
    }

    public function testTheCardsDefineTheirFigures(): void
    {
        $view = (new QueriesScreen(true, self::checks(), 250, 7))->build(
            $this->reader([$this->query('h1', 'SELECT 1', 12, 60.0, 30.0, 2), $this->query('h2', 'SELECT 2', 3, 1500.0, 900.0, 1)], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], totals: self::totals(25, 1600.0, 3)),
            Period::SixHours,
            self::NOW,
            'total',
            'desc',
        );

        static::assertSame(
            [
                ['Queries', '25', 'Run through Doctrine DBAL.', null],
                ['SQL time', '1.6 s', 'Time your code spent waiting for the database.', null],
                ['Slow queries', '3', 'Took 250 ms or more. The limit is yours to set.', 'warn'],
                ['Repeated queries', '1', 'Same SELECT 7 times or more in one request, command or message.', 'warn'],
            ],
            array_map(static fn(Card $card): array => [$card->label, $card->value, $card->hint, $card->tone], $view->cards),
        );
        foreach ($view->cards as $card) {
            static::assertFalse($card->wide);
            static::assertSame([], $card->series);
        }
    }

    public function testCardsWithoutSlowOrRepeatedQueriesStayNeutral(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')]);

        static::assertSame(['0', '0'], [$view->cards[2]->value, $view->cards[3]->value]);
        static::assertNull($view->cards[2]->tone);
        static::assertNull($view->cards[3]->tone);
    }

    public function testTheCardsTakeTheTotalsOfThePeriodAndNotTheRowsShownWhateverTheSort(): void
    {
        $rows = [$this->query('h1', 'SELECT 1', 12, 600.0, 90.0, 0), $this->query('h2', 'SELECT 2', 3, 300.0, 200.0, 2)];

        foreach (array_keys($this->screen()->sorts()) as $sort) {
            foreach (['desc', 'asc'] as $direction) {
                $view = $this->screen()->build($this->reader($rows, keyCount: 250, series: [1, 2], totals: self::totals(500, 4200.0, 17)), Period::OneHour, self::NOW, $sort, $direction);

                static::assertSame(['500', '4.2 s', '17'], [$view->cards[0]->value, $view->cards[1]->value, $view->cards[2]->value], $sort . ' ' . $direction);
                static::assertSame('warn', $view->cards[2]->tone);
            }
        }

        $view = $this->build($rows, totals: []);
        static::assertSame(['0', '<1 ms', '0'], [$view->cards[0]->value, $view->cards[1]->value, $view->cards[2]->value]);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, int, string, string}>
     */
    public static function repeatedCounts(): iterable
    {
        $one = self::repeat('n1', 'GET app_home');
        $two = self::repeat('n2', 'POST app_cart');
        $unreadable = new ReportRow('n3', 'malformed', 'malformed', [Metric::NPLUS1_CALLS => new MetricValue(1, 10.0, 10.0)]);
        $other = new ReportRow('no', Aggregator::OTHER, null, [Metric::NPLUS1_CALLS => new MetricValue(4, 60.0, 20.0)]);

        yield 'one' => [[$one], null, 1, '1', 'Repeated query (N+1)'];
        yield 'two' => [[$one, $two], null, 2, '2', '2 repeated queries (N+1)'];
        yield 'beyond the lines read' => [[$one], null, 130, '130', '130 repeated queries (N+1)'];
        yield 'a line whose key does not read' => [[$one, $unreadable], null, 2, '2', '2 repeated queries (N+1)'];
        yield 'the other key alone' => [[], $other, 0, '1', 'Repeated query (N+1)'];
        yield 'one and the other key' => [[$one], $other, 1, '2', '2 repeated queries (N+1)'];
        yield 'two and the other key' => [[$one, $two], $other, 2, '3', '3 repeated queries (N+1)'];
    }

    /**
     * @param list<ReportRow> $nPlusOne
     */
    #[DataProvider('repeatedCounts')]
    public function testTheRepeatedQueriesAreCountedOnceForTheCheckTheLeadAndTheCard(array $nPlusOne, ?ReportRow $other, int $keyCount, string $card, string $title): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: $nPlusOne, nPlusOneKeyCount: $keyCount, nPlusOneOther: $other);

        static::assertSame($card, $view->cards[3]->value);
        static::assertSame('warn', $view->cards[3]->tone);
        static::assertNotNull($view->checks);
        static::assertSame($title, $view->checks->lines[0]->title);
        static::assertNotNull($view->lead);
        static::assertStringEndsWith($card === '1' ? ' 1 query is repeated many times inside the same request.' : ' ' . $card . ' queries are repeated many times inside the same request.', $view->lead->detail);
    }

    public function testTheChecksOfQueriesAreTold(): void
    {
        $reader = $this->reader(
            [$this->query('h1', self::SQL, 12, 1500.0, 450.0, 3), $this->query('h2', 'SELECT 2', 3, 300.0, 150.0, 1)],
            nPlusOne: [$this->nPlusOne('n1', 'app:import', self::QUERY_ID, 2, 40.0, 30.0, unitType: Entry::TYPE_COMMAND), $this->nPlusOne('n2', 'GET app_list', self::QUERY_ID)],
            labels: [self::QUERY_ID => self::SQL],
        );

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame('Worth a look', $view->checks->title);
        static::assertSame(
            [
                ['queries.slow', 'warn', 'Warning', '4 slow queries', 'The one slow most often took 100 ms or more 3 times, 450 ms at worst.', self::SQL, '', null, 'Run it with EXPLAIN and look at the indexes of the tables in its WHERE and ORDER BY.'],
                ['queries.repeated', 'warn', 'Warning', '2 repeated queries (N+1)', 'Command app:import runs the same query 20 times on average, 30 at worst. 1 more is listed on Queries.', null, '', null, 'Load the related rows in one query: a join in the first query, or one WHERE id IN (…) for the whole list.'],
            ],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->mark->label, $line->title, $line->sentence, $line->code, $line->where, $line->path, $line->action], $view->checks->lines),
        );
        static::assertNotNull($view->raised);
        static::assertSame('2 warnings', $view->raised->badge('queries')?->text);
        static::assertNull($view->raised->badge('requests'));
    }

    public function testTheCulpritOfTheSlowCheckIsNotReadTwiceWhenThePageIsSortedByProblems(): void
    {
        $rows = [$this->query('h1', self::SQL, 12, 1500.0, 450.0, 3), $this->query('h2', 'SELECT 2', 3, 300.0, 150.0, 1)];
        $slowFirst = new SlowQueriesCheck(100);

        $reader = $this->reader($rows);
        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'problems', 'desc');
        static::assertSame([Family::Query], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertNotNull($view->checks);
        static::assertSame(self::SQL, $view->checks->lines[0]->code);

        foreach ([['problems', 'asc'], ['total', 'desc'], ['count', 'desc'], ['avg', 'asc']] as [$sort, $direction]) {
            $reader = $this->reader($rows);
            $view = $this->screen()->build($reader, Period::OneHour, self::NOW, $sort, $direction);

            static::assertCount(2, $reader->queries, $sort . ' ' . $direction);
            static::assertEquals($slowFirst->query(Period::OneHour, self::NOW), $reader->queries[1], $sort . ' ' . $direction);
            static::assertNotNull($view->checks);
            static::assertSame(['queries.slow'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
        }
    }

    public function testTheRepeatedQueriesAreNotReadWhenNoneIsRaised(): void
    {
        $reader = $this->reader([$this->query('h1', self::SQL, 12, 1500.0, 450.0, 3)]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'problems', 'desc');

        static::assertSame([Family::Query], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertSame([], $reader->labelRequests);
        static::assertCount(1, $reader->totalRequests);
        static::assertCount(1, $view->tables);
        static::assertSame('0', $view->cards[3]->value);
    }

    public function testTheRepeatedQueriesAreReadAsTheirCheckReadsThem(): void
    {
        $reader = $this->reader([$this->query('h1', self::SQL)], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], labels: [self::QUERY_ID => self::SQL]);

        $view = $this->screen()->build($reader, Period::SixHours, self::NOW, 'total', 'desc');

        static::assertSame([Family::Query, Family::NPlusOne], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertEquals((new RepeatedQueriesCheck(10))->query(Period::SixHours, self::NOW), $reader->queries[1]);
        static::assertSame([[self::QUERY_ID]], $reader->labelRequests);
        static::assertNotNull($view->checks);
        static::assertSame(['queries.repeated'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testWithoutCheckTheScreenSaysWhatWasChecked(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')], period: Period::SixHours);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('Worth a look', $view->checks->title);
        static::assertSame('Nothing to flag', $view->checks->emptyTitle);
        static::assertSame('No slow query and no repeated query in the last 6 hours.', $view->checks->emptyText);
        static::assertNotNull($view->raised);
        static::assertNull($view->raised->badge('queries'));
    }

    public function testTheGuideExplainsTheTable(): void
    {
        $view = (new QueriesScreen(true, self::checks(), 250, 10))->build($this->reader([$this->query('h1', 'SELECT 1')]), Period::OneHour, self::NOW, 'total', 'desc');

        static::assertNotNull($view->guide);
        static::assertSame(
            [
                ['One line, one shape of query.', 'The values are replaced by ?, so the same query with other values counts on the same line.', []],
                ['Fast but frequent.', 'A 2 ms query run 420 times costs more than a 44 ms one run 7 times. Look at the total before the average.', []],
                ['Slow.', 'A query counts as slow from 250 ms. For those, look at the indexes of the tables in its WHERE. The server itself is on Stack.', ['Stack' => 'stack']],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $view->guide->items),
        );
    }

    public function testAnEmptyScreenReadsNoCheck(): void
    {
        $reader = $this->reader([], totals: [Metric::NPLUS1_CALLS => new MetricValue(1, 12.0, 12.0), Metric::QUERY_SLOW => new MetricValue(1, 0.0, 0.0)]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertTrue($view->isEmpty());
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
        static::assertSame([], $view->cards);
        static::assertCount(1, $view->tables);
        static::assertNull($view->lead);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
    }

    public function testOnlyOtherQueriesStillShow(): void
    {
        $other = new ReportRow('ho', Aggregator::OTHER, null, [Metric::QUERY_DURATION => new MetricValue(50, 500.0, 40.0)]);

        $view = $this->build([], other: $other);

        static::assertFalse($view->isEmpty());
        static::assertSame(['Other queries'], array_map(static fn(Row $row): string => $row->cells['query']->text, $this->table($view)->rows));
        static::assertNotNull($view->lead);
        static::assertSame('50 SQL queries in the last hour, 500 ms in total.', $view->lead->sentence);
    }

    public function testTheSecondTableIsUnchanged(): void
    {
        $reader = $this->reader(
            [$this->query('h1', self::SQL)],
            nPlusOne: [
                $this->nPlusOne('n1', 'GET app_home', self::QUERY_ID, 3, 40.0, 15.0, 90.0),
                $this->nPlusOne('n2', 'POST app_cart', 'fedcba9876543210', 1, 10.0, 10.0, 5.0),
                new ReportRow('n3', 'malformed', 'malformed', [Metric::NPLUS1_CALLS => new MetricValue(1, 10.0, 10.0)]),
            ],
            labels: [self::QUERY_ID => self::SQL],
        );

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertCount(2, $view->tables);
        $table = $view->tables[1];
        static::assertSame('nplus1', $table->id);
        static::assertSame('Repeated queries (N+1)', $table->title);
        static::assertSame('query', $table->noun);
        static::assertSame('queries', $table->nounFor(2));
        static::assertNull($table->note);
        static::assertFalse($table->partsLegend);
        static::assertSame(
            [['where', 'Where', false, null], ['query', 'Query', false, null], ['calls', 'Calls', true, null], ['avg_repeats', 'Avg repeats', true, null], ['max_repeats', 'Max repeats', true, null], ['total', 'Total time', true, null]],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertCount(2, $table->rows);
        static::assertSame([[self::QUERY_ID, 'fedcba9876543210']], $reader->labelRequests);

        $row = $table->rows[0];
        static::assertSame('n1', $row->key);
        static::assertSame(['data-jm-nplus1' => 'GET app_home', 'data-jm-repeats-max' => '15'], $row->attributes);
        static::assertSame(
            ['where' => 'app_home', 'query' => self::SQL, 'calls' => '3', 'avg_repeats' => '13.3', 'max_repeats' => '15', 'total' => '90 ms'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('route', $row->cells['where']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['where']->data);
        static::assertSame('sql', $row->cells['query']->kind);
        static::assertFalse($row->muted);

        static::assertSame('(query text unavailable)', $table->rows[1]->cells['query']->text);
        static::assertSame('text', $table->rows[1]->cells['query']->kind);
        static::assertSame('10.0', $table->rows[1]->cells['avg_repeats']->text);
    }

    public function testRepeatedQueriesOfACommandShowTheCommand(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [
            $this->nPlusOne('n1', 'app:work', self::QUERY_ID, unitType: Entry::TYPE_COMMAND),
            $this->nPlusOne('n2', 'GET app_n_plus_one', self::QUERY_ID),
        ]);
        [$command, $request] = $view->tables[1]->rows;

        static::assertSame(['data-jm-nplus1' => 'app:work', 'data-jm-repeats-max' => '12'], $command->attributes);
        static::assertSame(['app:work', 'route', ['method' => 'CLI']], [$command->cells['where']->text, $command->cells['where']->kind, $command->cells['where']->data]);
        static::assertSame(['data-jm-nplus1' => 'GET app_n_plus_one', 'data-jm-repeats-max' => '12'], $request->attributes);
        static::assertSame(['app_n_plus_one', ['method' => 'GET']], [$request->cells['where']->text, $request->cells['where']->data]);
    }

    public function testRepeatedQueriesOfATaskAreMarkedWithItsLabel(): void
    {
        $task = (new TaskKey('default', '28af6db0', 'app:work', 'every 1 hour'))->toString();
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [
            $this->nPlusOne('n1', $task, self::QUERY_ID, unitType: Entry::TYPE_TASK),
            $this->nPlusOne('n2', 'not a task key', self::QUERY_ID, unitType: Entry::TYPE_TASK),
        ]);
        [$parsed, $raw] = $view->tables[1]->rows;

        static::assertSame(['data-jm-nplus1' => 'app:work', 'data-jm-repeats-max' => '12'], $parsed->attributes);
        static::assertSame(['app:work', ['method' => 'TASK']], [$parsed->cells['where']->text, $parsed->cells['where']->data]);
        static::assertSame(['data-jm-nplus1' => 'not a task key', 'data-jm-repeats-max' => '12'], $raw->attributes);
    }

    public function testRepeatedQueriesFoldedIntoOtherComeLast(): void
    {
        $other = new ReportRow('no', Aggregator::OTHER, null, [
            Metric::NPLUS1_CALLS => new MetricValue(4, 60.0, 20.0),
            Metric::NPLUS1_DURATION => new MetricValue(4, 120.0, 50.0),
        ]);
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], nPlusOneOther: $other);
        $table = $view->tables[1];
        $last = $table->rows[1];

        static::assertCount(2, $table->rows);
        static::assertTrue($last->muted);
        static::assertSame('no', $last->key);
        static::assertSame(['data-jm-nplus1' => Aggregator::OTHER, 'data-jm-repeats-max' => '20'], $last->attributes);
        static::assertSame(
            ['where' => 'Other repeated queries', 'calls' => '4', 'avg_repeats' => '15.0', 'max_repeats' => '20', 'total' => '120 ms'],
            array_map(static fn(Cell $cell): string => $cell->text, $last->cells),
        );
        static::assertSame('text', $last->cells['where']->kind);
    }

    public function testRepeatedQueriesAllFoldedIntoOtherStillShow(): void
    {
        $other = new ReportRow('no', Aggregator::OTHER, null, [Metric::NPLUS1_CALLS => new MetricValue(1, 12.0, 12.0)]);
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOneOther: $other);

        static::assertCount(2, $view->tables);
        static::assertCount(1, $view->tables[1]->rows);
        static::assertTrue($view->tables[1]->rows[0]->muted);
        static::assertNotNull($view->checks);
        static::assertSame(
            [['queries.repeated', 'Repeated query (N+1)', 'Queries repeated 10 times or more inside one request in the last hour.']],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->title, $line->sentence], $view->checks->lines),
        );
        static::assertNotNull($view->lead);
        static::assertSame('None was slow. 1 query is repeated many times inside the same request.', $view->lead->detail);
        static::assertSame(['1', 'warn'], [$view->cards[3]->value, $view->cards[3]->tone]);
    }

    public function testRepeatedQueriesBeyondTheLimitAreCounted(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')], nPlusOne: [$this->nPlusOne('n1', 'GET app_home', self::QUERY_ID)], nPlusOneKeyCount: 130);

        static::assertSame(129, $view->tables[1]->remaining);
        static::assertSame('130', $view->cards[3]->value);
    }

    public function testThePanelOfRepeatedQueriesIsLeftOutWhenThereAreNone(): void
    {
        $view = $this->build([$this->query('h1', 'SELECT 1')]);

        static::assertCount(1, $view->tables);
        static::assertFalse($view->isEmpty());
        static::assertTrue($this->build([])->isEmpty());
    }

    private function screen(): QueriesScreen
    {
        return new QueriesScreen(true, self::checks(), 100, 10);
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck(1000), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]);
    }

    /**
     * @param list<ReportRow>                  $rows
     * @param list<int>                        $series
     * @param list<ReportRow>                  $nPlusOne
     * @param array<string, string>            $labels
     * @param array<string, MetricValue>|null $totals
     */
    private function build(
        array $rows,
        string $sort = 'total',
        string $direction = 'desc',
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        array $nPlusOne = [],
        ?int $nPlusOneKeyCount = null,
        Period $period = Period::OneHour,
        ?ReportRow $nPlusOneOther = null,
        ?array $totals = null,
        array $labels = [],
    ): ScreenView {
        return $this->screen()->build($this->reader($rows, $other, $keyCount, $series, $nPlusOne, $nPlusOneKeyCount, $labels, $nPlusOneOther, $totals), $period, self::NOW, $sort, $direction);
    }

    /**
     * @param list<ReportRow>                  $rows
     * @param list<int>                        $series
     * @param list<ReportRow>                  $nPlusOne
     * @param array<string, string>            $labels
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private function reader(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        array $nPlusOne = [],
        ?int $nPlusOneKeyCount = null,
        array $labels = [],
        ?ReportRow $nPlusOneOther = null,
        ?array $totals = null,
    ): FixedReader {
        $totals ??= self::rowTotals([...$rows, ...($other === null ? [] : [$other])]);
        $queries = new Report($rows, $other, $keyCount ?? \count($rows), $series, $totals);
        $repeated = new Report($nPlusOne, $nPlusOneOther, $nPlusOneKeyCount ?? \count($nPlusOne));

        if ($nPlusOne !== [] || $nPlusOneOther !== null) {
            $totals[Metric::NPLUS1_CALLS] = new MetricValue(\count($nPlusOne) + ($nPlusOneOther === null ? 0 : 1), 12.0, 12.0);
        }

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::NPlusOne ? $repeated : $queries, $labels, $totals);
    }

    /** @return array<string, MetricValue> */
    private static function totals(int $executions, float $totalMs, int $slow): array
    {
        $totals = [Metric::QUERY_DURATION => new MetricValue($executions, $totalMs, 0.0)];
        if ($slow > 0) {
            $totals[Metric::QUERY_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }

        return $totals;
    }

    /**
     * @param list<ReportRow> $rows
     *
     * @return array<string, MetricValue>
     */
    private static function rowTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach ($row->metrics as $type => $value) {
                $total = $totals[$type] ?? new MetricValue(0, 0.0, 0.0);
                $totals[$type] = new MetricValue($total->count + $value->count, $total->sum + $value->sum, max($total->max, $value->max));
            }
        }

        return $totals;
    }

    /** @return list<array{string, string}> */
    private static function tags(Row $row): array
    {
        return array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags);
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    private function query(string $hash, ?string $sql, int $count = 1, float $sumMs = 2.0, float $maxMs = 2.0, int $slow = 0): ReportRow
    {
        $metrics = [Metric::QUERY_DURATION => new MetricValue($count, $sumMs, $maxMs)];
        if ($slow > 0) {
            $metrics[Metric::QUERY_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }

        return new ReportRow($hash, $sql ?? $hash, $sql, $metrics);
    }

    private function nPlusOne(string $hash, string $entryPoint, string $queryId, int $requests = 1, float $repeats = 12.0, float $maxRepeats = 12.0, float $durationMs = 24.0, string $unitType = Entry::TYPE_REQUEST): ReportRow
    {
        $key = $unitType . Entry::KEY_SEPARATOR . $entryPoint . Entry::KEY_SEPARATOR . $queryId;

        return new ReportRow($hash, $key, $key, [
            Metric::NPLUS1_CALLS => new MetricValue($requests, $repeats, $maxRepeats),
            Metric::NPLUS1_DURATION => new MetricValue($requests, $durationMs, $durationMs),
        ]);
    }

    private static function repeat(string $hash, string $entryPoint): ReportRow
    {
        $key = Entry::TYPE_REQUEST . Entry::KEY_SEPARATOR . $entryPoint . Entry::KEY_SEPARATOR . self::QUERY_ID;

        return new ReportRow($hash, $key, $key, [
            Metric::NPLUS1_CALLS => new MetricValue(1, 12.0, 12.0),
            Metric::NPLUS1_DURATION => new MetricValue(1, 24.0, 24.0),
        ]);
    }
}
