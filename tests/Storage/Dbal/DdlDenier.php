<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Storage\Dbal;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;

final class DdlDenier implements Middleware
{
    /**
     * @param array<string, mixed> $params
     */
    public static function connect(array $params): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new self()]);

        return DriverManager::getConnection($params, $configuration);
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                return new DdlDenyingConnection(parent::connect($params));
            }
        };
    }
}

final class DdlDenyingConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        self::deny($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        self::deny($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        self::deny($sql);

        return (int) parent::exec($sql);
    }

    private static function deny(string $sql): void
    {
        if (preg_match('/^\s*(DROP|CREATE)\b/i', $sql) === 1) {
            throw new \RuntimeException('Permission denied: ' . $sql);
        }
    }
}
