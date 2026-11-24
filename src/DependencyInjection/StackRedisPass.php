<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the live stack the Redis DSN of its configuration, otherwise those the cache pools and the sessions are given,
 * and REDIS_URL: each one is only resolved when its entry is read, so a missing environment variable breaks that entry alone.
 */
final class StackRedisPass implements CompilerPassInterface
{
    private const CONNECTION = '.cache_connection.';
    private const SESSIONS = 'session.abstract_handler';
    private const ENTRY = 'jmonitor.stack.redis_dsn.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RedisDsns::class)) {
            return;
        }

        $definition = $container->getDefinition(RedisDsns::class);
        $configured = [];
        $found = [];

        if (!self::isOff($container)) {
            $configured = self::configured($definition);
            $found = $configured === [] ? self::cache($container) + self::sessions($container) + self::environment($container) : [];
        }

        $entries = [];
        foreach ([$configured, $found] as $dsns) {
            foreach ($dsns as $entry => $dsn) {
                $id = self::ENTRY . \count($entries);
                $container->setDefinition($id, (new Definition(\ArrayObject::class, [[$dsn]]))->setPublic(false));
                $entries[(string) $entry] = new Reference($id);
            }
        }

        $definition
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $entries))
            ->replaceArgument(1, array_map(strval(...), array_keys($configured)))
            ->replaceArgument(2, array_map(strval(...), array_keys($found)));
    }

    private static function isOff(ContainerBuilder $container): bool
    {
        $components = $container->hasParameter('jmonitor.stack.components') ? $container->getParameter('jmonitor.stack.components') : null;

        return \is_array($components) && \is_array($components['redis'] ?? null) && ($components['redis']['enabled'] ?? null) === false;
    }

    /**
     * @return array<string, string> id => DSN, as the extension left them
     */
    private static function configured(Definition $definition): array
    {
        try {
            $configured = $definition->getArgument(1);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($configured) ? array_filter($configured, \is_string(...)) : [];
    }

    /**
     * @return array<string, string> the id of each connection a service uses => its DSN
     */
    private static function cache(ContainerBuilder $container): array
    {
        $used = self::usedConnections($container);
        $dsns = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if (!isset($used[$id]) || $definition->getFactory() !== [AbstractAdapter::class, 'createConnection']) {
                continue;
            }

            $dsn = self::firstArgument($definition);

            if (\is_string($dsn)) {
                $dsns[$id] = $dsn;
            }
        }

        return $dsns;
    }

    /**
     * A DSN of the sessions alone: one they share with the cache is a reference to its connection.
     *
     * @return array<string, string>
     */
    private static function sessions(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition(self::SESSIONS)) {
            return [];
        }

        $dsn = self::firstArgument($container->getDefinition(self::SESSIONS));

        return \is_string($dsn) ? [self::SESSIONS => $dsn] : [];
    }

    /**
     * @return array<string, string>
     */
    private static function environment(ContainerBuilder $container): array
    {
        try {
            $placeholder = $container->getParameterBag()->get('env(default::' . RedisDsns::ENVIRONMENT . ')');
        } catch (\Throwable) {
            return [];
        }

        return \is_string($placeholder) ? [RedisDsns::ENVIRONMENT => $placeholder] : [];
    }

    private static function firstArgument(Definition $definition): mixed
    {
        try {
            return $definition->getArgument(0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, true> the connections a service refers to
     */
    private static function usedConnections(ContainerBuilder $container): array
    {
        $used = [];
        $walk = static function (mixed $value) use (&$walk, &$used): void {
            if ($value instanceof Reference) {
                if (str_starts_with((string) $value, self::CONNECTION)) {
                    $used[(string) $value] = true;
                }
            } elseif ($value instanceof Definition) {
                $walk([$value->getArguments(), $value->getProperties(), $value->getMethodCalls(), $value->getFactory(), $value->getConfigurator()]);
            } elseif ($value instanceof ArgumentInterface) {
                $walk($value->getValues());
            } elseif (\is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };

        foreach ($container->getDefinitions() as $definition) {
            if (!$definition->isAbstract()) {
                $walk($definition);
            }
        }

        return $used;
    }
}
