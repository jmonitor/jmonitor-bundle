<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack;

enum Component: string
{
    case System = 'system';
    case Php = 'php';
    case Symfony = 'symfony';
    case FrankenPhp = 'frankenphp';
    case Apache = 'apache';
    case Nginx = 'nginx';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Server',
            self::Php => 'PHP',
            self::Symfony => 'Symfony',
            self::FrankenPhp => 'FrankenPHP',
            self::Apache => 'Apache',
            self::Nginx => 'Nginx',
        };
    }

    public function isWebServer(): bool
    {
        return match ($this) {
            self::FrankenPhp, self::Apache, self::Nginx => true,
            self::System, self::Php, self::Symfony => false,
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
