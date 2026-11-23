<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * Runs the queries of the collectors of one connection, which it only asks for at the first query.
 * Once the server could not be reached, every later query fails at once instead of waiting again;
 * a query refused on a live connection leaves the others alone.
 */
final class DbalLink implements DatabaseAdapterInterface
{
    private const READ_TIMEOUT = 'mysqlnd.net_read_timeout';

    /** What a PostgreSQL connection says once it is lost, while DBAL 3 still tells it connected. */
    private const LOST = ['no connection to the server', 'server closed the connection', 'terminating connection'];

    private ?Connection $connection = null;

    private ?UnreachableServerException $failure = null;

    private bool $opened = false;

    /**
     * @param \Closure(): Connection $connect
     * @param int                    $readTimeout seconds a query waits for a server that stopped answering, set while the connection opens
     * @param ?\Closure(): bool      $built       whether the connection was built before this link asked for it; without it, it was
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly int $readTimeout = 10,
        private readonly ?\Closure $built = null,
    ) {}

    /**
     * @param array<mixed> $params
     *
     * @return list<array<string, mixed>>
     *
     * @throws UnreachableServerException once the connection cannot be used: thrown again at once by every later query
     */
    public function fetchAllAssociative(string $query, array $params = []): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            $connection = $this->connection ??= $this->connect();
        } catch (\Throwable $e) {
            throw $this->failure = new UnreachableServerException($e->getMessage(), 0, $e);
        }

        $wasConnected = self::isConnected($connection);

        try {
            try {
                return $this->run($connection, $query, $params);
            } catch (ConnectionLost $e) {
                if (!$wasConnected) {
                    throw $e;
                }

                return $this->run($connection, $query, $params);
            }
        } catch (\Throwable $e) {
            throw $this->failed($connection, $e);
        }
    }

    /**
     * Closes the connection when this link opened it.
     */
    public function release(): void
    {
        if ($this->opened && $this->connection !== null) {
            $this->opened = false;
            $this->connection->close();
        }
    }

    /**
     * A connection that building it opened, to read the server version, counts as opened by this link.
     */
    private function connect(): Connection
    {
        $built = $this->built === null || ($this->built)();
        $connection = $this->bounded($this->connect);
        $this->opened = !$built && self::isConnected($connection);

        return $connection;
    }

    /**
     * @param array<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function run(Connection $connection, string $query, array $params): array
    {
        if (self::isConnected($connection)) {
            return $connection->fetchAllAssociative($query, $params);
        }

        try {
            return $this->bounded(static function () use ($connection, $query, $params): array {
                if ($connection instanceof PrimaryReadReplicaConnection) {
                    $connection->ensureConnectedToPrimary();
                }

                return $connection->fetchAllAssociative($query, $params);
            });
        } finally {
            $this->opened = $this->opened || self::isConnected($connection);
        }
    }

    /**
     * Bounds the wait on a server that accepts the connection and then stops answering, for the life of a connection opened meanwhile.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function bounded(\Closure $call): mixed
    {
        $previous = ini_set(self::READ_TIMEOUT, (string) $this->readTimeout);

        try {
            return $call();
        } finally {
            if ($previous !== false) {
                ini_set(self::READ_TIMEOUT, $previous);
            }
        }
    }

    private function failed(Connection $connection, \Throwable $e): \Throwable
    {
        $lost = false;
        foreach (self::LOST as $message) {
            $lost = $lost || str_contains($e->getMessage(), $message);
        }

        if ($lost) {
            $this->opened = false;
            $connection->close();
        } elseif (self::isConnected($connection)) {
            return $e;
        }

        return $this->failure = new UnreachableServerException($e->getMessage(), 0, $e);
    }

    private static function isConnected(Connection $connection): bool
    {
        // DBAL 3 declares no return type on these methods
        return ($connection instanceof PrimaryReadReplicaConnection ? $connection->isConnectedToPrimary() : $connection->isConnected()) === true;
    }
}
