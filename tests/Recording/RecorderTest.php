<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RecorderTest extends TestCase
{
    private BufferInterface $buffer;
    private AbstractLogger $logger;

    protected function setUp(): void
    {
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

            public function drain(callable $consumer): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
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
    }

    public function testAUnitIsWrittenOnceWhenItEnds(): void
    {
        $recorder = $this->recorder();

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.5);

        static::assertTrue($recorder->isRecording());
        static::assertSame([], $this->buffer->entries);

        $recorder->end($token, 'GET app_home', 12.5, ['status' => 200, 'memory' => 2048]);
        $recorder->end($token, 'GET app_home', 12.5, ['status' => 200, 'memory' => 2048]);

        static::assertCount(1, $this->buffer->entries);
        $entry = $this->buffer->entries[0];
        static::assertSame(Entry::TYPE_REQUEST, $entry->type);
        static::assertSame('GET app_home', $entry->key);
        static::assertSame(1000.5, $entry->startedAt);
        static::assertSame(12.5, $entry->durationMs);
        static::assertSame(['status' => 200, 'memory' => 2048], $entry->attributes);
        static::assertSame([], $entry->children);
        static::assertFalse($recorder->isRecording());
    }

    public function testADiscardedUnitWritesNothing(): void
    {
        $recorder = $this->recorder();

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->discard($token);
        $recorder->end($token, 'GET app_home', 1.0);

        static::assertSame([], $this->buffer->entries);
        static::assertFalse($recorder->isRecording());
    }

    public function testEndingAnUnknownUnitDoesNothing(): void
    {
        $recorder = $this->recorder();
        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);

        $recorder->end($token + 1, 'GET app_home', 1.0);
        $recorder->discard($token + 1);

        static::assertSame([], $this->buffer->entries);
        static::assertTrue($recorder->isRecording());

        $recorder->end($token, 'GET app_home', 1.0);

        static::assertCount(1, $this->buffer->entries);
    }

    public function testEndingAUnitClosesTheOnesOpenedAfterIt(): void
    {
        $recorder = $this->recorder();

        $a = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $b = $recorder->begin('command', 1001.0);
        $recorder->end($a, 'GET app_home', 5.0);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('GET app_home', $this->buffer->entries[0]->key);
        static::assertFalse($recorder->isRecording());

        $recorder->end($b, 'app:import', 1.0);

        static::assertCount(1, $this->buffer->entries);
    }

    public function testTheStackNeverGrowsPastItsDepth(): void
    {
        $recorder = $this->recorder();

        $tokens = [];
        for ($i = 0; $i < 20; ++$i) {
            $tokens[] = $recorder->begin(Entry::TYPE_REQUEST, 1000.0 + $i);
        }

        $recorder->end($tokens[0], 'first', 1.0);

        static::assertSame([], $this->buffer->entries);

        $recorder->end($tokens[19], 'last', 1.0);

        static::assertCount(1, $this->buffer->entries);
        static::assertSame('last', $this->buffer->entries[0]->key);
        static::assertSame(1019.0, $this->buffer->entries[0]->startedAt);
    }

    public function testAPausedRecorderIsNotRecording(): void
    {
        $recorder = $this->recorder();

        static::assertFalse($recorder->isRecording());

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->pause();

        static::assertFalse($recorder->isRecording());

        $recorder->resume();

        static::assertTrue($recorder->isRecording());

        $recorder->end($token, 'GET app_home', 1.0);
        $recorder->pause();
        $recorder->begin(Entry::TYPE_REQUEST, 1000.0);

        static::assertFalse($recorder->isRecording());
    }

    public function testResetForgetsOpenUnits(): void
    {
        $recorder = $this->recorder();
        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->pause();

        $recorder->reset();

        static::assertFalse($recorder->isRecording());

        $recorder->end($token, 'GET app_home', 1.0);

        static::assertSame([], $this->buffer->entries);

        $next = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);

        static::assertNotSame($token, $next);
        static::assertTrue($recorder->isRecording());
    }

    public function testAFailingBufferIsSwallowedAndLoggedOnce(): void
    {
        $this->buffer->failure = new \RuntimeException('disk on fire');
        $recorder = $this->recorder();

        for ($i = 0; $i < 2; ++$i) {
            $recorder->end($recorder->begin(Entry::TYPE_REQUEST, 1000.0), 'GET app_home', 1.0);
        }

        static::assertSame(['error JMonitor could not record a unit of work: disk on fire'], $this->logger->records);
        static::assertFalse($recorder->isRecording());
    }

    private function recorder(): Recorder
    {
        return new Recorder($this->buffer, $this->logger);
    }
}
