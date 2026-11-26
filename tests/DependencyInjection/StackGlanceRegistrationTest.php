<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use Jmonitor\JmonitorBundle\Stack\Screen\OverviewGlance;
use Jmonitor\JmonitorBundle\Stack\Screen\StackScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class StackGlanceRegistrationTest extends TestCase
{
    public function testTheGlanceIsTheOverviewOfTheStackWhenTheStackIsOn(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasAlias(StackGlanceInterface::class));
        self::assertSame(OverviewGlance::class, (string) $container->getAlias(StackGlanceInterface::class));
        self::assertEquals(
            $container->getDefinition(StackScreen::class)->getArgument(0),
            $container->getDefinition(OverviewGlance::class)->getArgument(0),
            'the services of the Stack screen',
        );
    }

    public function testThereIsNoGlanceWhenTheStackIsOff(): void
    {
        $container = $this->load(['stack' => false]);

        self::assertFalse($container->has(StackGlanceInterface::class));
        self::assertFalse($container->has(OverviewGlance::class));
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', '/srv/app');
        $container->setParameter('kernel.bundles', ['FrameworkBundle' => FrameworkBundle::class]);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        (new JmonitorBundle())->getContainerExtension()?->load([$config], $container);

        return $container;
    }
}
