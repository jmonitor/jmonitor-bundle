<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Aggregation\TaskKey;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;

final class TaskKeyTest extends TestCase
{
    public function testAKeyIsParsedBackIntoItsParts(): void
    {
        $key = new TaskKey('default', 'ab12', 'app:work', 'every 10 seconds');

        static::assertSame("default\x1eab12\x1eapp:work\x1eevery 10 seconds", $key->toString());
        static::assertSame("default\x1eab12", $key->identity());
        static::assertEquals($key, TaskKey::parse($key->toString()));
        static::assertSame('every 10 seconds', TaskKey::parse($key->toString())?->trigger);
    }

    public function testBothSeparatorsAreRemovedFromEveryPart(): void
    {
        $key = new TaskKey("def\x1eault", "ab\x1f12", "app:\x1ework\x1f", "every\x1e 10 seconds");

        static::assertSame(['default', 'ab12', 'app:work', 'every 10 seconds'], [$key->schedule, $key->id, $key->label, $key->trigger]);
        static::assertEquals($key, TaskKey::parse($key->toString()));
    }

    public function testTheLabelAndTheTriggerAreCut(): void
    {
        $key = new TaskKey('default', 'ab12', str_repeat('a', 199) . 'é', str_repeat('b', 150));

        static::assertSame(str_repeat('a', 199), $key->label);
        static::assertSame(str_repeat('b', 100), $key->trigger);
    }

    public function testAKeyWithoutExactlyFourPartsIsNotParsed(): void
    {
        static::assertNull(TaskKey::parse(''));
        static::assertNull(TaskKey::parse('(other)'));
        static::assertNull(TaskKey::parse("default\x1eab12\x1eapp:work"));
        static::assertNull(TaskKey::parse("default\x1eab12\x1eapp:work\x1eevery 10 seconds\x1ex"));
    }

    public function testATaskKeyIsReadBackAsTheUnitKeyOfAnEntryPoint(): void
    {
        $task = new TaskKey('default', 'ab12', "app:\x1fwork", 'every 10 seconds');
        $key = (new EntryPointKey(Entry::TYPE_TASK, $task->toString(), \RuntimeException::class))->toString();

        $entryPoint = EntryPointKey::parse($key);

        static::assertNotNull($entryPoint);
        static::assertSame(Entry::TYPE_TASK, $entryPoint->unitType);
        static::assertSame(\RuntimeException::class, $entryPoint->subject);
        static::assertEquals($task, TaskKey::parse($entryPoint->unitKey));
    }
}
