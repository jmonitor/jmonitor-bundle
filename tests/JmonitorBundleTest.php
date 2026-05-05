<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use Jmonitor\JmonitorBundle\Collector\Components\SchedulerCollector;
use Jmonitor\JmonitorBundle\Collector\SymfonyCollector;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class JmonitorBundleTest extends TestCase
{
    private function loadBundle(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // Minimal kernel params expected by Symfony's ExtensionTrait
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new JmonitorBundle();
        $extension = $bundle->getContainerExtension();
        // Load configuration and services into the container
        $extension->load([$config], $container);

        return $container;
    }

    public function testMinimalConfigRegistersCoreServices(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'abc-123',
            // no collectors, no schedule
        ]);

        static::assertTrue($container->hasDefinition(Jmonitor::class), 'Jmonitor service should be defined');
        static::assertTrue(
            $container->hasDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class),
            'CollectorCommand should be defined',
        );

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $args = $jmonitorDef->getArguments();
        static::assertSame('abc-123', $args[0] ?? null);
        static::assertArrayHasKey(1, $args);
        static::assertNull($args[1], 'Default http_client argument should be null when not configured');

        $commandDef = $container->getDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class);
        static::assertTrue($commandDef->hasTag('console.command'));
    }

    public function testMysqlCollectorsRegisterServicesAndMethodCalls(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(DoctrineAdapter::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlInformationSchemaCollector::class));

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $calls = $jmonitorDef->getMethodCalls();
        $methods = array_map(static fn(array $c) => $c[0], $calls);

        static::assertContains('addCollector', $methods, 'addCollector should be called');

        // Ensure all mysql collectors are registered via addCollector calls
        $serializedArgs = array_map(static fn(array $c) => array_map(static fn($a) => (string) $a, $c[1]), $calls);
        $flat = array_merge(...$serializedArgs);

        static::assertContains(MysqlStatusCollector::class, $flat);
        static::assertContains(MysqlVariablesCollector::class, $flat);
    }

    public function testRedisCollectorMutualExclusivityValidation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.',
        );

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'redis' => [
                    'dsn' => 'redis://localhost',
                    'adapter' => 'some.redis.adapter',
                ],
            ],
        ]);
    }

    public function testRedisCollectorRegistersAndAddedToJmonitor(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'redis' => [
                    'dsn' => 'redis://localhost',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(RedisCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(RedisCollector::class, $flat);
    }

    public function testApacheCollectorRegistersAndAddedToJmonitor(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => [
                    'server_status_url' => 'http://localhost/server-status',
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(ApacheCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(ApacheCollector::class, $flat);
    }

    public function testSystemCollectorRegistersWithCustomAdapter(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'system' => [
                    'adapter' => \stdClass::class,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SystemCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(SystemCollector::class, $flat);
    }

    public function testSymfonyCollectorRegistersWithAllComponents(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                    'scheduler' => true,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));
        static::assertTrue($container->hasDefinition(SchedulerCollector::class));
        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static fn(array $c) => array_map(
            static fn($a) => (string) $a,
            $c[1],
        ), $calls));
        static::assertContains(SymfonyCollector::class, $flat);
    }

    public function testSymfonyCollectorRegistersWithOnlyFlex(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                    'scheduler' => false,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));
        static::assertFalse($container->hasDefinition(SchedulerCollector::class));
        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
    }

    public function testSymfonyCollectorCanBeEnabledWithoutComponents(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => true,
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));

        if (class_exists('Symfony\Component\Scheduler\Scheduler')) {
            static::assertTrue(
                $container->hasDefinition(SchedulerCollector::class),
                'Scheduler should be enabled by default when symfony is true and component is present',
            );
        } else {
            static::assertFalse(
                $container->hasDefinition(SchedulerCollector::class),
                'Scheduler should be disabled by default when component is absent',
            );
        }

        if (class_exists('Symfony\Flex\SymfonyBundle')) {
            static::assertTrue(
                $container->hasDefinition(FlexRecipesCollector::class),
                'Flex should be enabled by default when symfony is true and component is present',
            );
        } else {
            static::assertFalse(
                $container->hasDefinition(FlexRecipesCollector::class),
                'Flex should be disabled by default when component is absent',
            );
        }
    }

    public function testSymfonyCollectorCanBeEnabledWithTilde(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => null,
            ],
        ]);

        static::assertTrue($container->hasDefinition(SymfonyCollector::class));

        if (class_exists('Symfony\Component\Scheduler\Scheduler')) {
            static::assertTrue($container->hasDefinition(SchedulerCollector::class));
        } else {
            static::assertFalse($container->hasDefinition(SchedulerCollector::class));
        }

        if (class_exists('Symfony\Flex\SymfonyBundle')) {
            static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
        } else {
            static::assertFalse($container->hasDefinition(FlexRecipesCollector::class));
        }
    }

    public function testApacheCollectorCanBeEnabledWithTilde(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => null,
            ],
        ]);

        static::assertTrue($container->hasDefinition(ApacheCollector::class));
    }

    public function testApacheCollectorCanBeDisabledExplicitly(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'apache' => [
                    'enabled' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(ApacheCollector::class));
    }

    public function testSymfonyCollectorWithCustomFlexCommand(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => [
                        'enabled' => true,
                        'command' => 'php bin/console custom:recipes',
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(FlexRecipesCollector::class));
        static::assertSame(
            'php bin/console custom:recipes',
            $container->getDefinition(FlexRecipesCollector::class)->getArgument(1),
        );
    }

    public function testMysqlVariablesCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'variables' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlStatusCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
    }

    public function testMysqlSlowQueriesCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlInformationSchemaCollectorCanBeDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'information_schema' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MysqlInformationSchemaCollector::class));
        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
    }

    public function testMysqlSubCollectorsEnabledWithExplicitNull(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => null,
                    'variables' => null,
                    'slow_queries' => null,
                    'information_schema' => null,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlStatusCollector::class));
        static::assertTrue($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertTrue($container->hasDefinition(MysqlInformationSchemaCollector::class));
    }

    public function testMysqlSlowQueriesCollectorWithCustomConfig(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => [
                        'limit' => 5,
                        'min_exec_count' => 10,
                        'min_avg_time_ms' => 50,
                        'order_by' => 'avg',
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        $def = $container->getDefinition(MysqlSlowQueriesCollector::class);
        static::assertSame('mydb', $def->getArgument(1));
        static::assertSame(5, $def->getArgument(2));
        static::assertSame(10, $def->getArgument(3));
        static::assertSame(50, $def->getArgument(4));
        static::assertSame('avg', $def->getArgument(5));
    }

    public function testMysqlSlowQueriesCollectorDefaultArgs(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => null,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MysqlSlowQueriesCollector::class));
        $def = $container->getDefinition(MysqlSlowQueriesCollector::class);
        static::assertSame('mydb', $def->getArgument(1));
        static::assertSame(5, $def->getArgument(2));
        static::assertSame(1, $def->getArgument(3));
        static::assertSame(0, $def->getArgument(4));
        static::assertSame('avg', $def->getArgument(5));
    }

    public function testMysqlSlowQueriesInvalidOrderByThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid order_by value');

        $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'slow_queries' => [
                        'order_by' => 'invalid',
                    ],
                ],
            ],
        ]);
    }

    public function testMessengerCollectorRegistersWithBooleanTrue(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => true,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertNull($def->getArgument(1), 'Default command should be null');
        static::assertSame(3, $def->getArgument(2), 'Default timeout should be 3');
    }

    public function testMessengerCollectorNotRegisteredWithBooleanFalse(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => false,
                ],
            ],
        ]);

        static::assertFalse($container->hasDefinition(MessengerStatsCollector::class));
    }

    public function testMessengerCollectorRegistersWithCustomCommandAndTimeout(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => [
                        'enabled' => true,
                        'command' => '/usr/bin/php bin/console messenger:stats --format=json',
                        'timeout' => 10,
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertSame('/usr/bin/php bin/console messenger:stats --format=json', $def->getArgument(1));
        static::assertSame(10, $def->getArgument(2));
    }

    public function testMessengerCollectorRegistersWithDefaultsWhenEnabledExplicitly(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'messenger' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
        $def = $container->getDefinition(MessengerStatsCollector::class);
        static::assertNull($def->getArgument(1));
        static::assertSame(3, $def->getArgument(2));
    }

    public function testDoctrineAdapterRegisteredEvenWhenAllSubCollectorsDisabled(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'mysql' => [
                    'db_name' => 'mydb',
                    'status' => false,
                    'variables' => false,
                    'slow_queries' => false,
                    'information_schema' => false,
                ],
            ],
        ]);

        static::assertTrue($container->hasDefinition(DoctrineAdapter::class));
        static::assertFalse($container->hasDefinition(MysqlStatusCollector::class));
        static::assertFalse($container->hasDefinition(MysqlVariablesCollector::class));
        static::assertFalse($container->hasDefinition(MysqlSlowQueriesCollector::class));
        static::assertFalse($container->hasDefinition(MysqlInformationSchemaCollector::class));
    }
}
