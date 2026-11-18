<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Aggregation;

use Jmonitor\JmonitorBundle\Aggregation\NPlusOneKey;
use Jmonitor\JmonitorBundle\Recording\Entry;
use PHPUnit\Framework\TestCase;

final class NPlusOneKeyTest extends TestCase
{
    public function testAKeyIsParsedBackIntoItsParts(): void
    {
        $key = (new NPlusOneKey(Entry::TYPE_REQUEST, 'GET app_list', 'a1b2c3d4e5f6a7b8'))->toString();

        static::assertSame('request' . Entry::KEY_SEPARATOR . 'GET app_list' . Entry::KEY_SEPARATOR . 'a1b2c3d4e5f6a7b8', $key);
        static::assertEquals(new NPlusOneKey(Entry::TYPE_REQUEST, 'GET app_list', 'a1b2c3d4e5f6a7b8'), NPlusOneKey::parse($key));
    }

    public function testAKeyWithTooFewOrTooManyPartsIsNotParsed(): void
    {
        static::assertNull(NPlusOneKey::parse(''));
        static::assertNull(NPlusOneKey::parse('(other)'));
        static::assertNull(NPlusOneKey::parse('request' . Entry::KEY_SEPARATOR . 'a1b2c3d4e5f6a7b8'));
        static::assertNull(NPlusOneKey::parse(implode(Entry::KEY_SEPARATOR, ['request', 'GET app_list', 'a1b2c3d4e5f6a7b8', 'x'])));
    }

    public function testAUnitKeyHoldingTheSeparatorGivesAKeyThatIsNotParsed(): void
    {
        $key = (new NPlusOneKey(Entry::TYPE_REQUEST, 'GET app' . Entry::KEY_SEPARATOR . 'list', 'a1b2c3d4e5f6a7b8'))->toString();

        static::assertNull(NPlusOneKey::parse($key));
    }
}
