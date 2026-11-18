<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LabelStoreTest extends TestCase
{
    private const ID = '0123456789abcdef';

    private string $dir;
    private BufferDirectory $directory;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-labels-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->directory = new BufferDirectory($this->dir, 'app');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAWrittenLabelIsReadBack(): void
    {
        $store = new LabelStore($this->directory);

        static::assertFalse($store->exists(self::ID));
        static::assertNull($store->read(self::ID));
        static::assertTrue($store->write(self::ID, 'SELECT * FROM t WHERE id = ?'));
        static::assertTrue($store->exists(self::ID));
        static::assertSame('SELECT * FROM t WHERE id = ?', $store->read(self::ID));

        $labels = $this->directory->path() . '/labels';
        static::assertSame(0o700, fileperms($labels) & 0o777);
        static::assertSame(0o600, fileperms($labels . '/' . self::ID) & 0o777);
        static::assertSame([self::ID], array_values(array_diff(scandir($labels) ?: [], ['.', '..'])));
    }

    public function testASecondWriteLeavesTheFileUnchanged(): void
    {
        $store = new LabelStore($this->directory);
        $store->write(self::ID, 'first');
        $store->markStored(self::ID, 5000);

        static::assertTrue($store->write(self::ID, 'second'));

        clearstatcache();
        static::assertSame('first', $store->read(self::ID));
        static::assertSame(5000, filemtime($this->directory->path() . '/labels/' . self::ID));
    }

    public function testInvalidIdsAreRefusedWithoutTouchingTheDisk(): void
    {
        $store = new LabelStore($this->directory);
        file_put_contents($this->directory->path() . '/x', 'secret');

        foreach (['../x', 'ABC', '0123456789ABCDEF', '0123456789abcdef0'] as $id) {
            static::assertFalse($store->write($id, 'SELECT ?'), $id);
            static::assertNull($store->read($id), $id);
            static::assertFalse($store->exists($id), $id);
            static::assertFalse($store->isDue($id, 1000), $id);
        }

        static::assertDirectoryDoesNotExist($this->directory->path() . '/labels');
    }

    public function testALabelIsDueUntilStoredThenAgainAfterAnHour(): void
    {
        $store = new LabelStore($this->directory);
        $now = time();

        static::assertFalse($store->isDue(self::ID, $now));

        $store->write(self::ID, 'SELECT ?');

        static::assertTrue($store->isDue(self::ID, $now));

        $store->markStored(self::ID, $now);

        static::assertFalse($store->isDue(self::ID, $now));
        static::assertFalse($store->isDue(self::ID, $now + LabelStore::STORE_EVERY - 1));
        static::assertTrue($store->isDue(self::ID, $now + LabelStore::STORE_EVERY));
    }

    public function testMarkingAMissingLabelCreatesNothing(): void
    {
        $store = new LabelStore($this->directory);

        $store->markStored(self::ID, time());

        static::assertFalse($store->exists(self::ID));
    }

    public function testPruneRemovesOldLabelsOutsideTheList(): void
    {
        $store = new LabelStore($this->directory);
        $kept = '1111111111111111';
        $old = '2222222222222222';
        foreach ([$kept, $old] as $id) {
            $store->write($id, 'SELECT ' . $id);
        }

        $store->prune([$kept], time() + LabelStore::STORE_EVERY + 10);

        static::assertTrue($store->exists($kept));
        static::assertFalse($store->exists($old));
    }

    public function testPruneKeepsRecentLabels(): void
    {
        $store = new LabelStore($this->directory);
        $store->write(self::ID, 'SELECT ?');

        $store->prune([], time() + 60);

        static::assertTrue($store->exists(self::ID));
    }

    public function testPruneSweepsOldTemporaryFiles(): void
    {
        $store = new LabelStore($this->directory);
        $store->write(self::ID, 'SELECT ?');
        $temporary = $this->directory->path() . '/labels/.tmp-0123abcd';
        touch($temporary);

        $store->prune([self::ID], time() + 60);

        static::assertFileExists($temporary);

        $store->prune([self::ID], time() + LabelStore::STORE_EVERY + 10);

        static::assertFileDoesNotExist($temporary);
        static::assertTrue($store->exists(self::ID));
    }

    public function testAFullStoreRefusesNewLabelsUntilPruned(): void
    {
        $store = new LabelStore($this->directory, 3);
        $ids = ['1111111111111111', '2222222222222222', '3333333333333333'];
        foreach ($ids as $id) {
            static::assertTrue($store->write($id, 'SELECT ' . $id));
        }
        $full = $this->directory->path() . '/labels/.full';

        $store->prune($ids, time());

        static::assertFileExists($full);
        static::assertFalse($store->write('4444444444444444', 'SELECT 4'));
        static::assertFalse($store->exists('4444444444444444'));
        static::assertTrue($store->write($ids[0], 'SELECT again'));

        $store->prune([$ids[0]], time() + LabelStore::STORE_EVERY + 10);

        static::assertFileDoesNotExist($full);
        static::assertTrue($store->write('4444444444444444', 'SELECT 4'));
    }

    public function testAnUnusableDirectoryRefusesEverythingQuietly(): void
    {
        touch($this->dir . '/file');
        $directory = new BufferDirectory($this->dir . '/file', 'app');
        static::assertNull($directory->path());
        $store = new LabelStore($directory);

        static::assertFalse($store->write(self::ID, 'SELECT ?'));
        static::assertFalse($store->exists(self::ID));
        static::assertNull($store->read(self::ID));
        static::assertFalse($store->isDue(self::ID, time()));
        $store->markStored(self::ID, time());
        $store->prune([], time());
    }

    public function testAnEmptyFileReadsAsNoLabel(): void
    {
        $store = new LabelStore($this->directory);
        $store->write('1111111111111111', 'SELECT ?');
        touch($this->directory->path() . '/labels/' . self::ID);

        static::assertNull($store->read(self::ID));
    }
}
