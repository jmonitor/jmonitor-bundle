<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Dashboard\Section\MessagesSection;
use Jmonitor\JmonitorBundle\Dashboard\Section\TasksSection;
use Jmonitor\JmonitorBundle\DependencyInjection\ComponentAvailabilityPass;
use Jmonitor\JmonitorBundle\Recording\MessengerRecorder;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpCli;
use Jmonitor\JmonitorBundle\Stack\Collector\QueuesCollector;
use Jmonitor\JmonitorBundle\Stack\Command\QueuesCommand;
use Jmonitor\JmonitorBundle\Stack\Detection\QueuesInstance;
use Jmonitor\JmonitorBundle\Stack\Screen\QueuesBlocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ComponentAvailabilityPassTest extends TestCase
{
    private const QUEUES = [QueuesBlocks::class, QueuesInstance::class, QueuesCollector::class, PhpCli::class];

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

    public function testKeepsTheMessagesSectionOptionWhenMessengerIsEnabled(): void
    {
        $container = $this->container(['messenger.receiver_locator']);
        $container->setDefinition(MessagesSection::class, new Definition(MessagesSection::class, ['%jmonitor.recording.messenger%']));

        (new ComponentAvailabilityPass())->process($container);

        static::assertSame('%jmonitor.recording.messenger%', $container->getDefinition(MessagesSection::class)->getArgument(0));
    }

    public function testTheMessagesSectionIsUnavailableWhenMessengerIsNotEnabled(): void
    {
        $container = $this->container([]);
        $container->setDefinition(MessagesSection::class, new Definition(MessagesSection::class, ['%jmonitor.recording.messenger%']));

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->getDefinition(MessagesSection::class)->getArgument(0));
    }

    public function testKeepsTheTasksSectionOptionWhenTheSchedulerIsEnabled(): void
    {
        $container = $this->container(['messenger.receiver_locator', 'scheduler.messenger_transport_factory']);
        $container->setDefinition(TasksSection::class, new Definition(TasksSection::class, ['%jmonitor.recording.scheduler%']));

        (new ComponentAvailabilityPass())->process($container);

        static::assertSame('%jmonitor.recording.scheduler%', $container->getDefinition(TasksSection::class)->getArgument(0));
    }

    public function testTheTasksSectionIsUnavailableWhenTheSchedulerIsNotEnabled(): void
    {
        $container = $this->container(['messenger.receiver_locator']);
        $container->setDefinition(TasksSection::class, new Definition(TasksSection::class, ['%jmonitor.recording.scheduler%']));

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->getDefinition(TasksSection::class)->getArgument(0));
        static::assertTrue($container->hasDefinition(MessengerRecorder::class));
    }

    public function testDoesNothingWithoutTheMessengerRecorder(): void
    {
        $container = new ContainerBuilder();

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->hasDefinition(MessengerRecorder::class));
    }

    public function testTheQueuesAreCountedByNameWithoutTheSchedulerAndSynchronousTransports(): void
    {
        $container = $this->container(['messenger.receiver_locator', ...self::QUEUES]);
        $container->setDefinition(QueuesCommand::class, new Definition(QueuesCommand::class, [null, []]));
        $container->setDefinition('messenger.transport.async', (new Definition(\stdClass::class, ['%env(MESSENGER_TRANSPORT_DSN)%']))->addTag('messenger.receiver', ['alias' => 'async']));
        $container->setDefinition('messenger.transport.scheduler_default', (new Definition(\stdClass::class, ['schedule://default']))->addTag('messenger.receiver', ['alias' => 'scheduler_default']));
        $container->setDefinition('messenger.transport.now', (new Definition(\stdClass::class, ['sync://']))->addTag('messenger.receiver', ['alias' => 'now']));
        $container->setDefinition('app.receiver', (new Definition(\stdClass::class))->addTag('messenger.receiver'));

        (new ComponentAvailabilityPass())->process($container);

        static::assertSame(['async', 'app.receiver'], $container->getDefinition(QueuesCommand::class)->getArgument(1));
        static::assertSame([true, true, true, true], array_map($container->hasDefinition(...), self::QUEUES));
    }

    public function testWithoutATransportToCountTheQueuesAreNotCounted(): void
    {
        $container = $this->container(['messenger.receiver_locator', ...self::QUEUES]);
        $container->setDefinition(QueuesCommand::class, new Definition(QueuesCommand::class, [null, []]));
        $container->setDefinition('messenger.transport.scheduler_default', (new Definition(\stdClass::class, ['schedule://default']))->addTag('messenger.receiver', ['alias' => 'scheduler_default']));
        $container->setDefinition('messenger.transport.now', (new Definition(\stdClass::class, ['sync://']))->addTag('messenger.receiver', ['alias' => 'now']));

        (new ComponentAvailabilityPass())->process($container);

        static::assertFalse($container->hasDefinition(QueuesCommand::class));
        static::assertSame([false, false, false, false], array_map($container->hasDefinition(...), self::QUEUES));
        static::assertTrue($container->hasDefinition(MessengerRecorder::class));
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
