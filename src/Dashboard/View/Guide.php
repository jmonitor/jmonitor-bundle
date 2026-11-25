<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Guide
{
    /** @param list<GuideItem> $items one to three */
    public function __construct(public readonly array $items)
    {
        if (\count($items) === 0 || \count($items) > 3) {
            throw new \LogicException(sprintf('A guide holds one to three items, %d given.', \count($items)));
        }
    }
}
