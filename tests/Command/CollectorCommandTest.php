<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Command;

use Jmonitor\CollectionResult;
use Jmonitor\Exceptions\NoCollectorException;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Command\CollectorCommand;
use Monolog\ResettableInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Service\ResetInterface;

class CollectorCommandTest extends TestCase
{
    public function testLoggerImplementingSymfonyResetInterfaceIsResetOnEachIteration(): void
    {
        $logger = new class extends AbstractLogger implements ResetInterface {
            public int $resetCount = 0;

            public function log($level, $message, array $context = []): void {}

            public function reset(): void
            {
                $this->resetCount++;
            }
        };

        $this->runWorkerIterations(2, $logger);

        $this->assertSame(2, $logger->resetCount);
    }

    public function testLoggerImplementingMonologResettableInterfaceIsResetOnEachIteration(): void
    {
        $logger = new class extends AbstractLogger implements ResettableInterface {
            public int $resetCount = 0;

            public function log($level, $message, array $context = []): void {}

            public function reset(): void
            {
                $this->resetCount++;
            }
        };

        $this->runWorkerIterations(2, $logger);

        $this->assertSame(2, $logger->resetCount);
    }

    public function testCommandRunsWithNonResettableLogger(): void
    {
        $exitCode = $this->runWorkerIterations(2, null);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * Runs the worker for $iterations successful collect loops, then stops it
     * (collect() throws NoCollectorException, which makes the worker exit).
     */
    private function runWorkerIterations(int $iterations, ?\Psr\Log\LoggerInterface $logger): int
    {
        $result = new CollectionResult();
        $result->setResponse(new Response(200, ['x-ratelimit-retry-after' => '0']));

        $calls = 0;
        $jmonitor = $this->createMock(Jmonitor::class);
        $jmonitor->method('collect')->willReturnCallback(static function () use (&$calls, $iterations, $result) {
            if (++$calls > $iterations) {
                throw new NoCollectorException();
            }

            return $result;
        });

        $tester = new CommandTester(new CollectorCommand($jmonitor, $logger));

        return $tester->execute([]);
    }
}
