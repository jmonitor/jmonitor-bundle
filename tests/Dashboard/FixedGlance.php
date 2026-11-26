<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;

final class FixedGlance implements StackGlanceInterface
{
    public int $calls = 0;

    /** @param StackGlance|\Closure(): StackGlance $glance */
    public function __construct(private readonly StackGlance|\Closure $glance = new StackGlance()) {}

    public function glance(): StackGlance
    {
        ++$this->calls;

        return $this->glance instanceof \Closure ? ($this->glance)() : $this->glance;
    }
}
