<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\RedisLink;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Redis DSN of the configuration, or those the application uses, read at run time without opening a connection.
 */
final class RedisDsns implements ResetInterface
{
    /** The entry of the REDIS_URL environment variable, the last one found. */
    public const ENVIRONMENT = 'REDIS_URL';

    private const NONE = 'This connection has no DSN.';

    private const OPTIONS = ['lazy' => true, 'timeout' => 1, 'read_timeout' => 2];

    /** Options of a DSN that would keep the connection open after the read, or let it wait longer than OPTIONS do. */
    private const DROPPED = ['timeout', 'read_timeout', 'retry_interval', 'persistent', 'persistent_id', 'lazy', 'class'];

    /** @var array<string, RedisTarget|\RuntimeException> */
    private array $targets = [];

    /** @var \Closure(string, array<string, mixed>): object */
    private readonly \Closure $factory;

    /**
     * @param ContainerInterface                                $dsns       an \ArrayObject per entry, which holds its DSN
     * @param list<string>                                      $configured the ids of jmonitor.stack.redis.connections
     * @param list<string>                                      $found      the entries of the cache, of the sessions and of REDIS_URL, in this order
     * @param ?\Closure(string, array<string, mixed>): object $factory    RedisAdapter::createConnection() without it
     */
    public function __construct(
        private readonly ContainerInterface $dsns,
        private readonly array $configured,
        private readonly array $found,
        ?\Closure $factory = null,
    ) {
        $this->factory = $factory ?? RedisAdapter::createConnection(...);
    }

    /**
     * @return list<string>
     */
    public function configured(): array
    {
        return $this->configured;
    }

    /**
     * @return list<string>
     */
    public function found(): array
    {
        return $this->found;
    }

    /**
     * @throws \RuntimeException the DSN cannot be read, or is not one of a Redis server; its message carries no part of it
     */
    public function target(string $entry): RedisTarget
    {
        $target = $this->targets[$entry] ??= $this->read($entry);

        if ($target instanceof \RuntimeException) {
            throw $target;
        }

        return $target;
    }

    /**
     * A link that opens a connection of its own to the server of this entry, at its first read only.
     */
    public function link(string $entry): RedisLink
    {
        return new RedisLink(fn(): object => ($this->factory)(self::opened($this->dsn($entry)), self::OPTIONS));
    }

    public function reset(): void
    {
        $this->targets = [];
    }

    private function read(string $entry): RedisTarget|\RuntimeException
    {
        try {
            return RedisTarget::of($this->dsn($entry));
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    /**
     * Symfony 6.4 does not know the schemes of Valkey, which only differ from those of Redis by their name.
     */
    private static function opened(string $dsn): string
    {
        $dsn = preg_replace('/^valkey(s?):/', 'redis$1:', $dsn) ?? $dsn;
        [$address, $query] = explode('?', $dsn, 2) + [1 => null];

        if ($query === null) {
            return $dsn;
        }

        [$query, $fragment] = explode('#', $query, 2) + [1 => null];
        $kept = array_filter(explode('&', $query), static function (string $pair): bool {
            parse_str($pair, $option);

            return array_intersect(array_map(strval(...), array_keys($option)), self::DROPPED) === [];
        });

        return $address . ($kept === [] ? '' : '?' . implode('&', $kept)) . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * @throws \RuntimeException
     */
    private function dsn(string $entry): string
    {
        try {
            $holder = $this->dsns->get($entry);
        } catch (EnvNotFoundException $e) {
            throw new \RuntimeException(preg_match('/"([^"]+)"/', $e->getMessage(), $variable) === 1
                ? \sprintf('%s: an environment variable is missing ("%s").', RedisTarget::UNREADABLE, $variable[1])
                : RedisTarget::UNREADABLE . ': an environment variable is missing.');
        } catch (\Throwable) {
            throw new \RuntimeException(RedisTarget::UNREADABLE . '.');
        }

        $dsn = $holder instanceof \ArrayObject && $holder->count() === 1 ? $holder[0] : false;

        if ($dsn === null || $dsn === '') {
            throw new \RuntimeException(self::NONE);
        }

        if (!\is_string($dsn)) {
            throw new \RuntimeException(RedisTarget::UNREADABLE . '.');
        }

        return $dsn;
    }
}
