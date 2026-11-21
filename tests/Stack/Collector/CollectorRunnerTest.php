<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\Exceptions\BootFailedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class CollectorRunnerTest extends TestCase
{
    public function testBootsCollectsAndResets(): void
    {
        $collector = new FakeCollector('system', ['cpu' => ['load' => 12], 'empty' => null]);

        $data = (new CollectorRunner())->run($collector);

        self::assertSame(['cpu' => ['load' => 12]], $data, 'null values are dropped like the collector library does');
        self::assertSame(1, $collector->boots);
        self::assertSame(1, $collector->collects);
        self::assertSame(1, $collector->resets);
    }

    public function testBootFailureIsRethrownAndNothingIsCollected(): void
    {
        $collector = new FakeCollector('symfony.recipes', bootFailure: new BootFailedException('composer is not installed'));

        try {
            (new CollectorRunner())->run($collector);
            self::fail('Expected the boot failure to be thrown');
        } catch (BootFailedException $e) {
            self::assertSame('composer is not installed', $e->getMessage());
        }

        self::assertSame(0, $collector->collects);
    }

    public function testCollectFailureStillResetsTheCollector(): void
    {
        $collector = new FakeCollector('php', collectFailure: new \RuntimeException('Connection refused'));

        $this->expectException(\RuntimeException::class);

        try {
            (new CollectorRunner())->run($collector);
        } finally {
            self::assertSame(1, $collector->resets);
        }
    }

    public function testLoggerAwareCollectorsReceiveALogger(): void
    {
        $collector = new class implements CollectorInterface, LoggerAwareInterface {
            use LoggerAwareTrait;

            public function collect(): array
            {
                return ['has_logger' => $this->logger !== null];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'anonymous';
            }
        };

        self::assertSame(['has_logger' => true], (new CollectorRunner())->run($collector));
    }
}
