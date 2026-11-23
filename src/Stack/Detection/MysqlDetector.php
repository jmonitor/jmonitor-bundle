<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;

/**
 * A MySQL or MariaDB server behind each Doctrine connection of the MySQL driver.
 */
final readonly class MysqlDetector implements ComponentDetectorInterface
{
    /**
     * @param array<string, array<string, mixed>> $connections the names of the connections to read; empty: all of them
     */
    public function __construct(
        private DatabaseInstances $instances,
        private array $connections,
    ) {}

    public function component(): Component
    {
        return Component::Mysql;
    }

    public function detect(): Detection
    {
        return $this->instances->detect(Component::Mysql, 'mysql', 'MySQL', $this->connections, static fn(): array => [], byServer: true, defaultPort: 3306);
    }
}
