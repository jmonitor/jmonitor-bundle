<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Availability;
use Jmonitor\JmonitorBundle\Stack\Detection\Detection;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\Detectors;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Detection\StaticDetector;
use PHPUnit\Framework\TestCase;

final class ComponentRegistryTest extends TestCase
{
    public function testAutoKeepsFoundComponentsInEnumOrder(): void
    {
        $registry = Detectors::registry([
            'php' => new StaticDetector(Component::Php, $this->found(Component::Php)),
            'system' => new StaticDetector(Component::System, $this->found(Component::System)),
            'symfony' => new StaticDetector(Component::Symfony, Detection::unavailable('No Symfony kernel')),
        ], $this->config());

        self::assertSame([Component::System, Component::Php], $registry->available());
        self::assertTrue($registry->isAvailable(Component::Php));
        self::assertFalse($registry->isAvailable(Component::Symfony));
        self::assertSame([], $registry->instancesFor(Component::Symfony));
        self::assertNull($registry->instance(Component::Symfony, null), 'an auto component that is not found is simply absent');
    }

    public function testForcedComponentStaysListedWithItsError(): void
    {
        $registry = Detectors::registry([
            'symfony' => new StaticDetector(Component::Symfony, Detection::unavailable('Connection refused')),
        ], $this->config(['symfony' => ['enabled' => true]]));

        self::assertSame([Component::Symfony], $registry->available());
        $instance = $registry->instance(Component::Symfony, null);
        self::assertNotNull($instance);
        self::assertFalse($instance->isFound());
        self::assertNull($instance->id);
        self::assertSame('Connection refused', $instance->error);
        self::assertSame([$instance], $registry->instancesFor(Component::Symfony));
    }

    public function testDisabledComponentIsNeverDetected(): void
    {
        $detector = new StaticDetector(Component::Php, $this->found(Component::Php));
        $registry = Detectors::registry(['php' => $detector], $this->config(['php' => ['enabled' => false]]));

        self::assertSame([], $registry->available());
        self::assertSame(0, $detector->calls);
    }

    public function testDetectionRunsOnceUntilReset(): void
    {
        $detector = new StaticDetector(Component::Php, $this->found(Component::Php));
        $registry = Detectors::registry(['php' => $detector], $this->config());

        $registry->available();
        $registry->instancesFor(Component::Php);
        self::assertSame(1, $detector->calls);

        $registry->reset();
        $registry->available();
        self::assertSame(2, $detector->calls);
    }

    public function testAComponentIsDetectedWhenAskedFor(): void
    {
        $symfony = new StaticDetector(Component::Symfony, $this->found(Component::Symfony));
        $php = new StaticDetector(Component::Php, $this->found(Component::Php));
        $registry = Detectors::registry(['symfony' => $symfony, 'php' => $php], $this->config());

        self::assertNotNull($registry->instance(Component::Php, null));
        self::assertTrue($registry->isAvailable(Component::Php));
        self::assertSame(0, $symfony->calls, 'the other components are left alone');

        self::assertSame([Component::Php, Component::Symfony], $registry->available());
        self::assertSame(1, $symfony->calls);
        self::assertSame(1, $php->calls);
    }

    public function testTheImplicitInstanceCarriesTheDetectedSet(): void
    {
        $set = new CollectorSet(Component::Php, [new CollectorSpec(new FakeCollector('php'), Lifetime::Dynamic, CostClass::Immediate)]);
        $registry = Detectors::registry(['php' => new StaticDetector(Component::Php, Detection::found($set))], $this->config());

        self::assertSame($set, $registry->instance(Component::Php, null)?->collectors);
        self::assertNull($registry->instance(Component::Php, 'anything'), 'an implicit instance has no id');
    }

    public function testANamedInstanceIsFoundByItsIdAndTheFirstOneByDefault(): void
    {
        $default = Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'default', 'shop');
        $legacy = Instance::found(Component::Symfony, new CollectorSet(Component::Symfony, []), 'legacy', 'erp');
        $registry = Detectors::registry(['symfony' => new StaticDetector(Component::Symfony, Detection::instances([$default, $legacy]))], $this->config());

        self::assertSame([$default, $legacy], $registry->instancesFor(Component::Symfony));
        self::assertSame($default, $registry->instance(Component::Symfony, null));
        self::assertSame($legacy, $registry->instance(Component::Symfony, 'legacy'));
        self::assertNull($registry->instance(Component::Symfony, 'nope'));
    }

    public function testAThrowingDetectorBecomesAnUnavailableComponent(): void
    {
        $throwing = new class implements \Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface {
            public function component(): Component
            {
                return Component::Symfony;
            }

            public function detect(): Detection
            {
                throw new \RuntimeException('Malformed DSN');
            }
        };

        $auto = Detectors::registry(['symfony' => $throwing, 'php' => new StaticDetector(Component::Php, $this->found(Component::Php))], $this->config());
        self::assertSame([Component::Php], $auto->available(), 'auto: the component is simply absent, the other components are still listed');

        $forced = Detectors::registry(['symfony' => $throwing], $this->config(['symfony' => ['enabled' => true]]));
        self::assertSame([Component::Symfony], $forced->available());
        self::assertSame('Malformed DSN', $forced->instance(Component::Symfony, null)?->error);
    }

    public function testADetectorThatCannotBeBuiltIsAComponentThatIsNotFound(): void
    {
        $unbuildable = static fn(): never => throw new \RuntimeException('Environment variable not found: "SOME_VARIABLE".');
        $detectors = ['symfony' => $unbuildable, 'php' => new StaticDetector(Component::Php, $this->found(Component::Php))];

        $auto = Detectors::registry($detectors, $this->config());
        self::assertSame([Component::Php], $auto->available());
        self::assertSame('Environment variable not found: "SOME_VARIABLE".', $auto->status(Component::Symfony)->reason);

        $forced = Detectors::registry($detectors, $this->config(['symfony' => ['enabled' => true]]));
        self::assertSame('Environment variable not found: "SOME_VARIABLE".', $forced->instance(Component::Symfony, null)?->error);

        $disabled = Detectors::registry($detectors, $this->config(['symfony' => ['enabled' => false]]));
        self::assertSame(Availability::Disabled, $disabled->status(Component::Symfony)->availability);
    }

    public function testADetectorIsBuiltWhenItsComponentIsDetected(): void
    {
        $built = [];
        $detector = function (Component $component) use (&$built): \Closure {
            return function () use ($component, &$built): StaticDetector {
                $built[] = $component->value;

                return new StaticDetector($component, $this->found($component));
            };
        };
        $registry = Detectors::registry(['php' => $detector(Component::Php), 'symfony' => $detector(Component::Symfony)], $this->config());

        self::assertTrue($registry->isAvailable(Component::Php));
        self::assertSame(['php'], $built);
    }

    public function testTheStatusOfADisabledComponentIsDisabled(): void
    {
        $registry = Detectors::registry(['php' => new StaticDetector(Component::Php, $this->found(Component::Php))], $this->config(['php' => ['enabled' => false]]));

        $status = $registry->status(Component::Php);

        self::assertSame(Component::Php, $status->component);
        self::assertSame(Availability::Disabled, $status->availability);
        self::assertSame([], $status->instances);
        self::assertNull($status->reason);
    }

    public function testAComponentWithoutDetectorIsNotDetected(): void
    {
        $registry = Detectors::registry([], $this->config());

        $status = $registry->status(Component::Php);

        self::assertSame(Availability::NotDetected, $status->availability);
        self::assertSame(Instance::UNAVAILABLE, $status->reason);
    }

    public function testTheStatusOfAnUndetectedComponentKeepsTheReason(): void
    {
        $registry = Detectors::registry(['system' => new StaticDetector(Component::System, Detection::unavailable('The server is not available.'))], $this->config());

        $status = $registry->status(Component::System);

        self::assertSame(Availability::NotDetected, $status->availability);
        self::assertSame([], $status->instances);
        self::assertSame('The server is not available.', $status->reason);
    }

    public function testTheStatusOfAThrowingDetectorKeepsItsMessage(): void
    {
        $throwing = new class implements \Jmonitor\JmonitorBundle\Stack\Detection\ComponentDetectorInterface {
            public function component(): Component
            {
                return Component::Symfony;
            }

            public function detect(): Detection
            {
                throw new \RuntimeException('boom');
            }
        };
        $registry = Detectors::registry(['symfony' => $throwing], $this->config());

        $status = $registry->status(Component::Symfony);

        self::assertSame(Availability::NotDetected, $status->availability);
        self::assertSame('boom', $status->reason);
    }

    public function testTheStatusOfAForcedComponentHoldsAnInstanceInError(): void
    {
        $registry = Detectors::registry(['symfony' => new StaticDetector(Component::Symfony, Detection::unavailable('nope'))], $this->config(['symfony' => ['enabled' => true]]));

        $status = $registry->status(Component::Symfony);

        self::assertSame(Availability::Available, $status->availability);
        self::assertCount(1, $status->instances);
        self::assertSame('nope', $status->instances[0]->error);
    }

    public function testTheStatusOfAFoundComponentHoldsItsInstances(): void
    {
        $registry = Detectors::registry(['php' => new StaticDetector(Component::Php, $this->found(Component::Php))], $this->config());

        $status = $registry->status(Component::Php);

        self::assertSame(Availability::Available, $status->availability);
        self::assertSame($registry->instancesFor(Component::Php), $status->instances);
        self::assertNull($status->reason);
    }

    public function testTheStatusIsDetectedOnce(): void
    {
        $detector = new StaticDetector(Component::Php, $this->found(Component::Php));
        $registry = Detectors::registry(['php' => $detector], $this->config());

        $registry->status(Component::Php);
        $registry->status(Component::Php);
        self::assertSame(1, $detector->calls);

        $registry->reset();
        $registry->status(Component::Php);
        self::assertSame(2, $detector->calls);
    }

    private function found(Component $component): Detection
    {
        return Detection::found(new CollectorSet($component, [new CollectorSpec(new FakeCollector($component->value), Lifetime::Dynamic, CostClass::Immediate)]));
    }

    /**
     * @param array<string, array{enabled: 'auto'|bool}> $overrides
     *
     * @return array<string, array{enabled: 'auto'|bool}>
     */
    private function config(array $overrides = []): array
    {
        $config = [];

        foreach (Component::cases() as $component) {
            $config[$component->value] = ['enabled' => 'auto'];
        }

        return array_replace($config, $overrides);
    }
}
