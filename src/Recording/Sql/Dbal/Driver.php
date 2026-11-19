<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;

final class Driver extends AbstractDriverMiddleware
{
    private static ?bool $dbal3 = null;

    private ?SqlDialect $dialect = null;

    public function __construct(DriverInterface $driver, private readonly Recorder $recorder)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        self::$dbal3 ??= !(new \ReflectionMethod(AbstractConnectionMiddleware::class, 'commit'))->hasReturnType();

        $connection = parent::connect($params);
        $this->dialect ??= $this->dialect($connection);

        return self::$dbal3
            ? new Dbal3Connection($connection, $this->recorder, $this->dialect)
            : new Connection($connection, $this->recorder, $this->dialect);
    }

    private function dialect(ConnectionInterface $connection): SqlDialect
    {
        try {
            $platform = $this->getDatabasePlatform($connection);
        } catch (\Throwable) {
            return SqlDialect::Unknown;
        }

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => SqlDialect::MySql,
            $platform instanceof PostgreSQLPlatform => SqlDialect::PostgreSql,
            $platform instanceof SQLitePlatform => SqlDialect::Sqlite,
            default => SqlDialect::Unknown,
        };
    }
}
