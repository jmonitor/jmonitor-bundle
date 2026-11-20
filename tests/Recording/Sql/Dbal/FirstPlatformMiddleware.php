<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Recording\Sql\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

final class FirstPlatformMiddleware implements Middleware
{
    /**
     * @param \Closure(): AbstractPlatform $first gives or throws in place of the first platform lookup
     */
    public function __construct(private readonly \Closure $first) {}

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->first) extends AbstractDriverMiddleware {
            /** @var (\Closure(): AbstractPlatform)|null */
            private ?\Closure $first;

            /**
             * @param \Closure(): AbstractPlatform $first
             */
            public function __construct(Driver $driver, \Closure $first)
            {
                parent::__construct($driver);
                $this->first = $first;
            }

            public function getDatabasePlatform(?ServerVersionProvider $versionProvider = null): AbstractPlatform
            {
                if ($this->first !== null) {
                    $first = $this->first;
                    $this->first = null;

                    return $first();
                }

                return parent::getDatabasePlatform(...\func_get_args());
            }
        };
    }
}
