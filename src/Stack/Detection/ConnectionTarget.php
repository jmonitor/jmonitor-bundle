<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

/**
 * Where a Doctrine connection leads: never its user nor its password.
 */
final readonly class ConnectionTarget
{
    /**
     * @param ?string $driver     "mysql", "postgresql", null for another driver
     * @param bool    $persistent closing it hands it back to the PHP process instead of ending it
     */
    public function __construct(
        public string $name,
        public ?string $driver,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $socket,
        public bool $persistent = false,
    ) {}

    public function server(int $defaultPort): Server
    {
        if ($this->socket !== null) {
            return new Server('socket:' . $this->socket, $this->socket);
        }

        $host = strtolower(trim($this->host ?? '', '[]'));
        $host = $host === '' ? 'localhost' : $host;
        $port = $this->port ?? $defaultPort;

        return new Server($host . ':' . $port, (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port);
    }
}
