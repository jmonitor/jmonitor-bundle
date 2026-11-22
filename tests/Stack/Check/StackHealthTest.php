<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackChecks;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Snapshots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class StackHealthTest extends TestCase
{
    public function testFindingsAreSortedBySeverityThenComponent(): void
    {
        $health = new StackHealth([
            self::finding('symfony.flex_recipes', Component::Symfony, Severity::Info),
            self::finding('php.opcache_memory', Component::Php, Severity::Warning),
            self::finding('symfony.debug_in_prod', Component::Symfony, Severity::Critical),
            self::finding('php.display_errors', Component::Php, Severity::Warning),
            self::finding('system.disk', Component::System, Severity::Warning),
            self::finding('php.end_of_life', Component::Php, Severity::Critical),
        ], []);

        self::assertSame(
            ['php.end_of_life', 'symfony.debug_in_prod', 'system.disk', 'php.display_errors', 'php.opcache_memory', 'symfony.flex_recipes'],
            array_map(static fn(Finding $finding): string => $finding->id, $health->findings),
        );
        self::assertSame(2, $health->count(Severity::Critical));
        self::assertSame(3, $health->count(Severity::Warning));
        self::assertSame(1, $health->count(Severity::Info));
        self::assertSame(['php.end_of_life', 'php.display_errors', 'php.opcache_memory'], array_map(static fn(Finding $finding): string => $finding->id, $health->of(Component::Php)));
    }

    public function testFindingsAreOfTheirInstance(): void
    {
        $health = new StackHealth([
            self::finding('system.disk', Component::System, Severity::Warning, 'a'),
            self::finding('system.memory', Component::System, Severity::Warning, 'b'),
        ], []);

        self::assertSame('system.disk', $health->of(Component::System, 'a')[0]->id ?? null);
        self::assertCount(1, $health->of(Component::System, 'b'));
        self::assertSame([], $health->of(Component::System));
        self::assertSame([], $health->of(Component::Php, 'a'));
    }

    public function testTheComponentVerdictIsTheWorstOfItsInstances(): void
    {
        $health = new StackHealth([], [
            ['component' => Component::System, 'instance' => 'a', 'verdict' => Verdict::Healthy],
            ['component' => Component::System, 'instance' => 'b', 'verdict' => Verdict::Warning],
            ['component' => Component::Php, 'instance' => null, 'verdict' => Verdict::Healthy],
            ['component' => Component::Symfony, 'instance' => 'a', 'verdict' => Verdict::Healthy],
            ['component' => Component::Symfony, 'instance' => 'b', 'verdict' => Verdict::Unknown],
        ]);

        self::assertSame(Verdict::Healthy, $health->verdict(Component::System, 'a'));
        self::assertSame(Verdict::Warning, $health->verdict(Component::System, 'b'));
        self::assertSame(Verdict::Unknown, $health->verdict(Component::System, 'c'), 'an instance that is not there');
        self::assertSame(Verdict::Unknown, $health->verdict(Component::System), 'its instances all have an id');
        self::assertSame(Verdict::Warning, $health->componentVerdict(Component::System));
        self::assertSame(Verdict::Healthy, $health->componentVerdict(Component::Php));
        self::assertSame(Verdict::Unknown, $health->componentVerdict(Component::Symfony));
        self::assertSame(Verdict::Unknown, (new StackHealth([], []))->componentVerdict(Component::Php), 'no instance');
    }

    public function testTheWorstVerdict(): void
    {
        self::assertSame(Verdict::Critical, Verdict::worst(Verdict::Healthy, Verdict::Critical, Verdict::Warning, Verdict::Unknown));
        self::assertSame(Verdict::Warning, Verdict::worst(Verdict::Unknown, Verdict::Warning, Verdict::Healthy));
        self::assertSame(Verdict::Unknown, Verdict::worst(Verdict::Healthy, Verdict::Unknown));
        self::assertSame(Verdict::Healthy, Verdict::worst(Verdict::Healthy, Verdict::Healthy));
        self::assertSame(Verdict::Unknown, Verdict::worst());
    }

    public function testVerdictsAndSeveritiesSayAWord(): void
    {
        self::assertSame(['Critical', 'Warning', 'Healthy', 'Unknown'], array_map(static fn(Verdict $verdict): string => $verdict->label(), Verdict::cases()));
        self::assertSame(['Critical', 'Warning', 'Info'], array_map(static fn(Severity $severity): string => $severity->label(), Severity::cases()));
    }

    public function testItIsPlainData(): void
    {
        $memory = Snapshots::system();
        $memory['ram'] = ['total' => 1_000, 'available' => 40];
        $health = (new StackChecks([], false, new MockClock(new \DateTimeImmutable('@' . Snapshots::NOW)))
        )->health(Snapshots::state([
            'system' => [new InstanceState(null, null, null, Snapshots::of(Component::System, ['system' => $memory]))],
            'php' => [new InstanceState(null, null, null, null)],
        ]));

        $copy = unserialize(serialize($health));

        self::assertEquals($health, $copy);
        self::assertSame(Verdict::Critical, $copy->verdict(Component::System));
        self::assertSame(Verdict::Unknown, $copy->verdict(Component::Php));
        self::assertCount(1, $copy->findings);
    }

    private static function finding(string $id, Component $component, Severity $severity, ?string $instance = null): Finding
    {
        return new Finding($id, $component, $instance, $severity, $id, 'Why it matters.');
    }
}
