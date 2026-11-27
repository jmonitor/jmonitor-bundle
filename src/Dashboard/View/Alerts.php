<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class Alerts
{
    /** @param list<Alert> $alerts in the order they are shown */
    public function __construct(public readonly array $alerts) {}
}
