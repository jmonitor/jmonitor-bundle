<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Symfony\Component\Cache\Exception\CacheException;

/**
 * Reads INFO through a client of its own, which it only asks for at the first read and closes on release.
 * Once a read failed, every later one fails at once with the same reason.
 * No reason copies what the client said: it may name the user, the host or the port.
 */
final class RedisLink
{
    private const UNREACHABLE = 'The Redis server cannot be reached: the connection is refused, cannot be opened or gets no answer in time.';
    private const PASSWORD = 'The Redis server asks for a password, or refuses the one given.';
    private const NO_INFO = 'The Redis server did not answer INFO.';
    private const CLIENT_FAILED = 'The Redis client failed while reading the server.';
    private const DENIED = 'The Redis user of this connection may not run INFO.';
    private const NO_CLIENT = 'Reading Redis needs the redis extension or the predis/predis package.';

    private ?object $client = null;

    /** The client got an answer from the server: its connection is open. */
    private bool $connected = false;

    private ?\RuntimeException $failure = null;

    /**
     * @param \Closure(): object $connect a client that only connects at its first command
     */
    public function __construct(
        private readonly \Closure $connect,
    ) {}

    /**
     * @return array<string, mixed> INFO, flat: the sections of Predis are merged, and each database is told as the redis extension tells it
     *
     * @throws UnreachableServerException
     * @throws MissingPrerequisiteException
     */
    public function info(): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            $info = self::silently(function (): mixed {
                $this->client ??= ($this->connect)();

                return $this->client->info();
            });
        } catch (\Throwable $e) {
            throw $this->failed($e);
        }

        $this->connected = true;

        if (!\is_array($info) || $info === []) {
            throw $this->fail(self::refusal($info));
        }

        return self::flat($info);
    }

    /**
     * Closes the connection it opened.
     */
    public function release(): void
    {
        $client = $this->client;
        $this->client = null;

        if ($client === null || !$this->connected) {
            return;
        }

        $this->connected = false;

        try {
            self::silently(static function () use ($client): void {
                if (method_exists($client, 'close')) {
                    $client->close();
                } elseif (method_exists($client, 'disconnect')) {
                    $client->disconnect();
                }
            });
        } catch (\Throwable) {
        }
    }

    private function failed(\Throwable $e): \RuntimeException
    {
        if ($this->client === null && $e instanceof CacheException) {
            return $this->fail(new MissingPrerequisiteException(self::NO_CLIENT));
        }

        if (self::code($e->getMessage()) === 'NOPERM') {
            $this->connected = true;

            return $this->fail(new MissingPrerequisiteException(self::DENIED));
        }

        $this->client = null;

        return $this->fail(new UnreachableServerException(match (true) {
            self::code($e->getMessage()) !== null => self::PASSWORD,
            $e instanceof \Error => self::CLIENT_FAILED,
            default => self::UNREACHABLE,
        }));
    }

    private function fail(\RuntimeException $failure): \RuntimeException
    {
        return $this->failure = $failure;
    }

    /**
     * Predis gives the error replies instead of throwing them.
     */
    private static function refusal(mixed $answer): \RuntimeException
    {
        $code = \is_object($answer) && method_exists($answer, 'getMessage') ? self::code((string) $answer->getMessage()) : null;

        return match ($code) {
            'NOPERM' => new MissingPrerequisiteException(self::DENIED),
            null => new UnreachableServerException(self::NO_INFO),
            default => new UnreachableServerException(self::PASSWORD),
        };
    }

    /**
     * The error code of a refusal of Redis the message carries, wherever the client put it.
     */
    private static function code(string $message): ?string
    {
        return preg_match('/(?<![\w-])(NOAUTH|WRONGPASS|NOPERM)(?![\w-])/', $message, $code) === 1 ? $code[1] : null;
    }

    /**
     * @param array<mixed> $info
     *
     * @return array<string, mixed>
     */
    private static function flat(array $info): array
    {
        $flat = [];

        foreach ($info as $key => $value) {
            foreach (\is_array($value) ? $value : [$key => $value] as $name => $item) {
                $flat[(string) $name] = \is_array($item) ? implode(',', array_map(static fn(string|int $field, mixed $figure): string => $field . '=' . (\is_scalar($figure) ? (string) $figure : ''), array_keys($item), $item)) : $item;
            }
        }

        return $flat;
    }

    /**
     * Keeps a warning of the client, as Predis gives on a failed TLS handshake, from the handler of the application.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private static function silently(\Closure $call): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
