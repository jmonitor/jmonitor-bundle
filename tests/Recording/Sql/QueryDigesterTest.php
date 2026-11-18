<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Jmonitor\JmonitorBundle\Recording\Sql\LabelStore;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigest;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class QueryDigesterTest extends TestCase
{
    private string $dir;
    private BufferDirectory $directory;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-digest-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->directory = new BufferDirectory($this->dir, 'app');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTheSameQueryWithDifferentValuesIsOneChild(): void
    {
        $raw = [];
        for ($i = 1; $i <= 12; ++$i) {
            $raw['SELECT * FROM items WHERE id = ' . $i] = [1, 2.0, 2.0, 0];
        }

        $digest = $this->digester()->digest($raw, 1000.5);
        $id = SqlNormalizer::id('SELECT * FROM items WHERE id = ?');

        static::assertSame(12, $digest->count);
        static::assertSame(24.0, $digest->totalMs);
        static::assertSame([[Entry::TYPE_QUERY, $id], [Entry::TYPE_NPLUS1, $id]], self::keys($digest));
        [$query, $nPlusOne] = $digest->children;
        static::assertSame(1000.5, $query->startedAt);
        static::assertSame(24.0, $query->durationMs);
        static::assertSame(['n' => 12, 'm' => 2.0], $query->attributes);
        static::assertSame(1000.5, $nPlusOne->startedAt);
        static::assertSame(24.0, $nPlusOne->durationMs);
        static::assertSame(['n' => 12], $nPlusOne->attributes);
        static::assertSame('SELECT * FROM items WHERE id = ?', (new LabelStore($this->directory))->read($id));
    }

    public function testWritesAreNeverAnNPlusOne(): void
    {
        $raw = [];
        for ($i = 1; $i <= 12; ++$i) {
            $raw['INSERT INTO items (id) VALUES (' . $i . ')'] = [1, 1.0, 1.0, 0];
        }

        $digest = $this->digester()->digest($raw, 1000.0);

        static::assertSame([[Entry::TYPE_QUERY, SqlNormalizer::id('INSERT INTO items (id) VALUES (?)')]], self::keys($digest));
        static::assertSame(12, $digest->children[0]->attributes['n']);
    }

    public function testBelowTheThresholdThereIsNoNPlusOne(): void
    {
        $digest = $this->digester()->digest(['SELECT * FROM items WHERE id = ?' => [9, 9.0, 1.0, 0]], 1000.0);

        static::assertSame([[Entry::TYPE_QUERY, SqlNormalizer::id('SELECT * FROM items WHERE id = ?')]], self::keys($digest));
    }

    public function testNewLabelsAreLimitedPerUnit(): void
    {
        $raw = [];
        for ($i = 1; $i <= 25; ++$i) {
            $raw['SELECT * FROM table_' . $i] = [1, 1.5, 1.5, 0];
        }

        $digest = $this->digester()->digest($raw, 1000.0);

        static::assertCount(20, $this->labelFiles());
        static::assertCount(21, $digest->children);
        $other = $digest->children[20];
        static::assertSame(Entry::TYPE_QUERY, $other->type);
        static::assertSame(QueryDigester::OTHER, $other->key);
        static::assertSame(['n' => 5, 'm' => 1.5], $other->attributes);
        static::assertSame(7.5, $other->durationMs);
        static::assertSame(37.5, $digest->totalMs);
        static::assertSame(25, $digest->count);
    }

    public function testAKnownLabelIsNotRewritten(): void
    {
        $raw = ['SELECT * FROM items WHERE id = 1' => [1, 1.0, 1.0, 0]];
        $this->digester()->digest($raw, 1000.0);
        $file = $this->labelFiles()[0];
        touch($file, 5000);

        $digester = $this->digester();
        $digester->digest($raw, 1000.0);
        $digester->digest(['SELECT * FROM items WHERE id = 2' => [1, 1.0, 1.0, 0]], 2000.0);

        clearstatcache();
        static::assertSame(5000, filemtime($file));
        static::assertCount(1, $this->labelFiles());
    }

    public function testAKnownQueryIsTakenFromMemory(): void
    {
        $digester = $this->digester();
        $raw = ['SELECT * FROM items WHERE id = 1' => [1, 1.0, 1.0, 0]];
        $digester->digest($raw, 1000.0);
        (new Filesystem())->remove($this->labelFiles());

        $digester->digest($raw, 1599.0);

        static::assertSame([], $this->labelFiles());

        $digest = $digester->digest($raw, 1600.0);

        static::assertCount(1, $this->labelFiles());
        static::assertSame([[Entry::TYPE_QUERY, SqlNormalizer::id('SELECT * FROM items WHERE id = ?')]], self::keys($digest));
    }

    public function testAFullStoreFoldsUnknownQueriesIntoOther(): void
    {
        $labels = new LabelStore($this->directory, 1);
        $known = 'SELECT * FROM known';
        $labels->write(SqlNormalizer::id($known), $known);
        $labels->prune([SqlNormalizer::id($known)], time());

        $digest = (new QueryDigester($labels))->digest([
            $known => [2, 4.0, 3.0, 0],
            'SELECT * FROM unknown' => [12, 6.0, 1.0, 0],
        ], 1000.0);

        static::assertSame([[Entry::TYPE_QUERY, SqlNormalizer::id($known)], [Entry::TYPE_QUERY, QueryDigester::OTHER]], self::keys($digest));
        static::assertSame(['n' => 12, 'm' => 1.0], $digest->children[1]->attributes);
        static::assertSame(14, $digest->count);
        static::assertSame(10.0, $digest->totalMs);
        static::assertCount(1, $this->labelFiles());
    }

    public function testTheOtherKeyOfTheRecorderIsNotNormalized(): void
    {
        $digest = $this->digester()->digest([
            'SELECT 1' => [1, 1.0, 1.0, 0],
            QueryDigester::OTHER => [30, 30.0, 4.0, 1],
        ], 1000.0);

        static::assertSame([[Entry::TYPE_QUERY, SqlNormalizer::id('SELECT ?')], [Entry::TYPE_QUERY, QueryDigester::OTHER]], self::keys($digest));
        static::assertSame(['n' => 30, 'm' => 4.0, 'sl' => 1], $digest->children[1]->attributes);
        static::assertSame(31, $digest->count);
    }

    public function testSlowExecutionsAreCarried(): void
    {
        $digest = $this->digester()->digest(['UPDATE t SET a = 1' => [3, 400.0, 250.0, 2]], 1000.0);

        static::assertSame(['n' => 3, 'm' => 250.0, 'sl' => 2], $digest->children[0]->attributes);
        static::assertSame(400.0, $digest->children[0]->durationMs);
    }

    public function testMergedTextsKeepTheirLargestMaximum(): void
    {
        $digest = $this->digester()->digest([
            'SELECT * FROM t WHERE id = 1' => [2, 5.0, 4.0, 0],
            'SELECT * FROM t WHERE id = 2' => [1, 9.0, 9.0, 1],
        ], 1000.0);

        static::assertSame(['n' => 3, 'm' => 9.0, 'sl' => 1], $digest->children[0]->attributes);
        static::assertSame(14.0, $digest->children[0]->durationMs);
    }

    public function testAnUnusableDirectoryFoldsEverythingIntoOther(): void
    {
        touch($this->dir . '/file');
        $digester = new QueryDigester(new LabelStore(new BufferDirectory($this->dir . '/file', 'app')));

        $digest = $digester->digest(['SELECT 1' => [1, 1.0, 1.0, 0]], 1000.0);

        static::assertSame([[Entry::TYPE_QUERY, QueryDigester::OTHER]], self::keys($digest));
        static::assertInstanceOf(QueryDigest::class, $digest);
    }

    private function digester(): QueryDigester
    {
        return new QueryDigester(new LabelStore($this->directory));
    }

    /**
     * @return list<string>
     */
    private function labelFiles(): array
    {
        return glob($this->directory->path() . '/labels/*') ?: [];
    }

    /**
     * @return list<array{string, string}>
     */
    private static function keys(QueryDigest $digest): array
    {
        return array_map(static fn(Entry $child): array => [$child->type, $child->key], $digest->children);
    }
}
