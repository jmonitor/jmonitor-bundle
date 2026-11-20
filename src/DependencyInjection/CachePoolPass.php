<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\DependencyInjection;

use Jmonitor\JmonitorBundle\Recording\Cache\RecordingAdapter;
use Jmonitor\JmonitorBundle\Recording\Cache\RecordingTagAwareAdapter;
use Jmonitor\JmonitorBundle\Recording\Recorder;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wraps cache.app and the pools the application declares, each service keeping its id, tags and visibility.
 *
 * A tag-aware view of a wrapped pool gets its own wrapper and reads the pool behind the first one, so a read
 * through the view counts once and the tag versions it reads do not count.
 */
final class CachePoolPass implements CompilerPassInterface
{
    public const DECLARED_POOLS = '.jmonitor.recording.cache.declared_pools';

    private const APPLICATION_POOL = 'cache.app';
    private const SYSTEM_FACTORY = [AbstractAdapter::class, 'createSystemCache'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Recorder::class) || $container->getParameter('jmonitor.recording.cache') === false) {
            return;
        }

        $declared = $container->hasParameter(self::DECLARED_POOLS) ? (array) $container->getParameter(self::DECLARED_POOLS) : [];
        $declared = array_flip([self::APPLICATION_POOL, ...array_filter($declared, '\is_string')]);

        /** @var array<string, string> $inners wrapped service id => id of the service it wraps */
        $inners = [];
        $names = [];
        foreach ($container->findTaggedServiceIds('cache.pool') as $id => $tags) {
            $name = $tags[0]['name'] ?? $id;
            if (!\is_string($name) || !isset($declared[$name]) || $container->getDefinition($id)->isAbstract()) {
                continue;
            }

            $pool = self::untraced($container, $id);
            // A wrapped computation cannot be sent to the message bus: a pool that computes early would compute online.
            if ($name !== self::APPLICATION_POOL && $pool->getFactory() === self::SYSTEM_FACTORY || $pool->hasMethodCall('setCallbackWrapper')) {
                continue;
            }

            $inners[$id] = self::wrap($container, $id, $name);
            $names[$name] = true;
        }

        foreach ($container->findTaggedServiceIds('cache.taggable') as $id => $tags) {
            $name = $tags[0]['pool'] ?? null;
            if (isset($inners[$id]) || !\is_string($name) || !isset($names[$name])) {
                continue;
            }

            $view = $container->getDefinition($id);
            $view->setArguments(self::bypass($view->getArguments(), $inners));
            self::wrap($container, $id, $name);
        }

        if ($container->hasDefinition('data_collector.cache')) {
            $collector = $container->getDefinition('data_collector.cache');
            $calls = [];
            foreach ($collector->getMethodCalls() as $call) {
                $call[1] = self::bypass($call[1], $inners);
                $calls[] = $call;
            }
            $collector->setMethodCalls($calls);
        }
    }

    /** @return string the id the wrapped definition moves to */
    private static function wrap(ContainerBuilder $container, string $id, string $name): string
    {
        $definition = $container->getDefinition($id);
        $innerId = $id . '.jmonitor_inner';
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        $wrapper = new Definition(
            \is_string($class) && is_a($class, TagAwareAdapterInterface::class, true) ? RecordingTagAwareAdapter::class : RecordingAdapter::class,
            [new Reference($innerId), new Reference(Recorder::class), $name],
        );
        $wrapper->setTags($definition->getTags());
        $wrapper->setPublic($definition->isPublic());

        $definition->setTags([]);
        $definition->setPublic(false);
        $container->setDefinition($innerId, $definition);
        $container->setDefinition($id, $wrapper);

        return $innerId;
    }

    /** The definition of the pool itself, under any TraceableAdapter around it. */
    private static function untraced(ContainerBuilder $container, string $id): Definition
    {
        $definition = $container->getDefinition($id);
        while (is_a((string) $definition->getClass(), TraceableAdapter::class, true)
            && ($inner = $definition->getArguments()[0] ?? null) instanceof Reference
        ) {
            $definition = $container->findDefinition((string) $inner);
        }

        return $definition;
    }

    /**
     * @param array<mixed>          $arguments
     * @param array<string, string> $inners
     *
     * @return array<mixed>
     */
    private static function bypass(array $arguments, array $inners): array
    {
        foreach ($arguments as $index => $argument) {
            if ($argument instanceof Reference && isset($inners[(string) $argument])) {
                $arguments[$index] = new Reference($inners[(string) $argument], $argument->getInvalidBehavior());
            }
        }

        return $arguments;
    }
}
