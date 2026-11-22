<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Component;

final readonly class StackHealth
{
    /** @var list<Finding> sorted by severity, then by Component::cases(), then by id */
    public array $findings;

    /**
     * @param list<Finding>                                                         $findings
     * @param list<array{component: Component, instance: ?string, verdict: Verdict}> $verdicts one per instance
     */
    public function __construct(
        array $findings,
        private array $verdicts,
    ) {
        $severities = array_flip(array_map(static fn(Severity $severity): string => $severity->value, Severity::cases()));
        $components = array_flip(array_map(static fn(Component $component): string => $component->value, Component::cases()));

        usort($findings, static fn(Finding $a, Finding $b): int => [$severities[$a->severity->value], $components[$a->component->value], $a->id]
            <=> [$severities[$b->severity->value], $components[$b->component->value], $b->id]);

        $this->findings = $findings;
    }

    public function count(Severity $severity): int
    {
        return count(array_filter($this->findings, static fn(Finding $finding): bool => $finding->severity === $severity));
    }

    /**
     * @return list<Finding>
     */
    public function of(Component $component, ?string $instance = null): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(Finding $finding): bool => $finding->component === $component && $finding->instance === $instance,
        ));
    }

    public function verdict(Component $component, ?string $instance = null): Verdict
    {
        foreach ($this->verdicts as $verdict) {
            if ($verdict['component'] === $component && $verdict['instance'] === $instance) {
                return $verdict['verdict'];
            }
        }

        return Verdict::Unknown;
    }

    public function componentVerdict(Component $component): Verdict
    {
        return Verdict::worst(...array_map(
            static fn(array $verdict): Verdict => $verdict['verdict'],
            array_values(array_filter($this->verdicts, static fn(array $verdict): bool => $verdict['component'] === $component)),
        ));
    }
}
