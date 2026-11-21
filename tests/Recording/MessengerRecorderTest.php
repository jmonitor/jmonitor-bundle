<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\Message\Ping;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class MessengerRecorderTest extends TestCase
{
    private const RECEIVER = 'async';

    private string $dir;
    private BufferInterface $buffer;
    private StorageInterface $storage;
    private AbstractLogger $logger;
    private Recorder $recorder;
    private MockClock $clock;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-messenger-recorder-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];
            public ?\Throwable $failure = null;

            public function append(Entry $entry): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $this->entries[] = $entry;
            }

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                $entries = $this->entries;
                $this->entries = [];
                $consumer($entries);

                return \count($entries);
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $this->storage = new class implements StorageInterface {
            public int $prepares = 0;
            public int $stores = 0;
            public int $purges = 0;

            /** @var list<Aggregate> */
            public array $aggregates = [];

            public function prepare(): bool
            {
                ++$this->prepares;

                return false;
            }

            public function store(array $aggregates, int $now, array $labels = []): void
            {
                ++$this->stores;
                array_push($this->aggregates, ...$aggregates);
            }

            public function purge(int $now): void
            {
                ++$this->purges;
            }
        };
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $level . ' ' . $message;
            }
        };
        $this->recorder = new Recorder($this->buffer, $this->logger, null, new QueryDigester(new LabelStore(new BufferDirectory($this->dir, 'app'))));
        $this->clock = new MockClock('@1000');
        $this->worker = new Worker([], new MessageBus());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAHandledMessageWritesOneUnitUnderItsClass(): void
    {
        $this->handle($this->messages(), new Envelope(new Ping()), function (): void {
            $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);
        });

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_MESSAGE, $entry->type);
        static::assertSame(Ping::class, $entry->key);
        static::assertIsInt($entry->attributes['memory']);
        static::assertGreaterThan(0, $entry->attributes['memory']);
        static::assertSame(1, $entry->attributes['sql_n']);
        static::assertArrayNotHasKey('failed', $entry->attributes);
        static::assertArrayNotHasKey('retried', $entry->attributes);
        static::assertGreaterThanOrEqual(0.0, $entry->durationMs);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testAFailureThatWillBeRetriedCountsARetryAndTheHandlerException(): void
    {
        $envelope = new Envelope(new Ping('fail'));

        $this->failToHandle($this->messages(), $envelope, new HandlerFailedException($envelope, [new \DomainException('boom')]), retry: true);

        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_MESSAGE, $entry->type);
        static::assertSame(1, $entry->attributes['retried']);
        static::assertArrayNotHasKey('failed', $entry->attributes);
        static::assertIsInt($entry->attributes['memory']);
        static::assertCount(1, $entry->children);
        static::assertSame(Entry::TYPE_EXCEPTION, $entry->children[0]->type);
        static::assertSame('DomainException', $entry->children[0]->key);
    }

    public function testAFailureWithoutRetryCountsAFailure(): void
    {
        $envelope = new Envelope(new Ping('fail'));

        $this->failToHandle($this->messages(), $envelope, new HandlerFailedException($envelope, [new \DomainException('boom')]), retry: false);

        $entry = $this->buffer->entries[0];
        static::assertSame(1, $entry->attributes['failed']);
        static::assertArrayNotHasKey('retried', $entry->attributes);
        static::assertSame('DomainException', $entry->children[0]->key);
    }

    public function testTheFirstExceptionOfTheHandlersIsTheOneCounted(): void
    {
        $envelope = new Envelope(new Ping('fail'));

        $this->failToHandle($this->messages(), $envelope, new HandlerFailedException($envelope, [new \LogicException('first'), new \DomainException('second')]), retry: false);

        static::assertSame(['LogicException'], array_map(static fn(Entry $child): string => $child->key, $this->buffer->entries[0]->children));
    }

    public function testAFailureOutsideTheHandlersCountsItsOwnClass(): void
    {
        $this->failToHandle($this->messages(), new Envelope(new Ping()), new \RuntimeException('middleware'), retry: false);

        static::assertSame('RuntimeException', $this->buffer->entries[0]->children[0]->key);
    }

    public function testWithExceptionsOffAFailureCountsNoException(): void
    {
        $envelope = new Envelope(new Ping('fail'));

        $this->failToHandle($this->messages(exceptions: false), $envelope, new HandlerFailedException($envelope, [new \DomainException('boom')]), retry: false);

        static::assertSame(1, $this->buffer->entries[0]->attributes['failed']);
        static::assertSame([], $this->buffer->entries[0]->children);
    }

    public function testAScheduledMessageIsATaskKeyedByScheduleIdLabelAndTrigger(): void
    {
        $this->handle($this->messages(), $this->scheduled(new RunCommandMessage('app:work --force')));

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_TASK, $entry->type);
        static::assertSame("default\x1eab12\x1eapp:work --force\x1eevery 10 seconds", $entry->key);
        static::assertArrayNotHasKey('failed', $entry->attributes);
        static::assertArrayNotHasKey('memory', $entry->attributes);
    }

    public function testAFailedTaskCountsAFailureEvenWhenRetried(): void
    {
        $envelope = $this->scheduled(new Ping('fail'));

        $this->failToHandle($this->messages(), $envelope, new HandlerFailedException($envelope, [new \DomainException('boom')]), retry: true);

        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_TASK, $entry->type);
        static::assertSame(1, $entry->attributes['failed']);
        static::assertArrayNotHasKey('retried', $entry->attributes);
        static::assertSame('DomainException', $entry->children[0]->key);
    }

    public function testARefusedMessageOpensNothing(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping());

        $this->receive($messages, $envelope, handle: false);

        static::assertSame(0, $this->recorder->openUnits());

        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));

        static::assertSame([], $this->buffer->entries);
    }

    public function testUnacknowledgedMessagesNeverPileUp(): void
    {
        $messages = $this->messages();

        for ($i = 0; $i < 100; ++$i) {
            $this->receive($messages, new Envelope(new Ping()));
        }

        static::assertSame(1, $this->recorder->openUnits());
        static::assertSame([], $this->buffer->entries);
        static::assertSame([], $this->logger->records);
    }

    public function testAnAcknowledgementForAnotherMessageAbandonsTheOpenUnit(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping());
        $this->receive($messages, $envelope);
        $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);

        $messages->onHandled(new WorkerMessageHandledEvent(new Envelope(new Ping()), self::RECEIVER));

        static::assertSame(0, $this->recorder->openUnits());

        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));

        static::assertSame([], $this->buffer->entries);
    }

    public function testAFailureOfAnotherMessageAbandonsTheOpenUnit(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping());
        $this->receive($messages, $envelope);

        $event = new WorkerMessageFailedEvent(new Envelope(new Ping()), self::RECEIVER, new \DomainException('boom'));
        $messages->stopOnFailure($event);
        $messages->closeOnFailure($event);

        static::assertSame(0, $this->recorder->openUnits());

        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));

        static::assertSame([], $this->buffer->entries);
    }

    public function testAUnitStillOpenWhenTheWorkerRunsAgainIsAbandonedBeforeTheFlush(): void
    {
        $messages = $this->messages(flusher: $this->flusher());
        $envelope = new Envelope(new Ping());
        $this->receive($messages, $envelope);
        $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);

        $messages->onRunning(new WorkerRunningEvent($this->worker, false));

        static::assertSame(0, $this->recorder->openUnits());
        static::assertSame(1, $this->storage->stores);
        static::assertSame([], $this->storage->aggregates);

        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));

        static::assertSame([], $this->buffer->entries);
    }

    public function testWhatTheWorkerDoesAfterAFailureIsNotMeasured(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping('fail'));
        $this->receive($messages, $envelope);
        $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);
        $event = new WorkerMessageFailedEvent($envelope, self::RECEIVER, new HandlerFailedException($envelope, [new \DomainException('boom')]));

        $messages->stopOnFailure($event);
        $stoppedBy = microtime(true);
        usleep(50_000);
        $this->recorder->addSql('INSERT INTO messenger_messages (body) VALUES (?)', 3.0, SqlDialect::Sqlite);
        $this->recorder->add($this->recorder->current(), Entry::TYPE_HTTP, 'broker.example', 4.0);
        $event->setForRetry();
        $messages->closeOnFailure($event);

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(1, $entry->attributes['retried']);
        static::assertSame(1, $entry->attributes['sql_n']);
        static::assertSame(2.0, $entry->attributes['sql_ms']);
        static::assertArrayNotHasKey('http_ms', $entry->attributes);
        static::assertEqualsCanonicalizing([Entry::TYPE_QUERY, Entry::TYPE_EXCEPTION], array_map(static fn(Entry $child): string => $child->type, $entry->children));
        static::assertLessThanOrEqual($stoppedBy + 1e-6, $entry->startedAt + $entry->durationMs / 1000);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testAFailureWhoseClosingListenerIsNeverReachedLeavesNothingOpen(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping('fail'));
        $this->receive($messages, $envelope);

        $messages->stopOnFailure(new WorkerMessageFailedEvent($envelope, self::RECEIVER, new \DomainException('boom')));
        $messages->onRunning(new WorkerRunningEvent($this->worker, false));

        static::assertSame([], $this->buffer->entries);
        static::assertSame(0, $this->recorder->openUnits());

        $this->handle($messages, new Envelope(new Ping()));

        static::assertCount(1, $this->buffer->entries);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testASentMessageIsCountedOnTheUnitThatSendsIt(): void
    {
        $messages = $this->messages();

        $this->handle($messages, new Envelope(new Ping('send')), static function () use ($messages): void {
            $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new \stdClass()), []));
            $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new \stdClass()), []));
        });

        $children = $this->buffer->entries[0]->children;
        static::assertCount(1, $children);
        static::assertSame(Entry::TYPE_SENT, $children[0]->type);
        static::assertSame('stdClass', $children[0]->key);
        static::assertSame(2, $children[0]->attributes['n']);
    }

    public function testASentMessageIsCountedOnAnyUnitInProgress(): void
    {
        $messages = $this->messages();
        $request = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));

        $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new Ping()), []));
        $this->recorder->end($request, 'GET app_dispatch', 1.0);

        static::assertSame(Entry::TYPE_SENT, $this->buffer->entries[0]->children[0]->type);
        static::assertSame(Ping::class, $this->buffer->entries[0]->children[0]->key);
    }

    public function testASentMessageOutsideAnyUnitIsLost(): void
    {
        $messages = $this->messages();

        $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new Ping()), []));
        $this->handle($messages, new Envelope(new Ping()));

        static::assertCount(1, $this->buffer->entries);
        static::assertSame([], $this->buffer->entries[0]->children);
        static::assertSame([], $this->logger->records);
    }

    public function testWithMessagesOffOnlyTasksAreRecorded(): void
    {
        $messages = $this->messages(messages: false);

        $this->handle($messages, new Envelope(new Ping()));

        static::assertSame([], $this->buffer->entries);
        static::assertSame(0, $this->recorder->openUnits());

        $this->handle($messages, $this->scheduled(new RunCommandMessage('app:work')), static function () use ($messages): void {
            $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new Ping()), []));
        });

        static::assertCount(1, $this->buffer->entries);
        static::assertSame(Entry::TYPE_TASK, $this->buffer->entries[0]->type);
        static::assertSame([], $this->buffer->entries[0]->children);
    }

    public function testWithTasksOffAScheduledMessageIsNotRecordedAtAll(): void
    {
        $messages = $this->messages(tasks: false);

        $this->handle($messages, $this->scheduled(new Ping()));

        static::assertSame([], $this->buffer->entries);
        static::assertSame(0, $this->recorder->openUnits());

        $this->handle($messages, new Envelope(new Ping()));

        static::assertCount(1, $this->buffer->entries);
        static::assertSame(Entry::TYPE_MESSAGE, $this->buffer->entries[0]->type);
    }

    public function testTheWorkerFlushesWhenDueBetweenMessagesAndOnceMoreWhenItStops(): void
    {
        $messages = $this->messages(flusher: $this->flusher());

        $this->handle($messages, new Envelope(new Ping()));
        $messages->onRunning(new WorkerRunningEvent($this->worker, false));

        static::assertSame(1, $this->storage->stores);

        $this->handle($messages, new Envelope(new Ping()));
        $messages->onRunning(new WorkerRunningEvent($this->worker, false));

        static::assertSame(1, $this->storage->stores);
        static::assertCount(1, $this->buffer->entries);

        $this->clock->sleep(10);
        $messages->onRunning(new WorkerRunningEvent($this->worker, true));

        static::assertSame(2, $this->storage->stores);
        static::assertSame([], $this->buffer->entries);

        $this->handle($messages, new Envelope(new Ping()));
        $this->clock->sleep(Flusher::EXIT_FLOOR_SECONDS);
        $messages->onStopped(new WorkerStoppedEvent($this->worker));

        static::assertSame(3, $this->storage->stores);
        static::assertSame([], $this->buffer->entries);
        $messageKeys = array_map(static fn(Aggregate $aggregate): string => $aggregate->key, $this->storage->aggregates);
        static::assertContains(Ping::class, $messageKeys);
    }

    public function testAStoppingWorkerAbandonsTheMessageItWasHandling(): void
    {
        $messages = $this->messages();
        $envelope = new Envelope(new Ping());
        $this->receive($messages, $envelope);

        $messages->onStopped(new WorkerStoppedEvent($this->worker));
        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));

        static::assertSame(0, $this->recorder->openUnits());
        static::assertSame([], $this->buffer->entries);
    }

    public function testAServiceResetBetweenMessagesLeavesNothingOpen(): void
    {
        $messages = $this->messages();
        $this->receive($messages, new Envelope(new Ping()));

        $this->recorder->reset();
        $messages->reset();

        static::assertSame(0, $this->recorder->openUnits());

        $this->handle($messages, new Envelope(new Ping()));

        static::assertCount(1, $this->buffer->entries);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testWithRecordingOffAtRuntimeNothingIsOpenedWrittenOrFlushed(): void
    {
        $this->recorder->end($this->recorder->begin(Entry::TYPE_REQUEST, microtime(true)), 'GET app_hello', 1.0);
        $messages = $this->messages(flusher: $this->flusher(), enabled: false);

        $this->handle($messages, new Envelope(new Ping()), function (): void {
            static::assertSame(0, $this->recorder->openUnits());
        });
        $failed = new Envelope(new Ping('fail'));
        $this->failToHandle($messages, $failed, new HandlerFailedException($failed, [new \DomainException('boom')]), retry: true);
        $this->failToHandle($messages, $failed, new HandlerFailedException($failed, [new \DomainException('boom')]), retry: false);
        $this->handle($messages, $this->scheduled(new RunCommandMessage('app:work')));
        $request = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));
        $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new Ping()), []));
        $this->recorder->end($request, 'GET app_dispatch', 1.0);
        $this->clock->sleep(10);
        $messages->onRunning(new WorkerRunningEvent($this->worker, false));
        $messages->onStopped(new WorkerStoppedEvent($this->worker));

        static::assertSame(0, $this->recorder->openUnits());
        static::assertSame(['GET app_hello', 'GET app_dispatch'], array_map(static fn(Entry $entry): string => $entry->key, $this->buffer->entries));
        static::assertSame([], $this->buffer->entries[1]->children);
        static::assertSame([0, 0, 0], [$this->storage->prepares, $this->storage->stores, $this->storage->purges]);
        static::assertSame([], $this->logger->records);
    }

    public function testAFailingRecorderNeverReachesTheWorker(): void
    {
        $this->buffer->failure = new \RuntimeException('disk on fire');
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('clock stopped');
            }
        };
        $messages = $this->messages(flusher: $this->flusher($clock));

        for ($i = 0; $i < 2; ++$i) {
            $envelope = new Envelope(new Ping('fail'));
            $this->handle($messages, new Envelope(new Ping()), static function () use ($messages): void {
                $messages->onSent(new SendMessageToTransportsEvent(new Envelope(new Ping()), []));
            });
            $this->failToHandle($messages, $envelope, new HandlerFailedException($envelope, [new \DomainException('boom')]), retry: false);
            $messages->onRunning(new WorkerRunningEvent($this->worker, false));
        }
        $messages->onStopped(new WorkerStoppedEvent($this->worker));

        static::assertSame([
            'error JMonitor could not record a unit of work: disk on fire',
            'error JMonitor could not flush its buffer: clock stopped',
            'error JMonitor could not record a message: clock stopped',
        ], $this->logger->records);
        static::assertSame(0, $this->storage->stores);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testATriggerThatCannotBeReadOpensNothing(): void
    {
        $messages = $this->messages();
        $trigger = new class implements TriggerInterface {
            public function __toString(): string
            {
                throw new \RuntimeException('no description');
            }

            public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
            {
                return null;
            }
        };
        $envelope = new Envelope(new Ping(), [new ScheduledStamp(new MessageContext('default', 'ab12', $trigger, new \DateTimeImmutable()))]);

        $this->handle($messages, $envelope);
        $this->handle($messages, new Envelope(new Ping()));

        static::assertSame([Entry::TYPE_MESSAGE], array_map(static fn(Entry $entry): string => $entry->type, $this->buffer->entries));
        static::assertSame(['error JMonitor could not record a message: no description'], $this->logger->records);
    }

    public function testItListensToTheWorkerAroundEveryOtherListener(): void
    {
        static::assertSame([
            WorkerMessageReceivedEvent::class => ['onReceived', -4096],
            WorkerMessageHandledEvent::class => ['onHandled', 4096],
            WorkerMessageFailedEvent::class => [['stopOnFailure', 4096], ['closeOnFailure', 0]],
            SendMessageToTransportsEvent::class => ['onSent', 0],
            WorkerRunningEvent::class => ['onRunning', 0],
            WorkerStoppedEvent::class => ['onStopped', 0],
        ], MessengerRecorder::getSubscribedEvents());
    }

    private function messages(?Flusher $flusher = null, bool $messages = true, bool $tasks = true, bool $exceptions = true, bool $enabled = true): MessengerRecorder
    {
        return new MessengerRecorder($this->recorder, $flusher, $messages, $tasks, $exceptions, $this->logger, $enabled);
    }

    private function flusher(?ClockInterface $clock = null): Flusher
    {
        $clock ??= $this->clock;

        return new Flusher(
            $this->buffer,
            new Aggregator(500),
            $this->storage,
            new FlushLock(new BufferDirectory($this->dir, 'app'), 10, $clock),
            $clock,
            $this->logger,
            recorder: $this->recorder,
        );
    }

    private function scheduled(object $message): Envelope
    {
        $trigger = new class implements TriggerInterface {
            public function __toString(): string
            {
                return 'every 10 seconds';
            }

            public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
            {
                return null;
            }
        };

        return new Envelope($message, [new ScheduledStamp(new MessageContext('default', 'ab12', $trigger, new \DateTimeImmutable()))]);
    }

    private function receive(MessengerRecorder $messages, Envelope $envelope, bool $handle = true): void
    {
        $event = new WorkerMessageReceivedEvent($envelope, self::RECEIVER);
        $event->shouldHandle($handle);
        $messages->onReceived($event);
    }

    /**
     * @param (\Closure(): void)|null $during
     */
    private function handle(MessengerRecorder $messages, Envelope $envelope, ?\Closure $during = null): void
    {
        $this->receive($messages, $envelope);
        if ($during !== null) {
            $during();
        }
        $messages->onHandled(new WorkerMessageHandledEvent($envelope, self::RECEIVER));
    }

    private function failToHandle(MessengerRecorder $messages, Envelope $envelope, \Throwable $error, bool $retry): void
    {
        $this->receive($messages, $envelope);
        $event = new WorkerMessageFailedEvent($envelope, self::RECEIVER, $error);
        $messages->stopOnFailure($event);
        if ($retry) {
            $event->setForRetry();
        }
        $messages->closeOnFailure($event);
    }
}
