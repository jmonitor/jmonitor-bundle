<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Component;

final class CollectorSet
{
    /** @var ?\Closure(): self */
    private ?\Closure $build = null;

    /**
     * @param list<CollectorSpec> $specs
     * @param ?\Closure(): void   $release frees what the collectors opened, once the collect is over
     */
    public function __construct(
        public readonly Component $component,
        private array $specs,
        private ?\Closure $release = null,
    ) {}

    /**
     * Builds the collectors on first use, so that a component with many instances builds only those that are read.
     *
     * @param \Closure(): self $build
     */
    public static function lazy(Component $component, \Closure $build): self
    {
        $set = new self($component, []);
        $set->build = $build;

        return $set;
    }

    /**
     * True until specs() has built the set.
     */
    public function isLazy(): bool
    {
        return $this->build !== null;
    }

    /**
     * @return list<CollectorSpec>
     */
    public function specs(): array
    {
        if ($this->build !== null) {
            $built = ($this->build)();
            $this->build = null;
            $this->specs = $built->specs();
            $this->release = $built->release;
        }

        return $this->specs;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn(CollectorSpec $spec): string => $spec->name, $this->specs());
    }

    public function get(string $name): ?CollectorSpec
    {
        foreach ($this->specs() as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        return null;
    }

    public function release(): void
    {
        if ($this->release !== null) {
            ($this->release)();
        }
    }
}
