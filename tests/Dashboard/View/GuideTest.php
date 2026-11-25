<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard\View;

use Jmonitor\JmonitorBundle\Dashboard\View\Guide;
use Jmonitor\JmonitorBundle\Dashboard\View\GuideItem;
use PHPUnit\Framework\TestCase;

final class GuideTest extends TestCase
{
    public function testAGuideHoldsThreeItemsAtMost(): void
    {
        $item = new GuideItem('Term', 'Text');

        static::assertCount(3, (new Guide([$item, $item, $item]))->items);

        $this->expectException(\LogicException::class);

        new Guide([$item, $item, $item, $item]);
    }

    public function testAGuideHoldsAtLeastOneItem(): void
    {
        $this->expectException(\LogicException::class);

        new Guide([]);
    }
}
