<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the parameters DoctrineBundle gives its connections, without building one.
 */
final class DoctrineConnections implements ResetInterface
{
    private const SCHEMES = [
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    private const UNREADABLE = 'The parameters of this connection cannot be read';

    /** What a primary takes from its connection when it does not set it itself. */
    private const INHERITED = ['driver' => true, 'driverClass' => true, 'driverOptions' => true, 'dbname_suffix' => true];

    /** \PDO::ATTR_PERSISTENT, without requiring PDO */
    private const PDO_PERSISTENT = 12;

    /** @var array<string, ConnectionTarget|\RuntimeException> */
    private array $targets = [];

    /**
     * @param ContainerInterface $params the parameters of each connection, as an \ArrayObject under its name
     * @param list<string>       $names  in the order of the Doctrine configuration
     */
    public function __construct(
        private readonly ContainerInterface $params,
        private readonly array $names,
        private ?DsnParser $parser = null,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @throws \RuntimeException the parameters cannot be read; its message carries no value of theirs
     */
    public function target(string $name): ConnectionTarget
    {
        $target = $this->targets[$name] ??= $this->read($name);

        if ($target instanceof \RuntimeException) {
            throw $target;
        }

        return $target;
    }

    public function reset(): void
    {
        $this->targets = [];
    }

    private function read(string $name): ConnectionTarget|\RuntimeException
    {
        try {
            $params = $this->params($name);
        } catch (EnvNotFoundException $e) {
            return new \RuntimeException(preg_match('/"([^"]+)"/', $e->getMessage(), $variable) === 1
                ? \sprintf('%s: an environment variable is missing ("%s").', self::UNREADABLE, $variable[1])
                : self::UNREADABLE . ': an environment variable is missing.');
        } catch (MalformedDsnException) {
            return new \RuntimeException(self::UNREADABLE . ': its URL is malformed.');
        } catch (\Throwable) {
            return new \RuntimeException(self::UNREADABLE . '.');
        }

        if ($params === null) {
            return new \RuntimeException('The URL of this connection cannot be read.');
        }

        $database = $params['dbname'] ?? null;
        $database = \is_string($database) || \is_int($database) ? (string) $database : '';
        $suffix = $params['dbname_suffix'] ?? null;

        if ($database !== '' && (\is_string($suffix) || \is_int($suffix))) {
            $database .= $suffix;
        }

        $host = \is_string($params['host'] ?? null) && $params['host'] !== '' ? $params['host'] : null;
        $port = $params['port'] ?? null;
        $socket = $params['unix_socket'] ?? null;
        $options = $params['driverOptions'] ?? null;

        return new ConnectionTarget(
            $name,
            self::driver($params),
            $host,
            \is_int($port) || (\is_string($port) && ctype_digit($port)) ? (int) $port : null,
            $database !== '' ? $database : null,
            \is_string($socket) && $socket !== '' && ($host === null || strtolower($host) === 'localhost') ? $socket : null,
            !empty($params['persistent']) || (\is_array($options) && !empty($options[self::PDO_PERSISTENT])),
        );
    }

    /**
     * @return ?array<mixed> null when the credentials of its URL were cut short
     */
    private function params(string $name): ?array
    {
        $params = $this->params->get($name);

        if (!$params instanceof \ArrayObject) {
            throw new \UnexpectedValueException();
        }

        $params = $params->getArrayCopy();

        if (\is_array($params['primary'] ?? null)) {
            $params = $params['primary'] + array_intersect_key($params, self::INHERITED);
        }

        if (!isset($params['url'])) {
            return $params;
        }

        if (!\is_string($params['url'])) {
            throw new \UnexpectedValueException();
        }

        if (self::isCutShort($params['url'])) {
            return null;
        }

        $url = ($this->parser ??= new DsnParser(self::SCHEMES))->parse($params['url']);

        if (isset($url['driver'])) {
            unset($params['driverClass']);
        }

        return array_merge($params, $url);
    }

    /**
     * A password that is not URL-encoded and holds a "/", "?" or "#" ends the address of the server early:
     * the "@" that closes the credentials then comes after it, and the parser would read part of the password as a host.
     */
    private static function isCutShort(string $url): bool
    {
        $scheme = strpos($url, '://');
        $start = $scheme === false ? 0 : $scheme + 3;

        return str_contains(substr($url, $start + strcspn($url, '/?#', $start)), '@');
    }

    /**
     * @param array<mixed> $params
     */
    private static function driver(array $params): ?string
    {
        $class = $params['driverClass'] ?? null;

        if (\is_string($class) && $class !== '') {
            return match (true) {
                is_a($class, AbstractMySQLDriver::class, true) => 'mysql',
                is_a($class, AbstractPostgreSQLDriver::class, true) => 'postgresql',
                default => null,
            };
        }

        return match ($params['driver'] ?? null) {
            'pdo_mysql', 'mysqli' => 'mysql',
            'pdo_pgsql', 'pgsql' => 'postgresql',
            default => null,
        };
    }
}
