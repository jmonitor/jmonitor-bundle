<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Doctrine\DBAL\Connection;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Component;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The instances of a database component, one per Doctrine connection, found without building any.
 */
final readonly class DatabaseInstances
{
    private const NO_DBAL = 'No Doctrine DBAL connection is configured.';

    private const UNREADABLE_SHOWN = 3;

    /**
     * @param ContainerInterface    $container where the Doctrine connections are built
     * @param array<string, string> $ids       the service id of each Doctrine connection, under its name
     * @param list<string>          $exclude   fnmatch patterns of connections to leave out
     */
    public function __construct(
        private DoctrineConnections $connections,
        private ContainerInterface $container,
        private array $ids,
        private array $exclude,
    ) {}

    /**
     * @param array<string, array<string, mixed>>                                            $configured name => options; empty: every connection of the driver
     * @param \Closure(ConnectionTarget, DbalLink, array<string, mixed>): list<CollectorSpec> $collectors
     */
    public function detect(Component $component, string $driver, string $product, array $configured, \Closure $collectors, bool $byServer, int $defaultPort): Detection
    {
        $names = $this->connections->names();

        if ($configured !== []) {
            $instances = [];

            foreach ($configured as $name => $options) {
                $instances[] = $this->configured($component, (string) $name, $options, $driver, $product, $collectors, $byServer, $defaultPort);
            }

            return Detection::instances($instances);
        }

        if ($names === []) {
            return Detection::unavailable(self::NO_DBAL);
        }

        $instances = [];
        $unreadable = [];

        foreach ($names as $name) {
            if ($this->isExcluded($name)) {
                continue;
            }

            try {
                $target = $this->connections->target($name);
            } catch (\RuntimeException $e) {
                $unreadable[$name] = $e->getMessage();

                continue;
            }

            if ($target->driver === $driver) {
                $instances[] = $this->found($component, $target, [], $collectors, $byServer, $defaultPort);
            }
        }

        if ($instances === []) {
            return Detection::unavailable(self::noneFound($product, $unreadable));
        }

        return Detection::instances($instances);
    }

    /**
     * @param array<string, mixed>                                                           $options
     * @param \Closure(ConnectionTarget, DbalLink, array<string, mixed>): list<CollectorSpec> $collectors
     */
    private function configured(Component $component, string $name, array $options, string $driver, string $product, \Closure $collectors, bool $byServer, int $defaultPort): Instance
    {
        if ($this->connections->names() === []) {
            return Instance::unavailable($component, self::NO_DBAL, $name);
        }

        if (!\in_array($name, $this->connections->names(), true)) {
            return Instance::unavailable($component, \sprintf('Unknown Doctrine connection "%s".', $name), $name);
        }

        try {
            $target = $this->connections->target($name);
        } catch (\RuntimeException $e) {
            return Instance::unavailable($component, $e->getMessage(), $name);
        }

        if ($target->driver !== $driver) {
            return Instance::unavailable($component, \sprintf('Doctrine connection "%s" is not a %s connection.', $name, $product), $name, $target->database);
        }

        return $this->found($component, $target, $options, $collectors, $byServer, $defaultPort);
    }

    /**
     * @param array<string, mixed>                                                           $options
     * @param \Closure(ConnectionTarget, DbalLink, array<string, mixed>): list<CollectorSpec> $collectors
     */
    private function found(Component $component, ConnectionTarget $target, array $options, \Closure $collectors, bool $byServer, int $defaultPort): Instance
    {
        $container = $this->container;
        $name = $target->name;
        $id = $this->ids[$name] ?? null;
        $link = new DbalLink(
            static function () use ($container, $id, $name): Connection {
                if ($id === null) {
                    throw new \LogicException(\sprintf('No service is known for the Doctrine connection "%s".', $name));
                }

                $connection = $container->get($id);
                \assert($connection instanceof Connection);

                return $connection;
            },
            built: static fn(): bool => $id !== null && $container->initialized($id),
            persistent: $target->persistent,
        );

        return Instance::found(
            $component,
            new CollectorSet($component, $collectors($target, $link, $options), $link->release(...)),
            $name,
            $target->database,
            $byServer ? $target->server($defaultPort) : null,
        );
    }

    private function isExcluded(string $name): bool
    {
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $unreadable name => why its parameters cannot be read
     */
    private static function noneFound(string $product, array $unreadable): string
    {
        $error = \sprintf('No %s Doctrine connection found.', $product);

        foreach (\array_slice($unreadable, 0, self::UNREADABLE_SHOWN, true) as $name => $reason) {
            $error .= \sprintf(' Connection "%s" could not be read: %s', $name, $reason);
        }

        if (\count($unreadable) > self::UNREADABLE_SHOWN) {
            $error .= \sprintf(' %d more could not be read.', \count($unreadable) - self::UNREADABLE_SHOWN);
        }

        return $error;
    }
}
