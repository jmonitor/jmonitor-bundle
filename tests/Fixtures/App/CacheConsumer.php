<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/** Holds the pools as an application gets them by autowiring. */
final class CacheConsumer
{
    public function __construct(
        public readonly CacheInterface $cache,
        public readonly CacheItemPoolInterface $items,
        public readonly TagAwareCacheInterface $taggable,
        public readonly CacheInterface $appPlain,
        #[Target('app.plain')]
        public readonly CacheItemPoolInterface $appPlainItems,
        #[Target('app.plain')]
        public readonly TagAwareCacheInterface $appPlainTaggable,
        public readonly TagAwareCacheInterface $appTagged,
        #[Target('app.tagged')]
        public readonly CacheInterface $appTaggedUntagged,
        public readonly CacheInterface $appSystem,
        public readonly CacheInterface $appDerived,
        #[Autowire(service: 'cache.system')]
        public readonly CacheItemPoolInterface $system,
        #[Autowire(service: 'cache.validator')]
        public readonly CacheItemPoolInterface $validator,
    ) {}
}
