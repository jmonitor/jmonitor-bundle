<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Jmonitor\JmonitorBundle\Recording\Recorder;

final class Driver extends AbstractDriverMiddleware
{
    private static ?bool $dbal3 = null;

    public function __construct(DriverInterface $driver, private readonly Recorder $recorder)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        self::$dbal3 ??= !(new \ReflectionMethod(AbstractConnectionMiddleware::class, 'commit'))->hasReturnType();

        return self::$dbal3
            ? new Dbal3Connection(parent::connect($params), $this->recorder)
            : new Connection(parent::connect($params), $this->recorder);
    }
}
