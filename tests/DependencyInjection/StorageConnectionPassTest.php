<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\DependencyInjection\StorageConnectionPass;
use Jmonitor\JmonitorBundle\Flush\Flusher;
use Jmonitor\JmonitorBundle\Recording\RequestRecorder;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalReader;
use Jmonitor\JmonitorBundle\Storage\Dbal\DbalStorage;
use Jmonitor\JmonitorBundle\Storage\Dbal\SchemaAssetFilter;
use Jmonitor\JmonitorBundle\Storage\ReaderInterface;
use Jmonitor\JmonitorBundle\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class StorageConnectionPassTest extends TestCase
{
    private const STORAGE_SERVICES = [RequestRecorder::class, Flusher::class, DbalStorage::class, DbalReader::class, SchemaAssetFilter::class];

    public function testKeepsTheStorageWhenTheConnectionExists(): void
    {
        $container = $this->container('default', ['doctrine.dbal.default_connection']);

        (new StorageConnectionPass())->process($container);

        static::assertTrue($container->getParameter('jmonitor.storage.available'));
        foreach (self::STORAGE_SERVICES as $id) {
            static::assertTrue($container->hasDefinition($id), $id);
        }
        static::assertTrue($container->hasAlias(StorageInterface::class));
        static::assertTrue($container->hasAlias(ReaderInterface::class));
    }

    public function testRemovesTheStorageWithoutTheConnection(): void
    {
        $container = $this->container('default', []);

        (new StorageConnectionPass())->process($container);

        static::assertFalse($container->getParameter('jmonitor.storage.available'));
        foreach (self::STORAGE_SERVICES as $id) {
            static::assertFalse($container->hasDefinition($id), $id);
        }
        static::assertFalse($container->hasAlias(StorageInterface::class));
        static::assertFalse($container->hasAlias(ReaderInterface::class));
        static::assertTrue($container->hasDefinition(FileBuffer::class));
        static::assertTrue($container->hasAlias(BufferInterface::class));
    }

    public function testLooksForTheConfiguredConnection(): void
    {
        $container = $this->container('analytics', ['doctrine.dbal.default_connection']);

        (new StorageConnectionPass())->process($container);

        static::assertFalse($container->getParameter('jmonitor.storage.available'));
        static::assertFalse($container->hasDefinition(DbalStorage::class));

        $container = $this->container('analytics', ['doctrine.dbal.analytics_connection']);

        (new StorageConnectionPass())->process($container);

        static::assertTrue($container->getParameter('jmonitor.storage.available'));
        static::assertTrue($container->hasDefinition(DbalStorage::class));
    }

    public function testDoesNothingWhenTheBundleIsNotConfigured(): void
    {
        $container = new ContainerBuilder();

        (new StorageConnectionPass())->process($container);

        static::assertFalse($container->hasParameter('jmonitor.storage.available'));
    }

    /**
     * @param list<string> $connections
     */
    private function container(string $connection, array $connections): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('jmonitor.storage.connection', $connection);

        foreach ([...self::STORAGE_SERVICES, FileBuffer::class, ...$connections] as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }
        $container->setAlias(BufferInterface::class, FileBuffer::class);
        $container->setAlias(StorageInterface::class, DbalStorage::class);
        $container->setAlias(ReaderInterface::class, DbalReader::class);

        return $container;
    }
}
