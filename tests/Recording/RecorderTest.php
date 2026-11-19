<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;

final class RecorderTest extends TestCase
{
    private BufferInterface $buffer;
    private AbstractLogger $logger;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-recorder-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];
            public ?\Throwable $failure = null;
            public ?bool $pressure = false;

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

            public function isUnderPressure(): ?bool
            {
                return $this->pressure;
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

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
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

    public function testSqlOutsideAUnitOrWhilePausedIsNotRecorded(): void
    {
        $recorder = $this->sqlRecorder();

        $recorder->addSql('SELECT 1', 1.0);
        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->pause();
        $recorder->addSql('SELECT 2', 1.0);
        $recorder->resume();
        $recorder->end($token, 'GET app_home', 5.0, ['status' => 200]);

        static::assertSame(['status' => 200], $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[0]->children);
    }

    public function testTheUnitCarriesItsSqlTotalsAndChildren(): void
    {
        $recorder = $this->sqlRecorder();

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.5);
        $recorder->addSql('SELECT * FROM t WHERE id = 1', 1.25);
        $recorder->addSql('SELECT * FROM t WHERE id = 2', 2.5);
        $recorder->addSql('SELECT * FROM t WHERE id = 1', 0.1234);
        $recorder->end($token, 'GET app_home', 12.0, ['status' => 200]);

        $entry = $this->buffer->entries[0];
        static::assertSame(['status' => 200, 'sql_ms' => 3.873, 'sql_n' => 3], $entry->attributes);
        static::assertCount(1, $entry->children);
        $child = $entry->children[0];
        static::assertSame(Entry::TYPE_QUERY, $child->type);
        static::assertSame(SqlNormalizer::id('SELECT * FROM t WHERE id = ?'), $child->key);
        static::assertSame(1000.5, $child->startedAt);
        static::assertSame(['n' => 3, 'm' => 2.5], $child->attributes);
        static::assertStringContainsString('"c":[["' . $child->key . '",3.87,3,2.5]]', $entry->toLine());
    }

    public function testSqlGoesToTheInnermostUnit(): void
    {
        $recorder = $this->sqlRecorder();

        $outer = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $inner = $recorder->begin('command', 1001.0);
        $recorder->addSql('SELECT 1', 1.0);
        $recorder->end($inner, 'app:import', 2.0);
        $recorder->end($outer, 'GET app_home', 3.0);

        static::assertSame(['sql_ms' => 1.0, 'sql_n' => 1], $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[1]->attributes);
    }

    public function testResetForgetsTheSqlOfOpenUnits(): void
    {
        $recorder = $this->sqlRecorder();

        $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql('SELECT 1', 1.0);
        $recorder->reset();
        $recorder->end($recorder->begin(Entry::TYPE_REQUEST, 1000.0), 'GET app_home', 3.0);

        static::assertSame([], $this->buffer->entries[0]->attributes);
    }

    public function testDistinctTextsPastTheLimitAreFoldedUnderOther(): void
    {
        $recorder = $this->sqlRecorder(new QueryDigester(new LabelStore(new BufferDirectory($this->dir, 'app')), 10, 1000));

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        for ($i = 1; $i <= 250; ++$i) {
            $recorder->addSql('SELECT * FROM table_' . $i, 1.0);
        }
        $recorder->end($token, 'GET app_home', 300.0);

        $entry = $this->buffer->entries[0];
        static::assertSame(250, $entry->attributes['sql_n']);
        static::assertCount(Recorder::MAX_SQL_PER_UNIT + 1, $entry->children);
        $other = $entry->children[Recorder::MAX_SQL_PER_UNIT];
        static::assertSame(QueryDigester::OTHER, $other->key);
        static::assertSame(50, $other->attributes['n']);
    }

    public function testUnderPressureChildrenAreSampledButTotalsStay(): void
    {
        $this->buffer->pressure = true;

        foreach ([false, true] as $kept) {
            $recorder = new Recorder($this->buffer, $this->logger, static fn(): bool => $kept, $this->digester());
            $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
            $recorder->addSql('SELECT 1', 2.0);
            $recorder->end($token, 'GET app_home', 3.0);
        }

        [$dropped, $sampled] = $this->buffer->entries;
        static::assertSame(['sql_ms' => 2.0, 'sql_n' => 1], $dropped->attributes);
        static::assertSame([], $dropped->children);
        static::assertSame(['sql_ms' => 2.0, 'sql_n' => 1, 'cw' => Recorder::SAMPLE_WEIGHT], $sampled->attributes);
        static::assertSame(10, $sampled->attributes['cw']);
        static::assertCount(1, $sampled->children);
    }

    public function testAUnitWhoseChildrenAreDroppedIsNotDigested(): void
    {
        $this->buffer->pressure = true;
        $recorder = new Recorder($this->buffer, $this->logger, static fn(): bool => false, $this->digester());

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql('SELECT * FROM t WHERE id = 1', 2.0);
        $recorder->addSql('SELECT * FROM u WHERE id = 1', 0.5);
        $recorder->addSql('SELECT * FROM t WHERE id = 2', 1.0);
        $recorder->end($token, 'GET app_home', 5.0);

        static::assertSame(['sql_ms' => 3.5, 'sql_n' => 3], $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[0]->children);
        static::assertSame([], $this->labelFiles());
    }

    public function testAUnitIsNotDigestedWhenTheBufferCannotBeWritten(): void
    {
        $this->buffer->pressure = null;
        $recorder = new Recorder($this->buffer, $this->logger, static fn(): bool => true, $this->digester());

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql('SELECT 1', 2.0);
        $recorder->end($token, 'GET app_home', 3.0);

        static::assertSame(['sql_ms' => 2.0, 'sql_n' => 1], $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[0]->children);
        static::assertSame([], $this->labelFiles());
    }

    public function testATextLongerThanTheLimitIsAccumulatedUnderOther(): void
    {
        $recorder = $this->sqlRecorder();
        $longest = 'SELECT ' . str_repeat('a', Recorder::MAX_SQL_BYTES - 14) . ' FROM t';
        $tooLong = 'SELECT ' . str_repeat('a', Recorder::MAX_SQL_BYTES - 13) . ' FROM t';

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql($longest, 1.0);
        $recorder->addSql($tooLong, 2.0);
        $recorder->addSql($tooLong, 3.0);
        $recorder->end($token, 'GET app_home', 10.0);

        static::assertSame(65_536, \strlen($longest));
        static::assertSame(65_537, \strlen($tooLong));
        $entry = $this->buffer->entries[0];
        static::assertSame(['sql_ms' => 6.0, 'sql_n' => 3], $entry->attributes);
        static::assertSame([SqlNormalizer::id((string) SqlNormalizer::normalize($longest)), QueryDigester::OTHER], array_map(static fn(Entry $child): string => $child->key, $entry->children));
        static::assertSame(['n' => 2, 'm' => 3.0], $entry->children[1]->attributes);
    }

    public function testAnExecutionIsSlowFromTheThreshold(): void
    {
        $recorder = new Recorder($this->buffer, $this->logger, null, $this->digester(), 100);

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql('SELECT 1', 99.9);
        $recorder->addSql('SELECT 1', 100.0);
        $recorder->end($token, 'GET app_home', 300.0);

        static::assertSame(1, $this->buffer->entries[0]->children[0]->attributes['sl']);
    }

    public function testWithoutADigesterSqlIsIgnored(): void
    {
        $recorder = $this->recorder();

        $token = $recorder->begin(Entry::TYPE_REQUEST, 1000.0);
        $recorder->addSql('SELECT 1', 2.0);
        $recorder->end($token, 'GET app_home', 3.0);

        static::assertSame([], $this->buffer->entries[0]->attributes);
        static::assertSame([], $this->buffer->entries[0]->children);
    }

    private function recorder(): Recorder
    {
        return new Recorder($this->buffer, $this->logger);
    }

    private function sqlRecorder(?QueryDigester $digester = null): Recorder
    {
        return new Recorder($this->buffer, $this->logger, null, $digester ?? $this->digester());
    }

    /**
     * @return list<string>
     */
    private function labelFiles(): array
    {
        return glob((new BufferDirectory($this->dir, 'app'))->path() . '/labels/*') ?: [];
    }

    private function digester(): QueryDigester
    {
        return new QueryDigester(new LabelStore(new BufferDirectory($this->dir, 'app')));
    }
}
