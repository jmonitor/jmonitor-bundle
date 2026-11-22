<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Psr\Clock\ClockInterface;

/**
 * How much collecting one display may do: immediate and network collectors start only early in the display,
 * slow collectors only in a fragment and within a time of their own.
 */
final class CollectBudget
{
    private const SECONDS = 1.0;
    private const SLOW_SECONDS = 2.0;

    private readonly float $startedAt;
    private float $slowSpent = 0.0;

    private function __construct(
        private readonly ClockInterface $clock,
        private readonly bool $slow,
    ) {
        $this->startedAt = $this->now();
    }

    public static function page(ClockInterface $clock): self
    {
        return new self($clock, false);
    }

    public static function fragment(ClockInterface $clock): self
    {
        return new self($clock, true);
    }

    public function allows(CostClass $cost): bool
    {
        if ($cost === CostClass::Slow) {
            return $this->slow && $this->slowSpent < self::SLOW_SECONDS;
        }

        return $this->now() - $this->startedAt < self::SECONDS;
    }

    public function ran(CostClass $cost, float $seconds): void
    {
        if ($cost === CostClass::Slow) {
            $this->slowSpent += $seconds;
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
