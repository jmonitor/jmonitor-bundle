<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\EntryPointKey;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;

final class EntryPointKeyTest extends TestCase
{
    public function testAKeyIsParsedBackIntoItsParts(): void
    {
        $key = (new EntryPointKey(Entry::TYPE_REQUEST, 'GET app_list', 'a1b2c3d4e5f6a7b8'))->toString();

        static::assertSame('request' . Entry::KEY_SEPARATOR . 'GET app_list' . Entry::KEY_SEPARATOR . 'a1b2c3d4e5f6a7b8', $key);
        static::assertEquals(new EntryPointKey(Entry::TYPE_REQUEST, 'GET app_list', 'a1b2c3d4e5f6a7b8'), EntryPointKey::parse($key));
        static::assertSame('a1b2c3d4e5f6a7b8', EntryPointKey::parse($key)?->subject);
    }

    public function testAKeyWithTooFewOrTooManyPartsIsNotParsed(): void
    {
        static::assertNull(EntryPointKey::parse(''));
        static::assertNull(EntryPointKey::parse('(other)'));
        static::assertNull(EntryPointKey::parse('request' . Entry::KEY_SEPARATOR . 'a1b2c3d4e5f6a7b8'));
        static::assertNull(EntryPointKey::parse(implode(Entry::KEY_SEPARATOR, ['request', 'GET app_list', 'a1b2c3d4e5f6a7b8', 'x'])));
    }

    public function testAUnitKeyHoldingTheSeparatorGivesAKeyThatIsNotParsed(): void
    {
        $key = (new EntryPointKey(Entry::TYPE_REQUEST, 'GET app' . Entry::KEY_SEPARATOR . 'list', 'a1b2c3d4e5f6a7b8'))->toString();

        static::assertNull(EntryPointKey::parse($key));
    }
}
