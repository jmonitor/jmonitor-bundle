<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Cache;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingAdapter;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingTagAwareAdapter;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;

final class RecordingAdapterTest extends TestCase
{
    private const POOL = 'app.pool';

    private BufferInterface $buffer;
    private Recorder $recorder;
    private ArrayAdapter $pool;
    private RecordingAdapter $adapter;

    protected function setUp(): void
    {
        $this->buffer = new class implements BufferInterface {
            /** @var list<Entry> */
            public array $entries = [];

            public function append(Entry $entry): void
            {
                $this->entries[] = $entry;
            }

            public function drain(callable $consumer, bool $includeCurrent = false): int
            {
                return 0;
            }

            public function isUnderPressure(): bool
            {
                return false;
            }
        };
        $this->recorder = new Recorder($this->buffer);
        $this->pool = new ArrayAdapter(0, false);
        $this->adapter = new RecordingAdapter($this->pool, $this->recorder, self::POOL);
        $this->pool->save($this->pool->getItem('stored')->set('value'));
    }

    public function testAFoundItemIsAHit(): void
    {
        $unit = $this->begin();

        static::assertSame('value', $this->adapter->getItem('stored')->get());

        static::assertSame(['h' => 1], $this->counts($unit));
    }

    public function testAMissingItemIsAMiss(): void
    {
        $unit = $this->begin();

        static::assertFalse($this->adapter->getItem('missing')->isHit());

        static::assertSame(['mi' => 1], $this->counts($unit));
    }

    public function testTheItemsOfGetItemsAreCountedAsTheApplicationReadsThem(): void
    {
        $this->pool->save($this->pool->getItem('other')->set('value'));
        $unit = $this->begin();

        $hits = [];
        foreach ($this->adapter->getItems(['stored', 'missing', 'other']) as $key => $item) {
            $hits[$key] = $item->isHit();
        }
        foreach ($this->adapter->getItems(['missing', 'stored']) as $item) {
            break;
        }

        static::assertSame(['stored' => true, 'missing' => false, 'other' => true], $hits);
        static::assertSame(['h' => 2, 'mi' => 2], $this->counts($unit));
    }

    public function testItemsTheApplicationDoesNotReadAreNotCounted(): void
    {
        $unit = $this->begin();

        $items = $this->adapter->getItems(['stored', 'missing']);

        static::assertInstanceOf(\Generator::class, $items);
        static::assertSame([], $this->counts($unit));
    }

    public function testAnArrayOfItemsFromThePoolIsHandedBackAsIs(): void
    {
        $pool = new class extends ArrayAdapter {
            /** @var array<string, CacheItem> */
            public array $returned = [];

            public function getItems(array $keys = []): iterable
            {
                return $this->returned = iterator_to_array(parent::getItems($keys));
            }
        };
        $pool->save($pool->getItem('stored')->set('value'));
        $adapter = new RecordingAdapter($pool, $this->recorder, self::POOL);
        $unit = $this->begin();

        $items = $adapter->getItems(['stored', 'missing']);

        static::assertIsArray($items);
        static::assertSame($pool->returned, $items);
        static::assertCount(2, $items);
        static::assertTrue($items['stored']->isHit());
        static::assertSame(['h' => 1, 'mi' => 1], $this->counts($unit));
    }

    public function testAGetThatDoesNotComputeIsAHit(): void
    {
        $this->adapter->get('cached', static fn(): string => 'value');
        $unit = $this->begin();

        static::assertSame('value', $this->adapter->get('cached', static fn(): string => 'recomputed'));

        static::assertSame(['h' => 1], $this->counts($unit));
    }

    public function testAGetThatComputesIsAMissAndAWrite(): void
    {
        $unit = $this->begin();

        static::assertSame('value', $this->adapter->get('computed', static fn(): string => 'value'));

        static::assertSame(['mi' => 1, 'w' => 1], $this->counts($unit));
        static::assertTrue($this->pool->getItem('computed')->isHit());
    }

    public function testAComputationThatDeclinesToSaveIsNotAWrite(): void
    {
        $unit = $this->begin();

        $value = $this->adapter->get('volatile', static function (ItemInterface $item, bool &$save): string {
            $save = false;

            return 'value';
        });

        static::assertSame('value', $value);
        static::assertSame(['mi' => 1], $this->counts($unit));
        static::assertFalse($this->pool->getItem('volatile')->isHit());
    }

    public function testSaveAndSaveDeferredAreWrites(): void
    {
        $unit = $this->begin();

        static::assertTrue($this->adapter->save($this->pool->getItem('a')->set(1)));
        static::assertTrue($this->adapter->saveDeferred($this->pool->getItem('b')->set(2)));
        static::assertTrue($this->adapter->commit());

        static::assertSame(['w' => 2], $this->counts($unit));
        static::assertTrue($this->pool->getItem('b')->isHit());
    }

    public function testEachDeletedKeyIsADelete(): void
    {
        $unit = $this->begin();

        static::assertTrue($this->adapter->deleteItem('stored'));
        static::assertTrue($this->adapter->deleteItems(['a', 'b', 'c']));
        static::assertTrue($this->adapter->deleteItems([]));
        static::assertTrue($this->adapter->delete('d'));

        static::assertSame(['de' => 5], $this->counts($unit));
        static::assertFalse($this->pool->hasItem('stored'));
    }

    public function testTheOtherOperationsAreNotCounted(): void
    {
        $tagAware = new RecordingTagAwareAdapter(new TagAwareAdapter(new ArrayAdapter()), $this->recorder, self::POOL);
        $unit = $this->begin();

        static::assertTrue($this->adapter->hasItem('stored'));
        static::assertTrue($this->adapter->commit());
        static::assertFalse($this->adapter->prune());
        static::assertTrue($this->adapter->clear());
        static::assertTrue($tagAware->invalidateTags(['tag']));
        $this->adapter->reset();

        static::assertSame([], $this->counts($unit));
        static::assertFalse($this->pool->hasItem('stored'));
    }

    public function testANestedGetIsCountedOnItsOwn(): void
    {
        $unit = $this->begin();
        $compute = fn(): string => 'outer ' . $this->adapter->get('inner', static fn(): string => 'inner');

        static::assertSame('outer inner', $this->adapter->get('outer', $compute));
        static::assertSame('outer inner', $this->adapter->get('outer', $compute));
        static::assertSame('inner', $this->adapter->get('inner', static fn(): string => 'recomputed'));

        static::assertSame(['mi' => 2, 'w' => 2, 'h' => 2], $this->counts($unit));
    }

    public function testAnExceptionOfTheComputationGoesThroughAsIs(): void
    {
        $unit = $this->begin();
        $thrown = new \DomainException('Not computable');

        try {
            $this->adapter->get('failing', static fn(): never => throw $thrown);
            static::fail('The exception of the computation was swallowed.');
        } catch (\DomainException $e) {
            static::assertSame($thrown, $e);
        }

        static::assertSame(['mi' => 1], $this->counts($unit));
        static::assertFalse($this->pool->hasItem('failing'));
    }

    public function testAnExceptionOfThePoolGoesThroughAndCountsNothing(): void
    {
        $failure = new \RuntimeException('The pool is down.');
        $adapter = new RecordingAdapter(new class ($failure) extends ArrayAdapter {
            public function __construct(private readonly \RuntimeException $failure)
            {
                parent::__construct();
            }

            public function getItem(mixed $key): CacheItem
            {
                throw $this->failure;
            }

            public function getItems(array $keys = []): iterable
            {
                throw $this->failure;
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw $this->failure;
            }

            public function save(CacheItemInterface $item): bool
            {
                throw $this->failure;
            }

            public function saveDeferred(CacheItemInterface $item): bool
            {
                throw $this->failure;
            }

            public function deleteItem(mixed $key): bool
            {
                throw $this->failure;
            }

            public function deleteItems(array $keys): bool
            {
                throw $this->failure;
            }
        }, $this->recorder, self::POOL);
        $item = $this->pool->getItem('stored');
        $operations = [
            'getItem' => static fn() => $adapter->getItem('stored'),
            'getItems' => static fn() => $adapter->getItems(['stored']),
            'get' => static fn() => $adapter->get('stored', static fn(): string => 'value'),
            'save' => static fn() => $adapter->save($item),
            'saveDeferred' => static fn() => $adapter->saveDeferred($item),
            'deleteItem' => static fn() => $adapter->deleteItem('stored'),
            'deleteItems' => static fn() => $adapter->deleteItems(['stored']),
            'delete' => static fn() => $adapter->delete('stored'),
        ];
        $unit = $this->begin();

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                static::fail(sprintf('%s() swallowed the exception of the pool.', $name));
            } catch (\RuntimeException $e) {
                static::assertSame($failure, $e, $name);
            }
        }

        static::assertSame([], $this->counts($unit));
    }

    public function testNothingIsCountedWithoutAnOpenUnit(): void
    {
        $this->adapter->getItem('stored');
        iterator_to_array($this->adapter->getItems(['stored', 'missing']));
        $this->adapter->get('computed', static fn(): string => 'value');
        $this->adapter->save($this->pool->getItem('a')->set(1));
        $this->adapter->deleteItem('a');
        $unit = $this->begin();

        static::assertSame([], $this->counts($unit));
        static::assertTrue($this->pool->hasItem('computed'));
    }

    public function testTheTagAwareWrapperCountsAndInvalidates(): void
    {
        $tagAware = new RecordingTagAwareAdapter(new TagAwareAdapter(new ArrayAdapter()), $this->recorder, self::POOL);
        $compute = static function (ItemInterface $item): string {
            $item->tag('tag');

            return 'value';
        };
        $unit = $this->begin();

        $tagAware->get('tagged', $compute);
        $tagAware->get('tagged', $compute);
        $tagAware->invalidateTags(['tag']);
        $tagAware->get('tagged', $compute);

        static::assertSame(['mi' => 2, 'w' => 2, 'h' => 1], $this->counts($unit));
    }

    public function testASubNamespaceCountsOnItsPool(): void
    {
        if (!method_exists($this->adapter, 'withSubNamespace')) {
            static::markTestSkipped('Sub-namespaces come with Symfony 7.3.');
        }

        $unit = $this->begin();
        $child = $this->adapter->withSubNamespace('child');

        static::assertInstanceOf(RecordingAdapter::class, $child);
        static::assertFalse($child->getItem('stored')->isHit());
        static::assertSame(['mi' => 1], $this->counts($unit));
    }

    public function testAHundredThousandOperationsKeepNoMemory(): void
    {
        $unit = $this->begin();
        $this->adapter->getItem('stored');
        $before = memory_get_usage();

        for ($i = 0; $i < 100_000; ++$i) {
            $this->adapter->getItem('stored');
            $this->adapter->get('stored', static fn(): string => 'value');
            $this->adapter->hasItem('stored');
        }

        static::assertLessThan(64 * 1024, memory_get_usage() - $before);
        static::assertSame([], $this->adapter->getCalls());
        static::assertSame(['h' => 200_001], $this->counts($unit));
    }

    private function begin(): int
    {
        return $this->recorder->begin(Entry::TYPE_REQUEST, 1700000000.0);
    }

    /** @return array<string, int|float|string> the counters of the pool on the closed unit */
    private function counts(int $unit): array
    {
        $this->recorder->end($unit, 'GET app_test', 1.0);
        $entry = end($this->buffer->entries);
        static::assertInstanceOf(Entry::class, $entry, 'No unit was written.');

        $counts = [];
        foreach ($entry->children as $child) {
            static::assertSame([Entry::TYPE_CACHE, self::POOL, 0.0], [$child->type, $child->key, $child->durationMs]);
            $counts += array_diff_key($child->attributes, ['n' => true]);
        }

        return $counts;
    }
}
