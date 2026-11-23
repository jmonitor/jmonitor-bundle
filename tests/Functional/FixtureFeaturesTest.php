<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Functional;

use Jmonitor\JmonitorBundle\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class FixtureFeaturesTest extends TestCase
{
    private const VARIABLES = ['APP_ENV', 'APP_DEBUG', 'JMONITOR_FIXTURE_FEATURES'];

    /** @var array<string, mixed> */
    private array $saved = [];

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = $_SERVER[$name] ?? null;
        }
        $_SERVER['APP_ENV'] = 'test';
        $_SERVER['APP_DEBUG'] = '0';
    }

    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            (new Filesystem())->remove($this->kernel->getVarDir());
        }

        foreach ($this->saved as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }

    public function testTheQueuesFeatureCountsTheQueuesWithoutCallingOut(): void
    {
        $container = $this->boot('embed, queues');

        self::assertTrue($container->getParameter('jmonitor.stack.symfony.messenger'));
        self::assertFalse($container->getParameter('jmonitor.stack.end_of_life'));
        self::assertFalse($container->getParameter('jmonitor.stack.symfony.flex'));
        self::assertTrue($container->has('messenger.default_bus'));
        self::assertFalse($container->has('jmonitor.tests.queues_runner'), 'the kernel of the environment starts the real command');
    }

    public function testWithoutTheQueuesFeatureTheQueuesAreNotCounted(): void
    {
        $container = $this->boot('messenger');

        self::assertFalse($container->getParameter('jmonitor.stack.symfony.messenger'));
    }

    private function boot(string $features): ContainerInterface
    {
        $_SERVER['JMONITOR_FIXTURE_FEATURES'] = $features;
        $this->kernel = TestKernel::fromEnvironment();
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }
}
