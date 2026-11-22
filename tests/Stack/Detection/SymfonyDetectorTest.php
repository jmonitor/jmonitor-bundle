<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\FlexRecipesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\KernelCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\ProcessRunner;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\SymfonyDetector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class SymfonyDetectorTest extends TestCase
{
    public function testTheKernelIsReadDirectly(): void
    {
        $detector = $this->detector(flex: false, endOfLife: false);
        $detection = $detector->detect();

        self::assertSame(Component::Symfony, $detector->component());
        self::assertTrue($detection->isFound());
        $collectors = $detection->instances[0]->collectors;
        self::assertNotNull($collectors);
        self::assertFalse($collectors->isLazy());
        self::assertSame(['symfony'], $collectors->names());

        $spec = $collectors->get('symfony');
        self::assertNotNull($spec);
        self::assertInstanceOf(KernelCollector::class, $spec->collector);
        self::assertSame(Lifetime::Dynamic, $spec->lifetime);
        self::assertSame(CostClass::Immediate, $spec->cost);
        self::assertFalse($spec->trackDelta);
        self::assertFalse($spec->shared);
    }

    public function testFlexRecipesNeedFlex(): void
    {
        self::assertFalse(class_exists('Symfony\Flex\SymfonyBundle'));

        $collectors = $this->detector(flex: true, endOfLife: false)->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['symfony'], $collectors->names());
    }

    #[RunInSeparateProcess]
    public function testFlexRecipesAreReadWhenFlexIsInstalled(): void
    {
        class_alias((new class {})::class, 'Symfony\Flex\SymfonyBundle');
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(['composer', 'recipes', '-o'], '/srv/app', 5)
            ->willReturn(['exit_code' => 0, 'output' => '', 'error_output' => '']);

        $collectors = $this->detector(flex: true, endOfLife: false, runner: $runner)->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['symfony', 'symfony.flex'], $collectors->names());

        $spec = $collectors->get('symfony.flex');
        self::assertNotNull($spec);
        self::assertInstanceOf(FlexRecipesCollector::class, $spec->collector);
        self::assertSame(Lifetime::Static, $spec->lifetime);
        self::assertSame(CostClass::Slow, $spec->cost);
        self::assertFalse($spec->trackDelta);
        self::assertFalse($spec->shared);

        $spec->collector->collect();
    }

    #[RunInSeparateProcess]
    public function testFlexRecipesAreNotReadWhenTheOptionIsOff(): void
    {
        class_alias((new class {})::class, 'Symfony\Flex\SymfonyBundle');

        $collectors = $this->detector(flex: false, endOfLife: false)->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['symfony'], $collectors->names());
    }

    public function testTheEndOfLifeIsReadOncePerDayForEveryServer(): void
    {
        $fetcher = new FakeUrlFetcher('[{"cycle": "7.4", "eol": "2029-11-30", "support": "2028-11-30"}]');
        $collectors = $this->detector(flex: false, endOfLife: true, fetcher: $fetcher)->detect()->instances[0]->collectors;

        self::assertNotNull($collectors);
        self::assertSame(['symfony', 'eol.symfony'], $collectors->names());

        $spec = $collectors->get('eol.symfony');
        self::assertNotNull($spec);
        self::assertInstanceOf(EndOfLifeCollector::class, $spec->collector);
        self::assertSame(Lifetime::Day, $spec->lifetime);
        self::assertSame(CostClass::Slow, $spec->cost);
        self::assertFalse($spec->trackDelta);
        self::assertTrue($spec->shared);

        $spec->collector->collect();
        self::assertSame('https://endoflife.date/api/symfony.json', $fetcher->url);
    }

    private function detector(bool $flex, bool $endOfLife, ?ProcessRunner $runner = null, ?FakeUrlFetcher $fetcher = null): SymfonyDetector
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/srv/app');
        $runner ??= $this->createStub(ProcessRunner::class);

        return $fetcher === null
            ? new SymfonyDetector($kernel, $runner, $flex, $endOfLife)
            : new SymfonyDetector($kernel, $runner, $flex, $endOfLife, $fetcher);
    }
}
