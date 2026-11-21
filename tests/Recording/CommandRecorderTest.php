<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Flush\FlushLock;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\CommandRecorder;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

final class CommandRecorderTest extends TestCase
{
    private string $dir;
    private BufferInterface $buffer;
    private StorageInterface $storage;
    private AbstractLogger $logger;
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-command-recorder-' . bin2hex(random_bytes(6));
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
            public ?\Throwable $failure = null;

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
                if ($this->failure !== null) {
                    throw $this->failure;
                }
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
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testACommandWritesOneUnitWithItsExitCodeAndPeakMemory(): void
    {
        $this->runCommand($this->commands(), 'app:work');

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_COMMAND, $entry->type);
        static::assertSame('app:work', $entry->key);
        static::assertSame(0, $entry->attributes['exit']);
        static::assertIsInt($entry->attributes['memory']);
        static::assertGreaterThan(0, $entry->attributes['memory']);
        static::assertGreaterThanOrEqual(0.0, $entry->durationMs);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testAnExitCodeOtherThanZeroIsKept(): void
    {
        $this->runCommand($this->commands(), 'app:work', 3);

        static::assertSame(3, $this->buffer->entries[0]->attributes['exit']);
    }

    public function testACommandWithoutNameIsRecordedAsUnknown(): void
    {
        $this->runCommand($this->commands(), null);

        static::assertSame('(unknown)', $this->buffer->entries[0]->key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultIgnoredCommands(): iterable
    {
        foreach (['messenger:consume', 'messenger:failed:retry', 'messenger:stats', 'jmonitor:collect', 'cache:clear', 'assets:install', 'importmap:install', 'asset-map:compile', 'secrets:decrypt-to-local', 'tailwind:build', 'sass:build', 'list', 'help', '_complete', 'completion', 'debug:router', 'lint:twig', 'server:dump', 'doctrine:database:create'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('defaultIgnoredCommands')]
    public function testDefaultIgnoredCommandsRecordNothing(string $name): void
    {
        $this->runCommand($this->commands(), $name, during: function (): void {
            $this->recorder->add($this->recorder->current(), Entry::TYPE_HTTP, 'collector.example', 12.0);
        });

        static::assertSame([], $this->buffer->entries);
        static::assertFalse($this->recorder->hasWritten());
        static::assertSame(0, $this->recorder->openUnits());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandsCloseToIgnoredNames(): iterable
    {
        foreach (['app:cache:warm', 'doctrine:migrations:migrate', 'messenger:setup-transports'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('commandsCloseToIgnoredNames')]
    public function testCommandsCloseToIgnoredNamesAreRecorded(string $name): void
    {
        $this->runCommand($this->commands(), $name);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame($name, $this->buffer->entries[0]->key);
    }

    public function testConfiguredPatternsAreIgnored(): void
    {
        $commands = $this->commands(ignore: ['app:internal:*']);

        $this->runCommand($commands, 'app:internal:sync');
        $this->runCommand($commands, 'app:work');
        $this->runCommand($commands, 'cache:clear');

        static::assertSame(['app:work'], array_map(static fn(Entry $entry): string => $entry->key, $this->buffer->entries));
    }

    public function testANestedCommandWritesItsOwnUnitAndCarriesItsSqlToTheOuterOne(): void
    {
        $commands = $this->commands();

        $this->runCommand($commands, 'app:outer', during: function () use ($commands): void {
            $this->runCommand($commands, 'app:inner', 3, function (): void {
                $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);
            });
        });

        [$inner, $outer] = $this->buffer->entries;
        static::assertSame('app:inner', $inner->key);
        static::assertSame(3, $inner->attributes['exit']);
        static::assertSame(1, $inner->attributes['sql_n']);
        static::assertCount(1, $inner->children);
        static::assertSame('app:outer', $outer->key);
        static::assertSame(0, $outer->attributes['exit']);
        static::assertSame(1, $outer->attributes['sql_n']);
        static::assertSame(2.0, $outer->attributes['sql_ms']);
        static::assertSame([], $outer->children);
    }

    public function testACommandInsideAnIgnoredCommandIsRecorded(): void
    {
        $commands = $this->commands();

        $this->runCommand($commands, 'cache:clear', during: function () use ($commands): void {
            $this->runCommand($commands, 'app:work', during: function (): void {
                $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);
            });
        });

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('app:work', $this->buffer->entries[0]->key);
        static::assertSame(1, $this->buffer->entries[0]->attributes['sql_n']);
        static::assertSame(0, $this->recorder->openUnits());
    }

    public function testAnErrorIsCountedUnderItsClass(): void
    {
        $this->runCommand($this->commands(), 'app:work', 1, error: new \RuntimeException('boom'));
        $this->runCommand($this->commands(exceptions: false), 'app:work', 1, error: new \RuntimeException('boom'));

        [$counted, $uncounted] = $this->buffer->entries;
        static::assertSame(1, $counted->attributes['exit']);
        static::assertCount(1, $counted->children);
        static::assertSame(Entry::TYPE_EXCEPTION, $counted->children[0]->type);
        static::assertSame('RuntimeException', $counted->children[0]->key);
        static::assertSame(1, $uncounted->attributes['exit']);
        static::assertSame([], $uncounted->children);
    }

    public function testAnErrorOfAnAnonymousClassIsCountedUnderOneName(): void
    {
        $this->runCommand($this->commands(), 'app:work', 1, error: new class ('boom') extends \RuntimeException {});

        static::assertSame('class@anonymous', $this->buffer->entries[0]->children[0]->key);
    }

    public function testAnErrorThatGoesThroughTheOuterCommandIsCountedOnce(): void
    {
        $commands = $this->commands();
        $error = new \RuntimeException('boom');

        $this->runCommand($commands, 'app:outer', 1, function () use ($commands, $error): void {
            $this->runCommand($commands, 'app:inner', 1, error: $error);
        }, $error);

        [$inner, $outer] = $this->buffer->entries;
        static::assertSame('RuntimeException', $inner->children[0]->key);
        static::assertSame([], $outer->children);
    }

    public function testTheClosingFlushRunsWhenTheOutermostCommandEnds(): void
    {
        $commands = $this->commands(flusher: $this->flusher());

        $this->runCommand($commands, 'app:outer', during: function () use ($commands): void {
            $this->runCommand($commands, 'app:inner');

            static::assertSame(0, $this->storage->stores);
        });

        static::assertSame(1, $this->storage->stores);
        static::assertSame([], $this->buffer->entries);
        $commandKeys = array_map(
            static fn(Aggregate $aggregate): string => $aggregate->key,
            array_values(array_filter($this->storage->aggregates, static fn(Aggregate $aggregate): bool => $aggregate->type === Metric::COMMAND_DURATION)),
        );
        static::assertContains('app:outer', $commandKeys);
        static::assertContains('app:inner', $commandKeys);
    }

    public function testNoClosingFlushWhileAUnitIsStillOpen(): void
    {
        $commands = $this->commands(flusher: $this->flusher());
        $request = $this->recorder->begin(Entry::TYPE_REQUEST, microtime(true));

        $this->runCommand($commands, 'app:work');

        static::assertSame(0, $this->storage->stores);
        static::assertCount(1, $this->buffer->entries);
        static::assertSame(1, $this->recorder->openUnits());
        $this->recorder->discard($request);
    }

    public function testAnIgnoredCommandThatRecordedNothingNeverTouchesTheStorage(): void
    {
        $this->runCommand($this->commands(flusher: $this->flusher()), 'cache:clear');

        static::assertSame([0, 0, 0], [$this->storage->prepares, $this->storage->stores, $this->storage->purges]);
    }

    public function testAnIgnoredCommandStillFlushesWhatItsInnerCommandsRecorded(): void
    {
        $commands = $this->commands(flusher: $this->flusher());

        $this->runCommand($commands, 'cache:clear', during: function () use ($commands): void {
            $this->runCommand($commands, 'app:work');
        });

        static::assertSame(1, $this->storage->stores);
        static::assertSame([], $this->buffer->entries);
    }

    public function testAStorageThatRefusesLeavesTheExitCodeAndLogsOnce(): void
    {
        $this->storage->failure = new \RuntimeException('database down');

        $event = $this->runCommand($this->commands(flusher: $this->flusher()), 'app:work', 3);

        static::assertSame(3, $event->getExitCode());
        static::assertSame(['error JMonitor could not flush its buffer: database down'], $this->logger->records);
    }

    public function testATerminateWithoutCommandIsIgnored(): void
    {
        $commands = $this->commands(flusher: $this->flusher());
        $outer = new Command('app:outer');
        $commands->onCommand(new ConsoleCommandEvent($outer, new ArrayInput([]), new NullOutput()));

        $commands->onTerminate(new ConsoleTerminateEvent(new Command('app:stranger'), new ArrayInput([]), new NullOutput(), 1));

        static::assertSame([], $this->buffer->entries);
        static::assertSame(1, $this->recorder->openUnits());
        static::assertSame(0, $this->storage->stores);

        $commands->onTerminate(new ConsoleTerminateEvent($outer, new ArrayInput([]), new NullOutput(), 0));

        static::assertSame(1, $this->storage->stores);
        static::assertSame([], $this->logger->records);
    }

    public function testTheClosingFlushStillRunsAfterTheServicesWereReset(): void
    {
        $commands = $this->commands(flusher: $this->flusher());
        $worker = new Command('messenger:consume');
        $commands->onCommand(new ConsoleCommandEvent($worker, new ArrayInput([]), new NullOutput()));
        $this->runCommand($commands, 'app:work');

        static::assertSame(0, $this->storage->stores);

        $commands->reset();
        $this->recorder->reset();
        $commands->onTerminate(new ConsoleTerminateEvent($worker, new ArrayInput([]), new NullOutput(), 0));

        static::assertSame(1, $this->storage->stores);
        static::assertSame([], $this->buffer->entries);
    }

    public function testATerminateSeenTwiceWritesOnce(): void
    {
        $commands = $this->commands();
        $command = new Command('app:work');
        $commands->onCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));

        for ($i = 0; $i < 2; ++$i) {
            $commands->onTerminate(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 130));
        }

        static::assertCount(1, $this->buffer->entries);
    }

    public function testResetForgetsOpenCommands(): void
    {
        $commands = $this->commands(flusher: $this->flusher());
        $command = new Command('app:work');
        $commands->onCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));

        $commands->reset();
        $this->recorder->reset();
        $commands->onTerminate(new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), 0));

        static::assertSame([], $this->buffer->entries);
        static::assertSame(0, $this->storage->stores);

        $this->runCommand($commands, 'app:work');

        static::assertSame(1, $this->storage->stores);
    }

    public function testTheStackOfCommandsNeverGrowsPastTheDepthOfTheRecorder(): void
    {
        $commands = $this->commands();

        for ($i = 0; $i < Recorder::MAX_DEPTH * 3; ++$i) {
            $commands->onCommand(new ConsoleCommandEvent(new Command('app:loop'), new ArrayInput([]), new NullOutput()));
        }

        static::assertLessThanOrEqual(Recorder::MAX_DEPTH, $this->recorder->openUnits());

        $this->runCommand($commands, 'app:work');

        static::assertSame(['app:work'], array_map(static fn(Entry $entry): string => $entry->key, $this->buffer->entries));
    }

    public function testWithRecordingOffAtRuntimeNothingIsOpenedWrittenOrFlushed(): void
    {
        $this->recorder->end($this->recorder->begin(Entry::TYPE_REQUEST, microtime(true)), 'GET app_hello', 1.0);
        $commands = $this->commands(flusher: $this->flusher(), enabled: false);

        $event = $this->runCommand($commands, 'app:work', 1, function () use ($commands): void {
            static::assertSame(0, $this->recorder->openUnits());
            $this->runCommand($commands, 'app:inner', during: function (): void {
                $this->recorder->addSql('SELECT 1', 2.0, SqlDialect::Sqlite);
            });
        }, new \RuntimeException('boom'));

        static::assertSame(1, $event->getExitCode());
        static::assertSame(0, $this->recorder->openUnits());
        static::assertSame(['GET app_hello'], array_map(static fn(Entry $entry): string => $entry->key, $this->buffer->entries));
        static::assertSame([0, 0, 0], [$this->storage->prepares, $this->storage->stores, $this->storage->purges]);
        static::assertSame([], $this->logger->records);
    }

    public function testAFailingRecorderNeverReachesTheCommand(): void
    {
        $this->buffer->failure = new \RuntimeException('disk on fire');
        $commands = $this->commands(flusher: $this->flusher());

        $event = $this->runCommand($commands, 'app:work', 2, error: new \LogicException('boom'));
        $this->runCommand($commands, 'app:work');

        static::assertSame(2, $event->getExitCode());
        static::assertSame(['error JMonitor could not record a unit of work: disk on fire'], $this->logger->records);
        static::assertSame([0, 0, 0], [$this->storage->prepares, $this->storage->stores, $this->storage->purges]);
    }

    public function testItListensToTheConsoleAroundEveryOtherListener(): void
    {
        static::assertSame([
            ConsoleEvents::COMMAND => ['onCommand', 4096],
            ConsoleEvents::ERROR => ['onError', 0],
            ConsoleEvents::TERMINATE => ['onTerminate', -4096],
        ], CommandRecorder::getSubscribedEvents());
    }

    /**
     * @param list<string> $ignore
     */
    private function commands(?Flusher $flusher = null, array $ignore = [], bool $exceptions = true, bool $enabled = true): CommandRecorder
    {
        return new CommandRecorder($this->recorder, $flusher, $ignore, $exceptions, $this->logger, $enabled);
    }

    private function flusher(): Flusher
    {
        $clock = new MockClock('@1000');

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

    /**
     * @param (\Closure(): void)|null $during
     */
    private function runCommand(CommandRecorder $commands, ?string $name, int $exit = 0, ?\Closure $during = null, ?\Throwable $error = null): ConsoleTerminateEvent
    {
        $command = new Command($name);
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $commands->onCommand(new ConsoleCommandEvent($command, $input, $output));
        if ($during !== null) {
            $during();
        }
        if ($error !== null) {
            $commands->onError(new ConsoleErrorEvent($input, $output, $error, $command));
        }
        $event = new ConsoleTerminateEvent($command, $input, $output, $exit);
        $commands->onTerminate($event);

        return $event;
    }
}
