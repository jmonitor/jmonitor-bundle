<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack;

enum Component: string
{
    case System = 'system';
    case Php = 'php';
    case Symfony = 'symfony';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Server',
            self::Php => 'PHP',
            self::Symfony => 'Symfony',
        };
    }

    /**
     * Read on the web server answering the request: behind a load balancer, each one has its own values.
     */
    public function isNodeLocal(): bool
    {
        return true;
    }
}
