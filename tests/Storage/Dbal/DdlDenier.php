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
     * @param array<string, mixed>  $params
     * @param (\Closure(): void)|null $beforeFirstDenial runs once, just before the first refusal
     */
    public static function connect(array $params, ?\Closure $beforeFirstDenial = null): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new self($beforeFirstDenial)]);

        return DriverManager::getConnection($params, $configuration);
    }

    /** @param (\Closure(): void)|null $beforeFirstDenial */
    private function __construct(private ?\Closure $beforeFirstDenial) {}

    public function wrap(Driver $driver): Driver
    {
        $denier = $this;

        return new class ($driver, $denier) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly DdlDenier $denier)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new DdlDenyingConnection(parent::connect($params), $this->denier);
            }
        };
    }

    public function deny(string $sql): void
    {
        if (preg_match('/^\s*(DROP|CREATE)\b/i', $sql) !== 1) {
            return;
        }

        $before = $this->beforeFirstDenial;
        $this->beforeFirstDenial = null;
        $before?->__invoke();

        throw new \RuntimeException('Permission denied: ' . $sql);
    }
}

final class DdlDenyingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly DdlDenier $denier)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->denier->deny($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->denier->deny($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        $this->denier->deny($sql);

        return (int) parent::exec($sql);
    }
}
