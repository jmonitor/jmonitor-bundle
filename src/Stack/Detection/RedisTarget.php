<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

/**
 * Where a Redis DSN leads: never its user nor its password.
 */
final readonly class RedisTarget
{
    public const NOT_REDIS = 'This DSN is not a Redis DSN.';
    public const UNREADABLE = 'The DSN of this connection cannot be read';

    private const SEVERAL_SERVERS = 'A Redis Cluster, a Sentinel or several hosts are not read.';
    private const SCHEMES = ['redis', 'rediss', 'valkey', 'valkeys'];
    private const DEFAULT_PORT = 6379;

    public function __construct(
        public string $scheme,
        public ?string $host,
        public ?int $port,
        public ?string $socket,
    ) {}

    /**
     * @throws \RuntimeException the DSN is not read; its message carries no part of it
     */
    public static function of(string $dsn): self
    {
        $scheme = null;
        foreach (self::SCHEMES as $candidate) {
            if (str_starts_with($dsn, $candidate . ':')) {
                $scheme = $candidate;
            }
        }

        if ($scheme === null) {
            throw new \RuntimeException(self::NOT_REDIS);
        }

        $rest = substr($dsn, \strlen($scheme) + 1);
        $slashes = str_starts_with($rest, '//');
        $rest = $slashes ? substr($rest, 2) : $rest;
        $end = strcspn($rest, '/?#');

        if (str_contains(substr($rest, $end), '@')) {
            throw new \RuntimeException(self::UNREADABLE . '.');
        }

        $authority = substr($rest, 0, $end);
        $at = strrpos($authority, '@');
        $host = $at === false ? $authority : substr($authority, $at + 1);
        $rest = substr($rest, $end);
        $rest = explode('#', $rest, 2)[0];
        [$path, $query] = explode('?', $rest, 2) + [1 => ''];
        parse_str($query, $options);

        if (self::readsSeveralServers($options)) {
            throw new \RuntimeException(self::SEVERAL_SERVERS);
        }

        if (!$slashes) {
            [$host, $path] = ['', $host . $path];
        }

        if ($host === '') {
            $socket = preg_replace('#/(\d+)?$#', '', $path) ?? '';

            if ($socket === '') {
                throw new \RuntimeException(self::UNREADABLE . '.');
            }

            return new self($scheme, null, null, $socket);
        }

        if (preg_match('#^(?:\[([^\[\]]+)\]|([^\[\]:]+))(?::(\d{1,5}))?$#', $host, $parts) !== 1) {
            throw new \RuntimeException(self::UNREADABLE . '.');
        }

        return new self($scheme, $parts[1] !== '' ? $parts[1] : $parts[2], isset($parts[3]) ? (int) $parts[3] : null, null);
    }

    /**
     * "host:port", the default port when the DSN names none, or "socket:<path>"
     */
    public function server(): Server
    {
        if ($this->socket !== null) {
            return new Server('socket:' . $this->socket, $this->socket);
        }

        $host = strtolower($this->host ?? '');
        $port = $this->port ?? self::DEFAULT_PORT;

        return new Server($host . ':' . $port, (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port);
    }

    /**
     * @param array<mixed> $options
     */
    private static function readsSeveralServers(array $options): bool
    {
        if (isset($options['host'])) {
            return true;
        }

        foreach (['redis_sentinel', 'sentinel', 'sentinel_master'] as $sentinel) {
            if (isset($options[$sentinel]) && $options[$sentinel] !== '') {
                return true;
            }
        }

        foreach (['redis_cluster', 'cluster'] as $cluster) {
            if (isset($options[$cluster]) && \is_string($options[$cluster]) && filter_var($options[$cluster], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) !== false) {
                return true;
            }
        }

        return false;
    }
}
