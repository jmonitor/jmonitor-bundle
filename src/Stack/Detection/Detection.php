<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;

final readonly class Detection
{
    /**
     * @param list<Instance> $instances
     * @param ?string        $error     why there is no instance at all
     */
    private function __construct(
        public array $instances,
        public ?string $error,
    ) {}

    public static function found(CollectorSet $collectors): self
    {
        return new self([Instance::found($collectors->component, $collectors)], null);
    }

    /**
     * @param non-empty-list<Instance> $instances
     */
    public static function instances(array $instances): self
    {
        return new self($instances, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self([], $reason);
    }

    /**
     * @phpstan-assert-if-true non-empty-list<Instance> $this->instances
     */
    public function isFound(): bool
    {
        return $this->instances !== [];
    }
}
