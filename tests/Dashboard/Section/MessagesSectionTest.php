<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Section;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Percentile;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
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
        $screen = new MessagesSection(true, true);

        static::assertSame('messages', $screen->id());
        static::assertSame('Messages', $screen->title());
        static::assertSame('Work your code hands to Messenger to do later.', $screen->summary());
        static::assertTrue($screen->isAvailable());
        static::assertSame(
            ['handled' => 'desc', 'message' => 'asc', 'sent' => 'desc', 'avg' => 'desc', 'p95' => 'desc', 'max' => 'desc', 'failed' => 'desc', 'retried' => 'desc', 'sql' => 'desc'],
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
        static::assertFalse((new MessagesSection(false, true))->isAvailable());
        static::assertTrue((new MessagesSection(true, true))->isAvailable());
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
        static::assertSame($available, (new MessagesSection($messenger, $recorded, $counted ? self::queues() : null))->isAvailable());
    }

    public function testWithoutRecordingItReadsNoStorageAndSaysSo(): void
    {
        $reader = new FixedReader();
        $screen = new MessagesSection(true, false, self::queues());

        $view = $screen->build($reader, Period::OneHour, self::NOW, 'handled', 'desc');

        static::assertSame([], $reader->queries);
        static::assertTrue($view->isEmpty());
        foreach (Period::cases() as $period) {
            static::assertSame([
                'title' => 'Messages are not recorded.',
                'text' => 'Recording the messages is off (jmonitor.recording.messenger). The queues are still counted.',
            ], $screen->emptyState($period));
        }
        static::assertSame(array_keys((new MessagesSection(true, true))->sorts()), array_keys($screen->sorts()));
    }

    public function testItsBlocksAreThoseOfItsSource(): void
    {
        $request = new ScreenRequest(self::NOW, true);
        $notice = Block::notice(new Notice('info', 'No transport to count'));
        $queues = self::queues($notice);

        static::assertSame([$notice], (new MessagesSection(true, true, $queues))->blocks($request));
        static::assertSame([$request], $queues->requests);
        static::assertSame([], (new MessagesSection(true, true))->blocks($request));
    }

    public function testTheScreenReadsTheMessageFamilyOnceWithItsSeries(): void
    {
        foreach (array_keys((new MessagesSection(true, true))->sorts()) as $sort) {
            $reader = new FixedReader();
            (new MessagesSection(true, true))->build($reader, Period::SixHours, self::NOW, $sort, 'asc');

            static::assertCount(1, $reader->queries, $sort);
            $query = $reader->queries[0];
            static::assertSame([Family::Message, Period::SixHours, self::NOW, Metric::MESSAGE_DURATION], [$query->family, $query->period, $query->now, $query->seriesMetric]);
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testTheCellsOfAHandledMessage(): void
    {
        $table = $this->table($this->build([self::message('h1', 'App\Message\SendInvoice', 20, 1000.0, 95.0, self::HISTOGRAM, sent: 25, failed: 2, retried: 1, sqlHandled: 20, sqlTime: 200.0, sqlCount: 30.0)]));
        $row = $table->rows[0];

        static::assertSame('messages', $table->id);
        static::assertSame('Messages', $table->title);
        static::assertSame('message', $table->noun);
        static::assertSame(
            [
                ['message', 'Message', false, 'message'],
                ['sent', 'Sent', true, 'sent'],
                ['handled', 'Handled', true, 'handled'],
                ['avg', 'Avg', true, 'avg'],
                ['p95', 'p95', true, 'p95'],
                ['max', 'Max', true, 'max'],
                ['failed', 'Failed', true, 'failed'],
                ['retried', 'Retried', true, 'retried'],
                ['sql', 'SQL', true, 'sql'],
            ],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-message' => 'App\Message\SendInvoice', 'data-jm-handled' => '20', 'data-jm-sent' => '25'], $row->attributes);
        static::assertSame(Format::duration(Percentile::estimate(self::HISTOGRAM, 0.95, 95.0)), $row->cells['p95']->text);
        static::assertSame(
            ['message' => 'App\Message\SendInvoice', 'sent' => '25', 'handled' => '20', 'avg' => '50 ms', 'p95' => '88 ms', 'max' => '95 ms', 'failed' => '2', 'retried' => '1', 'sql' => '10 ms · 1.5 q'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['message']->kind);
        static::assertSame('bad', $row->cells['failed']->tone);
        static::assertSame('warn', $row->cells['retried']->tone);
        static::assertNull($row->cells['sql']->tone);
    }

    public function testAMessageOnlySentShowsNoDurationWithoutDividingByZero(): void
    {
        $row = $this->table($this->build([self::message('h1', 'App\Message\Notify', 0, sent: 4)]))->rows[0];

        static::assertSame(
            ['message' => 'App\Message\Notify', 'sent' => '4', 'handled' => '0', 'avg' => '—', 'p95' => '—', 'max' => '—', 'failed' => '0', 'retried' => '0', 'sql' => '—'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame(['zero', 'zero', 'zero', 'zero', 'zero', 'zero'], array_map(static fn(string $id): ?string => $row->cells[$id]->tone, ['avg', 'p95', 'max', 'failed', 'retried', 'sql']));
        static::assertSame(['data-jm-message' => 'App\Message\Notify', 'data-jm-handled' => '0', 'data-jm-sent' => '4'], $row->attributes);
    }

    public function testEverySortKey(): void
    {
        $rows = [
            self::message('ha', 'App\A', 10, 500.0, 90.0, ['100' => 10], sent: 1, failed: 1, retried: 2, sqlHandled: 10, sqlTime: 300.0, sqlCount: 10.0),
            self::message('hb', 'App\B', 0, sent: 30),
            self::message('hc', 'App\C', 4, 800.0, 400.0, ['500' => 4], sent: 5, failed: 3, retried: 1, sqlHandled: 4, sqlTime: 40.0, sqlCount: 4.0),
        ];
        $descending = [
            'handled' => ['App\A', 'App\C', 'App\B'],
            'message' => ['App\C', 'App\B', 'App\A'],
            'sent' => ['App\B', 'App\C', 'App\A'],
            'avg' => ['App\C', 'App\A', 'App\B'],
            'p95' => ['App\C', 'App\A', 'App\B'],
            'max' => ['App\C', 'App\A', 'App\B'],
            'failed' => ['App\C', 'App\A', 'App\B'],
            'retried' => ['App\A', 'App\C', 'App\B'],
            'sql' => ['App\A', 'App\C', 'App\B'],
        ];

        static::assertSame(array_keys((new MessagesSection(true, true))->sorts()), array_keys($descending));

        foreach ($descending as $sort => $expected) {
            static::assertSame($expected, $this->names($this->build($rows, $sort, 'desc')), $sort . ' desc');
            static::assertSame(array_reverse($expected), $this->names($this->build($rows, $sort, 'asc')), $sort . ' asc');
        }
    }

    public function testOtherMessagesComeLastMuted(): void
    {
        $other = self::message('ho', Aggregator::OTHER, 9, 90.0, 20.0, ['25' => 9], sent: 3);

        foreach ([['handled', 'desc'], ['handled', 'asc'], ['message', 'asc']] as [$sort, $direction]) {
            $table = $this->table($this->build([self::message('h1', 'App\A'), self::message('h2', 'App\B')], $sort, $direction, $other));
            $last = $table->rows[2];

            static::assertCount(3, $table->rows);
            static::assertTrue($last->muted);
            static::assertSame('ho', $last->key);
            static::assertSame('Other messages', $last->cells['message']->text);
            static::assertSame('text', $last->cells['message']->kind);
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

    public function testTheCards(): void
    {
        $view = $this->build([
            self::message('h1', 'App\A', 20, 1000.0, 95.0, self::HISTOGRAM, sent: 25, failed: 2),
            self::message('h2', 'App\B', 0, sent: 4),
        ], other: self::message('ho', Aggregator::OTHER, 0, sent: 1), period: Period::SixHours, series: [2, 0, 1]);

        static::assertSame(['Handled', 'Sent', 'Failed', 'p95'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['20', '30', '2', '88 ms'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('Last 6 hours', $view->cards[0]->hint);
        static::assertSame([2, 0, 1], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame([null, null, 'bad', null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutHandledMessagesShowNoPercentile(): void
    {
        $view = $this->build([self::message('h1', 'App\A', 0, sent: 4)]);

        static::assertSame(['0', '4', '0', '—'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        static::assertTrue($this->build([])->isEmpty());
        static::assertFalse($this->build([], other: self::message('ho', Aggregator::OTHER, 0, sent: 1))->isEmpty());
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     */
    private function build(array $rows, string $sort = 'handled', string $direction = 'desc', ?ReportRow $other = null, Period $period = Period::OneHour, array $series = []): ScreenView
    {
        return (new MessagesSection(true, true))->build(
            new FixedReader(static fn(ReportQuery $query): Report => new Report($rows, $other, \count($rows), $series)),
            $period,
            self::NOW,
            $sort,
            $direction,
        );
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

        return new ReportRow($hash, $class, $class, $metrics);
    }
}
