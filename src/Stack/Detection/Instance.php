<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Component;

/**
 * One monitored thing inside a component.
 * Components that monitor a single thing have one implicit instance, without id.
 */
final readonly class Instance
{
    public const UNAVAILABLE = 'This component is not available.';

    private function __construct(
        public Component $component,
        public ?string $id,
        public ?string $detail,
        public ?CollectorSet $collectors,
        public ?string $error,
    ) {}

    public static function found(Component $component, CollectorSet $collectors, ?string $id = null, ?string $detail = null): self
    {
        return new self($component, $id, $detail, $collectors, null);
    }

    public static function unavailable(Component $component, string $error, ?string $id = null, ?string $detail = null): self
    {
        return new self($component, $id, $detail, null, $error);
    }

    /**
     * @phpstan-assert-if-true CollectorSet $this->collectors
     */
    public function isFound(): bool
    {
        return $this->collectors !== null;
    }

    /**
     * "php" for the implicit instance, "mysql.main" for a named one, the id URL-encoded
     */
    public function key(): string
    {
        return $this->id === null ? $this->component->value : $this->component->value . '.' . rawurlencode($this->id);
    }
}
