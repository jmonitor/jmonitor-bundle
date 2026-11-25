<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Screen\RequestsScreen;
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

final class RequestsScreenTest extends TestCase
{
    private const NOW = 1800000000;
    private const THRESHOLD = 1000;
    private const QUERY_ID = '0123456789abcdef';

    public function testItReadsTheRequestFamilyWithItsSeries(): void
    {
        $reader = $this->reader([$this->route('GET app_a')], series: array_fill(0, 60, 1));

        $view = $this->screen()->build($reader, Period::SixHours, self::NOW, 'total', 'desc');

        static::assertCount(1, $reader->queries);
        $query = $reader->queries[0];
        static::assertSame(Family::Request, $query->family);
        static::assertSame(Period::SixHours, $query->period);
        static::assertSame(self::NOW, $query->now);
        static::assertSame(Metric::REQUEST_DURATION, $query->seriesMetric);
        static::assertNotNull($view->lead);
        static::assertSame(array_fill(0, 60, 1), $view->lead->series);
    }

    public function testTheScreenDeclaresItsSortsWithTotalTimeFirst(): void
    {
        $screen = $this->screen();

        static::assertSame('requests', $screen->id());
        static::assertSame('Requests', $screen->label());
        static::assertSame('Traffic and response times per route', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['total' => 'desc', 'route' => 'asc', 'requests' => 'desc', 'avg' => 'desc', 'p95' => 'desc', 'max' => 'desc', 'problems' => 'desc', 'memory' => 'desc'],
            $screen->sorts(),
        );
    }

    public function testTheColumnsOfTheTable(): void
    {
        $table = $this->table($this->build([$this->route('GET app_a')]));

        static::assertSame(
            [
                ['route', 'Route', false, 'route'],
                ['requests', 'Requests', true, 'requests'],
                ['total', 'Total time', true, 'total'],
                ['avg', 'Avg', true, 'avg'],
                ['p95', 'p95', true, 'p95'],
                ['max', 'Max', true, 'max'],
                ['split', 'Where the average goes', false, null],
                ['problems', 'Problems', false, 'problems'],
                ['memory', 'Memory', true, 'memory'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('routes', $table->id);
        static::assertSame('Routes', $table->title);
        static::assertTrue($table->partsLegend);
    }

    public function testEverySortKey(): void
    {
        $rows = [
            $this->route('POST app_b', 40, 500.0, 90.0, ['25' => 40], 2, 0, 4096),
            $this->route('GET app_a', 7, 560.0, 400.0, ['250' => 7], 0, 3, 1024),
            $this->route('GET app_d', 120, 384.0, 15.0, ['10' => 100, '25' => 20], 1, 2, 2048),
            $this->route('DELETE app_c', 1, 900.0, 900.0, ['1000' => 1], 3, 1, 8192),
        ];
        $descending = [
            'total' => ['c', 'a', 'b', 'd'],
            'route' => ['d', 'c', 'b', 'a'],
            'requests' => ['d', 'b', 'a', 'c'],
            'avg' => ['c', 'a', 'b', 'd'],
            'p95' => ['c', 'a', 'b', 'd'],
            'max' => ['c', 'a', 'b', 'd'],
            'problems' => ['c', 'b', 'd', 'a'],
            'memory' => ['c', 'b', 'd', 'a'],
        ];

        static::assertSame(array_keys($this->screen()->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->order($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->order($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testSortingByProblemsPutsServerErrorsFirst(): void
    {
        $rows = [
            $this->route('GET app_slow', slow: 9),
            $this->route('GET app_error', errors: 1),
            $this->route('GET app_list'),
            $this->route('GET app_both', errors: 1, slow: 1),
            $this->route('GET app_clean'),
            $this->route('GET app_also_clean'),
        ];
        $nPlusOne = [$this->repeat(Entry::TYPE_REQUEST, 'GET app_list')];

        static::assertSame(
            ['GET app_both', 'GET app_error', 'GET app_slow', 'GET app_list', 'GET app_also_clean', 'GET app_clean'],
            $this->routes($this->build($rows, 'problems', 'desc', nPlusOne: $nPlusOne)),
        );
        static::assertSame(
            ['GET app_also_clean', 'GET app_clean', 'GET app_list', 'GET app_slow', 'GET app_error', 'GET app_both'],
            $this->routes($this->build($rows, 'problems', 'asc', nPlusOne: $nPlusOne)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function removedSorts(): iterable
    {
        yield 'sql' => ['sql'];
        yield 'http' => ['http'];
        yield 'errors' => ['errors'];
        yield 'slow' => ['slow'];
    }

    #[DataProvider('removedSorts')]
    public function testARemovedSortFallsBackToTheDefault(string $sort): void
    {
        $reader = $this->reader([$this->route('GET app_a', 1, 10.0), $this->route('GET app_b', 1, 30.0)]);
        $page = (new ScreenBuilder($reader, true, new MockClock('@' . self::NOW)))->create($this->screen(), '1h', $sort, 'asc');

        static::assertSame('total', $page->sort);
        static::assertSame('desc', $page->direction);
        $tables = array_values(array_filter(array_map(static fn($block): mixed => $block->content, $page->blocks), static fn(mixed $content): bool => $content instanceof Table));
        static::assertCount(1, $tables);
        static::assertSame(['GET app_b', 'GET app_a'], array_map(static fn(Row $row): string => $row->attributes['data-jm-route'], $tables[0]->rows));
    }

    public function testTheCellsOfARoute(): void
    {
        $view = $this->build([$this->route('GET app_a', 7, 560.0, 400.0, ['250' => 7], 0, 3, 1024, 7, 350.0, 7.0)]);
        $row = $this->table($view)->rows[0];

        static::assertSame(['data-jm-route' => 'GET app_a', 'data-jm-requests' => '7'], $row->attributes);
        static::assertFalse($row->muted);
        static::assertSame(['route', 'requests', 'total', 'avg', 'p95', 'max', 'split', 'problems', 'memory'], array_keys($row->cells));
        static::assertSame(
            ['route' => 'app_a', 'requests' => '7', 'total' => '560 ms', 'avg' => '80 ms', 'p95' => '243 ms', 'max' => '400 ms', 'split' => 'SQL 50 ms · 1.0 queries', 'problems' => '', 'memory' => '1 KB'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('route', $row->cells['route']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['route']->data);
        static::assertSame('bar', $row->cells['total']->kind);
        static::assertSame('split', $row->cells['split']->kind);
        static::assertSame('tags', $row->cells['problems']->kind);
        static::assertSame('warn', $row->cells['p95']->tone);
        static::assertSame('warn', $row->cells['max']->tone);
        static::assertNull($row->cells['total']->tone);
        static::assertNull($row->cells['memory']->tone);
    }

    public function testHighlightsFollowSlowRequests(): void
    {
        $row = $this->table($this->build([$this->route('GET app_a', 100, 1000.0, 50.0, ['25' => 100], 4, 0)]))->rows[0];

        static::assertNull($row->cells['p95']->tone);
        static::assertNull($row->cells['max']->tone);

        $row = $this->table($this->build([$this->route('GET app_a', 100, 1000.0, 50.0, ['25' => 100], 0, 5)]))->rows[0];
        static::assertNull($row->cells['p95']->tone);
        static::assertSame('warn', $row->cells['max']->tone);

        $row = $this->table($this->build([$this->route('GET app_a', 100, 1000.0, 50.0, ['25' => 100], 0, 6)]))->rows[0];
        static::assertSame('warn', $row->cells['p95']->tone);
        static::assertSame('warn', $row->cells['max']->tone);
    }

    public function testARouteNameContainingSpacesKeepsItsMethodApart(): void
    {
        $row = $this->table($this->build([$this->route('GET (unmatched)'), $this->route('OTHER app_hello')]))->rows;

        static::assertSame('(unmatched)', $row[0]->cells['route']->text);
        static::assertSame(['method' => 'GET'], $row[0]->cells['route']->data);
        static::assertSame(['method' => 'OTHER'], $row[1]->cells['route']->data);
    }

    public function testTheFootnoteNamesTheRequestsThatMatchedNoRoute(): void
    {
        static::assertSame('(unmatched): requests that matched no route.', $this->table($this->build([$this->route('GET (unmatched)'), $this->route('GET app_a')]))->footnote);
        static::assertNull($this->table($this->build([$this->route('GET app_a')]))->footnote);

        $rows = [$this->route('GET (unmatched)', 1, 0.5, 0.5)];
        for ($i = 1; $i <= 100; ++$i) {
            $rows[] = $this->route(sprintf('GET app_%03d', $i), 1, 10.0 + $i);
        }
        static::assertNull($this->table($this->build($rows))->footnote, 'Only a route shown in the table calls for the footnote.');
    }

    public function testTheNoteSaysTheMostExpensiveComeFirstOnTheDefaultSortOnly(): void
    {
        $rows = [$this->route('GET app_a'), $this->route('GET app_b')];

        static::assertSame('the most expensive first', $this->table($this->build($rows, 'total', 'desc'))->note);
        static::assertNull($this->table($this->build($rows, 'total', 'asc'))->note);
        static::assertNull($this->table($this->build($rows, 'requests', 'desc'))->note);
    }

    public function testTheBarIsTheShareOfTheLargestTotalShown(): void
    {
        $rows = [$this->route('GET app_a', 2, 200.0, 100.0), $this->route('GET app_b', 1, 50.0, 50.0)];
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(4, 400.0, 100.0));

        $table = $this->table($this->build($rows, other: $other));
        static::assertSame(['50', '13', '100'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));
        static::assertSame(['200 ms', '50 ms', '400 ms'], array_map(static fn(Row $row): string => $row->cells['total']->text, $table->rows));

        $table = $this->table($this->build($rows));
        static::assertSame(['100', '25'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));

        $rows = [];
        for ($i = 1; $i <= 101; ++$i) {
            $rows[] = $this->route(sprintf('GET app_%03d', $i), 1, $i === 101 ? 1000.0 : 10.0);
        }
        $table = $this->table($this->build($rows, 'route', 'asc'));
        static::assertCount(100, $table->rows);
        static::assertSame('100', $table->rows[0]->cells['total']->data['share'], 'The largest total is the one of a route shown.');

        $table = $this->table($this->build([$this->route('GET app_a', 2, 0.0, 0.0), $this->route('GET app_b', 1, 0.0, 0.0)]));
        static::assertSame(['0', '0'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));
    }

    /**
     * @return iterable<string, array{ReportRow, array{php: float, sql: float, http: float}, string}>
     */
    public static function splits(): iterable
    {
        yield 'SQL, HTTP and PHP' => [self::splitRoute(10, 1440.0, 10, 100.0, 50.0, 10, 570.0), ['php' => 77.0, 'sql' => 10.0, 'http' => 57.0], 'HTTP out 57 ms · 5.0 queries'];
        yield 'SQL longer than HTTP' => [self::splitRoute(4, 400.0, 4, 68.0, 48.0, 2, 20.0), ['php' => 78.0, 'sql' => 17.0, 'http' => 5.0], 'SQL 17 ms · 12 queries'];
        yield 'SQL and HTTP equal' => [self::splitRoute(2, 100.0, 2, 20.0, 2.0, 2, 20.0), ['php' => 30.0, 'sql' => 10.0, 'http' => 10.0], 'SQL 10 ms · 1.0 queries'];
        yield 'neither SQL nor HTTP' => [self::splitRoute(3, 90.0), ['php' => 30.0, 'sql' => 0.0, 'http' => 0.0], 'PHP only'];
        yield 'HTTP without SQL' => [self::splitRoute(2, 200.0, 0, 0.0, 0.0, 2, 114.0), ['php' => 43.0, 'sql' => 0.0, 'http' => 57.0], 'HTTP out 57 ms'];
        yield 'SQL and HTTP longer than the average' => [self::splitRoute(2, 100.0, 2, 80.0, 6.0, 2, 90.0), ['php' => 0.0, 'sql' => 40.0, 'http' => 45.0], 'HTTP out 45 ms · 3.0 queries'];
        yield 'an average of zero' => [self::splitRoute(2, 0.0, 2, 0.0, 4.0), ['php' => 0.0, 'sql' => 0.0, 'http' => 0.0], 'SQL <1 ms · 2.0 queries'];
        yield 'SQL on a part of the requests' => [self::splitRoute(4, 400.0, 1, 40.0, 3.0), ['php' => 90.0, 'sql' => 10.0, 'http' => 0.0], 'SQL 10 ms · 0.8 queries'];
    }

    /**
     * @param array{php: float, sql: float, http: float} $parts
     */
    #[DataProvider('splits')]
    public function testTheSplitOfTheAverage(ReportRow $route, array $parts, string $text): void
    {
        $split = $this->table($this->build([$route]))->rows[0]->cells['split'];

        static::assertSame($text, $split->text);
        static::assertSame($parts, $split->parts);
    }

    public function testTheNumberOfQueriesChoosesItsDecimalsOnceRounded(): void
    {
        static::assertSame('SQL 5 ms · 9.9 queries', $this->table($this->build([self::splitRoute(100, 10000.0, 100, 500.0, 994.0)]))->rows[0]->cells['split']->text);
        static::assertSame('SQL 5 ms · 10 queries', $this->table($this->build([self::splitRoute(100, 10000.0, 100, 500.0, 996.0)]))->rows[0]->cells['split']->text);
    }

    public function testTheOtherRoutesFollowTheSameSplit(): void
    {
        $other = new ReportRow('h', Aggregator::OTHER, null, self::splitRoute(10, 1440.0, 10, 100.0, 50.0, 10, 570.0)->metrics);

        $last = $this->table($this->build([], other: $other))->rows[0];

        static::assertTrue($last->muted);
        static::assertSame('HTTP out 57 ms · 5.0 queries', $last->cells['split']->text);
        static::assertSame(['php' => 77.0, 'sql' => 10.0, 'http' => 57.0], $last->cells['split']->parts);
    }

    public function testTheTagsComeInOrder(): void
    {
        $rows = [
            $this->route('GET app_all', 10, 1000.0, 5000.0, errors: 1234, slow: 2),
            $this->route('GET app_slow', 10, 900.0, 5000.0, slow: 1),
            $this->route('GET app_clean', 10, 800.0),
        ];
        $table = $this->table($this->build($rows, nPlusOne: [$this->repeat(Entry::TYPE_REQUEST, 'GET app_all'), $this->repeat(Entry::TYPE_REQUEST, 'GET app_clean')]));

        static::assertSame(
            [
                [['1,234 × 5xx', 'bad'], ['2 slow', 'warn'], ['N+1', 'warn']],
                [['1 slow', 'warn']],
                [['N+1', 'warn']],
            ],
            array_map(static fn(Row $row): array => array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags), $table->rows),
        );
    }

    public function testTheOtherRoutesNeverCarryNPlusOne(): void
    {
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(50, 5000.0, 900.0) + [Metric::REQUEST_STATUS_PREFIX . '5xx' => new MetricValue(2, 0.0, 0.0)]);

        $rows = $this->table($this->build([$this->route('GET app_a')], other: $other, nPlusOne: [$this->repeat(Entry::TYPE_REQUEST, Aggregator::OTHER), $this->repeat(Entry::TYPE_REQUEST, 'GET app_a')]))->rows;

        static::assertSame(['N+1'], array_map(static fn(Tag $tag): string => $tag->text, $rows[0]->cells['problems']->tags));
        static::assertSame(['2 × 5xx'], array_map(static fn(Tag $tag): string => $tag->text, $rows[1]->cells['problems']->tags));
    }

    public function testARouteThatRepeatsAQueryIsTaggedAndBringsTheCheckOfQueries(): void
    {
        $reader = $this->reader(
            [$this->route('GET app_list', 3, 300.0), $this->route('GET app_show', 2, 100.0)],
            nPlusOne: [$this->repeat(Entry::TYPE_COMMAND, 'app:import', 40.0, 2, 30.0), $this->repeat(Entry::TYPE_REQUEST, 'GET app_list', 24.0, 2, 14.0)],
        );

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertSame([Family::Request, Family::NPlusOne], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertEquals((new RepeatedQueriesCheck(10))->query(Period::OneHour, self::NOW), $reader->queries[1]);
        static::assertCount(1, $reader->totalRequests);
        static::assertSame([['N+1'], []], array_map(static fn(Row $row): array => array_map(static fn(Tag $tag): string => $tag->text, $row->cells['problems']->tags), $this->table($view)->rows));

        static::assertNotNull($view->checks);
        static::assertSame('Worth a look', $view->checks->title);
        static::assertCount(1, $view->checks->lines);
        $line = $view->checks->lines[0];
        static::assertSame('queries.repeated', $line->id);
        static::assertSame('warn', $line->mark->tone);
        static::assertSame('Warning', $line->mark->label);
        static::assertSame('2 repeated queries (N+1)', $line->title);
        static::assertSame('Command app:import runs the same query 20 times on average, 30 at worst. 1 more is listed on Queries.', $line->sentence);
        static::assertSame('Queries', $line->where);
        static::assertSame('queries', $line->path);
        static::assertSame(['period' => '1h'], $line->query);
        static::assertNotNull($view->raised);
        static::assertTrue($view->raised->has('queries.repeated'));
        static::assertNull($view->raised->badge('requests'), 'The check of Queries shown on Requests does not count on the tab of Requests.');
    }

    public function testARepeatOnARouteBeyondTheHundredShownStillBringsTheCheck(): void
    {
        $rows = [];
        for ($i = 1; $i <= 101; ++$i) {
            $rows[] = $this->route(sprintf('GET app_%03d', $i), 1, (float) $i);
        }

        $view = $this->build($rows, nPlusOne: [$this->repeat(Entry::TYPE_REQUEST, 'GET app_001')]);

        static::assertCount(100, $this->table($view)->rows);
        static::assertNotNull($view->checks);
        static::assertSame(['queries.repeated'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testARepeatInACommandOnlyDoesNotShowOnRequests(): void
    {
        $reader = $this->reader([$this->route('GET app_list')], nPlusOne: [$this->repeat(Entry::TYPE_COMMAND, 'app:import'), $this->repeat(Entry::TYPE_REQUEST, 'GET app_gone')]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertSame([Family::Request, Family::NPlusOne], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertSame([], $this->table($view)->rows[0]->cells['problems']->tags);
        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertNotNull($view->raised);
        static::assertTrue($view->raised->has('queries.repeated'));
    }

    public function testARepeatInACommandNamedLikeARouteDoesNotTagTheRoute(): void
    {
        $view = $this->build([$this->route('GET app_list')], nPlusOne: [$this->repeat(Entry::TYPE_COMMAND, 'GET app_list')]);

        static::assertSame([], $this->table($view)->rows[0]->cells['problems']->tags);
    }

    public function testTheRepeatedQueriesAreNotReadWhenNoneIsRaised(): void
    {
        $reader = $this->reader([$this->route('GET app_list', errors: 2, slow: 1)]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertSame([Family::Request], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
        static::assertCount(1, $reader->totalRequests);
        static::assertNotNull($view->checks);
        static::assertSame(['requests.server_errors', 'requests.slow'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testTheChecksOfRequestsAreTold(): void
    {
        $reader = $this->reader([
            $this->route('GET app_list', 4, 9000.0, 4000.0, slow: 3),
            $this->route('POST app_save', 4, 400.0, 100.0, errors: 2),
            $this->route('GET app_show', 4, 400.0, 1500.0, errors: 1, slow: 1),
        ]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame(
            [
                ['requests.server_errors', 'bad', 'Critical', '3 server errors', 'POST app_save answered with a 5xx status 2 times. 1 other route did too.', '', null, 'Open Exceptions to see what was thrown, then look for it in your logs.'],
                ['requests.slow', 'warn', 'Warning', '4 slow requests', 'GET app_list took 1.0 s or more 3 times, 4.0 s at worst. 1 other route did too.', '', null, 'Look at where the average of the route goes in the table: your PHP code, SQL or another site.'],
            ],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->mark->label, $line->title, $line->sentence, $line->where, $line->path, $line->action], $view->checks->lines),
        );
        static::assertCount(1, $reader->queries, 'The checks of Requests reuse the report of the screen.');
        static::assertNotNull($view->raised);
        static::assertSame('2 critical', $view->raised->badge('requests')?->text);
    }

    public function testWithoutCheckTheScreenSaysWhatWasChecked(): void
    {
        $view = $this->build([$this->route('GET app_list')], period: Period::SixHours);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('Worth a look', $view->checks->title);
        static::assertSame('Nothing to flag', $view->checks->emptyTitle);
        static::assertSame('No server error, no slow request and no route that repeats a query in the last 6 hours.', $view->checks->emptyText);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, string, string}>
     */
    public static function leads(): iterable
    {
        yield 'one request' => [
            [self::splitRoute(1, 42.0)],
            '1 request in the last hour, answered in 42 ms.',
            'No server error, no slow request.',
        ];
        yield 'one route, no problem' => [
            [self::splitRoute(4, 120.0)],
            '4 requests in the last hour, 95 % answered in under 10 ms.',
            'No server error, no slow request.',
        ];
        yield 'one of each' => [
            [self::splitRoute(3, 3000.0, errors: 1, slow: 1, key: 'GET app_a'), self::splitRoute(1, 1000.0, key: 'GET app_b')],
            '4 requests in the last hour, 95 % answered in under 10 ms.',
            '1 server error, 1 slow request. The most expensive route takes 75 % of the total time.',
        ];
        yield 'several of each' => [
            [self::splitRoute(1200, 3000.0, errors: 1100, slow: 2, key: 'GET app_a'), self::splitRoute(3, 1000.0, errors: 1, slow: 1, key: 'GET app_b')],
            '1,203 requests in the last hour, 95 % answered in under 10 ms.',
            '1,101 server errors, 3 slow requests. The most expensive route takes 75 % of the total time.',
        ];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('leads')]
    public function testTheLeadSumsUpThePeriod(array $rows, string $sentence, string $detail): void
    {
        $view = $this->build($rows);

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
        static::assertSame($detail, $view->lead->detail);
    }

    public function testTheLeadCountsTheOtherRoutes(): void
    {
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(2, 2000.0, 1000.0) + [
            Metric::REQUEST_DURATION_LE_PREFIX . '1000' => new MetricValue(2, 0.0, 0.0),
            Metric::REQUEST_STATUS_PREFIX . '5xx' => new MetricValue(1, 0.0, 0.0),
        ]);

        $view = $this->build([$this->route('GET app_a', 2, 200.0, 100.0, ['100' => 2]), $this->route('GET app_b', 1, 300.0, 300.0, ['500' => 1])], other: $other, period: Period::OneDay);

        static::assertNotNull($view->lead);
        static::assertSame('5 requests in the last 24 hours, 95 % answered in under 938 ms.', $view->lead->sentence);
        static::assertSame('1 server error, no slow request. The most expensive route takes 12 % of the total time.', $view->lead->detail);
        static::assertSame('938 ms', $view->cards[1]->value, 'The p95 of the sentence is the one of the card.');
    }

    public function testTheShareOfTheMostExpensiveRouteNeedsTwoRoutes(): void
    {
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(2, 2000.0, 1000.0));

        $view = $this->build([$this->route('GET app_a', 2, 200.0, 100.0)], other: $other);

        static::assertNotNull($view->lead);
        static::assertSame('No server error, no slow request.', $view->lead->detail);
    }

    /**
     * @return iterable<string, array{Period, string}>
     */
    public static function seriesStarts(): iterable
    {
        yield '1 hour' => [Period::OneHour, '1 h ago'];
        yield '6 hours' => [Period::SixHours, '6 h ago'];
        yield '24 hours' => [Period::OneDay, '24 h ago'];
        yield '7 days' => [Period::SevenDays, '7 d ago'];
    }

    #[DataProvider('seriesStarts')]
    public function testTheLeadDrawsTheRequestsOfThePeriod(Period $period, string $start): void
    {
        $series = range(1, 60);
        $view = $this->screen()->build($this->reader([$this->route('GET app_a')], series: $series), $period, self::NOW, 'total', 'desc');

        static::assertNotNull($view->lead);
        static::assertSame($series, $view->lead->series);
        static::assertSame('requests', $view->lead->seriesLabel);
        static::assertSame($start, $view->lead->seriesStart);
    }

    public function testTheCardsDefineTheirFigures(): void
    {
        $view = $this->screen(250)->build($this->reader([
            $this->route('GET app_home', 3, 120.0, 60.0, ['25' => 1, '50' => 1, '100' => 1], 1),
            $this->route('POST app_cart_add', 1, 1500.0, 1500.0, ['2500' => 1], slow: 1),
        ]), Period::SixHours, self::NOW, 'total', 'desc');

        static::assertSame(
            [
                ['Average', '405 ms', 'The mean response time.', null],
                ['p95', '1.5 s', '95 % of requests are faster. What the unlucky visitors feel.', null],
                ['Slowest', '1.5 s', 'The single worst request of the last 6 hours.', null],
                ['Server errors', '25 %', 'Responses with a 5xx status: your code or a server failed.', 'bad'],
                ['Slow requests', '1', 'Took 250 ms or more. The limit is yours to set.', 'warn'],
            ],
            array_map(static fn(Card $card): array => [$card->label, $card->value, $card->hint, $card->tone], $view->cards),
        );
        foreach ($view->cards as $card) {
            static::assertFalse($card->wide);
            static::assertSame([], $card->series);
        }
    }

    public function testNoErrorNorSlowRequestLeavesTheCardsNeutral(): void
    {
        $view = $this->build([$this->route('GET app_home')]);

        static::assertSame(['0 %', '0'], [$view->cards[3]->value, $view->cards[4]->value]);
        static::assertNull($view->cards[3]->tone);
        static::assertNull($view->cards[4]->tone);
        static::assertSame('Took 1.0 s or more. The limit is yours to set.', $view->cards[4]->hint);
    }

    public function testTheGuideExplainsTheTable(): void
    {
        $view = $this->build([$this->route('GET app_home')]);

        static::assertNotNull($view->guide);
        static::assertSame(
            [
                ['Total time.', 'Requests × average. The table is sorted by it because it shows where an improvement pays the most.', []],
                ['Avg, p95, max.', 'The average hides the bad moments. p95 leaves out the slowest 5 %, max is the single worst request.', []],
                ['Where the average goes.', 'What an average request of the route waits for: your PHP code, SQL, then calls to other sites. A long SQL part sends you to Queries, a long HTTP part to Cache & HTTP.', ['Queries' => 'queries', 'Cache & HTTP' => 'cache-http']],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $view->guide->items),
        );
    }

    public function testAnEmptyScreenReadsNoCheck(): void
    {
        $reader = $this->reader([], totals: [Metric::NPLUS1_CALLS => new MetricValue(1, 12.0, 12.0)]);

        $view = $this->screen()->build($reader, Period::OneHour, self::NOW, 'total', 'desc');

        static::assertTrue($view->isEmpty());
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
        static::assertNull($view->lead);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
    }

    public function testOtherRoutesComeLastWhateverTheSort(): void
    {
        $rows = [$this->route('GET app_a', 2, 20.0, 10.0), $this->route('GET app_b', 3, 30.0, 10.0)];
        $other = new ReportRow('h', Aggregator::OTHER, null, $this->metrics(50, 5000.0, 900.0));

        foreach ([['total', 'desc'], ['total', 'asc'], ['route', 'asc'], ['requests', 'desc'], ['problems', 'desc']] as [$sort, $direction]) {
            $view = $this->build($rows, $sort, $direction, $other);
            $last = $this->table($view)->rows[2];

            static::assertCount(3, $this->table($view)->rows);
            static::assertTrue($last->muted);
            static::assertSame('Other routes', $last->cells['route']->text);
            static::assertSame('text', $last->cells['route']->kind);
            static::assertSame('50', $last->cells['requests']->text);
            static::assertNotNull($view->lead);
            static::assertStringStartsWith('55 requests', $view->lead->sentence);
        }

        static::assertFalse($this->build([], other: $other)->isEmpty());
        static::assertTrue($this->build([])->isEmpty());
    }

    public function testTheTableShowsAHundredRoutesAndCountsTheOthers(): void
    {
        $rows = [];
        for ($i = 1; $i <= 105; ++$i) {
            $rows[] = $this->route(sprintf('GET app_%03d', $i), 1, (float) $i, (float) $i);
        }

        $table = $this->table($this->build($rows));

        static::assertCount(100, $table->rows);
        static::assertSame(5, $table->remaining);
        static::assertSame('route', $table->noun);
        static::assertSame('GET app_105', $table->rows[0]->attributes['data-jm-route']);
        static::assertSame('GET app_006', $table->rows[99]->attributes['data-jm-route']);
    }

    private function screen(int $threshold = self::THRESHOLD): RequestsScreen
    {
        return new RequestsScreen(new ApplicationChecks([new ServerErrorsCheck(), new SlowRequestsCheck($threshold), new SlowQueriesCheck(100), new RepeatedQueriesCheck(10)]), $threshold);
    }

    /**
     * @param list<ReportRow>             $rows
     * @param list<ReportRow>             $nPlusOne   the rows of the repeated queries
     * @param list<int>                   $series
     * @param ?array<string, MetricValue> $totals     derived from the rows when null
     */
    private function reader(array $rows, ?ReportRow $other = null, array $nPlusOne = [], array $series = [], ?array $totals = null): FixedReader
    {
        $requests = new Report($rows, $other, \count($rows), $series === [] ? array_fill(0, 60, 0) : $series);
        $repeats = new Report($nPlusOne, keyCount: \count($nPlusOne));

        if ($totals === null) {
            $totals = [];
            foreach ([...$rows, ...($other === null ? [] : [$other])] as $row) {
                foreach ([Metric::REQUEST_STATUS_PREFIX . '5xx', Metric::REQUEST_SLOW] as $metric) {
                    if ($row->count($metric) > 0) {
                        $totals[$metric] = new MetricValue(($totals[$metric]->count ?? 0) + $row->count($metric), 0.0, 0.0);
                    }
                }
            }
            if ($nPlusOne !== []) {
                $totals[Metric::NPLUS1_CALLS] = new MetricValue(\count($nPlusOne), 12.0, 12.0);
            }
        }

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::NPlusOne ? $repeats : $requests, totals: $totals);
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<ReportRow> $nPlusOne
     */
    private function build(array $rows, string $sort = 'total', string $direction = 'desc', ?ReportRow $other = null, Period $period = Period::OneHour, array $nPlusOne = []): ScreenView
    {
        return $this->screen()->build($this->reader($rows, $other, $nPlusOne), $period, self::NOW, $sort, $direction);
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    /** @return list<string> the last letter of each route name, in display order */
    private function order(ScreenView $view): array
    {
        return array_map(static fn(string $route): string => substr($route, -1), $this->routes($view));
    }

    /** @return list<string> */
    private function routes(ScreenView $view): array
    {
        return array_map(static fn(Row $row): string => $row->attributes['data-jm-route'], $this->table($view)->rows);
    }

    private function repeat(string $unitType, string $unitKey, float $calls = 12.0, int $requests = 1, float $max = 12.0): ReportRow
    {
        $key = $unitType . Entry::KEY_SEPARATOR . $unitKey . Entry::KEY_SEPARATOR . self::QUERY_ID;

        return new ReportRow(md5($key), $key, null, [
            Metric::NPLUS1_CALLS => new MetricValue($requests, $calls, $max),
            Metric::NPLUS1_DURATION => new MetricValue($requests, 10.0, 10.0),
        ]);
    }

    /**
     * @param array<string, int> $buckets
     */
    private function route(
        string $key,
        int $requests = 1,
        float $totalMs = 10.0,
        float $maxMs = 10.0,
        array $buckets = ['10' => 1],
        int $errors = 0,
        int $slow = 0,
        int $memory = 1024,
        int $sqlRequests = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpRequests = 0,
        float $httpTime = 0.0,
    ): ReportRow {
        $metrics = $this->metrics($requests, $totalMs, $maxMs);
        $metrics[Metric::REQUEST_MEMORY] = new MetricValue($requests, (float) ($memory * $requests), (float) $memory);
        foreach ($buckets as $bound => $count) {
            $metrics[Metric::REQUEST_DURATION_LE_PREFIX . $bound] = new MetricValue($count, 0.0, 0.0);
        }
        if ($errors > 0) {
            $metrics[Metric::REQUEST_STATUS_PREFIX . '5xx'] = new MetricValue($errors, 0.0, 0.0);
        }
        if ($slow > 0) {
            $metrics[Metric::REQUEST_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }
        if ($sqlRequests > 0) {
            $metrics[Metric::REQUEST_SQL_TIME] = new MetricValue($sqlRequests, $sqlTime, $sqlTime);
            $metrics[Metric::REQUEST_SQL_COUNT] = new MetricValue($sqlRequests, $sqlCount, $sqlCount);
        }
        if ($httpRequests > 0) {
            $metrics[Metric::REQUEST_HTTP_TIME] = new MetricValue($httpRequests, $httpTime, $httpTime);
        }

        return new ReportRow(md5($key), $key, $key, $metrics);
    }

    /** a route of the given requests and total time, with ten milliseconds as its p95 */
    private static function splitRoute(
        int $requests,
        float $totalMs,
        int $sqlRequests = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpRequests = 0,
        float $httpTime = 0.0,
        int $errors = 0,
        int $slow = 0,
        string $key = 'GET app_split',
    ): ReportRow {
        $metrics = [
            Metric::REQUEST_DURATION => new MetricValue($requests, $totalMs, $totalMs),
            Metric::REQUEST_DURATION_LE_PREFIX . '10' => new MetricValue($requests, 0.0, 0.0),
        ];
        if ($errors > 0) {
            $metrics[Metric::REQUEST_STATUS_PREFIX . '5xx'] = new MetricValue($errors, 0.0, 0.0);
        }
        if ($slow > 0) {
            $metrics[Metric::REQUEST_SLOW] = new MetricValue($slow, 0.0, 0.0);
        }
        if ($sqlRequests > 0) {
            $metrics[Metric::REQUEST_SQL_TIME] = new MetricValue($sqlRequests, $sqlTime, $sqlTime);
            $metrics[Metric::REQUEST_SQL_COUNT] = new MetricValue($sqlRequests, $sqlCount, $sqlCount);
        }
        if ($httpRequests > 0) {
            $metrics[Metric::REQUEST_HTTP_TIME] = new MetricValue($httpRequests, $httpTime, $httpTime);
        }

        return new ReportRow(md5($key), $key, $key, $metrics);
    }

    /** @return array<string, MetricValue> */
    private function metrics(int $requests, float $totalMs, float $maxMs): array
    {
        return [Metric::REQUEST_DURATION => new MetricValue($requests, $totalMs, $maxMs)];
    }
}
