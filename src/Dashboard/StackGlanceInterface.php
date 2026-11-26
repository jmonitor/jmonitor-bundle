<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;

/**
 * What runs the application, as a screen outside the stack shows it: read without any slow read.
 */
interface StackGlanceInterface
{
    public function glance(): StackGlance;
}
