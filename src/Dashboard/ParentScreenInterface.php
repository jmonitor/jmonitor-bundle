<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

interface ParentScreenInterface extends ScreenInterface
{
    /** @param non-empty-string $id matching [a-z][a-z-]* */
    public function child(string $id): ?ScreenInterface;
}
