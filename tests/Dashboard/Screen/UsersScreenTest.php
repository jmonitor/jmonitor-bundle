<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Screen\UsersScreen;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\ScreenView;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;

final class UsersScreenTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheScreenDeclaresItself(): void
    {
        $screen = $this->screen();

        static::assertSame('users', $screen->id());
        static::assertSame('Users', $screen->label());
        static::assertSame('Activity per signed-in user', $screen->heading());
        static::assertTrue($screen->isAvailable());
        static::assertSame(['requests' => 'desc', 'total' => 'desc', 'avg' => 'desc', 'slow' => 'desc', 'errors' => 'desc'], $screen->sorts());
        static::assertSame(
            ['title' => 'No signed-in activity yet', 'text' => 'Requests made by an authenticated user show up here within a few seconds.'],
            $screen->emptyState(),
        );
    }

    public function testTheScreenIsUnavailableWithoutTheCapture(): void
    {
        static::assertFalse((new UsersScreen(false, 1000))->isAvailable());
    }

    public function testEverySortIsComputedByTheDatabase(): void
    {
        $sorts = [
            'requests' => [Metric::USER_DURATION, 'count'],
            'total' => [Metric::USER_DURATION, 'sum'],
            'avg' => [Metric::USER_DURATION, 'avg'],
            'slow' => [Metric::USER_SLOW, 'count'],
            'errors' => [Metric::USER_ERROR, 'count'],
        ];

        static::assertSame(array_keys($this->screen()->sorts()), array_keys($sorts));

        foreach ($sorts as $sort => [$metric, $field]) {
            foreach (['desc' => true, 'asc' => false] as $direction => $descending) {
                $reader = new FixedReader();
                $this->screen()->build($reader, Period::SixHours, self::NOW, $sort, $direction);

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

        $reader = new FixedReader();
        $this->screen()->build($reader, Period::OneHour, self::NOW, 'unknown', 'desc');
        static::assertSame([Metric::USER_DURATION, 'count'], [$reader->queries[0]->sortMetric, $reader->queries[0]->sortField]);
    }

    public function testTheCellsOfAUser(): void
    {
        $table = $this->build([self::user('h1', 'alice@example.com', 12, 3000.0, 2, 1)])->tables[0];
        $row = $table->rows[0];

        static::assertSame('users', $table->id);
        static::assertSame('Users', $table->title);
        static::assertSame('user', $table->noun);
        static::assertSame(
            [['user', 'User', false, null], ['requests', 'Requests', true, 'requests'], ['total', 'Total time', true, 'total'], ['avg', 'Avg', true, 'avg'], ['slow', 'Slow', true, 'slow'], ['errors', '5xx', true, 'errors']],
            array_map(static fn(Column $column): array => [$column->id, $column->label, $column->numeric, $column->sort], $table->columns),
        );
        static::assertSame('h1', $row->key);
        static::assertFalse($row->muted);
        static::assertSame(['data-jm-user' => 'alice@example.com', 'data-jm-requests' => '12'], $row->attributes);
        static::assertSame(
            ['user' => 'alice@example.com', 'requests' => '12', 'total' => '3.0 s', 'avg' => '250 ms', 'slow' => '2', 'errors' => '1'],
            array_map(static fn(Cell $cell): string => $cell->text, $row->cells),
        );
        static::assertSame('text', $row->cells['user']->kind);
        static::assertSame('warn', $row->cells['slow']->tone);
        static::assertSame('bad', $row->cells['errors']->tone);
    }

    public function testAUserWithoutSlowRequestsNorErrorsIsNotHighlighted(): void
    {
        $row = $this->build([self::user('h1', 'bob')])->tables[0]->rows[0];

        static::assertSame(['0', '0'], [$row->cells['slow']->text, $row->cells['errors']->text]);
        static::assertSame(['zero', 'zero'], [$row->cells['slow']->tone, $row->cells['errors']->tone]);
    }

    public function testOtherUsersComeLastAndTheRestIsCounted(): void
    {
        $other = self::user('ho', Aggregator::OTHER, 50, 500.0);
        $table = $this->build([self::user('h1', 'alice'), self::user('h2', 'bob')], other: $other, keyCount: 130)->tables[0];

        static::assertSame(['h1', 'h2', 'ho'], array_map(static fn(Row $row): string => $row->key, $table->rows));
        $last = $table->rows[2];
        static::assertTrue($last->muted);
        static::assertSame('Other users', $last->cells['user']->text);
        static::assertSame('50', $last->cells['requests']->text);
        static::assertSame(['data-jm-user' => Aggregator::OTHER, 'data-jm-requests' => '50'], $last->attributes);
        static::assertSame(128, $table->remaining);
    }

    public function testTheCards(): void
    {
        $view = $this->build(
            [self::user('h1', 'alice', 12, 3000.0, 2, 1), self::user('h2', 'bob', 3, 30.0)],
            keyCount: 140,
            series: [3, 0, 7],
            period: Period::OneDay,
            totals: self::totals(250, 9000.0, 4, 2),
        );

        static::assertSame(['Active users', 'Requests', 'Slow requests', '5xx errors'], array_map(static fn(Card $card): string => $card->label, $view->cards));
        static::assertSame(['140', '250', '4', '2'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame(['Last 24 hours', 'By signed-in users', '1000 ms or more', 'Responses with a 5xx status'], array_map(static fn(Card $card): string => $card->hint, $view->cards));
        static::assertSame([3, 0, 7], $view->cards[0]->series);
        static::assertTrue($view->cards[0]->wide);
        static::assertSame([null, null, 'warn', 'bad'], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testCardsWithoutSlowRequestsNorErrorsStayNeutral(): void
    {
        $view = $this->build([self::user('h1', 'alice')]);

        static::assertSame(['1', '1', '0', '0'], array_map(static fn(Card $card): string => $card->value, $view->cards));
        static::assertSame([null, null, null, null], array_map(static fn(Card $card): ?string => $card->tone, $view->cards));
    }

    public function testAnEmptyPeriodShowsTheEmptyState(): void
    {
        static::assertTrue($this->build([])->isEmpty());
        static::assertFalse($this->build([self::user('h1', 'alice')])->isEmpty());
    }

    /**
     * @param list<ReportRow>                 $rows
     * @param list<int>                       $series
     * @param array<string, MetricValue>|null $totals null for the totals of the rows
     */
    private function build(
        array $rows,
        ?ReportRow $other = null,
        ?int $keyCount = null,
        array $series = [],
        Period $period = Period::OneHour,
        ?array $totals = null,
    ): ScreenView {
        $all = [...$rows, ...($other === null ? [] : [$other])];
        $totals ??= self::totals(
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): float => $row->sum(Metric::USER_DURATION), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_SLOW), $all)),
            array_sum(array_map(static fn(ReportRow $row): int => $row->count(Metric::USER_ERROR), $all)),
        );
        $reader = new FixedReader(new Report($rows, $other, $keyCount ?? \count($rows), $series, $all === [] ? [] : $totals));

        return $this->screen()->build($reader, $period, self::NOW, 'requests', 'desc');
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

    private function screen(): UsersScreen
    {
        return new UsersScreen(true, 1000);
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
