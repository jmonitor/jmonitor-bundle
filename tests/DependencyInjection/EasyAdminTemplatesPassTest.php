<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\DependencyInjection\EasyAdminTemplatesPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

final class EasyAdminTemplatesPassTest extends TestCase
{
    public function testTheTemplatesAreRegisteredUnderTheirOwnNamespaceWhenTheOptionIsOn(): void
    {
        $container = $this->container(true);

        (new EasyAdminTemplatesPass())->process($container);

        static::assertSame(
            [['addPath', [\dirname(__DIR__, 2) . '/templates-easyadmin', 'JmonitorEasyAdmin']]],
            $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls(),
        );
    }

    public function testNothingIsRegisteredWhenTheOptionIsOff(): void
    {
        $container = $this->container(false);

        (new EasyAdminTemplatesPass())->process($container);

        static::assertSame([], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
    }

    public function testNothingIsRegisteredWhenTheParameterIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));

        (new EasyAdminTemplatesPass())->process($container);

        static::assertSame([], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
    }

    public function testAContainerWithoutTwigIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('jmonitor.easyadmin.enabled', true);

        (new EasyAdminTemplatesPass())->process($container);

        static::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    private function container(bool $enabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('jmonitor.easyadmin.enabled', $enabled);
        $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));

        return $container;
    }
}
