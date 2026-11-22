<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

interface NavBadgeScreenInterface extends ScreenInterface
{
    /** Called on every page of every screen: at most one cache read. */
    public function navBadge(): ?NavBadge;
}
