<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\MysqlStatusCollector as LibraryCollector;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

final readonly class MysqlStatusCollector implements CollectorInterface
{
    public function __construct(
        private DatabaseAdapterInterface $db,
    ) {}

    /**
     * @return array<string, mixed> the global status, by variable name
     *
     * @throws UnreachableServerException
     */
    public function collect(): array
    {
        $status = new LibraryCollector($this->db);

        try {
            $status->boot();

            return $status->collect();
        } catch (\Throwable $e) {
            throw MysqlFailure::of($e);
        }
    }

    public function getVersion(): int
    {
        return (new LibraryCollector($this->db))->getVersion();
    }

    public function getName(): string
    {
        return 'mysql';
    }
}
