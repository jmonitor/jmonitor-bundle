<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Psr\Container\ContainerInterface;
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

    /** @var array<string, RedisTarget|\RuntimeException> */
    private array $targets = [];

    /**
     * @param ContainerInterface $dsns       an \ArrayObject per entry, which holds its DSN
     * @param list<string>       $configured the ids of jmonitor.stack.redis.connections
     * @param list<string>       $found      the entries of the cache, of the sessions and of REDIS_URL, in this order
     */
    public function __construct(
        private readonly ContainerInterface $dsns,
        private readonly array $configured,
        private readonly array $found,
    ) {}

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
