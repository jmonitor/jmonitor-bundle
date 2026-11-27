<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

enum Space: string
{
    case Overview = 'overview';
    case Application = 'application';
    case Stack = 'stack';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Application => 'Application',
            self::Stack => 'Stack',
        };
    }
}
