<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedTasksCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedTasksCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new FailedTasksCheck();

        static::assertSame('tasks.failed', $check->id());
        static::assertSame(Severity::Critical, $check->severity());
        static::assertSame('background', $check->screen());
        static::assertSame('Background', $check->screenLabel());
        static::assertSame([Metric::TASK_FAILED], $check->metrics());
        static::assertNotInstanceOf(KeyedCheckInterface::class, $check);
    }

    public function testItReadsEveryTask(): void
    {
        static::assertEquals(new ReportQuery(Period::SevenDays, self::NOW, Family::Task), (new FailedTasksCheck())->query(Period::SevenDays, self::NOW));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one task, once' => [[self::row(self::task('Send reminders'), 1)], 1, '1 scheduled run failed', 'Send reminders failed once.'];
        yield 'one task, several times' => [[self::row(self::task('Send reminders'), 3)], 3, '3 scheduled runs failed', 'Send reminders failed 3 times.'];
        yield 'the most failures wins' => [
            [self::row(self::task('Send reminders'), 1), self::row(self::task('Purge carts'), 4), self::row(self::task('Ping'), 0)],
            5,
            '5 scheduled runs failed',
            'Purge carts failed 4 times. 1 other task failed too.',
        ];
        yield 'several other tasks' => [
            [self::row(self::task('A'), 1), self::row(self::task('B'), 2), self::row(self::task('C'), 1)],
            4,
            '4 scheduled runs failed',
            'B failed 2 times. 2 other tasks failed too.',
        ];
        yield 'a key that does not parse keeps its key' => [[self::row('legacy-task', 2)], 2, '2 scheduled runs failed', 'legacy-task failed 2 times.'];
        yield 'thousands' => [[self::row(self::task('Send reminders'), 1234)], 1234, '1,234 scheduled runs failed', 'Send reminders failed 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheTaskWithTheMostFailures(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new FailedTasksCheck())->finding(new Report($rows), [Metric::TASK_FAILED => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('tasks.failed', $finding->id);
        static::assertSame(Severity::Critical, $finding->severity);
        static::assertSame('background', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A failed run is work that did not happen at its hour, and nobody is told.', $finding->why);
        static::assertSame('Open Exceptions to see what was thrown, fix it, then wait for the next run or start the task by hand.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testATieGoesToTheSmallestKeyNotTheSmallestLabel(): void
    {
        $first = (new TaskKey('a', '1', 'Zebra', 'every hour'))->toString();
        $second = (new TaskKey('b', '1', 'Alpaca', 'every hour'))->toString();

        $finding = (new FailedTasksCheck())->finding(new Report([self::row($second, 2), self::row($first, 2)]), [Metric::TASK_FAILED => new MetricValue(4, 0.0, 0.0)], Period::OneHour);

        static::assertSame('Zebra failed 2 times. 1 other task failed too.', $finding->sentence);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row(self::task('Send reminders'), 2)], self::row(Aggregator::OTHER, 50), 2);

        $finding = (new FailedTasksCheck())->finding($report, [Metric::TASK_FAILED => new MetricValue(52, 0.0, 0.0)], Period::OneHour);

        static::assertSame('Send reminders failed 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row(self::task('Send reminders'), 0)]), new Report([], self::row(Aggregator::OTHER, 3), 1)] as $report) {
            $finding = (new FailedTasksCheck())->finding($report, [Metric::TASK_FAILED => new MetricValue(3, 0.0, 0.0)], Period::SixHours);

            static::assertSame('3 scheduled runs failed', $finding->title);
            static::assertSame('Scheduled runs that ended with an exception in the last 6 hours.', $finding->sentence);
        }
    }

    private static function task(string $label): string
    {
        return (new TaskKey('default', md5($label), $label, 'every 15 minutes'))->toString();
    }

    private static function row(string $key, int $failures): ReportRow
    {
        return new ReportRow(md5($key), $key, null, [
            Metric::TASK_DURATION => new MetricValue(10, 400.0, 90.0),
            Metric::TASK_FAILED => new MetricValue($failures, 0.0, (float) self::NOW),
        ]);
    }
}
