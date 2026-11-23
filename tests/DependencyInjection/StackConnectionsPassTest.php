<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\DependencyInjection\StackConnectionsPass;
use Jmonitor\JmonitorBundle\Stack\Detection\DoctrineConnections;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class StackConnectionsPassTest extends TestCase
{
    public function testEachConnectionGetsItsParametersInALocator(): void
    {
        $container = self::container([
            'default' => ['driver' => 'pdo_mysql', 'host' => 'db1.internal', 'dbname' => 'shop', 'user' => 'root', 'password' => 's3cr3t'],
            '2024' => ['driver' => 'pdo_pgsql', 'host' => 'db2.internal', 'port' => 5433, 'dbname' => 'archive'],
        ]);

        (new StackConnectionsPass())->process($container);

        $definition = $container->getDefinition(DoctrineConnections::class);
        self::assertSame(['default', '2024'], $definition->getArgument(1));
        $parser = $definition->getArgument(2);
        self::assertInstanceOf(Reference::class, $parser);
        self::assertSame('doctrine.dbal.connection_factory.dsn_parser', (string) $parser);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $parser->getInvalidBehavior());

        $params = self::locator($container, $definition->getArgument(0));
        self::assertSame(['default', '2024'], array_map(strval(...), array_keys($params)));
        foreach ($params as $id) {
            self::assertSame(\ArrayObject::class, $container->getDefinition($id)->getClass());
            self::assertFalse($container->getDefinition($id)->isPublic());
        }

        $definition->setPublic(true);
        $container->compile();
        $connections = $container->get(DoctrineConnections::class);
        self::assertInstanceOf(DoctrineConnections::class, $connections);
        self::assertSame('db1.internal', $connections->target('default')->host);
        self::assertSame(5433, $connections->target('2024')->port);
        self::assertFalse($container->initialized('doctrine.dbal.default_connection'));
    }

    public function testTheParametersAreCopiedAsTheyAre(): void
    {
        $container = new ContainerBuilder();
        $url = $container->getParameterBag()->get('env(JMONITOR_TEST_PASS_URL)');
        self::assertIsString($url);
        $params = ['driver' => 'pdo_mysql', 'host' => 'localhost', 'url' => $url];
        self::populate($container, ['default' => $params]);

        (new StackConnectionsPass())->process($container);

        $ids = self::locator($container, $container->getDefinition(DoctrineConnections::class)->getArgument(0));
        self::assertSame([$params], $container->getDefinition($ids['default'])->getArguments());
    }

    public function testWithoutDoctrineNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(DoctrineConnections::class, self::doctrineConnections());
        $before = $container->getDefinitions();

        (new StackConnectionsPass())->process($container);

        self::assertEquals($before, $container->getDefinitions());
        self::assertSame([], $container->getDefinition(DoctrineConnections::class)->getArgument(1));
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private static function container(array $connections): ContainerBuilder
    {
        $container = new ContainerBuilder();
        self::populate($container, $connections);

        return $container;
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    private static function populate(ContainerBuilder $container, array $connections): void
    {
        $ids = [];
        foreach ($connections as $name => $params) {
            $ids[$name] = 'doctrine.dbal.' . $name . '_connection';
            $container->setDefinition($ids[$name], (new Definition(\stdClass::class, [$params, new Reference('doctrine.dbal.' . $name . '_connection.configuration'), []]))->setPublic(true));
            $container->setDefinition('doctrine.dbal.' . $name . '_connection.configuration', new Definition(\stdClass::class));
        }
        $container->setParameter('doctrine.connections', $ids);
        $container->setDefinition(DoctrineConnections::class, self::doctrineConnections());
    }

    private static function doctrineConnections(): Definition
    {
        return new Definition(DoctrineConnections::class, [new ServiceLocatorArgument([]), [], null]);
    }

    /**
     * @return array<string, string> name => id of the service the locator gives
     */
    private static function locator(ContainerBuilder $container, mixed $reference): array
    {
        self::assertInstanceOf(Reference::class, $reference);
        $map = $container->getDefinition((string) $reference)->getArgument(0);
        self::assertIsArray($map);

        $ids = [];
        foreach ($map as $name => $closure) {
            self::assertInstanceOf(ServiceClosureArgument::class, $closure);
            $ids[(string) $name] = (string) $closure->getValues()[0];
        }

        return $ids;
    }
}
