<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Check\Verdict;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the last display of the Stack screen found on this web server, kept for the badge of every other screen.
 */
final class StackSummary implements ResetInterface
{
    private const KEY = 'jmonitor.stack.summary.';
    private const SECONDS = 900;

    private readonly LoggerInterface $logger;

    private bool $loaded = false;

    /** @var ?array{at: int, critical: int, warning: int, components: array<string, string>} */
    private ?array $last = null;

    /**
     * @param ContainerInterface $services gives the pool of the stack under "cache"
     */
    public function __construct(
        private readonly ContainerInterface $services,
        private readonly WebNode $node,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function remember(StackState $state, StackHealth $health): void
    {
        if ($state->error !== null || $state->cache === CacheState::OutOfOrder) {
            return;
        }

        $components = [];

        foreach ($state->components as $component) {
            $components[$component->component->value] = $health->componentVerdict($component->component)->value;
        }

        $this->loaded = true;
        $this->last = [
            'at' => $this->now(),
            'critical' => $health->count(Severity::Critical),
            'warning' => $health->count(Severity::Warning),
            'components' => $components,
        ];

        try {
            $pool = $this->pool();
            $pool->save($pool->getItem($this->key())->set($this->last)->expiresAfter(self::SECONDS));
        } catch (\Throwable $e) {
            $this->failed('written', $e);
        }
    }

    /**
     * @return ?array{at: int, critical: int, warning: int, components: array<string, string>} component value => Verdict value
     */
    public function last(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;

            try {
                $item = $this->pool()->getItem($this->key());
                $this->last = $item->isHit() ? self::summary($item->get()) : null;
            } catch (\Throwable $e) {
                $this->failed('read', $e);
            }
        }

        return $this->last !== null && $this->now() - $this->last['at'] <= self::SECONDS ? $this->last : null;
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->last = null;
    }

    /**
     * @return ?array{at: int, critical: int, warning: int, components: array<string, string>}
     */
    private static function summary(mixed $payload): ?array
    {
        if (!is_array($payload) || array_keys($payload) !== ['at', 'critical', 'warning', 'components']) {
            return null;
        }

        ['at' => $at, 'critical' => $critical, 'warning' => $warning, 'components' => $components] = $payload;

        if (!is_int($at) || !is_int($critical) || !is_int($warning) || $critical < 0 || $warning < 0 || !is_array($components)) {
            return null;
        }

        foreach ($components as $component => $verdict) {
            if (Component::tryFrom((string) $component) === null || !is_string($verdict) || Verdict::tryFrom($verdict) === null) {
                return null;
            }
        }

        /** @var array<string, string> $components */
        return ['at' => $at, 'critical' => $critical, 'warning' => $warning, 'components' => $components];
    }

    private function pool(): CacheItemPoolInterface
    {
        $pool = $this->services->get('cache');

        return $pool instanceof CacheItemPoolInterface ? $pool : throw new \LogicException('The pool of the live stack is not a PSR-6 pool.');
    }

    private function failed(string $action, \Throwable $e): void
    {
        $this->logger->warning('The summary of the live stack could not be ' . $action . ': {reason}', [
            'reason' => ErrorMessage::clean(ErrorMessage::of($e)),
            'exception' => $e,
        ]);
    }

    private function key(): string
    {
        return self::KEY . $this->node->keyPrefix() . 'last';
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
