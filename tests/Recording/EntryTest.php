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
}
