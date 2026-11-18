<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Jmonitor\JmonitorBundle\Recording\Recorder;

final class Driver extends AbstractDriverMiddleware
{
    public function __construct(DriverInterface $driver, private readonly Recorder $recorder)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
    {
        return new Connection(parent::connect($params), $this->recorder);
    }
}
