<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Dashboard\View\Tag;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessagesSectionTest extends TestCase
{
    private const NOW = 1800000000;
    private const HISTOGRAM = ['10' => 10, '25' => 6, '100' => 4];

    public function testTheSectionDeclaresItself(): void
    {
        $screen = self::section();

        static::assertSame('messages', $screen->id());
        static::assertSame('Messages', $screen->title());
        static::assertSame('Work your code hands to Messenger to do later.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['handled' => 'desc', 'message' => 'asc', 'sent' => 'desc', 'avg' => 'desc', 'p95' => 'desc', 'max' => 'desc', 'problems' => 'desc'],
            $screen->sorts(),
        );
        static::assertSame([
            'title' => 'No message was sent or handled in the last hour.',
            'text' => 'Messages sent to a transport and messages handled by a worker show up here. Messages handled synchronously are part of their request.',
        ], $screen->emptyState(Period::OneHour));
        static::assertSame('No message was sent or handled in the last 7 days.', $screen->emptyState(Period::SevenDays)['title']);
    }

    public function testTheScreenFollowsItsAvailability(): void
    {
        static::assertFalse((new MessagesSection(false, true, null, self::checks()))->isAvailable());
        static::assertTrue((new MessagesSection(true, true, null, self::checks()))->isAvailable());
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function presence(): iterable
    {
        foreach ([true, false] as $messenger) {
            foreach ([true, false] as $recorded) {
                foreach ([true, false] as $counted) {
                    $name = sprintf('%s, %s, %s', $messenger ? 'messenger' : 'no messenger', $recorded ? 'recorded' : 'not recorded', $counted ? 'counted' : 'not counted');

                    yield $name => [$messenger, $recorded, $counted, $messenger && ($recorded || $counted)];
                }
            }
        }
    }

    #[DataProvider('presence')]
    public function testItIsThereWhenMessengerIsAndSomethingIsShown(bool $messenger, bool $recorded, bool $counted, bool $available): void
    {
        static::assertSame($available, (new MessagesSection($messenger, $recorded, $counted ? self::queues() : null, self::checks()))->isAvailable());
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function recording(): iterable
    {
        foreach (self::presence() as $name => [$messenger, $recorded, $counted]) {
            yield $name => [$messenger, $recorded, $counted, $messenger && $recorded];
        }
    }

    #[DataProvider('recording')]
    public function testItSaysWhetherTheMessagesAreRecorded(bool $messenger, bool $recorded, bool $counted, bool $recording): void
    {
        static::assertSame($recording, (new MessagesSection($messenger, $recorded, $counted ? self::queues() : null, self::checks()))->recordsMessages());
    }

    public function testWithoutRecordingItReadsNoStorageAndSaysSo(): void
    {
        $reader = new FixedReader();
        $screen = new MessagesSection(true, false, self::queues(), self::checks());

        $view = $screen->build($reader, Period::OneHour, self::NOW, 'handled', 'desc', self::raised());

        static::assertSame([], $reader->queries);
        static::assertSame([], $reader->totalRequests);
        static::assertTrue($view->isEmpty());
        static::assertNull($view->brief);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        foreach (Period::cases() as $period) {
            static::assertSame([
                'title' => 'Messages are not recorded.',
                'text' => 'Recording the messages is off (jmonitor.recording.messenger). The queues are still counted.',
            ], $screen->emptyState($period));
        }
        static::assertSame(array_keys(self::section()->sorts()), array_keys($screen->sorts()));
    }

    public function testItsBlocksAreThoseOfItsSource(): void
    {
        $request = new ScreenRequest(self::NOW, true);
        $notice = Block::notice(new Notice('info', 'No transport to count'));
        $queues = self::queues($notice);

        static::assertSame([$notice], (new MessagesSection(true, true, $queues, self::checks()))->blocks($request));
        static::assertSame([$request], $queues->requests);
        static::assertSame([], self::section()->blocks($request));
    }

    public function testTheScreenReadsTheMessageFamilyOnceWithoutASeries(): void
    {
        foreach (array_keys(self::section()->sorts()) as $sort) {
            $reader = new FixedReader();
            self::section()->build($reader, Period::SixHours, self::NOW, $sort, 'asc', self::raised());

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Message, Period::SixHours, self::NOW, null], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
            static::assertSame([], $reader->totalRequests);
        }
    }

    public function testTheCellsOfAHandledMessage(): void
    {
        $table = $this->table($this->build([self::message('h1', 'App\Message\SendInvoice', 20, 1000.0, 95.0, self::HISTOGRAM, sent: 25, failed: 2, retried: 1, sqlHandled: 20, sqlTime: 200.0, sqlCount: 30.0, httpHandled: 5, httpTime: 100.0)]));
        $row = $table->rows[0];

        static::assertSame('messages', $table->id);
        static::assertSame('Messages', $table->title);
        static::assertSame('message', $table->noun);
        static::assertTrue($table->partsLegend);
        static::assertSame(
            [
                ['message', 'Message', false, 'message'],
                ['sent', 'Sent', true, 'sent'],
                ['handled', 'Handled', true, 'handled'],
                ['avg', 'Avg', true, 'avg'],
                ['p95', 'p95', true, 'p95'],
                ['max', 'Max', true, 'max'],
                ['split', 'Where the average goes', false, null],
                ['problems', 'Problems', false, 'problems'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-message' => 'App\Message\SendInvoice', 'data-jm-handled' => '20', 'data-jm-sent' => '25'], $row->attributes);
        static::assertSame(Format::duration(Percentile::estimate(self::HISTOGRAM, 0.95, 95.0)), $row->cells['p95']->text);
        static::assertSame(
            ['message' => 'App\Message\SendInvoice', 'sent' => '25', 'handled' => '20', 'avg' => '50 ms', 'p95' => '88 ms', 'max' => '95 ms', 'split' => 'SQL 10 ms · 1.5 queries', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['message']->kind);
        static::assertSame(['split', ['php' => 35.0, 'sql' => 10.0, 'http' => 5.0]], [$row->cells['split']->kind, $row->cells['split']->parts]);
        static::assertSame([['2 failed', 'bad'], ['1 retried', 'warn']], self::tags($row));
    }

    /**
     * @return iterable<string, array{ReportRow, string, array{php: float, sql: float, http: float}}>
     */
    public static function splits(): iterable
    {
        yield 'SQL and HTTP longer than the average' => [self::message('h', 'App\A', 2, 40.0, sqlHandled: 2, sqlTime: 30.0, sqlCount: 4.0, httpHandled: 2, httpTime: 50.0), 'HTTP out 25 ms · 2.0 queries', ['php' => 0.0, 'sql' => 15.0, 'http' => 25.0]];
        yield 'neither SQL nor an outgoing call' => [self::message('h', 'App\A', 4, 80.0), 'PHP only', ['php' => 20.0, 'sql' => 0.0, 'http' => 0.0]];
        yield 'an outgoing call only' => [self::message('h', 'App\A', 4, 80.0, httpHandled: 1, httpTime: 40.0), 'HTTP out 10 ms', ['php' => 10.0, 'sql' => 0.0, 'http' => 10.0]];
        yield 'SQL only' => [self::message('h', 'App\A', 4, 80.0, sqlHandled: 4, sqlTime: 20.0, sqlCount: 48.0), 'SQL 5 ms · 12 queries', ['php' => 15.0, 'sql' => 5.0, 'http' => 0.0]];
    }

    /**
     * @param array{php: float, sql: float, http: float} $parts
     */
    #[DataProvider('splits')]
    public function testWhereTheAverageMessageGoes(ReportRow $message, string $text, array $parts): void
    {
        $split = $this->table($this->build([$message]))->rows[0]->cells['split'];

        static::assertSame(['split', $text, $parts], [$split->kind, $split->text, $split->parts]);
    }

    public function testAMessageOnlySentHasNoDurationNorSplit(): void
    {
        $row = $this->table($this->build([self::message('h1', 'App\Message\Notify', 0, sent: 4)]))->rows[0];

        static::assertSame(
            ['message' => 'App\Message\Notify', 'sent' => '4', 'handled' => '0', 'avg' => '—', 'p95' => '—', 'max' => '—', 'split' => '', 'problems' => ''],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame(['zero', 'zero', 'zero'], array_map(static fn(string $id): ?string => $row->cells[$id]->tone, ['avg', 'p95', 'max']));
        static::assertSame(['text', []], [$row->cells['split']->kind, $row->cells['split']->parts]);
        static::assertSame([], self::tags($row));
        static::assertSame(['data-jm-message' => 'App\Message\Notify', 'data-jm-handled' => '0', 'data-jm-sent' => '4'], $row->attributes);
    }

    public function testEachProblemHasItsOwnTag(): void
    {
        $rows = $this->table($this->build([
            self::message('h1', 'App\A', 3, failed: 3),
            self::message('h2', 'App\B', 6, retried: 6),
            self::message('h3', 'App\C', 2, failed: 1, retried: 1),
        ], 'message', 'asc'))->rows;

        static::assertSame([[['3 failed', 'bad']], [['6 retried', 'warn']], [['1 failed', 'bad'], ['1 retried', 'warn']]], array_map(self::tags(...), $rows));
    }

    public function testEverySortKey(): void
    {
        $rows = [
            self::message('ha', 'App\A', 10, 500.0, 90.0, ['100' => 10], sent: 1, failed: 1, retried: 2),
            self::message('hb', 'App\B', 0, sent: 30),
            self::message('hc', 'App\C', 4, 800.0, 400.0, ['500' => 4], sent: 5, failed: 3, retried: 1),
            self::message('hd', 'App\D', 2, 10.0, 6.0, ['10' => 2], sent: 2, failed: 1, retried: 5),
        ];
        $descending = [
            'handled' => ['App\A', 'App\C', 'App\D', 'App\B'],
            'message' => ['App\D', 'App\C', 'App\B', 'App\A'],
            'sent' => ['App\B', 'App\C', 'App\D', 'App\A'],
            'avg' => ['App\C', 'App\A', 'App\D', 'App\B'],
            'p95' => ['App\C', 'App\A', 'App\D', 'App\B'],
            'max' => ['App\C', 'App\A', 'App\D', 'App\B'],
            'problems' => ['App\C', 'App\D', 'App\A', 'App\B'],
        ];

        static::assertSame(array_keys(self::section()->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->names($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->names($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testARemovedSortFallsBackToTheDefault(): void
    {
        $rows = [
            self::message('ha', 'App\A', 1, failed: 5, retried: 5, sqlHandled: 1, sqlTime: 90.0, sqlCount: 9.0),
            self::message('hb', 'App\B', 7),
        ];

        foreach (['failed', 'retried', 'sql', 'unknown'] as $sort) {
            static::assertSame(['App\B', 'App\A'], $this->names($this->build($rows, $sort, 'desc')), $sort);
        }
    }

    public function testOtherMessagesComeLastMutedWithTheirCells(): void
    {
        $other = self::message('ho', Aggregator::OTHER, 9, 90.0, 20.0, ['25' => 9], sent: 3, failed: 2, retried: 4, sqlHandled: 9, sqlTime: 27.0, sqlCount: 9.0);

        foreach ([['handled', 'desc'], ['handled', 'asc'], ['message', 'asc'], ['problems', 'desc']] as [$sort, $direction]) {
            $table = $this->table($this->build([self::message('h1', 'App\A'), self::message('h2', 'App\B', failed: 9)], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame('Other messages', $last->cells['message']->text);
            static::assertSame('text', $last->cells['message']->kind);
            static::assertSame('SQL 3 ms · 1.0 queries', $last->cells['split']->text);
            static::assertSame([['2 failed', 'bad'], ['4 retried', 'warn']], self::tags($last));
            static::assertSame(['data-jm-message' => Aggregator::OTHER, 'data-jm-handled' => '9', 'data-jm-sent' => '3'], $last->attributes);
        }
    }

    public function testTheTableShowsAHundredMessagesAndCountsTheOthers(): void
    {
        $rows = [];
        for ($i = 1; $i <= 105; ++$i) {
            $rows[] = self::message('h' . $i, sprintf('App\M%03d', $i), $i);
        }

        $table = $this->table($this->build($rows));

        static::assertCount(100, $table->rows);
        static::assertSame(5, $table->remaining);
        static::assertSame('App\M105', $table->rows[0]->attributes['data-jm-message']);
        static::assertSame('App\M006', $table->rows[99]->attributes['data-jm-message']);
    }

    public function testNoCardIsRenderedInTheSection(): void
    {
        $view = $this->build([self::message('h1', 'App\A', 20, 1000.0, 95.0, self::HISTOGRAM, sent: 25, failed: 2)], other: self::message('ho', Aggregator::OTHER, 0, sent: 1));

        static::assertSame([], $view->cards);
        static::assertNull($view->lead);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, list<string|null>}>
     */
    public static function briefs(): iterable
    {
        yield 'handled and sent' => [[self::message('h1', 'App\A', 40, sent: 10), self::message('h2', 'App\B', 2, sent: 2)], null, ['42', '42 messages handled', 'handled, 12 sent', '', null]];
        yield 'one handled' => [[self::message('h1', 'App\A', 1, sent: 1)], null, ['1', '1 message handled', 'handled, 1 sent', '', null]];
        yield 'handled, none sent' => [[self::message('h1', 'App\A', 3)], null, ['3', '3 messages handled', 'handled, 0 sent', '', null]];
        yield 'one failure' => [[self::message('h1', 'App\A', 5, sent: 5, failed: 1)], null, ['5', '5 messages handled', 'handled, 5 sent, 1 failed', '1 message failed.', 'bad']];
        yield 'failures, the other messages counted' => [
            [self::message('h1', 'App\A', 1200, sent: 1000, failed: 2)],
            self::message('ho', Aggregator::OTHER, 34, sent: 1, failed: 1),
            ['1,234', '1,234 messages handled', 'handled, 1,001 sent, 3 failed', '3 messages failed.', 'bad'],
        ];
        yield 'only sent' => [[self::message('h1', 'App\A', 0, sent: 7)], null, ['7', '7 messages sent', 'sent', '', null]];
        yield 'one sent' => [[self::message('h1', 'App\A', 0, sent: 1)], null, ['1', '1 message sent', 'sent', '', null]];
        yield 'only sent by the other messages' => [[], self::message('ho', Aggregator::OTHER, 0, sent: 3), ['3', '3 messages sent', 'sent', '', null]];
    }

    /**
     * @param list<ReportRow>     $rows
     * @param list<string|null> $expected
     */
    #[DataProvider('briefs')]
    public function testTheBrief(array $rows, ?ReportRow $other, array $expected): void
    {
        $brief = $this->build($rows, other: $other)->brief;

        static::assertNotNull($brief);
        static::assertSame($expected, [$brief->count, $brief->clause, $brief->figures, $brief->standout, $brief->tone]);
    }

    public function testWithoutAMessageSentOrHandledThereIsNoBrief(): void
    {
        $view = $this->build([new ReportRow('h1', 'App\A', 'App\A', [Metric::MESSAGE_RETRIED => new MetricValue(1, 1.0, 1.0)])]);

        static::assertFalse($view->isEmpty());
        static::assertNull($view->brief);
    }

    public function testTheCheckLineIsReadInTheReportOfTheSection(): void
    {
        $rows = [self::message('h1', 'App\Message\SendInvoice', 4, failed: 1), self::message('h2', 'App\Message\Resize', 6, failed: 3)];
        $reader = $this->reader($rows);

        $view = self::section()->build($reader, Period::OneHour, self::NOW, 'handled', 'desc', self::raisedBy(4));

        static::assertNotNull($view->checks);
        static::assertSame('no failed message', $view->checks->emptyText);
        static::assertSame(
            [['messages.failed', 'bad', 'Critical', '4 messages failed', 'App\Message\Resize failed 3 times. 1 other message class failed too.', '', null, []]],
            array_map(static fn(CheckLine $line): array => [$line->id, $line->mark->tone, $line->mark->label, $line->title, $line->sentence, $line->where, $line->path, $line->query], $view->checks->lines),
        );
        static::assertCount(1, $reader->queries);
        static::assertSame([], $reader->totalRequests);
    }

    public function testWithoutRaisedCheckThereIsNoLine(): void
    {
        $view = $this->build([self::message('h1', 'App\A', 4, failed: 1)]);

        static::assertNotNull($view->checks);
        static::assertSame([], $view->checks->lines);
        static::assertSame('no failed message', $view->checks->emptyText);
    }

    public function testTheGuide(): void
    {
        $guide = $this->build([self::message('h1', 'App\A')])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [['Sent and handled.', 'Sent is put in the queue, handled is done. When sent stays above handled, the workers do not keep up and the queue grows.', []]],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testTheQueuesGuideOnlyShowsWhenTheQueuesAreCounted(): void
    {
        $view = (new MessagesSection(true, true, self::queues(), self::checks()))->build($this->reader([self::message('h1', 'App\A')]), Period::OneHour, self::NOW, 'handled', 'desc', self::raised());

        static::assertNotNull($view->guide);
        static::assertSame(
            [
                ['Sent and handled.', 'Sent is put in the queue, handled is done. When sent stays above handled, the workers do not keep up and the queue grows.'],
                ['Queues.', 'The messages waiting in each transport at this moment, whatever the period. A number that never comes down means no worker is running.'],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text], $view->guide->items),
        );
        static::assertNotNull($this->build([self::message('h1', 'App\A')])->guide);
        static::assertSame(['Sent and handled.'], array_map(static fn(GuideItem $item): string => $item->term, $this->build([self::message('h1', 'App\A')])->guide->items ?? []));
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        $view = $this->build([]);

        static::assertTrue($view->isEmpty());
        static::assertNull($view->brief);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertFalse($this->build([], other: self::message('ho', Aggregator::OTHER, 0, sent: 1))->isEmpty());
    }

    private static function section(): MessagesSection
    {
        return new MessagesSection(true, true, null, self::checks());
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([new FailedMessagesCheck()]);
    }

    private static function raised(): RaisedChecks
    {
        return new RaisedChecks([], [], Period::OneHour);
    }

    private static function raisedBy(int $failed): RaisedChecks
    {
        $check = new FailedMessagesCheck();

        return new RaisedChecks([$check->id() => $check], [Metric::MESSAGE_FAILED => new MetricValue($failed, (float) $failed, 1.0)], Period::OneHour);
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function build(array $rows, string $sort = 'handled', string $direction = 'desc', ?ReportRow $other = null): ScreenView
    {
        return self::section()->build($this->reader($rows, $other), Period::OneHour, self::NOW, $sort, $direction, self::raised());
    }

    /**
     * @param list<ReportRow> $rows
     */
    private function reader(array $rows, ?ReportRow $other = null): FixedReader
    {
        return new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows)));
    }

    /**
     * @return BlockSourceInterface&object{requests: list<ScreenRequest>}
     */
    private static function queues(Block ...$blocks): BlockSourceInterface
    {
        return new class (array_values($blocks)) implements BlockSourceInterface {
            /** @var list<ScreenRequest> */
            public array $requests = [];

            /** @param list<Block> $blocks */
            public function __construct(
                private readonly array $blocks,
            ) {}

            public function blocks(ScreenRequest $request): array
            {
                $this->requests[] = $request;

                return $this->blocks;
            }
        };
    }

    private function table(ScreenView $view): Table
    {
        return $view->tables[0];
    }

    /** @return list<string> */
    private function names(ScreenView $view): array
    {
        return array_map(static fn(Row $row): string => $row->cells['message']->text, $this->table($view)->rows);
    }

    /** @return list<array{string, string}> */
    private static function tags(Row $row): array
    {
        return array_map(static fn(Tag $tag): array => [$tag->text, $tag->tone], $row->cells['problems']->tags);
    }

    /**
     * @param array<string, int> $histogram
     */
    private static function message(
        string $hash,
        string $class,
        int $handled = 1,
        float $totalMs = 10.0,
        float $maxMs = 10.0,
        array $histogram = [],
        int $sent = 0,
        int $failed = 0,
        int $retried = 0,
        int $sqlHandled = 0,
        float $sqlTime = 0.0,
        float $sqlCount = 0.0,
        int $httpHandled = 0,
        float $httpTime = 0.0,
    ): ReportRow {
        $metrics = [];
        if ($handled > 0) {
            $metrics[Metric::MESSAGE_DURATION] = new MetricValue($handled, $totalMs, $maxMs);
            foreach ($histogram === [] ? ['10' => $handled] : $histogram as $bound => $count) {
                $metrics[Metric::MESSAGE_DURATION_LE_PREFIX . $bound] = new MetricValue($count, (float) $count, 1.0);
            }
        }
        if ($sent > 0) {
            $metrics[Metric::MESSAGE_SENT] = new MetricValue($sent, 0.0, 0.0);
        }
        if ($failed > 0) {
            $metrics[Metric::MESSAGE_FAILED] = new MetricValue($failed, (float) $failed, 1.0);
        }
        if ($retried > 0) {
            $metrics[Metric::MESSAGE_RETRIED] = new MetricValue($retried, (float) $retried, 1.0);
        }
        if ($sqlHandled > 0) {
            $metrics[Metric::MESSAGE_SQL_TIME] = new MetricValue($sqlHandled, $sqlTime, $sqlTime);
            $metrics[Metric::MESSAGE_SQL_COUNT] = new MetricValue($sqlHandled, $sqlCount, $sqlCount);
        }
        if ($httpHandled > 0) {
            $metrics[Metric::MESSAGE_HTTP_TIME] = new MetricValue($httpHandled, $httpTime, $httpTime);
        }

        return new ReportRow($hash, $class, $class, $metrics);
    }
}
