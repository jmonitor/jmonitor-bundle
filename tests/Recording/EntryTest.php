<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording;

use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    public function testLineRoundTrip(): void
    {
        $child = new Entry('query', 'SELECT 1', 1700000000.5, 3.2);
        $entry = new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1700000000.25, 84.5, ['status' => 200, 'memory' => 14680064], [$child]);

        $line = $entry->toLine();

        static::assertStringNotContainsString("\n", $line);
        static::assertEquals($entry, Entry::fromLine($line));
    }

    public function testInvalidLinesGiveNull(): void
    {
        static::assertNull(Entry::fromLine(''));
        static::assertNull(Entry::fromLine('{"type":"request","key":"GET a'));
        static::assertNull(Entry::fromLine('[1,2,3]'));
        static::assertNull(Entry::fromLine('{"type":"request"}'));
    }

    public function testKeyWithInvalidUtf8StillSerializes(): void
    {
        $entry = new Entry(Entry::TYPE_REQUEST, "GET caf\xE9", 1.0, 1.0);

        static::assertNotNull(Entry::fromLine($entry->toLine()));
    }

    public function testChildrenSurviveARoundTrip(): void
    {
        $child = new Entry(Entry::TYPE_QUERY, 'a1b2c3d4e5f6a7b8', 1000.5, 12.35, ['n' => 3, 'm' => 5.1, 'sl' => 1]);
        $entry = new Entry(Entry::TYPE_REQUEST, 'GET app_home', 1000.5, 80.0, ['status' => 200, 'sql_ms' => 12.35, 'sql_n' => 3], [$child]);

        static::assertEquals($entry, Entry::fromLine($entry->toLine()));
    }

    public function testAChildWithAnotherTypeOrStartSurvivesARoundTrip(): void
    {
        $children = [
            new Entry(Entry::TYPE_NPLUS1, 'a' . Entry::KEY_SEPARATOR . 'b', 1001.25, 4.5, ['n' => 2, 'm' => 3.0, 'sl' => 0]),
            new Entry(Entry::TYPE_QUERY, 'c', 1000.0, 1.5, ['n' => 1, 'm' => 1.5]),
            new Entry('other', 'd', 1000.0, 1.5, ['foo' => 'bar']),
        ];
        $entry = new Entry(Entry::TYPE_REQUEST, 'GET a', 1000.0, 10.0, [], $children);

        static::assertEquals($entry, Entry::fromLine($entry->toLine()));
    }

    public function testAChildInheritsTheStartOfItsParent(): void
    {
        $entry = Entry::fromLine('{"t":"request","k":"GET a","s":1000.5,"d":9.0,"c":[["abc",1.5,2,1.0],{"t":"q","k":"x","d":2.0}]}');

        static::assertNotNull($entry);
        static::assertSame(1000.5, $entry->children[0]->startedAt);
        static::assertSame(Entry::TYPE_QUERY, $entry->children[0]->type);
        static::assertSame(1000.5, $entry->children[1]->startedAt);
    }

    public function testALineWrittenByTheFirstFormatStillReads(): void
    {
        $entry = Entry::fromLine('{"t":"request","k":"GET app_home","s":1000.5,"d":12.5,"a":{"status":200,"memory":2097152}}');

        static::assertSame('GET app_home', $entry?->key);
    }

    public function testThirtyQueriesFitInTwelveHundredBytes(): void
    {
        $children = [];
        for ($i = 0; $i < 30; ++$i) {
            $children[] = new Entry(Entry::TYPE_QUERY, hash('xxh3', "q{$i}"), 1700000000.123, 12.345 + $i, ['n' => 3, 'm' => 5.125]);
        }
        $entry = new Entry(Entry::TYPE_REQUEST, 'GET app_product_show', 1700000000.123, 182.4, ['status' => 200, 'memory' => 4194304, 'sql_ms' => 500.5, 'sql_n' => 90], $children);

        static::assertLessThan(1200, \strlen($entry->toLine()));
    }

    public function testAMalformedChildGivesNull(): void
    {
        static::assertNull(Entry::fromLine('{"t":"request","k":"a","s":1,"d":1,"c":[["abc",1.5]]}'));
        static::assertNull(Entry::fromLine('{"t":"request","k":"a","s":1,"d":1,"c":[[1,1.5,2,1.0]]}'));
        static::assertNull(Entry::fromLine('{"t":"request","k":"a","s":1,"d":1,"c":["x"]}'));
    }
}
