<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class ExceptionsScreenTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheScreenDeclaresItself(): void
    {
        $screen = new ExceptionsScreen(true);

        static::assertSame('exceptions', $screen->id());
        static::assertSame('Exceptions', $screen->label());
        static::assertSame('Server errors per exception class', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['count' => 'desc', 'last' => 'desc', 'class' => 'asc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No exceptions recorded', 'text' => 'An exception that ends in a 5xx response shows up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new ExceptionsScreen(false))->isAvailable());
    }

    public function testTheScreenReadsBothFamiliesOfThePeriod(): void
    {
        foreach (array_keys((new ExceptionsScreen(true))->sorts()) as $sort) {
            $reader = new FixedReader();
            (new ExceptionsScreen(true))->build($reader, Period::SixHours, self::NOW, $sort, 'desc');

            static::assertSame([Family::Exception, Family::ExceptionWhere], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries), $sort);
            static::assertSame([Period::SixHours, Period::SixHours], array_map(static fn(ReportQuery $query): Period => $query->period, $reader->queries));
            static::assertSame([self::NOW, self::NOW], array_map(static fn(ReportQuery $query): int => $query->now, $reader->queries));
            static::assertSame([Metric::EXCEPTION_COUNT, null], array_map(static fn(ReportQuery $query): ?string => $query->seriesMetric, $reader->queries));
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testTheCellsOfAnException(): void
    {
        $table = $this->build([self::exception('h1', \RuntimeException::class, 3, self::NOW - 42)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('exceptions', $table->id);
        static::assertSame('Exceptions', $table->title);
        static::assertSame(
            [['exception', 'Exception', false, 'class'], ['count', 'Count', true, 'count'], ['last', 'Last seen', true, 'last']],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-exception' => \RuntimeException::class, 'data-jm-count' => '3'], $row->attributes);
        static::assertSame(
            ['exception' => \RuntimeException::class, 'count' => '3', 'last' => '42 s ago'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['exception']->kind);
    }

    public function testTheSorts(): void
    {
        $rows = [
            self::exception('h1', 'RuntimeException', 5, self::NOW - 100),
            self::exception('h2', 'LogicException', 2, self::NOW - 10),
            self::exception('h3', 'App\FooException', 5, self::NOW - 3000),
        ];

        $expected = [
            'count desc' => ['App\FooException', 'RuntimeException', 'LogicException'],
            'count asc' => ['LogicException', 'App\FooException', 'RuntimeException'],
            'last desc' => ['LogicException', 'RuntimeException', 'App\FooException'],
            'last asc' => ['App\FooException', 'RuntimeException', 'LogicException'],
            'class asc' => ['App\FooException', 'LogicException', 'RuntimeException'],
            'class desc' => ['RuntimeException', 'LogicException', 'App\FooException'],
        ];

        foreach ($expected as $order => $classes) {
            [$sort, $direction] = explode(' ', $order);
            $view = (new ExceptionsScreen(true))->build($this->reader($rows), Period::OneHour, self::NOW, $sort, $direction);

            static::assertSame($classes, array_map(static fn(Row $row): string => $row->cells['exception']->text, $view->tables[0]->rows), $order);
        }
    }

    public function testOtherExceptionsComeLastAndTheRestIsCounted(): void
    {
        $rows = [];
        for ($i = 1; $i <= 102; ++$i) {
            $rows[] = self::exception('h' . $i, sprintf('App\E%03dException', $i), $i, self::NOW - 60);
        }
        $other = self::exception('ho', Aggregator::OTHER, 7, self::NOW - 600);

        $table = $this->build($rows, other: $other)->tables[0];

        static::assertCount(101, $table->rows);
        static::assertSame('App\E102Exception', $table->rows[0]->cells['exception']->text);
        static::assertSame('App\E003Exception', $table->rows[99]->cells['exception']->text);
        $last = $table->rows[100];
        static::assertTrue($last->muted);
        static::assertSame('ho', $last->key);
        static::assertSame(['exception' => 'Other exceptions', 'count' => '7', 'last' => '10 min ago'], array_map(static fn(Cell $cell): string => $cell->text, $last->cells));
        static::assertSame('text', $last->cells['exception']->kind);
        static::assertSame(2, $table->remaining);
    }

    public function testWhereEachExceptionWasThrown(): void
    {
        $view = $this->build([self::exception('h1', \RuntimeException::class, 4, self::NOW - 5)], where: [
            self::where('w1', 'GET app_boom', \RuntimeException::class, 1, self::NOW - 3600),
            self::where('w2', 'POST app_save', \RuntimeException::class, 3, self::NOW - 5),
            new ReportRow('w3', 'malformed', 'malformed', [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(9, 0.0, self::NOW)]),
        ]);

        static::assertCount(2, $view->tables);
        $table = $view->tables[1];
        static::assertSame('where', $table->id);
        static::assertSame('Where', $table->title);
        static::assertSame(
            [['exception', 'Exception', false, null], ['where', 'Where', false, null], ['count', 'Count', true, null], ['last', 'Last seen', true, null]],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame(['w2', 'w1'], array_map(static fn(Row $row): string => $row->key, $table->rows));

        $row = $table->rows[1];
        static::assertSame(['data-jm-exception' => \RuntimeException::class, 'data-jm-count' => '1'], $row->attributes);
        static::assertSame(
            ['exception' => \RuntimeException::class, 'where' => 'app_boom', 'count' => '1', 'last' => '1 h ago'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['exception']->kind);
        static::assertSame('route', $row->cells['where']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['where']->data);
    }

    public function testEntryPointsFoldedIntoOtherComeLast(): void
    {
        $rows = [];
        for ($i = 1; $i <= 101; ++$i) {
            $rows[] = self::where('w' . $i, 'GET app_' . $i, \RuntimeException::class, $i, self::NOW - 60);
        }
        $other = new ReportRow('wo', Aggregator::OTHER, null, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(4, 0.0, self::NOW - 7200)]);

        $table = $this->build([self::exception('h1', \RuntimeException::class)], where: $rows, whereOther: $other)->tables[1];

        static::assertCount(101, $table->rows);
        static::assertSame(1, $table->remaining);
        static::assertTrue($table->rows[100]->muted);
        static::assertSame(['exception' => 'Other entry points', 'count' => '4', 'last' => '2 h ago'], array_map(static fn(Cell $cell): string => $cell->text, $table->rows[100]->cells));
    }

    public function testThePanelOfEntryPointsIsLeftOutWhenThereAreNone(): void
    {
        $view = $this->build([self::exception('h1', \RuntimeException::class)]);

        static::assertCount(1, $view->tables);
        static::assertFalse($view->isEmpty());
        static::assertTrue($this->build([])->isEmpty());
    }

    public function testTheCards(): void
    {
        $other = self::exception('ho', Aggregator::OTHER, 4, self::NOW - 7200);
        $view = $this->build([
            self::exception('h1', \RuntimeException::class, 3, self::NOW - 300),
            self::exception('h2', \LogicException::class, 2, self::NOW - 2),
        ], other: $other, series: [1, 0, 4], where: [
            self::where('w1', 'GET app_boom', \RuntimeException::class),
            self::where('w2', 'GET app_save', \LogicException::class),
        ], whereKeyCount: 130, period: Period::OneDay);

        static::assertSame(['Exceptions', 'Classes', 'Last exception', 'Entry points'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['9', '2', 'just now', '130'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame('Last 24 hours', $view->cards[0]->hint);
        static::assertSame([1, 0, 4], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame(\LogicException::class, $view->cards[2]->hint);
        static::assertSame([null, null, null, 'bad'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testTheLastExceptionMayBeAnOtherOne(): void
    {
        $view = $this->build([self::exception('h1', \RuntimeException::class, 1, self::NOW - 600)], other: self::exception('ho', Aggregator::OTHER, 1, self::NOW - 120));

        static::assertSame('2 min ago', $view->cards[2]->value);
        static::assertSame('Other exceptions', $view->cards[2]->hint);
        static::assertSame('0', $view->cards[3]->value);
        static::assertNull($view->cards[3]->tone);
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     * @param list<ReportRow> $where
     */
    private function build(
        array $rows,
        ?ReportRow $other = null,
        array $series = [],
        array $where = [],
        ?ReportRow $whereOther = null,
        ?int $whereKeyCount = null,
        Period $period = Period::OneHour,
    ): ScreenView {
        return (new ExceptionsScreen(true))->build($this->reader($rows, $other, $series, $where, $whereOther, $whereKeyCount), $period, self::NOW, 'count', 'desc');
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     * @param list<ReportRow> $where
     */
    private function reader(
        array $rows,
        ?ReportRow $other = null,
        array $series = [],
        array $where = [],
        ?ReportRow $whereOther = null,
        ?int $whereKeyCount = null,
    ): FixedReader {
        $all = [...$rows, ...($other === null ? [] : [$other])];
        $total = new MetricValue(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::EXCEPTION_COUNT), $all)),
            0.0,
            (float) max([0, ...array_map(static fn(ReportRow $row): float => $row->max(Metric::EXCEPTION_COUNT), $all)]),
        );
        $exceptions = new Report($rows, $other, \count($rows), $series, $all === [] ? [] : [Metric::EXCEPTION_COUNT => $total]);
        $entryPoints = new Report($where, $whereOther, $whereKeyCount ?? \count($where));

        return new FixedReader(static fn(ReportQuery $query): Report => $query->family === Family::ExceptionWhere ? $entryPoints : $exceptions);
    }

    private static function exception(string $hash, string $class, int $count = 1, int $last = self::NOW): ReportRow
    {
        return new ReportRow($hash, $class, $class, [Metric::EXCEPTION_COUNT => new MetricValue($count, 0.0, (float) $last)]);
    }

    private static function where(string $hash, string $entryPoint, string $class, int $count = 1, int $last = self::NOW): ReportRow
    {
        $key = 'request' . Entry::KEY_SEPARATOR . $entryPoint . Entry::KEY_SEPARATOR . $class;

        return new ReportRow($hash, $key, $key, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue($count, 0.0, (float) $last)]);
    }
}
