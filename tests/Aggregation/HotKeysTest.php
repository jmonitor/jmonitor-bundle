<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\Family;
use Jmonitor\JmonitorBundle\Aggregation\HotKeys;
use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class HotKeysTest extends TestCase
{
    private string $dir;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-hot-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->clock = new MockClock('@1000');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testHeavyKeysStayAndTheKeptSetDoesNotGrowWithTheNumberOfFlushes(): void
    {
        mt_srand(7);
        $registry = $this->registry();
        $heavy = [];
        for ($i = 0; $i < 10; ++$i) {
            $heavy['heavy-' . $i] = 50.0;
        }
        $union = [];

        for ($call = 1; $call <= 60; ++$call) {
            $weights = $heavy;
            for ($i = 0; $i < 2000; ++$i) {
                $weights['light-' . mt_rand(1, 20_000)] = mt_rand(100, 300) / 100;
            }

            $kept = $registry->select(Family::Query, $weights, 200);

            static::assertLessThanOrEqual(200, \count($kept));
            static::assertLessThanOrEqual(1000, \count($registry->keys(Family::Query)));
            if ($call >= 2) {
                static::assertSame($heavy, array_intersect_key($heavy, $kept), 'call ' . $call);
            }
            $union += $kept;
            $this->clock->sleep(10);
        }

        static::assertLessThanOrEqual(600, \count($union));
    }

    public function testAnIdleKeyIsForgotten(): void
    {
        $registry = $this->registry();
        $registry->select(Family::Query, ['old' => 100.0], 200);
        $this->clock->sleep(10 * 3600);

        $fresh = [];
        for ($i = 0; $i < 1000; ++$i) {
            $fresh['new-' . $i] = 1.0;
        }
        $registry->select(Family::Query, $fresh, 200);

        static::assertNotContains('old', $registry->keys(Family::Query));
    }

    public function testFamiliesDoNotShareTheirCounters(): void
    {
        $registry = $this->registry();
        $registry->select(Family::Query, ['shared' => 100.0, 'query-only' => 1.0], 200);

        static::assertSame(['only' => true], $registry->select(Family::NPlusOne, ['shared' => 1.0, 'only' => 5.0], 1));
        static::assertSame(['query-only', 'shared'], $this->sorted($registry->keys(Family::Query)));
        static::assertSame(['only', 'shared'], $this->sorted($registry->keys(Family::NPlusOne)));
    }

    public function testTheRegistrySurvivesOnlyWhenCommitted(): void
    {
        $this->registry()->select(Family::Query, ['lost' => 10.0], 200);

        static::assertSame([], $this->registry()->keys(Family::Query));

        $registry = $this->registry();
        $registry->select(Family::Query, ['kept' => 10.0, 'other' => 1.0], 1);
        $registry->commit();

        $reloaded = $this->registry();
        static::assertSame(['kept', 'other'], $this->sorted($reloaded->keys(Family::Query)));
        static::assertSame(['kept' => true], $reloaded->select(Family::Query, ['kept' => 1.0, 'other' => 1.0], 1));
    }

    public function testKeysTheRegistryHoldsAsNumbersStayStrings(): void
    {
        $registry = $this->registry();
        $registry->select(Family::Query, ['123' => 10.0], 200);
        $registry->commit();

        static::assertSame(['123'], $this->registry()->keys(Family::Query));
    }

    public function testACorruptFileStartsAnEmptyRegistry(): void
    {
        foreach (['{oops', '[]', '{"version":99,"families":[]}', '{"version":1,"families":{"query":{"at":"x","keys":7}}}'] as $content) {
            $directory = new BufferDirectory($this->dir, 'app');
            file_put_contents($directory->path() . '/hot-keys.json', $content);
            $registry = new HotKeys($directory, $this->clock);

            static::assertSame(['a' => true], $registry->select(Family::Query, ['a' => 1.0], 200), $content);
            static::assertSame(['a'], $registry->keys(Family::Query));
        }
    }

    public function testAnUnusableDirectoryDoesNotFail(): void
    {
        touch($this->dir . '/file');
        $directory = new BufferDirectory($this->dir . '/file', 'app');
        static::assertNull($directory->path());
        $registry = new HotKeys($directory, $this->clock);

        static::assertSame(['a' => true], $registry->select(Family::Query, ['a' => 1.0], 200));
        $registry->commit();

        static::assertSame(['a'], $registry->keys(Family::Query));
    }

    public function testTiesAreBrokenAlphabetically(): void
    {
        static::assertSame(['a' => true, 'b' => true], $this->registry()->select(Family::Query, ['c' => 1.0, 'b' => 1.0, 'a' => 1.0], 2));
    }

    public function testTheFileIsPrivateAndLeavesNoTemporaryFile(): void
    {
        $registry = $this->registry();
        $registry->select(Family::Query, ['a' => 1.0], 200);
        $registry->commit();

        $files = glob($this->dir . '/*/hot-keys.json') ?: [];
        static::assertCount(1, $files);
        static::assertSame(0, fileperms($files[0]) & 0o077);
        static::assertSame(['hot-keys.json'], array_map('basename', glob(\dirname($files[0]) . '/*') ?: []));
    }

    private function registry(): HotKeys
    {
        return new HotKeys(new BufferDirectory($this->dir, 'app'), $this->clock);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
