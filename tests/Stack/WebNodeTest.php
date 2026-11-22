<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack;

use Jmonitor\JmonitorBundle\Stack\WebNode;
use PHPUnit\Framework\TestCase;

final class WebNodeTest extends TestCase
{
    public function testItIsNamedAfterTheHostByDefault(): void
    {
        self::assertSame((string) gethostname(), (new WebNode())->name);
    }

    public function testItsEntriesAreKeyedByItsEncodedName(): void
    {
        self::assertSame('%node.web-1.', (new WebNode('web-1'))->keyPrefix());
        self::assertSame('%node.web%201.', (new WebNode('web 1'))->keyPrefix());
        self::assertSame('%node.web%2F1%40dc.', (new WebNode('web/1@dc'))->keyPrefix(), 'PSR-6 reserves / and @');
    }

    public function testAServerWithoutNameSharesTheEntries(): void
    {
        $node = new WebNode('');

        self::assertNull($node->name);
        self::assertSame('', $node->keyPrefix());
    }
}
