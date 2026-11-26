<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedCommandsCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedCommandsCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new FailedCommandsCheck();

        static::assertSame('commands.failed', $check->id());
        static::assertSame(Severity::Critical, $check->severity());
        static::assertSame('background', $check->screen());
        static::assertSame('Background', $check->screenLabel());
        static::assertSame([Metric::COMMAND_FAILED], $check->metrics());
        static::assertNotInstanceOf(KeyedCheckInterface::class, $check);
    }

    public function testItReadsTheFailuresOfTheCommands(): void
    {
        static::assertEquals(new ReportQuery(Period::OneDay, self::NOW, Family::Command, metrics: [Metric::COMMAND_FAILED]), (new FailedCommandsCheck())->query(Period::OneDay, self::NOW));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one command, once' => [[self::row('app:import', 1)], 1, '1 command run failed', 'app:import ended with a non-zero exit code once.'];
        yield 'one command, several times' => [[self::row('app:import', 3)], 3, '3 command runs failed', 'app:import ended with a non-zero exit code 3 times.'];
        yield 'the most failures wins' => [
            [self::row('app:import', 1), self::row('app:export', 4), self::row('app:ping', 0)],
            5,
            '5 command runs failed',
            'app:export ended with a non-zero exit code 4 times. 1 other command did too.',
        ];
        yield 'several other commands' => [
            [self::row('app:a', 1), self::row('app:b', 2), self::row('app:c', 1)],
            4,
            '4 command runs failed',
            'app:b ended with a non-zero exit code 2 times. 2 other commands did too.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('app:import', 2), self::row('app:export', 2)],
            4,
            '4 command runs failed',
            'app:export ended with a non-zero exit code 2 times. 1 other command did too.',
        ];
        yield 'thousands' => [[self::row('app:import', 1234)], 1234, '1,234 command runs failed', 'app:import ended with a non-zero exit code 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheCommandWithTheMostFailures(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new FailedCommandsCheck())->finding(new Report($rows), [Metric::COMMAND_FAILED => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('commands.failed', $finding->id);
        static::assertSame(Severity::Critical, $finding->severity);
        static::assertSame('background', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A non-zero exit code means the command gave up. When cron or a deploy started it, nobody may have seen it.', $finding->why);
        static::assertSame('Run it by hand to read its output, and open Exceptions to see what was thrown.', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('app:import', 2)], self::row(Aggregator::OTHER, 50), 2);

        $finding = (new FailedCommandsCheck())->finding($report, [Metric::COMMAND_FAILED => new MetricValue(52, 0.0, 0.0)], Period::OneHour);

        static::assertSame('app:import ended with a non-zero exit code 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('app:import', 0)]), new Report([], self::row(Aggregator::OTHER, 3), 1)] as $report) {
            $finding = (new FailedCommandsCheck())->finding($report, [Metric::COMMAND_FAILED => new MetricValue(3, 0.0, 0.0)], Period::SixHours);

            static::assertSame('3 command runs failed', $finding->title);
            static::assertSame('Command runs that ended with a non-zero exit code in the last 6 hours.', $finding->sentence);
        }
    }

    private static function row(string $command, int $failures): ReportRow
    {
        return new ReportRow(md5($command), $command, null, [
            Metric::COMMAND_DURATION => new MetricValue(10, 400.0, 90.0),
            Metric::COMMAND_FAILED => new MetricValue($failures, 0.0, 0.0),
        ]);
    }
}
