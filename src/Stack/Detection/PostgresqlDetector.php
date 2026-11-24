<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Component;

/**
 * The PostgreSQL database behind each Doctrine connection of the PostgreSQL driver.
 */
final readonly class PostgresqlDetector implements ComponentDetectorInterface
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
        return Component::Postgresql;
    }

    public function detect(): Detection
    {
        return $this->instances->detect(Component::Postgresql, 'postgresql', 'PostgreSQL', $this->connections, static fn(): array => [], defaultPort: 5432);
    }
}
