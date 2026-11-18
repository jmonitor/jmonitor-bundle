<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Jmonitor\JmonitorBundle\Recording\Recorder;

final class Middleware implements MiddlewareInterface
{
    public function __construct(private readonly Recorder $recorder) {}

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->recorder);
    }
}
