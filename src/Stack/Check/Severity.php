<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

enum Severity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Info => 'Info',
        };
    }
}
