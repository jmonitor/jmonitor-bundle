<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

enum Space: string
{
    case Application = 'application';
    case Background = 'background';
    case Stack = 'stack';

    public function label(): string
    {
        return match ($this) {
            self::Application => 'Application',
            self::Background => 'Background',
            self::Stack => 'Stack',
        };
    }
}
