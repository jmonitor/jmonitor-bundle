<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests;

use Jmonitor\JmonitorBundle\Collector\Components\CacheableComponentCollector;
use Jmonitor\JmonitorBundle\Collector\Components\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class CacheDecorationTest extends TestCase
{
    public function testCollectorsAreDecoratedWhenCacheAppIsPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.project_dir', __DIR__);

        // Mock kernel service
        $container->setDefinition('kernel', new Definition(\stdClass::class));

        // Mock cache.app service
        $container->setDefinition('cache.app', new Definition(\stdClass::class));

        $bundle = new JmonitorBundle();
        $bundle->build($container);

        // Required for autoconfiguration to work in tests
        $container->addCompilerPass(new ResolveInstanceofConditionalsPass());

        // Make services public for inspection
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
                foreach ($container->getAliases() as $alias) {
                    $alias->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        $extension = $bundle->getContainerExtension();
        $extension->load([[
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                ],
            ],
        ]], $container);

        $container->compile();

        static::assertTrue($container->has(FlexRecipesCollector::class), 'FlexRecipesCollector should be present');

        $id = FlexRecipesCollector::class;
        if ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        $definition = $container->getDefinition($id);
        static::assertEquals(CacheableComponentCollector::class, $definition->getClass());
    }

    public function testCollectorsAreNotDecoratedWhenCacheAppIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.project_dir', __DIR__);

        // Mock kernel service
        $container->setDefinition('kernel', new Definition(\stdClass::class));

        $bundle = new JmonitorBundle();
        $bundle->build($container);

        $container->addCompilerPass(new ResolveInstanceofConditionalsPass());

        // Make services public for inspection
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
                foreach ($container->getAliases() as $alias) {
                    $alias->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        $extension = $bundle->getContainerExtension();
        $extension->load([[
            'project_api_key' => 'key',
            'collectors' => [
                'symfony' => [
                    'flex' => true,
                ],
            ],
        ]], $container);

        $container->compile();

        static::assertTrue($container->has(FlexRecipesCollector::class), 'FlexRecipesCollector should be present');

        $id = FlexRecipesCollector::class;
        if ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }

        $definition = $container->getDefinition($id);
        static::assertEquals(FlexRecipesCollector::class, $definition->getClass() ?? $id);
        static::assertNotEquals(CacheableComponentCollector::class, $definition->getClass());
    }
}
