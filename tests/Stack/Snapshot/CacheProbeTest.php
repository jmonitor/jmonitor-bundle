<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheProbe;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Cache\RecordingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ForgetfulCachePool;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\ThrowingCachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Clock\MockClock;

final class CacheProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{AdapterInterface, AdapterInterface}> the pool given to the probe, the pool that would keep the mark
     */
    public static function arrayAndNullPools(): iterable
    {
        $array = new ArrayAdapter();
        $null = new NullAdapter();

        yield 'array' => [$array, $array];
        yield 'null' => [$null, $null];
        yield 'traced array' => [new TraceableAdapter($array = new ArrayAdapter()), $array];
        yield 'traced twice' => [new TraceableAdapter(new TraceableAdapter($array = new ArrayAdapter())), $array];
        yield 'traced null' => [new TraceableAdapter($null = new NullAdapter()), $null];
    }

    #[DataProvider('arrayAndNullPools')]
    public function testAnArrayOrNullPoolIsOutOfOrder(AdapterInterface $pool, AdapterInterface $inner): void
    {
        self::assertSame(CacheState::OutOfOrder, (new CacheProbe($pool, new MockClock()))->state());
        self::assertSame([], $inner instanceof ArrayAdapter ? $inner->getValues() : [], 'nothing is written');
    }

    public function testTheFirstCallOnAWorkingPoolIsColdAndTheNextOneWorks(): void
    {
        $probe = new CacheProbe(new CountingCachePool(), new MockClock());

        self::assertSame(CacheState::Cold, $probe->state());
        self::assertSame(CacheState::Working, $probe->state());
    }

    public function testThePoolKeepsTheMarkForADay(): void
    {
        $recording = new RecordingCachePool();

        (new CacheProbe(new CountingCachePool($recording), new MockClock()))->state();

        self::assertSame(86400, $recording->ttls['jmonitor.stack.mark']);
    }

    public function testAPoolThatKeepsNothingIsOutOfOrder(): void
    {
        $probe = new CacheProbe(new ForgetfulCachePool(), new MockClock());

        self::assertSame(CacheState::OutOfOrder, $probe->state());
        self::assertSame(CacheState::OutOfOrder, $probe->state());
    }

    public function testAPoolThatThrowsIsOutOfOrder(): void
    {
        self::assertSame(CacheState::OutOfOrder, (new CacheProbe(new ThrowingCachePool(), new MockClock()))->state());
    }

    public function testAWorkingPoolCostsOneRoundTrip(): void
    {
        $pool = new CountingCachePool();
        $probe = new CacheProbe($pool, new MockClock());
        $probe->state();
        $pool->reset();

        self::assertSame(CacheState::Working, $probe->state());
        self::assertSame(1, $pool->roundTrips());
    }
}
