<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Component;

final readonly class CollectorSet
{
    /**
     * @param list<CollectorSpec> $specs
     * @param ?\Closure(): void   $release frees what the collectors opened, once the collect is over
     */
    public function __construct(
        public Component $component,
        private array $specs,
        private ?\Closure $release = null,
    ) {}

    /**
     * @return list<CollectorSpec>
     */
    public function specs(): array
    {
        return $this->specs;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn(CollectorSpec $spec): string => $spec->name, $this->specs);
    }

    public function get(string $name): ?CollectorSpec
    {
        foreach ($this->specs as $spec) {
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
