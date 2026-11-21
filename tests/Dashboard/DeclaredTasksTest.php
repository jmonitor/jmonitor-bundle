<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\DeclaredTask;
use Jmonitor\JmonitorBundle\Dashboard\DeclaredTasks;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class DeclaredTasksTest extends TestCase
{
    private const NOW = 1800000000;

    public function testTheTasksOfEverySchedule(): void
    {
        $work = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . (self::NOW - 600));
        $ping = RecurringMessage::every('10 seconds', new Ping(), '@' . (self::NOW - 15));

        $tasks = (new DeclaredTasks(FixedSchedules::locator([
            'default' => static fn(): Schedule => (new Schedule())->add($work),
            'reports' => static fn(): Schedule => (new Schedule())->add($ping),
        ])))->all(self::NOW);

        static::assertEquals([
            new DeclaredTask('default', $work->getId(), 'app:work', 'every 1 hour', self::NOW + 3000),
            new DeclaredTask('reports', $ping->getId(), Ping::class, 'every 10 seconds', self::NOW + 5),
        ], $tasks);
    }

    public function testAnEnvelopedMessageIsLabelledByTheMessageItCarries(): void
    {
        $enveloped = RecurringMessage::every('1 hour', new Envelope(new RunCommandMessage('app:work --enveloped')), '@' . self::NOW);

        $tasks = (new DeclaredTasks(FixedSchedules::locator(['default' => static fn(): Schedule => (new Schedule())->add($enveloped)])))->all(self::NOW);

        static::assertSame('app:work --enveloped', $tasks[0]->label);
    }

    public function testATaskWithoutANextRun(): void
    {
        $ended = RecurringMessage::every('1 hour', new Ping(), '@' . (self::NOW - 7200), '@' . (self::NOW - 3600));

        $tasks = (new DeclaredTasks(FixedSchedules::locator(['default' => static fn(): Schedule => (new Schedule())->add($ended)])))->all(self::NOW);

        static::assertCount(1, $tasks);
        static::assertNull($tasks[0]->nextRun);
    }

    public function testAProviderWrittenByTheApplicationIsDescribedWithoutAskingForItsMessages(): void
    {
        $described = new class implements MessageProviderInterface, \Stringable {
            public int $messageCalls = 0;

            public function getMessages(MessageContext $context): iterable
            {
                ++$this->messageCalls;

                return [];
            }

            public function getId(): string
            {
                return 'nightly';
            }

            public function __toString(): string
            {
                return 'Nightly reports';
            }
        };
        $plain = new class implements MessageProviderInterface {
            public int $messageCalls = 0;

            public function getMessages(MessageContext $context): iterable
            {
                ++$this->messageCalls;

                return [];
            }

            public function getId(): string
            {
                return 'cleanup';
            }
        };
        $schedule = (new Schedule())->add(
            RecurringMessage::every('1 day', $described, '@' . self::NOW),
            RecurringMessage::every('1 hour', $plain, '@' . self::NOW),
        );

        $tasks = (new DeclaredTasks(FixedSchedules::locator(['default' => static fn(): Schedule => $schedule])))->all(self::NOW);

        static::assertSame(['Nightly reports', 'cleanup'], array_map(static fn(DeclaredTask $task): string => $task->label, $tasks));
        static::assertSame(0, $described->messageCalls);
        static::assertSame(0, $plain->messageCalls);
    }

    public function testAFailingScheduleIsSkippedAndLoggedOnce(): void
    {
        $logger = self::logger();
        $ping = RecurringMessage::every('1 hour', new Ping(), '@' . self::NOW);

        $tasks = (new DeclaredTasks(FixedSchedules::locator([
            'broken' => static fn(): Schedule => throw new \RuntimeException('No schedule today'),
            'default' => static fn(): Schedule => (new Schedule())->add($ping),
        ]), $logger))->all(self::NOW);

        static::assertSame(['default'], array_map(static fn(DeclaredTask $task): string => $task->schedule, $tasks));
        static::assertSame(['error JMonitor could not read the "broken" schedule: No schedule today'], $logger->messages);
    }

    public function testAScheduleWhoseTriggerFailsIsSkippedWhole(): void
    {
        $logger = self::logger();
        $failing = new class implements TriggerInterface {
            public function __toString(): string
            {
                return 'never';
            }

            public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
            {
                throw new \LogicException('Broken trigger');
            }
        };
        $broken = (new Schedule())->add(
            RecurringMessage::every('1 hour', new Ping(), '@' . self::NOW),
            RecurringMessage::trigger($failing, new Ping('other')),
        );
        $ping = RecurringMessage::every('1 hour', new RunCommandMessage('app:work'), '@' . self::NOW);

        $tasks = (new DeclaredTasks(FixedSchedules::locator([
            'broken' => static fn(): Schedule => $broken,
            'default' => static fn(): Schedule => (new Schedule())->add($ping),
        ]), $logger))->all(self::NOW);

        static::assertSame(['app:work'], array_map(static fn(DeclaredTask $task): string => $task->label, $tasks));
        static::assertSame(['error JMonitor could not read the "broken" schedule: Broken trigger'], $logger->messages);
    }

    public function testWithoutSchedulesThereIsNoTask(): void
    {
        static::assertSame([], (new DeclaredTasks())->all(self::NOW));
        static::assertSame([], (new DeclaredTasks(FixedSchedules::locator([])))->all(self::NOW));
    }

    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . $message;
            }
        };
    }
}
