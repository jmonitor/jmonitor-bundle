<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;

interface BlockScreenInterface extends ScreenInterface
{
    /** @return list<Block> */
    public function blocks(ScreenRequest $request): array;
}
