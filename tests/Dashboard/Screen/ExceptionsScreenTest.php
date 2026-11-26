<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\CacheHitRateCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\HttpFailuresCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\RepeatedQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\ServerErrorsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowQueriesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\SlowRequestsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Screen\ExceptionsScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\CheckLine;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionsScreenTest extends TestCase
{
    private const NOW = 1800000000;

    /** @var array<string, string> check id => the metric that raises it */
    private const RAISING = [
        'requests.server_errors' => Metric::REQUEST_STATUS_PREFIX . '5xx',
        'messages.failed' => Metric::MESSAGE_FAILED,
        'tasks.failed' => Metric::TASK_FAILED,
        'commands.failed' => Metric::COMMAND_FAILED,
    ];

    /** @var array<string, Family> check id => the family that names its culprit */
    private const CULPRITS = [
        'requests.server_errors' => Family::Request,
        'messages.failed' => Family::Message,
        'tasks.failed' => Family::Task,
        'commands.failed' => Family::Command,
    ];

    public function testTheScreenDeclaresItself(): void
    {
        $screen = self::screen();

        static::assertSame('exceptions', $screen->id());
        static::assertSame('Exceptions', $screen->label());
        static::assertSame('Errors per exception class', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['count' => 'desc', 'last' => 'desc', 'class' => 'asc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No exceptions recorded', 'text' => 'An exception behind a 5xx response, a failed command or a failed message shows up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new ExceptionsScreen(false, self::checks()))->isAvailable());
    }

    public function testTheScreenReadsBothFamiliesThenItsChecks(): void
    {
        foreach (array_keys(self::screen()->sorts()) as $sort) {
            $reader = self::reader([self::exception('h1', \RuntimeException::class)]);
            self::screen()->build($reader, Period::SixHours, self::NOW, $sort, 'desc');

            static::assertSame([Family::Exception, Family::ExceptionWhere], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries), $sort);
            static::assertSame([Period::SixHours, Period::SixHours], array_map(static fn(ReportQuery $query): Period => $query->period, $reader->queries));
            static::assertSame([self::NOW, self::NOW], array_map(static fn(ReportQuery $query): int => $query->now, $reader->queries));
            static::assertSame([Metric::EXCEPTION_COUNT, null], array_map(static fn(ReportQuery $query): ?string => $query->seriesMetric, $reader->queries));
            static::assertSame([[Period::SixHours, self::NOW]], array_map(static fn(array $request): array => [$request[0], $request[1]], $reader->totalRequests));
            static::assertSame([], $reader->labelRequests);
        }
    }

    public function testAnEmptyScreenReadsNoCheck(): void
    {
        $reader = self::reader([], totals: self::raising(...array_keys(self::RAISING)));
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertTrue($view->isEmpty());
        static::assertSame([], $reader->totalRequests);
        static::assertCount(2, $reader->queries);
        static::assertNull($view->lead);
        static::assertNull($view->checks);
        static::assertNull($view->guide);
        static::assertNull($view->raised);
        static::assertSame([], $view->cards);
        static::assertSame('exceptions', $view->tables[0]->id);
    }

    public function testTheTable(): void
    {
        $table = self::build([self::exception('h1', \RuntimeException::class, 3, self::NOW - 42)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('exceptions', $table->id);
        static::assertSame('By class', $table->title);
        static::assertSame(
            [['exception', 'Exception', false, 'class'], ['count', 'Count', true, 'count'], ['last', 'Last seen', true, 'last']],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame(['class', 'classes'], [$table->nounFor(1), $table->nounFor(3)]);
        static::assertSame('each with where it was thrown', $table->note);
        static::assertSame('JMonitor counts exceptions. It keeps no stack trace: the trace is in your logs or your error tracker.', $table->footnote);
        static::assertFalse($table->partsLegend);

        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertFalse($row->nested);
        static::assertSame(['data-jm-exception' => \RuntimeException::class, 'data-jm-count' => '3'], $row->attributes);
        static::assertSame(
            ['exception' => \RuntimeException::class, 'count' => '3', 'last' => '42 s ago'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('code', $row->cells['exception']->kind);
    }

    public function testTheSortsOrderTheClassesAndThePlacesFollowTheirClass(): void
    {
        $rows = [
            self::exception('h1', 'RuntimeException', 5, self::NOW - 100),
            self::exception('h2', 'LogicException', 2, self::NOW - 10),
            self::exception('h3', 'App\FooException', 5, self::NOW - 3000),
        ];
        $where = [
            self::where('w1', 'GET app_a', 'RuntimeException', 5),
            self::where('w2', 'GET app_b', 'LogicException', 2),
            self::where('w3', 'GET app_c', 'App\FooException', 5),
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
            $view = self::screen()->build(self::reader($rows, where: $where), Period::OneHour, self::NOW, $sort, $direction);
            $tableRows = $view->tables[0]->rows;

            static::assertSame($classes, array_map(static fn(Row $row): string => $row->attributes['data-jm-exception'], array_values(array_filter($tableRows, static fn(Row $row): bool => !$row->nested))), $order);
            static::assertSame(
                array_merge(...array_map(static fn(string $class): array => [$class, $class], $classes)),
                array_map(static fn(Row $row): string => $row->attributes['data-jm-exception'], $tableRows),
                $order,
            );
            static::assertSame([false, true, false, true, false, true], array_map(static fn(Row $row): bool => $row->nested, $tableRows), $order);
        }
    }

    public function testOtherExceptionsComeLastAndTheRestIsCounted(): void
    {
        $rows = [];
        for ($i = 1; $i <= 102; ++$i) {
            $rows[] = self::exception('h' . $i, sprintf('App\E%03dException', $i), $i, self::NOW - 60);
        }
        $other = self::exception('ho', Aggregator::OTHER, 7, self::NOW - 600);

        $table = self::build($rows, other: $other)->tables[0];

        static::assertCount(101, $table->rows);
        static::assertSame('App\E102Exception', $table->rows[0]->cells['exception']->text);
        static::assertSame('App\E003Exception', $table->rows[99]->cells['exception']->text);
        $last = $table->rows[100];
        static::assertTrue($last->muted);
        static::assertFalse($last->nested);
        static::assertSame('ho', $last->key);
        static::assertSame(['data-jm-exception' => Aggregator::OTHER, 'data-jm-count' => '7'], $last->attributes);
        static::assertSame(['exception' => 'Other exceptions', 'count' => '7', 'last' => '10 min ago'], array_map(static fn(Cell $cell): string => $cell->text, $last->cells));
        static::assertSame('text', $last->cells['exception']->kind);
        static::assertSame(2, $table->remaining);
    }

    public function testThePlacesOfAClassAreNestedUnderItTheMostCountedFirst(): void
    {
        $view = self::build([self::exception('h1', \RuntimeException::class, 7, self::NOW - 5)], where: [
            self::where('w1', 'GET app_boom', \RuntimeException::class, 1, self::NOW - 3600),
            self::where('w2', 'POST app_save', \RuntimeException::class, 3, self::NOW - 5),
            self::where('w3', 'GET app_list', \RuntimeException::class, 3, self::NOW - 60),
        ]);

        static::assertCount(1, $view->tables);
        $rows = $view->tables[0]->rows;
        static::assertSame(['h1', 'w3', 'w2', 'w1'], array_map(static fn(Row $row): string => $row->key, $rows));

        $row = $rows[3];
        static::assertTrue($row->nested);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-exception' => \RuntimeException::class, 'data-jm-place' => 'request GET app_boom', 'data-jm-count' => '1'], $row->attributes);
        static::assertSame(
            ['exception' => 'app_boom', 'count' => '1', 'last' => '1 h ago'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame(['exception', 'count', 'last'], array_keys($row->cells));
        static::assertSame('route', $row->cells['exception']->kind);
        static::assertSame(['method' => 'GET'], $row->cells['exception']->data);
    }

    public function testAPlaceMayBeARequestACommandAMessageOrATask(): void
    {
        $task = (new TaskKey('default', '28af6db0', 'app:cleanup', 'every 1 hour'))->toString();
        $rows = self::build([self::exception('h1', \RuntimeException::class, 10)], where: [
            self::where('w1', 'app:work', \RuntimeException::class, 4, unitType: Entry::TYPE_COMMAND),
            self::where('w2', 'App\Message\SendInvoice', \RuntimeException::class, 3, unitType: Entry::TYPE_MESSAGE),
            self::where('w3', $task, \RuntimeException::class, 2, unitType: Entry::TYPE_TASK),
            self::where('w4', 'GET app_boom', \RuntimeException::class, 1),
        ])->tables[0]->rows;
        $place = static fn(Row $row): array => [$row->cells['exception']->text, $row->cells['exception']->kind, $row->cells['exception']->data, $row->attributes['data-jm-place']];

        static::assertSame(['h1', 'w1', 'w2', 'w3', 'w4'], array_map(static fn(Row $row): string => $row->key, $rows));
        static::assertSame(['app:work', 'route', ['method' => 'CLI'], 'command app:work'], $place($rows[1]));
        static::assertSame(['App\Message\SendInvoice', 'route', ['method' => 'MSG'], 'message App\Message\SendInvoice'], $place($rows[2]));
        static::assertSame(['app:cleanup', 'route', ['method' => 'TASK'], 'task app:cleanup'], $place($rows[3]));
        static::assertSame(['app_boom', 'route', ['method' => 'GET'], 'request GET app_boom'], $place($rows[4]));
    }

    public function testOnlyThePlacesOfTheListedClassesAreShown(): void
    {
        $rows = [];
        for ($i = 1; $i <= 101; ++$i) {
            $rows[] = self::exception('h' . $i, sprintf('App\E%03dException', $i), $i + 1);
        }
        $other = self::exception('ho', Aggregator::OTHER, 1);
        $where = [
            self::where('w1', 'GET app_listed', 'App\E101Exception', 2),
            self::where('w2', 'GET app_unlisted', 'App\E001Exception', 2),
            self::where('w3', 'GET app_unknown', 'App\NeverReadException', 2),
            self::where('w4', 'GET app_other', Aggregator::OTHER, 2),
            new ReportRow('w5', 'malformed', 'malformed', [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(9, 0.0, self::NOW)]),
        ];
        $whereOther = new ReportRow('wo', Aggregator::OTHER, null, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(4, 0.0, self::NOW)]);

        $table = self::build($rows, other: $other, where: $where, whereOther: $whereOther)->tables[0];
        $nested = array_values(array_filter($table->rows, static fn(Row $row): bool => $row->nested));

        static::assertSame(['w1'], array_map(static fn(Row $row): string => $row->key, $nested));
        static::assertSame('h101', $table->rows[0]->key);
        static::assertSame('w1', $table->rows[1]->key);
        static::assertSame('h100', $table->rows[2]->key);
        static::assertSame('ho', $table->rows[\count($table->rows) - 1]->key);
        static::assertCount(102, $table->rows);
        static::assertSame(1, $table->remaining);
    }

    public function testAClassWithoutPlaceStandsAlone(): void
    {
        $rows = self::build([
            self::exception('h1', \RuntimeException::class, 2),
            self::exception('h2', \LogicException::class, 1),
        ], where: [self::where('w1', 'GET app_boom', \LogicException::class)])->tables[0]->rows;

        static::assertSame(['h1', 'h2', 'w1'], array_map(static fn(Row $row): string => $row->key, $rows));
        static::assertSame([false, false, true], array_map(static fn(Row $row): bool => $row->nested, $rows));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, string}>
     */
    public static function sentences(): iterable
    {
        yield 'one exception' => [[self::exception('h1', \RuntimeException::class, 1)], null, '1 exception in the last hour.'];
        yield 'one class' => [[self::exception('h1', \RuntimeException::class, 4)], null, '4 exceptions in the last hour, all of one class.'];
        yield 'several classes' => [[self::exception('h1', \RuntimeException::class, 1200), self::exception('h2', \LogicException::class, 3)], null, '1,203 exceptions in the last hour, of 2 classes.'];
        yield 'the other line counts as a class at least' => [[self::exception('h1', \RuntimeException::class, 2)], self::exception('ho', Aggregator::OTHER, 3), '5 exceptions in the last hour, of 2 classes or more.'];
        yield 'several classes and the other line' => [[self::exception('h1', \RuntimeException::class, 2), self::exception('h2', \LogicException::class, 1)], self::exception('ho', Aggregator::OTHER, 1200), '1,203 exceptions in the last hour, of 3 classes or more.'];
        yield 'the other line alone' => [[], self::exception('ho', Aggregator::OTHER, 3), '3 exceptions in the last hour.'];
        yield 'one exception in the other line' => [[], self::exception('ho', Aggregator::OTHER, 1), '1 exception in the last hour.'];
    }

    /** @param list<ReportRow> $rows */
    #[DataProvider('sentences')]
    public function testTheLeadSumsUpThePeriod(array $rows, ?ReportRow $other, string $sentence): void
    {
        $view = self::build($rows, other: $other);

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
    }

    /**
     * @return iterable<string, array{list<ReportRow>, ?ReportRow, string}>
     */
    public static function details(): iterable
    {
        $task = (new TaskKey('default', '28af6db0', 'app:cleanup', 'every 1 hour'))->toString();

        yield 'no place' => [[], null, ''];
        yield 'requests only' => [[self::where('w1', 'GET app_a', 'A', 2), self::where('w2', 'GET app_b', 'B', 1)], null, 'All thrown in requests.'];
        yield 'commands only' => [[self::where('w1', 'app:work', 'A', 2, unitType: Entry::TYPE_COMMAND)], null, 'All thrown in commands.'];
        yield 'messages only' => [[self::where('w1', 'App\Message\Send', 'A', 2, unitType: Entry::TYPE_MESSAGE)], null, 'All thrown in messages.'];
        yield 'scheduled tasks only' => [[self::where('w1', $task, 'A', 2, unitType: Entry::TYPE_TASK)], null, 'All thrown in scheduled tasks.'];
        yield 'every kind, in a fixed order' => [[
            self::where('w1', $task, 'A', 4, unitType: Entry::TYPE_TASK),
            self::where('w2', 'App\Message\Send', 'A', 3, unitType: Entry::TYPE_MESSAGE),
            self::where('w3', 'app:work', 'B', 2, unitType: Entry::TYPE_COMMAND),
            self::where('w4', 'GET app_a', 'A', 1200),
            self::where('w5', 'GET app_b', 'B', 1),
        ], null, '1,201 in requests, 2 in commands, 3 in messages, 4 in scheduled tasks.'];
        yield 'two kinds, the others left out' => [[
            self::where('w1', 'app:work', 'A', 2, unitType: Entry::TYPE_COMMAND),
            self::where('w2', 'App\Message\Send', 'A', 1, unitType: Entry::TYPE_MESSAGE),
        ], null, '2 in commands, 1 in messages.'];
        yield 'places not all read' => [[self::where('w1', 'GET app_a', 'A', 2)], new ReportRow('wo', Aggregator::OTHER, null, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(1, 0.0, self::NOW)]), ''];
        yield 'a key that does not read' => [[self::where('w1', 'GET app_a', 'A', 2), new ReportRow('w2', 'malformed', 'malformed', [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(1, 0.0, self::NOW)])], null, ''];
        yield 'a kind that is not known' => [[self::where('w1', 'GET app_a', 'A', 2), self::where('w2', 'somewhere', 'A', 1, unitType: 'elsewhere')], null, ''];
    }

    /** @param list<ReportRow> $where */
    #[DataProvider('details')]
    public function testTheDetailSaysWhereTheExceptionsWereThrown(array $where, ?ReportRow $whereOther, string $detail): void
    {
        $view = self::build([self::exception('h1', 'A', 3), self::exception('h2', 'B', 2)], where: $where, whereOther: $whereOther);

        static::assertNotNull($view->lead);
        static::assertSame($detail, $view->lead->detail);
    }

    /**
     * @return iterable<string, array{Period, string, string}>
     */
    public static function periods(): iterable
    {
        yield 'last hour' => [Period::OneHour, '1 h ago', '2 exceptions in the last hour, all of one class.'];
        yield 'last 6 hours' => [Period::SixHours, '6 h ago', '2 exceptions in the last 6 hours, all of one class.'];
        yield 'last 24 hours' => [Period::OneDay, '24 h ago', '2 exceptions in the last 24 hours, all of one class.'];
        yield 'last 7 days' => [Period::SevenDays, '7 d ago', '2 exceptions in the last 7 days, all of one class.'];
    }

    #[DataProvider('periods')]
    public function testTheLeadDrawsTheExceptionsOfThePeriod(Period $period, string $start, string $sentence): void
    {
        $view = self::build([self::exception('h1', \RuntimeException::class, 2)], series: [1, 0, 1], period: $period);

        static::assertNotNull($view->lead);
        static::assertSame($sentence, $view->lead->sentence);
        static::assertSame([1, 0, 1], $view->lead->series);
        static::assertSame('exceptions', $view->lead->seriesLabel);
        static::assertSame($start, $view->lead->seriesStart);
    }

    public function testTheCards(): void
    {
        $other = self::exception('ho', Aggregator::OTHER, 4, self::NOW - 7200);
        $view = self::build([
            self::exception('h1', \RuntimeException::class, 3, self::NOW - 300),
            self::exception('h2', \LogicException::class, 2, self::NOW - 90),
        ], other: $other, where: [
            self::where('w1', 'GET app_boom', \RuntimeException::class),
            self::where('w2', 'GET app_save', \LogicException::class),
        ], whereKeyCount: 130, period: Period::OneDay);

        static::assertSame(['Exceptions', 'Classes', 'Places', 'Last one'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['9', '3+', '130', '1 min ago'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(
            ['Errors your code did not recover from.', 'Distinct kinds of error.', 'Routes, commands, messages or tasks that threw one.', 'Still happening, or already over?'],
            array_map(static fn(Card $card): string => $card->hint, $view->cards),
        );
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
        static::assertSame([[], [], [], []], array_map(static fn(Card $card): array => $card->series, $view->cards));
        static::assertSame([false, false, false, false], array_map(static fn(Card $card): bool => $card->wide, $view->cards));
    }

    public function testTheLastOneMayBeAnOtherException(): void
    {
        $view = self::build([self::exception('h1', \RuntimeException::class, 1, self::NOW - 600)], other: self::exception('ho', Aggregator::OTHER, 1, self::NOW - 120));

        static::assertSame('2 min ago', $view->cards[3]->value);
    }

    public function testTheClassesCardCountsTheClassesBehindTheOtherLine(): void
    {
        static::assertSame('2', self::build([self::exception('h1', 'A'), self::exception('h2', 'B')])->cards[1]->value);
        static::assertSame('3+', self::build([self::exception('h1', 'A'), self::exception('h2', 'B')], other: self::exception('ho', Aggregator::OTHER))->cards[1]->value);
        static::assertSame('1+', self::build([], other: self::exception('ho', Aggregator::OTHER, 2))->cards[1]->value);
    }

    public function testThePlacesCardCountsThePlacesBehindTheOtherLine(): void
    {
        $other = new ReportRow('wo', Aggregator::OTHER, null, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(4, 0.0, self::NOW)]);

        static::assertSame('0', self::build([self::exception('h1', 'A')])->cards[2]->value);
        static::assertSame('1+', self::build([self::exception('h1', 'A', 4)], where: [], whereOther: $other)->cards[2]->value);
        static::assertSame('3+', self::build([self::exception('h1', 'A', 4)], where: [self::where('w1', 'GET app_a', 'A'), self::where('w2', 'GET app_b', 'A')], whereOther: $other)->cards[2]->value);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function placesAndChecks(): iterable
    {
        $task = (new TaskKey('default', '28af6db0', 'app:cleanup', 'every 1 hour'))->toString();

        yield 'a request' => [Entry::TYPE_REQUEST, 'GET app_boom', 'requests.server_errors', 'requests'];
        yield 'a message' => [Entry::TYPE_MESSAGE, 'App\Message\Send', 'messages.failed', 'background'];
        yield 'a scheduled task' => [Entry::TYPE_TASK, $task, 'tasks.failed', 'background'];
        yield 'a command' => [Entry::TYPE_COMMAND, 'app:work', 'commands.failed', 'background'];
    }

    #[DataProvider('placesAndChecks')]
    public function testACheckOnlyShowsForAPlaceOfItsKind(string $unitType, string $place, string $check, string $screen): void
    {
        $reader = self::reader([self::exception('h1', \RuntimeException::class, 2)], where: [self::where('w1', $place, \RuntimeException::class, 2, unitType: $unitType)], totals: self::raising(...array_keys(self::RAISING)));
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame('Worth a look', $view->checks->title);
        static::assertSame([$check], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
        $line = $view->checks->lines[0];
        static::assertSame($screen, $line->path);
        static::assertSame($screen === 'requests' ? 'Requests' : 'Background', $line->where);
        static::assertSame(['period' => '1h'], $line->query);
        static::assertSame([Family::Exception, Family::ExceptionWhere, self::CULPRITS[$check]], array_map(static fn(ReportQuery $query): Family => $query->family, $reader->queries));
    }

    public function testTheChecksOfSeveralKindsOfPlaceShowMostSevereFirstInTheirOrder(): void
    {
        $reader = self::reader([self::exception('h1', \RuntimeException::class, 4)], where: [
            self::where('w1', 'app:work', \RuntimeException::class, 1, unitType: Entry::TYPE_COMMAND),
            self::where('w2', 'App\Message\Send', \RuntimeException::class, 1, unitType: Entry::TYPE_MESSAGE),
            self::where('w3', 'GET app_boom', \RuntimeException::class, 2),
        ], totals: self::raising(...array_keys(self::RAISING)));
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame(['requests.server_errors', 'messages.failed', 'commands.failed'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testEveryRaisedCheckShowsWhenThePlacesAreNotAllRead(): void
    {
        $whereOther = new ReportRow('wo', Aggregator::OTHER, null, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue(4, 0.0, self::NOW)]);
        $reader = self::reader([self::exception('h1', \RuntimeException::class, 6)], where: [self::where('w1', 'GET app_boom', \RuntimeException::class, 2)], whereOther: $whereOther, totals: self::raising(...array_keys(self::RAISING)));
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame(['requests.server_errors', 'messages.failed', 'tasks.failed', 'commands.failed'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));

        $reader = self::reader([self::exception('h1', \RuntimeException::class, 6)], whereOther: $whereOther, totals: self::raising('tasks.failed'));
        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame(['tasks.failed'], array_map(static fn(CheckLine $line): string => $line->id, $view->checks->lines));
    }

    public function testWithoutCheckThereIsNoBlock(): void
    {
        $where = [self::where('w1', 'GET app_boom', \RuntimeException::class, 2)];

        $quiet = self::screen()->build(self::reader([self::exception('h1', \RuntimeException::class, 2)], where: $where), Period::OneHour, self::NOW, 'count', 'desc');
        $elsewhere = self::screen()->build(self::reader([self::exception('h1', \RuntimeException::class, 2)], where: $where, totals: self::raising('messages.failed', 'tasks.failed', 'commands.failed')), Period::OneHour, self::NOW, 'count', 'desc');
        $noPlace = self::screen()->build(self::reader([self::exception('h1', \RuntimeException::class, 2)], totals: self::raising(...array_keys(self::RAISING))), Period::OneHour, self::NOW, 'count', 'desc');

        foreach (['quiet' => $quiet, 'raised for another kind of place' => $elsewhere, 'no place read' => $noPlace] as $case => $view) {
            static::assertNull($view->checks, $case);
            static::assertNotNull($view->lead, $case);
            static::assertNotNull($view->guide, $case);
        }
    }

    public function testTheCulpritsAreOnlyReadForTheChecksShown(): void
    {
        $where = [self::where('w1', 'app:work', \RuntimeException::class, 2, unitType: Entry::TYPE_COMMAND)];

        $none = self::reader([self::exception('h1', \RuntimeException::class, 2)], where: $where);
        self::screen()->build($none, Period::OneHour, self::NOW, 'count', 'desc');

        $one = self::reader([self::exception('h1', \RuntimeException::class, 2)], where: $where, totals: self::raising(...array_keys(self::RAISING)));
        self::screen()->build($one, Period::OneHour, self::NOW, 'count', 'desc');

        $notRaised = self::reader([self::exception('h1', \RuntimeException::class, 2)], where: [...$where, self::where('w2', 'GET app_boom', \RuntimeException::class)], totals: self::raising('commands.failed'));
        self::screen()->build($notRaised, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertSame([Family::Exception, Family::ExceptionWhere], array_map(static fn(ReportQuery $query): Family => $query->family, $none->queries));
        static::assertSame([Family::Exception, Family::ExceptionWhere, Family::Command], array_map(static fn(ReportQuery $query): Family => $query->family, $one->queries));
        static::assertSame([Family::Exception, Family::ExceptionWhere, Family::Command], array_map(static fn(ReportQuery $query): Family => $query->family, $notRaised->queries));
        static::assertCount(1, $one->totalRequests);
    }

    public function testTheCheckNamesItsCulprit(): void
    {
        $requests = new Report([new ReportRow('r1', 'GET app_boom', 'GET app_boom', [
            Metric::REQUEST_DURATION => new MetricValue(3, 30.0, 10.0),
            Metric::REQUEST_STATUS_PREFIX . '5xx' => new MetricValue(3, 0.0, 0.0),
        ])], null, 1);
        $exceptions = new Report([self::exception('h1', \RuntimeException::class, 3)], null, 1, [], [Metric::EXCEPTION_COUNT => new MetricValue(3, 0.0, (float) self::NOW)]);
        $where = new Report([self::where('w1', 'GET app_boom', \RuntimeException::class, 3)], null, 1);
        $reader = new FixedReader(static fn(ReportQuery $query): Report => match ($query->family) {
            Family::Exception => $exceptions,
            Family::ExceptionWhere => $where,
            default => $requests,
        }, totals: [Metric::REQUEST_STATUS_PREFIX . '5xx' => new MetricValue(3, 0.0, 0.0)]);

        $view = self::screen()->build($reader, Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->checks);
        static::assertSame('3 server errors', $view->checks->lines[0]->title);
        static::assertSame('GET app_boom answered with a 5xx status 3 times.', $view->checks->lines[0]->sentence);
    }

    public function testTheViewCarriesTheRaisedChecks(): void
    {
        $view = self::screen()->build(self::reader([self::exception('h1', 'A')], totals: self::raising('tasks.failed')), Period::OneHour, self::NOW, 'count', 'desc');

        static::assertNotNull($view->raised);
        static::assertTrue($view->raised->has('tasks.failed'));
        static::assertNull($view->raised->badge('exceptions'));
    }

    public function testTheGuideExplainsTheScreen(): void
    {
        $guide = self::build([self::exception('h1', 'A')])->guide;

        static::assertNotNull($guide);
        static::assertSame(
            [
                ['Class, then place.', 'The class says what went wrong, the place says where. One class in many places points to a shared cause, such as a service that is down.', []],
                ['New or usual?', 'Switch the period to 24h or 7d. An exception that appears there every hour is a known one; one that only shows in the last hour started recently.', []],
                ['An empty screen is the goal.', 'When nothing was thrown, this screen says so and shows nothing else.', []],
            ],
            array_map(static fn(GuideItem $item): array => [$item->term, $item->text, $item->links], $guide->items),
        );
    }

    public function testAScreenWithOnlyTheOtherLineIsNotEmpty(): void
    {
        static::assertFalse(self::build([], other: self::exception('ho', Aggregator::OTHER))->isEmpty());
        static::assertTrue(self::build([])->isEmpty());
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<int>       $series
     * @param list<ReportRow> $where
     */
    private static function build(
        array $rows,
        ?ReportRow $other = null,
        array $series = [],
        array $where = [],
        ?ReportRow $whereOther = null,
        ?int $whereKeyCount = null,
        Period $period = Period::OneHour,
    ): ScreenView {
        return self::screen()->build(self::reader($rows, $other, $series, $where, $whereOther, $whereKeyCount), $period, self::NOW, 'count', 'desc');
    }

    /**
     * @param list<ReportRow>            $rows
     * @param list<int>                  $series
     * @param list<ReportRow>            $where
     * @param array<string, MetricValue> $totals metric type => total, for the checks
     */
    private static function reader(
        array $rows,
        ?ReportRow $other = null,
        array $series = [],
        array $where = [],
        ?ReportRow $whereOther = null,
        ?int $whereKeyCount = null,
        array $totals = [],
    ): FixedReader {
        $all = [...$rows, ...($other === null ? [] : [$other])];
        $total = new MetricValue(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::EXCEPTION_COUNT), $all)),
            0.0,
            (float) max([0, ...array_map(static fn(ReportRow $row): float => $row->max(Metric::EXCEPTION_COUNT), $all)]),
        );
        $exceptions = new Report($rows, $other, \count($rows), $series, $all === [] ? [] : [Metric::EXCEPTION_COUNT => $total]);
        $places = new Report($where, $whereOther, $whereKeyCount ?? \count($where));

        return new FixedReader(static fn(ReportQuery $query): Report => match ($query->family) {
            Family::Exception => $exceptions,
            Family::ExceptionWhere => $places,
            default => new Report(),
        }, totals: $totals);
    }

    /** @return array<string, MetricValue> */
    private static function raising(string ...$checks): array
    {
        $totals = [];
        foreach ($checks as $check) {
            $totals[self::RAISING[$check]] = new MetricValue(2, 0.0, 0.0);
        }

        return $totals;
    }

    private static function screen(): ExceptionsScreen
    {
        return new ExceptionsScreen(true, self::checks());
    }

    private static function checks(): ApplicationChecks
    {
        return new ApplicationChecks([
            new ServerErrorsCheck(),
            new SlowRequestsCheck(1000),
            new SlowQueriesCheck(100),
            new RepeatedQueriesCheck(10),
            new HttpFailuresCheck(),
            new HttpErrorsCheck(),
            new CacheHitRateCheck(),
            new FailedMessagesCheck(),
            new FailedTasksCheck(),
            new FailedCommandsCheck(),
        ]);
    }

    private static function exception(string $hash, string $class, int $count = 1, int $last = self::NOW): ReportRow
    {
        return new ReportRow($hash, $class, $class, [Metric::EXCEPTION_COUNT => new MetricValue($count, 0.0, (float) $last)]);
    }

    private static function where(string $hash, string $entryPoint, string $class, int $count = 1, int $last = self::NOW, string $unitType = Entry::TYPE_REQUEST): ReportRow
    {
        $key = $unitType . Entry::KEY_SEPARATOR . $entryPoint . Entry::KEY_SEPARATOR . $class;

        return new ReportRow($hash, $key, $key, [Metric::EXCEPTION_WHERE_COUNT => new MetricValue($count, 0.0, (float) $last)]);
    }
}
