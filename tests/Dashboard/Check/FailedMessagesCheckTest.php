<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\Check;

use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\FailedMessagesCheck;
use Jmonitor\JmonitorBundle\Dashboard\Check\KeyedCheckInterface;
use Jmonitor\JmonitorBundle\Dashboard\Check\Severity;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Storage\Read\Report;
use Jmonitor\JmonitorBundle\Storage\Read\ReportQuery;
use Jmonitor\JmonitorBundle\Storage\Read\ReportRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedMessagesCheckTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheCheckDeclaresItself(): void
    {
        $check = new FailedMessagesCheck();

        static::assertSame('messages.failed', $check->id());
        static::assertSame(Severity::Critical, $check->severity());
        static::assertSame('background', $check->screen());
        static::assertSame('Background', $check->screenLabel());
        static::assertSame([Metric::MESSAGE_FAILED], $check->metrics());
        static::assertNotInstanceOf(KeyedCheckInterface::class, $check);
    }

    public function testItReadsEveryMessageClass(): void
    {
        static::assertEquals(new ReportQuery(Period::SixHours, self::NOW, Family::Message, metrics: [Metric::MESSAGE_FAILED]), (new FailedMessagesCheck())->query(Period::SixHours, self::NOW));
    }

    /**
     * @return iterable<string, array{list<ReportRow>, int, string, string}>
     */
    public static function culprits(): iterable
    {
        yield 'one class, once' => [[self::row('App\Message\SendInvoice', 1)], 1, '1 message failed', 'App\Message\SendInvoice failed once.'];
        yield 'one class, several times' => [[self::row('App\Message\SendInvoice', 3)], 3, '3 messages failed', 'App\Message\SendInvoice failed 3 times.'];
        yield 'the most failures wins' => [
            [self::row('App\Message\SendInvoice', 1), self::row('App\Message\ResizeImage', 4), self::row('App\Message\Ping', 0)],
            5,
            '5 messages failed',
            'App\Message\ResizeImage failed 4 times. 1 other message class failed too.',
        ];
        yield 'several other classes' => [
            [self::row('App\Message\A', 1), self::row('App\Message\B', 2), self::row('App\Message\C', 1)],
            4,
            '4 messages failed',
            'App\Message\B failed 2 times. 2 other message classes failed too.',
        ];
        yield 'a tie goes to the smallest key' => [
            [self::row('App\Message\SendInvoice', 2), self::row('App\Message\ResizeImage', 2)],
            4,
            '4 messages failed',
            'App\Message\ResizeImage failed 2 times. 1 other message class failed too.',
        ];
        yield 'thousands' => [[self::row('App\Message\SendInvoice', 1234)], 1234, '1,234 messages failed', 'App\Message\SendInvoice failed 1,234 times.'];
    }

    /**
     * @param list<ReportRow> $rows
     */
    #[DataProvider('culprits')]
    public function testTheCulpritIsTheClassWithTheMostFailures(array $rows, int $total, string $title, string $sentence): void
    {
        $finding = (new FailedMessagesCheck())->finding(new Report($rows), [Metric::MESSAGE_FAILED => new MetricValue($total, 0.0, 0.0)], Period::OneHour);

        static::assertSame('messages.failed', $finding->id);
        static::assertSame(Severity::Critical, $finding->severity);
        static::assertSame('background', $finding->screen);
        static::assertSame($title, $finding->title);
        static::assertSame($sentence, $finding->sentence);
        static::assertSame('A failed message is work that did not happen. Nobody saw an error page: only this screen tells you.', $finding->why);
        static::assertSame('Open Exceptions to see what was thrown. Fix the cause, then run them again: bin/console messenger:failed:retry', $finding->action);
        static::assertNull($finding->code);
    }

    public function testTheOtherKeyIsNeverTheCulpritNorCounted(): void
    {
        $report = new Report([self::row('App\Message\SendInvoice', 2)], self::row(Aggregator::OTHER, 50), 2);

        $finding = (new FailedMessagesCheck())->finding($report, [Metric::MESSAGE_FAILED => new MetricValue(52, 0.0, 0.0)], Period::OneHour);

        static::assertSame('App\Message\SendInvoice failed 2 times.', $finding->sentence);
    }

    public function testWithoutCulpritTheFindingOnlyTellsItsCount(): void
    {
        foreach ([new Report(), new Report([self::row('App\Message\SendInvoice', 0)]), new Report([], self::row(Aggregator::OTHER, 3), 1)] as $report) {
            $finding = (new FailedMessagesCheck())->finding($report, [Metric::MESSAGE_FAILED => new MetricValue(3, 0.0, 0.0)], Period::SixHours);

            static::assertSame('3 messages failed', $finding->title);
            static::assertSame('Messages handled with an exception in the last 6 hours.', $finding->sentence);
        }
    }

    private static function row(string $class, int $failures): ReportRow
    {
        return new ReportRow(md5($class), $class, null, [
            Metric::MESSAGE_DURATION => new MetricValue(10, 400.0, 90.0),
            Metric::MESSAGE_FAILED => new MetricValue($failures, 0.0, 0.0),
        ]);
    }
}
