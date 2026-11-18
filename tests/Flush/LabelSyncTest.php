<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Flush;

use Jmonitor\JmonitorBundle\Aggregation\Aggregate;
use Jmonitor\JmonitorBundle\Aggregation\Aggregator;
use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Flush\LabelSync;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class LabelSyncTest extends TestCase
{
    private const ID = '0123456789abcdef';
    private const TEXT = 'SELECT * FROM items WHERE id = ?';

    private string $dir;
    private BufferDirectory $directory;
    private LabelStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-label-sync-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->directory = new BufferDirectory($this->dir, 'app');
        $this->store = new LabelStore($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testALabelNeverStoredIsSentWithItsText(): void
    {
        $this->store->write(self::ID, self::TEXT);

        $labels = (new LabelSync($this->store))->labelsFor([self::aggregate(Metric::QUERY_DURATION, self::ID), self::aggregate(Metric::QUERY_SLOW, self::ID)], time());

        static::assertSame([self::ID => self::TEXT], $labels);
    }

    public function testAConfirmedLabelIsNotSentAgain(): void
    {
        $this->store->write(self::ID, self::TEXT);
        $sync = new LabelSync($this->store);
        $now = time();

        $sync->confirm($sync->labelsFor([self::aggregate(Metric::QUERY_DURATION, self::ID)], $now), $now);

        static::assertSame([self::ID => null], $sync->labelsFor([self::aggregate(Metric::QUERY_DURATION, self::ID)], $now + 1));
        static::assertSame([self::ID => self::TEXT], $sync->labelsFor([self::aggregate(Metric::QUERY_DURATION, self::ID)], $now + LabelStore::STORE_EVERY));
    }

    public function testAMissingLabelOnlyRefreshesItsKey(): void
    {
        static::assertSame([self::ID => null], (new LabelSync($this->store))->labelsFor([self::aggregate(Metric::QUERY_DURATION, self::ID)], time()));
    }

    public function testOnlyTheQueriesOfTheBatchAreMapped(): void
    {
        $this->store->write(self::ID, self::TEXT);

        $labels = (new LabelSync($this->store))->labelsFor([
            self::aggregate(Metric::QUERY_DURATION, Aggregator::OTHER),
            self::aggregate(Metric::REQUEST_DURATION, 'GET app_home'),
            self::aggregate(Metric::NPLUS1_CALLS, 'request' . Entry::KEY_SEPARATOR . 'GET app_home' . Entry::KEY_SEPARATOR . self::ID),
            self::aggregate(Metric::QUERY_DURATION, self::ID),
        ], time());

        static::assertSame([self::ID => self::TEXT], $labels);
    }

    public function testConfirmDropsTheOldLabelsOutsideTheRegistry(): void
    {
        $kept = 'fedcba9876543210';
        $this->store->write(self::ID, self::TEXT);
        $this->store->write($kept, 'SELECT 1');
        $hotKeys = new HotKeys($this->directory, new MockClock());
        $hotKeys->select(Family::Query, [$kept => 1.0], 10);

        (new LabelSync($this->store, $hotKeys))->confirm([], time() + LabelStore::STORE_EVERY + 10);

        static::assertFalse($this->store->exists(self::ID));
        static::assertTrue($this->store->exists($kept));
    }

    private static function aggregate(string $type, string $key): Aggregate
    {
        return new Aggregate(Period::OneHour, 0, $type, $key, 1, 1.0, 1.0);
    }
}
