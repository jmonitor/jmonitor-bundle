<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Postgresql\PostgresqlSettingsCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

final readonly class PostgresqlSettingsCollector implements CollectorInterface
{
    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array<string, mixed> the settings by name, sizes in bytes
     *
     * @throws UnreachableServerException
     */
    public function collect(): array
    {
        $settings = new LibraryCollector($this->db);

        try {
            $settings->boot();

            return $settings->collect();
        } catch (\Throwable $e) {
            throw PostgresqlFailure::of($e);
        }
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db))->getVersion();
    }

    public function getName(): string
    {
        return 'postgresql.settings';
    }
}
