<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\DependencyInjection\ComponentAvailabilityPass;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ComponentAvailabilityPassTest extends TestCase
{
    public function testKeepsTheMessengerRecorderWhenMessengerIsEnabled(): void
    {
        $container = $this->container(['messenger.receiver_locator']);

        (new ComponentAvailabilityPass())->process($container);

        static::assertTrue($container->hasDefinition(MessengerRecorder::class));
    }

    public function testRemovesTheMessengerRecorderWhenMessengerIsNotEnabled(): void
    {
        $container = $this->container([]);

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->hasDefinition(MessengerRecorder::class));
    }

    public function testDoesNothingWithoutTheMessengerRecorder(): void
    {
        $container = new ContainerBuilder();

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->hasDefinition(MessengerRecorder::class));
    }

    /**
     * @param list<string> $services
     */
    private function container(array $services): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ([MessengerRecorder::class, ...$services] as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }

        return $container;
    }
}
