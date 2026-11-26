<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Section\HttpSection;
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
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpSectionTest extends TestCase
{
    private const NOW = 1800000000;
    private const CLIENT_ERRORS = Metric::HTTP_STATUS_PREFIX . '4xx';
    private const SERVER_ERRORS = Metric::HTTP_STATUS_PREFIX . '5xx';

    public function testTheSectionDeclaresItself(): void
    {
        $screen = self::section();

        static::assertSame('http', $screen->id());
        static::assertSame('Outgoing HTTP', $screen->title());
        static::assertSame('Calls your code makes to other sites and APIs through the Symfony HTTP client.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['total' => 'desc', 'calls' => 'desc', 'avg' => 'desc', 'max' => 'desc', 'problems' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame(
            ['title' => 'No outgoing call in the last hour.', 'text' => 'Calls made through the http_client service or a scoped client show up here within a few seconds.'],
            $screen->emptyState(Period::OneHour),
        );
        static::assertSame('No outgoing call in the last 24 hours.', $screen->emptyState(Period::OneDay)['title']);
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new HttpSection(false, new ApplicationChecks([])))->isAvailable());
    }

    public function testEverySortIsComputedByTheDatabaseWithoutACurve(): void
    {
        $sorts = [
            'total' => [Metric::HTTP_DURATION, 'sum'],
            'calls' => [Metric::HTTP_DURATION, 'count'],
            'avg' => [Metric::HTTP_DURATION, 'avg'],
            'max' => [Metric::HTTP_DURATION, 'max'],
            'problems' => [Metric::HTTP_FAILED, 'count'],
        ];

        static::assertSame(array_keys(self::section()->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = new FixedReader();
                self::section()->build($reader, Period::SixHours, self::NOW, $sort, $direction, self::raised());

                static::assertCount(1, $reader->queries);
                $query = $reader->queries[0];
                static::assertSame(Family::Http, $query->family);
                static::assertSame(Period::SixHours, $query->period);
                static::assertSame(self::NOW, $query->now);
                static::assertSame($metric, $query->sortMetric, $sort);
                static::assertSame($field, $query->sortField, $sort);
                static::assertSame($descending, $query->descending, $sort . ' ' . $direction);
                static::assertSame(100, $query->limit);
                static::assertNull($query->seriesMetric);
                static::assertSame([], $reader->labelRequests);
                static::assertSame([], $reader->totalRequests);
            }
        }
    }

    public function testARemovedSortFallsBackToTheDefault(): void
    {
        foreach (['4xx', '5xx', 'failed', 'unknown'] as $sort) {
            $reader = new FixedReader();
            self::section()->build($reader, Period::OneHour, self::NOW, $sort, 'desc', self::raised());

            static::assertSame([Metric::HTTP_DURATION, 'sum', true], [$reader->queries[0]->sortMetric, $reader->queries[0]->sortField, $reader->queries[0]->descending], $sort);
        }
    }

    public function testTheCellsOfAHost(): void
    {
        $table = $this->build([self::host('h1', 'api.example.com:8443', 12, 3000.0, 900.0, 2, 1, 3)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('hosts', $table->id);
        static::assertSame('Hosts', $table->title);
        static::assertSame('host', $table->noun);
        static::assertSame(
            [
                ['host', 'Host', false, null],
                ['calls', 'Calls', true, 'calls'],
                ['total', 'Total time', true, 'total'],
                ['avg', 'Avg', true, 'avg'],
                ['max', 'Max', true, 'max'],
                ['problems', 'Problems', false, 'problems'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-host' => 'api.example.com:8443', 'data-jm-calls' => '12'], $row->attributes);
        static::assertSame(
            ['host' => 'api.example.com:8443', 'calls' => '12', 'total' => '3.0 s', 'avg' => '250 ms', 'max' => '900 ms', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame(['bar', ['share' => '100']], [$row->cells['total']->kind, $row->cells['total']->data]);
        static::assertSame(
            [['3 no answer', 'bad'], ['1 × 5xx', 'bad'], ['2 × 4xx', 'warn']],
            array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags),
        );
    }

    public function testAHostWithoutProblemsHasNoTag(): void
    {
        $row = $this->build([self::host('h1', 'api.example.com')])->tables[0]->rows[0];

        static::assertSame(['tags', []], [$row->cells['problems']->kind, $row->cells['problems']->tags]);
    }

    public function testEachKindOfProblemIsTaggedAlone(): void
    {
        $rows = $this->build([
            self::host('h1', 'a.example', 5, 50.0, failures: 1),
            self::host('h2', 'b.example', 5, 40.0, serverErrors: 1200),
            self::host('h3', 'c.example', 5, 30.0, clientErrors: 4),
        ])->tables[0]->rows;

        static::assertSame(
            [[['1 no answer', 'bad']], [['1,200 × 5xx', 'bad']], [['4 × 4xx', 'warn']]],
            array_map(static fn(Row $row): array => array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags), $rows),
        );
    }

    public function testTheBarIsAShareOfTheLargestTotalShownOtherHostsIncluded(): void
    {
        $other = self::host('ho', Aggregator::OTHER, 40, 4000.0, 300.0, 1, 0, 2);
        $table = $this->build([self::host('h1', 'a.example', 10, 1000.0), self::host('h2', 'b.example', 2, 3000.0)], other: $other, keyCount: 75)->tables[0];

        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn(Row $row): string => $row->key, $table->rows));
        static::assertSame(['25', '75', '100'], array_map(static fn(Row $row): string => $row->cells['total']->data['share'], $table->rows));
        $last = $table->rows[2];
        static::assertTrue($last->muted);
        static::assertSame('Other hosts', $last->cells['host']->text);
        static::assertSame('40', $last->cells['calls']->text);
        static::assertSame(['data-jm-host' => Aggregator::OTHER, 'data-jm-calls' => '40'], $last->attributes);
        static::assertSame(
            [['2 no answer', 'bad'], ['1 × 4xx', 'warn']],
            array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $last->cells['problems']->tags),
        );
        static::assertSame(73, $table->remaining);
    }

    public function testTheNoteAndTheFootnote(): void
    {
        $rows = [self::host('h1', 'a.example')];

        foreach (['total desc' => 'the most expensive first', 'total asc' => null, 'calls desc' => null, 'problems desc' => null] as $order => $note) {
            [$sort, $direction] = explode(' ', $order);
            $table = $this->build($rows, sort: $sort, direction: $direction)->tables[0];

            static::assertSame($note, $table->note, $order);
            static::assertSame('A host appears here the first time your code calls it.', $table->footnote, $order);
        }
    }

    public function testSortingByProblemsReordersTheHostsRead(): void
    {
        $rows = [
            self::host('h1', 'a.example', clientErrors: 9),
            self::host('h2', 'b.example', serverErrors: 1),
            self::host('h3', 'c.example', failures: 1),
            self::host('h4', 'd.example', failures: 1, clientErrors: 1),
            self::host('h5', 'e.example'),
            self::host('h6', 'f.example', serverErrors: 1, clientErrors: 3),
            self::host('h0', 'g.example', failures: 1),
        ];
        $other = self::host('ho', Aggregator::OTHER, failures: 50);

        $descending = $this->build($rows, other: $other, sort: 'problems', direction: 'desc')->tables[0]->rows;
        $ascending = $this->build($rows, other: $other, sort: 'problems', direction: 'asc')->tables[0]->rows;

        $hosts = static fn(array $rows): array => array_map(static fn(Row $row): string => $row->cells['host']->text, $rows);
        static::assertSame(['d.example', 'c.example', 'g.example', 'f.example', 'b.example', 'a.example', 'e.example', 'Other hosts'], $hosts($descending));
        static::assertSame(['e.example', 'a.example', 'b.example', 'f.example', 'c.example', 'g.example', 'd.example', 'Other hosts'], $hosts($ascending));
    }

    public function testHostsEqualByProblemsAreSortedByName(): void
    {
        $rows = [
            self::host('h1', 'c.example', failures: 1),
            self::host('h2', 'b.example', serverErrors: 2),
            self::host('h3', 'a.example', failures: 1),
            self::host('h4', 'd.example', serverErrors: 2),
        ];

        $hosts = fn(string $direction): array => array_map(static fn(Row $row): string => $row->cells['host']->text, $this->build($rows, sort: 'problems', direction: $direction)->tables[0]->rows);
        static::assertSame(['a.example', 'c.example', 'b.example', 'd.example'], $hosts('desc'));
        static::assertSame(['b.example', 'd.example', 'a.example', 'c.example'], $hosts('asc'));
    }

    public function testTheOtherSortsKeepTheOrderOfTheDatabase(): void
    {
        $rows = [self::host('h1', 'z.example', failures: 3), self::host('h2', 'a.example')];

        static::assertSame(['z.example', 'a.example'], array_map(static fn(Row $row): string => $row->cells['host']->text, $this->build($rows, sort: 'calls', direction: 'asc')->tables[0]->rows));
    }

    public function testTheCards(): void
    {
        $view = $this->build(
            [self::host('h1', 'a.example', 12, 3000.0, 900.0, 2, 1, 3)],
            keyCount: 4,
            totals: self::totals(250, 10000.0, 6, 4, 5),
        );

        static::assertSame(['Calls', 'Time in calls', 'Error responses', 'No answer'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['250', '10 s', '10', '5'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(
            ['To 4 hosts.', '40 ms per call on average.', 'Answers with a 4xx or 5xx status.', 'Timeouts and network errors.'],
            array_map(static fn(Card $card): string => $card->hint, $view->cards),
        );
        static::assertSame([[], [], [], []], array_map(static fn(Card $card): array => $card->series, $view->cards));
        static::assertSame([false, false, false, false], array_map(static fn(Card $card): bool => $card->wide, $view->cards));
        static::assertSame([null, null, 'warn', 'bad'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutProblemsStayNeutral(): void
    {
        $view = $this->build([self::host('h1', 'a.example')]);

        static::assertSame(['1', '20 ms', '0', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('To 1 host.', $view->cards[0]->hint);
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, int, string}>
     */
    public static function provideHosts(): iterable
    {
        $other = self::host('ho', Aggregator::OTHER, 2);

        yield 'one host' => [[self::host('h1', 'a.example')], null, 1, 'To 1 host.'];
        yield 'hosts' => [[self::host('h1', 'a.example'), self::host('h2', 'b.example')], null, 1200, 'To 1,200 hosts.'];
        yield 'only the other line' => [[], $other, 0, 'To 1 host or more.'];
        yield 'one host and the other line' => [[self::host('h1', 'a.example')], $other, 1, 'To 2 hosts or more.'];
        yield 'hosts and the other line' => [[self::host('h1', 'a.example'), self::host('h2', 'b.example')], $other, 75, 'To 76 hosts or more.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('provideHosts')]
    public function testTheCallsCardCountsTheHostsBehindTheOtherLine(array $rows, ?ReportRow $other, int $keyCount, string $hint): void
    {
        $view = $this->build($rows, other: $other, keyCount: $keyCount);

        static::assertSame(['Calls', $hint], [$view->cards[0]->label, $view->cards[0]->hint]);
    }

    /**
     * @return iterable<string, array{int, int, int, int, string, string, string}>
     */
    public static function provideBrief(): iterable
    {
        yield 'one call' => [1, 0, 0, 0, '1', '1 outgoing call', ''];
        yield 'calls' => [1300, 0, 0, 0, '1,300', '1,300 outgoing calls', ''];
        yield 'one without answer' => [13, 0, 0, 1, '13', '13 outgoing calls', '1 call got no answer.'];
        yield 'some without answer' => [13, 0, 0, 2, '13', '13 outgoing calls', '2 calls got no answer.'];
        yield 'one error response' => [13, 1, 0, 0, '13', '13 outgoing calls', '1 error response.'];
        yield 'error responses' => [13, 1, 1, 0, '13', '13 outgoing calls', '2 error responses.'];
        yield 'both' => [13, 2, 1, 1, '13', '13 outgoing calls', '1 call got no answer. 3 error responses.'];
    }

    #[DataProvider('provideBrief')]
    public function testTheBrief(int $calls, int $clientErrors, int $serverErrors, int $failures, string $count, string $clause, string $standout): void
    {
        $brief = $this->build([self::host('h1', 'a.example')], totals: self::totals($calls, 100.0, $clientErrors, $serverErrors, $failures))->brief;

        static::assertNotNull($brief);
        static::assertSame([$count, $clause, '', $standout, null], [$brief->count, $brief->clause, $brief->figures, $brief->standout, $brief->tone]);
    }

    public function testTheCheckLinesComeFromTheReportOfTheSection(): void
    {
        $rows = [self::host('h1', 'a.example', 5, failures: 2, serverErrors: 1), self::host('h2', 'b.example', 5, clientErrors: 3)];
        $view = $this->build($rows, raised: self::raised(failures: true, errors: true));

        static::assertNotNull($view->checks);
        static::assertSame('no call without an answer, no error response', $view->checks->emptyText);
        static::assertSame(
            [
                ['http.failures', 'bad', '2 calls without an answer', 'a.example did not answer 2 times.', '', null],
                ['http.errors', 'warn', '4 error responses', 'b.example answered with a 4xx or 5xx status 3 times. 1 other host did too.', '', null],
            ],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->title, $line->sentence, $line->where, $line->path], $view->checks->lines),
        );
    }

    public function testWithoutRaisedCheckThereIsNoLine(): void
    {
        $view = $this->build([self::host('h1', 'a.example', failures: 2)]);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
    }

    public function testTheChecksReadNothingMore(): void
    {
        foreach (array_keys(self::section()->sorts()) as $sort) {
            $reader = new FixedReader(self::report([self::host('h1', 'a.example', 5, failures: 2, serverErrors: 1)]));

            $view = self::section()->build($reader, Period::OneHour, self::NOW, $sort, 'desc', self::raised(failures: true, errors: true));

            static::assertNotNull($view->checks);
            static::assertCount(2, $view->checks->lines, $sort);
            static::assertCount(1, $reader->queries, $sort);
            static::assertSame([], $reader->totalRequests, $sort);
        }
    }

    public function testTheGuide(): void
    {
        $guide = $this->build([self::host('h1', 'a.example')])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [
                ['Time in outgoing calls.', 'Your request waits while the other site answers. A slow host makes your own routes slow: look at the HTTP part of their bars.', ['Requests' => 'requests']],
                ['Three kinds of problem.', '4xx: the other side refused the call. 5xx: the other side failed. No answer: a timeout or a network error.', []],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        $view = $this->build([]);

        static::assertTrue($view->isEmpty());
        static::assertNull($view->brief);
        static::assertFalse($this->build([self::host('h1', 'a.example')])->isEmpty());
    }

    private static function section(): HttpSection
    {
        return new HttpSection(true, new ApplicationChecks([new HttpFailuresCheck(), new HttpErrorsCheck()]));
    }

    private static function raised(bool $failures = false, bool $errors = false): RaisedChecks
    {
        $checks = array_filter(['http.failures' => $failures ? new HttpFailuresCheck() : null, 'http.errors' => $errors ? new HttpErrorsCheck() : null]);

        return new RaisedChecks($checks, self::totals(10, 0.0, 3, 1, 2), Period::OneHour);
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private function build(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        ?array $totals = null,
        string $sort = 'total',
        string $direction = 'desc',
        ?RaisedChecks $raised = null,
    ): ScreenView {
        return self::section()->build(new FixedReader(self::report($rows, $other, $keyCount, $totals)), Period::OneHour, self::NOW, $sort, $direction, $raised ?? self::raised());
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private static function report(array $rows, ?ReportRow $other = null, ?int $keyCount = null, ?array $totals = null): Report
    {
        $all = [...$rows, ...($other === null ? [] : [$other])];
        $totals ??= self::totals(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): float => $row->sum(Metric::HTTP_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(self::CLIENT_ERRORS), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(self::SERVER_ERRORS), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::HTTP_FAILED), $all)),
        );

        return new Report($rows, $other, $keyCount ?? \count($rows), [], $all === [] ? [] : $totals);
    }

    /** @return array<string, MetricValue> */
    private static function totals(int $calls, float $totalMs, int $clientErrors, int $serverErrors, int $failures): array
    {
        return [
            Metric::HTTP_DURATION => new MetricValue($calls, $totalMs, 0.0),
            self::CLIENT_ERRORS => new MetricValue($clientErrors, 0.0, 0.0),
            self::SERVER_ERRORS => new MetricValue($serverErrors, 0.0, 0.0),
            Metric::HTTP_FAILED => new MetricValue($failures, 0.0, 0.0),
        ];
    }

    private static function host(
        string $hash,
        string $host,
        int $calls = 1,
        float $totalMs = 20.0,
        float $maxMs = 20.0,
        int $clientErrors = 0,
        int $serverErrors = 0,
        int $failures = 0,
    ): ReportRow {
        $metrics = [Metric::HTTP_DURATION => new MetricValue($calls, $totalMs, $maxMs)];
        foreach ([self::CLIENT_ERRORS => $clientErrors, self::SERVER_ERRORS => $serverErrors, Metric::HTTP_FAILED => $failures] as $metric => $count) {
            if ($count > 0) {
                $metrics[$metric] = new MetricValue($count, 0.0, 0.0);
            }
        }

        return new ReportRow($hash, $host, $host, $metrics);
    }
}
