<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Redis\RedisCollector as LibraryCollector;

final readonly class RedisInfoCollector implements CollectorInterface
{
    public function __construct(
        private RedisLink $link,
    ) {}

    /**
     * @return array<string, mixed> what the library reads of INFO, without the configuration, and the name and the version of Valkey
     *
     * @throws UnreachableServerException
     * @throws MissingPrerequisiteException
     */
    public function collect(): array
    {
        $info = $this->link->info();
        // @phpstan-ignore argument.type (the library only calls info() and config() on its client)
        $kept = (new LibraryCollector(new class ($info) {
            /**
             * @param array<string, mixed> $info
             */
            public function __construct(
                private readonly array $info,
            ) {}

            /**
             * @return array<string, mixed>
             */
            public function info(): array
            {
                return $this->info;
            }

            /**
             * @return array<string, mixed>
             */
            public function config(mixed ...$arguments): array
            {
                return [];
            }
        }))->collect();

        unset($kept['config']);
        $kept['server']['name'] = $info['server_name'] ?? null;
        $kept['server']['valkey_version'] = $info['valkey_version'] ?? null;

        return $kept;
    }

    public function getVersion(): int
    {
        return (new \ReflectionClass(LibraryCollector::class))->newInstanceWithoutConstructor()->getVersion();
    }

    public function getName(): string
    {
        return 'redis';
    }
}
