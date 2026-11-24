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
    case Mysql = 'mysql';
    case Postgresql = 'postgresql';
    case Redis = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Server',
            self::Php => 'PHP',
            self::Symfony => 'Symfony',
            self::FrankenPhp => 'FrankenPHP',
            self::Apache => 'Apache',
            self::Nginx => 'Nginx',
            self::Mysql => 'MySQL',
            self::Postgresql => 'PostgreSQL',
            self::Redis => 'Redis',
        };
    }

    public function isWebServer(): bool
    {
        return match ($this) {
            self::FrankenPhp, self::Apache, self::Nginx => true,
            self::System, self::Php, self::Symfony, self::Mysql, self::Postgresql, self::Redis => false,
        };
    }

    /**
     * What its main collector reads is the server's, the same through each of its connections.
     */
    public function groupsByServer(): bool
    {
        return $this === self::Mysql;
    }

    /**
     * Read on the web server answering the request: behind a load balancer, each one has its own values.
     */
    public function isNodeLocal(): bool
    {
        return !\in_array($this, [self::Mysql, self::Postgresql, self::Redis], true);
    }
}
