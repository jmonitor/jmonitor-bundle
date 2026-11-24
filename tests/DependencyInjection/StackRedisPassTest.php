<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\DependencyInjection\StackRedisPass;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\Failures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\SessionHandlerFactory;

final class StackRedisPassTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            (new Filesystem())->remove($kernel->getVarDir());
        }
        $this->kernels = [];
        unset($_ENV['REDIS_URL'], $_SERVER['REDIS_URL']);
    }

    public function testTheConfiguredDsnAreHandedOverAlone(): void
    {
        $container = self::container(['main' => 'redis://cache:6380', 'sessions' => 'redis://sessions/1']);
        self::cacheConnection($container, 'pool', 'redis://pool:6379');

        (new StackRedisPass())->process($container);

        $definition = $container->getDefinition(RedisDsns::class);
        self::assertSame(['main', 'sessions'], $definition->getArgument(1));
        self::assertSame([], $definition->getArgument(2));
        self::assertSame(['main' => [['redis://cache:6380']], 'sessions' => [['redis://sessions/1']]], self::entries($container));

        $dsns = self::compiled($container);
        self::assertSame('cache:6380', $dsns->target('main')->server()->key);
        self::assertSame('sessions:6379', $dsns->target('sessions')->server()->key);
    }

    public function testTheDsnOfACachePoolOnRedisIsFound(): void
    {
        $container = self::container();
        $connection = self::cacheConnection($container, 'cache.app', 'redis://cache:6390');

        (new StackRedisPass())->process($container);

        self::assertSame([], $container->getDefinition(RedisDsns::class)->getArgument(1));
        self::assertSame([$connection, RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
        self::assertSame([['redis://cache:6390']], self::entries($container)[$connection]);
        self::assertSame('cache:6390', self::compiled($container)->target($connection)->server()->key);
    }

    public function testTheDsnOfTheSessionsOnRedisIsFound(): void
    {
        $container = self::container();
        $container->setDefinition('session.abstract_handler', (new Definition(\SessionHandlerInterface::class, ['redis://sessions:6391', []]))->setFactory([SessionHandlerFactory::class, 'createHandler']));

        (new StackRedisPass())->process($container);

        self::assertSame(['session.abstract_handler', RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
        self::assertSame('sessions:6391', self::compiled($container)->target('session.abstract_handler')->server()->key);
    }

    public function testSessionsSharingTheConnectionOfTheCacheAreFoundOnce(): void
    {
        $container = self::container();
        $connection = self::cacheConnection($container, 'cache.app', 'redis://cache:6390');
        $container->setDefinition('session.abstract_handler', (new Definition(\SessionHandlerInterface::class, [new Reference($connection), []]))->setFactory([SessionHandlerFactory::class, 'createHandler']));

        (new StackRedisPass())->process($container);

        self::assertSame([$connection, RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testTheCacheComesBeforeTheSessionsAndTheEnvironmentLast(): void
    {
        $container = self::container();
        $container->setDefinition('session.abstract_handler', (new Definition(\SessionHandlerInterface::class, ['redis://sessions', []]))->setFactory([SessionHandlerFactory::class, 'createHandler']));
        $first = self::cacheConnection($container, 'cache.app', 'redis://cache');
        $second = self::cacheConnection($container, 'app.pool', 'redis://pool');

        (new StackRedisPass())->process($container);

        self::assertSame([$first, $second, 'session.abstract_handler', RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testRedisUrlIsReadAtRunTime(): void
    {
        $without = self::container();
        $with = self::container();
        (new StackRedisPass())->process($without);
        (new StackRedisPass())->process($with);

        $missing = self::compiled($without);

        self::assertSame([RedisDsns::ENVIRONMENT], $missing->found());
        self::assertSame('This connection has no DSN.', Failures::thrownBy(static fn() => $missing->target(RedisDsns::ENVIRONMENT))->getMessage());

        $_ENV['REDIS_URL'] = 'redis://from-the-environment:6392';

        self::assertSame('from-the-environment:6392', self::compiled($with)->target(RedisDsns::ENVIRONMENT)->server()->key);
    }

    public function testAMemcachedProviderIsHandedOverToo(): void
    {
        $container = self::container();
        $connection = self::cacheConnection($container, 'cache.app', 'memcached://cache:11211');

        (new StackRedisPass())->process($container);

        self::assertSame([$connection, RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
        self::assertSame('This DSN is not a Redis DSN.', Failures::thrownBy(static fn() => self::compiled($container)->target($connection))->getMessage());
    }

    public function testTheDefaultProvidersOfTheFrameworkAreNotFound(): void
    {
        $container = self::container();
        foreach (['redis' => 'redis://localhost', 'memcached' => 'memcached://localhost', 'valkey' => 'valkey://localhost'] as $provider => $dsn) {
            $id = '.cache_connection.' . ContainerBuilder::hash($dsn);
            $container->setDefinition($id, self::connection($dsn));
            $container->setAlias('cache.default_' . $provider . '_provider', $id);
        }
        $container->setDefinition('cache.adapter.redis', (new Definition(\stdClass::class, [new Reference('.cache_connection.' . ContainerBuilder::hash('redis://localhost'))]))->setAbstract(true));

        (new StackRedisPass())->process($container);

        self::assertSame([RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testTheDefaultProvidersOfARealKernelAreNotFound(): void
    {
        $plain = $this->dsns(null);
        $onRedis = $this->dsns('cache.adapter.redis');

        self::assertSame([RedisDsns::ENVIRONMENT], $plain->found());
        self::assertCount(2, $onRedis->found());
        self::assertSame('localhost:6379', $onRedis->target($onRedis->found()[0])->server()->key);
        self::assertSame(RedisDsns::ENVIRONMENT, $onRedis->found()[1]);
    }

    public function testRedisUrlIsReadByARealKernelOnceItRuns(): void
    {
        $missing = $this->dsns(null);
        $failure = Failures::thrownBy(static fn() => $missing->target(RedisDsns::ENVIRONMENT));
        $_ENV['REDIS_URL'] = 'redis://from-the-environment:6392';

        self::assertSame('This connection has no DSN.', $failure->getMessage());
        self::assertSame('from-the-environment:6392', $this->dsns(null)->target(RedisDsns::ENVIRONMENT)->server()->key);
    }

    public function testWithoutFrameworkCacheNothingIsFound(): void
    {
        $container = self::container();

        (new StackRedisPass())->process($container);

        self::assertSame([], $container->getDefinition(RedisDsns::class)->getArgument(1));
        self::assertSame([RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testItNeverThrows(): void
    {
        $container = self::container();
        $bare = '.cache_connection.bare';
        $container->setDefinition($bare, (new Definition(AbstractAdapter::class))->setFactory([AbstractAdapter::class, 'createConnection']));
        $reference = '.cache_connection.reference';
        $container->setDefinition($reference, self::connection(new Reference('some.service')));
        $list = '.cache_connection.list';
        $container->setDefinition($list, self::connection(['redis://a', 'redis://b']));
        $other = '.cache_connection.other';
        $container->setDefinition($other, (new Definition(\stdClass::class, ['redis://other']))->setFactory([\stdClass::class, 'create']));
        $container->setDefinition('app.pool', new Definition(\stdClass::class, [new Reference($bare), [new Reference($reference)], new ServiceLocatorArgument([new Reference($list)]), new ServiceClosureArgument(new Reference($other))]));
        $container->setDefinition('session.abstract_handler', (new Definition(\SessionHandlerInterface::class, [new AbstractArgument('A string or a connection object'), []]))->setFactory([SessionHandlerFactory::class, 'createHandler']));

        (new StackRedisPass())->process($container);

        self::assertSame([RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testAReferenceFromAnyPartOfADefinitionCounts(): void
    {
        $container = self::container();
        $argument = self::cacheConnection($container, null, 'redis://argument');
        $call = self::cacheConnection($container, null, 'redis://call');
        $property = self::cacheConnection($container, null, 'redis://property');
        $inline = self::cacheConnection($container, null, 'redis://inline');
        $container->setDefinition('app.user', (new Definition(\stdClass::class, [['nested' => new Reference($argument)]]))
            ->addMethodCall('setRedis', [new Reference($call)])
            ->setProperty('redis', new Reference($property))
            ->setConfigurator([new Definition(\stdClass::class, [new Reference($inline)]), 'configure']));

        (new StackRedisPass())->process($container);

        self::assertSame([$argument, $call, $property, $inline, RedisDsns::ENVIRONMENT], $container->getDefinition(RedisDsns::class)->getArgument(2));
    }

    public function testNothingIsHandedOverWhileRedisIsOff(): void
    {
        $container = self::container(['main' => 'redis://cache']);
        self::cacheConnection($container, 'cache.app', 'redis://cache');
        $container->setParameter('jmonitor.stack.components', ['redis' => ['enabled' => false]]);

        (new StackRedisPass())->process($container);

        $definition = $container->getDefinition(RedisDsns::class);
        self::assertSame([], $definition->getArgument(1));
        self::assertSame([], $definition->getArgument(2));
        self::assertSame([], self::entries($container));
    }

    public function testWithoutTheStackNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        self::cacheConnection($container, 'cache.app', 'redis://cache');
        $before = $container->getDefinitions();

        (new StackRedisPass())->process($container);

        self::assertEquals($before, $container->getDefinitions());
    }

    /**
     * @param array<string, string> $configured
     */
    private static function container(array $configured = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RedisDsns::class, (new Definition(RedisDsns::class, [new ServiceLocatorArgument([]), $configured, []]))->setPublic(true));
        $container->setParameter('jmonitor.stack.components', ['redis' => ['enabled' => 'auto']]);

        return $container;
    }

    /**
     * @return string the id of the connection
     */
    private static function cacheConnection(ContainerBuilder $container, ?string $pool, string $dsn): string
    {
        $id = '.cache_connection.' . ContainerBuilder::hash($dsn);
        $container->setDefinition($id, self::connection($dsn));

        if ($pool !== null) {
            $container->setDefinition($pool, new Definition(\stdClass::class, [new Reference($id), '', 0]));
        }

        return $id;
    }

    private static function connection(mixed $dsn): Definition
    {
        return (new Definition(AbstractAdapter::class, [$dsn, ['lazy' => true]]))->setFactory([AbstractAdapter::class, 'createConnection']);
    }

    private static function compiled(ContainerBuilder $container): RedisDsns
    {
        $container->compile(true);
        $dsns = $container->get(RedisDsns::class);
        self::assertInstanceOf(RedisDsns::class, $dsns);

        return $dsns;
    }

    /**
     * @return array<string, mixed> the arguments of the service each entry of the locator gives
     */
    private static function entries(ContainerBuilder $container): array
    {
        $locator = $container->getDefinition(RedisDsns::class)->getArgument(0);
        self::assertInstanceOf(Reference::class, $locator);
        $map = $container->getDefinition((string) $locator)->getArgument(0);
        self::assertIsArray($map);

        $entries = [];
        foreach ($map as $entry => $closure) {
            self::assertInstanceOf(ServiceClosureArgument::class, $closure);
            self::assertStringStartsWith('.', (string) $closure->getValues()[0]);
            $definition = $container->getDefinition((string) $closure->getValues()[0]);
            self::assertSame(\ArrayObject::class, $definition->getClass());
            self::assertFalse($definition->isPublic());
            $entries[(string) $entry] = $definition->getArguments();
        }

        return $entries;
    }

    private function dsns(?string $cacheApp): RedisDsns
    {
        $this->kernels[] = $kernel = new TestKernel('test', true, withPublicInternals: true, cacheApp: $cacheApp);
        $kernel->boot();
        $dsns = $kernel->getContainer()->get(RedisDsns::class);
        self::assertInstanceOf(RedisDsns::class, $dsns);

        return $dsns;
    }
}
