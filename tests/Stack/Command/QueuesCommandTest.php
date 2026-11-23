<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Command;

use Jmonitor\JmonitorBundle\Stack\Command\QueuesCommand;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\CountedTransport;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\QueueTransport;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Scheduler\Generator\MessageGeneratorInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

final class QueuesCommandTest extends TestCase
{
    public function testTheNamesComeFirstThenOneLinePerTransport(): void
    {
        $journal = [];
        $tester = null;
        $receivers = self::receivers([
            'async' => static fn(): CountedTransport => new CountedTransport(12),
            'failed' => static fn(): CountedTransport => new CountedTransport(0),
        ], static function (string $id) use (&$journal, &$tester): void {
            $journal[] = $tester?->getDisplay();
        });
        $tester = new CommandTester(new QueuesCommand($receivers, ['async', 'failed']));

        self::assertSame(0, $tester->execute([]));
        self::assertSame(
            ['{"transports":["async","failed"]}', '{"name":"async","count":12}', '{"name":"failed","count":0}'],
            self::lines($tester),
        );
        self::assertSame(
            ["{\"transports\":[\"async\",\"failed\"]}\n", "{\"transports\":[\"async\",\"failed\"]}\n{\"name\":\"async\",\"count\":12}\n"],
            $journal,
            'each line is out before the next transport is built',
        );
    }

    public function testATransportThatCannotCountSaysNull(): void
    {
        $tester = self::execute(['batch' => static fn(): QueueTransport => new QueueTransport()]);

        self::assertSame(['{"transports":["batch"]}', '{"name":"batch","count":null}'], self::lines($tester));
    }

    public function testATransportThatThrowsGivesItsReasonWithoutItsPassword(): void
    {
        $tester = self::execute(['orders' => static fn(): CountedTransport => new CountedTransport(0, 'Cannot reach amqp://guest:s3cret@broker')]);

        self::assertSame(['{"transports":["orders"]}', '{"name":"orders","error":"Cannot reach amqp://broker"}'], self::lines($tester));
        self::assertStringNotContainsString('s3cret', $tester->getDisplay());
        self::assertStringNotContainsString('guest', $tester->getDisplay());
    }

    public function testAFailureWithoutMessageGivesTheNameOfItsClass(): void
    {
        $tester = self::execute([
            'orders' => static fn(): CountedTransport => new CountedTransport(0, ''),
            'reports' => static fn(): never => throw new \RuntimeException(),
        ]);

        self::assertSame([
            '{"transports":["orders","reports"]}',
            '{"name":"orders","error":"TransportException"}',
            '{"name":"reports","error":"RuntimeException"}',
        ], self::lines($tester));
    }

    public function testSchedulerAndSyncTransportsAreMarkedToBeSkipped(): void
    {
        $tester = self::execute([
            'scheduler_default' => fn(): SchedulerTransport => new SchedulerTransport($this->createStub(MessageGeneratorInterface::class)),
            'now' => static fn(): SyncTransport => new SyncTransport(new MessageBus()),
            'async' => static fn(): CountedTransport => new CountedTransport(3),
        ]);

        self::assertSame([
            '{"transports":["scheduler_default","now","async"]}',
            '{"name":"scheduler_default","skip":true}',
            '{"name":"now","skip":true}',
            '{"name":"async","count":3}',
        ], self::lines($tester));
    }

    public function testATransportThatCannotBeBuiltIsListedWithItsReason(): void
    {
        $tester = self::execute([
            'reports' => static fn(): never => throw new \RuntimeException('Connection refused'),
            'async' => static fn(): CountedTransport => new CountedTransport(1),
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([
            '{"transports":["reports","async"]}',
            '{"name":"reports","error":"Connection refused"}',
            '{"name":"async","count":1}',
        ], self::lines($tester));
    }

    public function testNoMoreThanFiftyTransportsAreCounted(): void
    {
        $built = [];
        $transports = [];

        foreach (range(1, 60) as $i) {
            $transports['t' . $i] = static fn(): CountedTransport => new CountedTransport($i);
        }

        $tester = new CommandTester(new QueuesCommand(self::receivers($transports, static function (string $id) use (&$built): void {
            $built[] = $id;
        }), array_keys($transports)));
        $tester->execute([]);

        $lines = self::lines($tester);
        $names = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR)['transports'];

        self::assertSame(array_map(static fn(int $i): string => 't' . $i, range(1, 50)), $names);
        self::assertCount(51, $lines);
        self::assertSame('{"name":"t50","count":50}', $lines[50]);
        self::assertSame($names, $built);
    }

    /**
     * @param array<string, \Closure(): object> $transports
     */
    private static function execute(array $transports): CommandTester
    {
        $tester = new CommandTester(new QueuesCommand(self::receivers($transports), array_keys($transports)));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param array<string, \Closure(): object> $transports
     * @param ?\Closure(string): void           $onGet
     */
    private static function receivers(array $transports, ?\Closure $onGet = null): ContainerInterface
    {
        return new class ($transports, $onGet) implements ContainerInterface {
            /**
             * @param array<string, \Closure(): object> $transports
             * @param ?\Closure(string): void           $onGet
             */
            public function __construct(
                private readonly array $transports,
                private readonly ?\Closure $onGet,
            ) {}

            public function get(string $id): object
            {
                if ($this->onGet !== null) {
                    ($this->onGet)($id);
                }

                return ($this->transports[$id])();
            }

            public function has(string $id): bool
            {
                return isset($this->transports[$id]);
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function lines(CommandTester $tester): array
    {
        return explode("\n", trim($tester->getDisplay()));
    }
}
