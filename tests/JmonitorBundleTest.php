<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\JmonitorBundle\Tests;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
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

        $this->assertTrue($container->hasDefinition(Jmonitor::class), 'Jmonitor service should be defined');
        $this->assertTrue($container->hasDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class), 'CollectorCommand should be defined');

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $args = $jmonitorDef->getArguments();
        $this->assertSame('abc-123', $args[0] ?? null);
        $this->assertArrayHasKey(1, $args);
        $this->assertNull($args[1], 'Default http_client argument should be null when not configured');

        $commandDef = $container->getDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class);
        $this->assertTrue($commandDef->hasTag('console.command'));
    }

    public function testScheduleTagAddedWhenConfigured(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'schedule' => 'default',
        ]);

        $commandDef = $container->getDefinition(\Jmonitor\JmonitorBundle\Command\CollectorCommand::class);
        $this->assertTrue($commandDef->hasTag('scheduler.task'));
        $tags = $commandDef->getTag('scheduler.task');
        $this->assertNotEmpty($tags);
        $tag = $tags[0];
        $this->assertSame(15, $tag['frequency']);
        $this->assertSame('default', $tag['schedule']);
        $this->assertSame('every', $tag['trigger']);
        $this->assertArrayHasKey('arguments', $tag);
        $this->assertNull($tag['arguments']);
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

        $this->assertTrue($container->hasDefinition(DoctrineAdapter::class));
        $this->assertTrue($container->hasDefinition(MysqlQueriesCountCollector::class));
        $this->assertTrue($container->hasDefinition(MysqlStatusCollector::class));
        $this->assertTrue($container->hasDefinition(MysqlVariablesCollector::class));

        $jmonitorDef = $container->getDefinition(Jmonitor::class);
        $calls = $jmonitorDef->getMethodCalls();
        $methods = array_map(static fn(array $c) => $c[0], $calls);

        $this->assertContains('addCollector', $methods, 'addCollector should be called');

        // Ensure all three mysql collectors are registered via addCollector calls
        $serializedArgs = array_map(static function (array $c) {
            return array_map(static fn($a) => (string) $a, $c[1]);
        }, $calls);
        $flat = array_merge(...$serializedArgs);

        $this->assertContains(MysqlQueriesCountCollector::class, $flat);
        $this->assertContains(MysqlStatusCollector::class, $flat);
        $this->assertContains(MysqlVariablesCollector::class, $flat);
    }

    public function testRedisCollectorMutualExclusivityValidation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.');

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

        $this->assertTrue($container->hasDefinition(RedisCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static function (array $c) {
            return array_map(static fn($a) => (string) $a, $c[1]);
        }, $calls));
        $this->assertContains(RedisCollector::class, $flat);
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

        $this->assertTrue($container->hasDefinition(ApacheCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static function (array $c) {
            return array_map(static fn($a) => (string) $a, $c[1]);
        }, $calls));
        $this->assertContains(ApacheCollector::class, $flat);
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

        $this->assertTrue($container->hasDefinition(SystemCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static function (array $c) {
            return array_map(static fn($a) => (string) $a, $c[1]);
        }, $calls));
        $this->assertContains(SystemCollector::class, $flat);
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

        $this->assertTrue($container->hasDefinition(SymfonyCollector::class));
        $this->assertTrue($container->hasDefinition(SchedulerCollector::class));
        $this->assertTrue($container->hasDefinition(FlexRecipesCollector::class));

        $calls = $container->getDefinition(Jmonitor::class)->getMethodCalls();
        $flat = array_merge(...array_map(static function (array $c) {
            return array_map(static fn($a) => (string) $a, $c[1]);
        }, $calls));
        $this->assertContains(SymfonyCollector::class, $flat);
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

        $this->assertTrue($container->hasDefinition(SymfonyCollector::class));
        $this->assertFalse($container->hasDefinition(SchedulerCollector::class));
        $this->assertTrue($container->hasDefinition(FlexRecipesCollector::class));
    }

    public function testSymfonyCollectorCanBeEnabledWithoutComponents(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => true,
            ],
        ]);

        $this->assertTrue($container->hasDefinition(SymfonyCollector::class));
        $this->assertTrue($container->hasDefinition(SchedulerCollector::class), 'Scheduler should be enabled by default when symfony is true');
        $this->assertTrue($container->hasDefinition(FlexRecipesCollector::class), 'Flex should be enabled by default when symfony is true');
    }

    public function testSymfonyCollectorCanBeEnabledWithTilde(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => null,
            ],
        ]);

        $this->assertTrue($container->hasDefinition(SymfonyCollector::class));
        $this->assertTrue($container->hasDefinition(SchedulerCollector::class));
        $this->assertTrue($container->hasDefinition(FlexRecipesCollector::class));
    }

    public function testSymfonyCollectorIsDisabledByDefault(): void
    {
        $container = $this->loadBundle([
            'project_api_key' => 'key',
            // symfony not specified
        ]);

        $this->assertFalse($container->hasDefinition(SymfonyCollector::class));
    }
}
