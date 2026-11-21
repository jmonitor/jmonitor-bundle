<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\MissingPrerequisiteException;
use Jmonitor\JmonitorBundle\Stack\Collector\UnreachableServerException;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads the collectors of instances behind the cache, within the budget of a display, one request at a time:
 * each value serves every reader for its lifetime, and an outdated one still shows while it is renewed.
 */
final readonly class SnapshotProvider
{
    private const WITNESS_SECONDS = 300;
    private const BUILD_FAILURE_SECONDS = 60;
    private const FORGOTTEN_SECONDS = 604800;

    private LoggerInterface $logger;
    private CacheKeys $keys;

    public function __construct(
        private CollectorRunner $runner,
        private DeltaCalculator $deltas,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private WebNode $node,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->keys = new CacheKeys($node);
    }

    /**
     * @param list<Instance> $instances
     *
     * @return array<string, ComponentSnapshot> keyed by Instance::key()
     */
    public function read(array $instances, CollectBudget $budget, CacheState $cache): array
    {
        $guard = new CacheGuard($this->logger, $cache !== CacheState::OutOfOrder);
        $now = $this->now();
        $errors = [];
        $reads = [];

        foreach ($instances as $instance) {
            $specs = $instance->isFound() ? $this->specs($instance->collectors, $instance, $guard, $now) : (string) $instance->error;

            if (is_string($specs)) {
                $errors[$instance->key()] = $specs;
            } else {
                $reads[$instance->key()] = $this->lookUp($instance, $specs, $guard, $now);
            }
        }

        foreach (CostClass::cases() as $cost) {
            foreach ($reads as $read) {
                foreach ($read->specs as $spec) {
                    if ($spec->cost === $cost && !isset($read->results[$spec->name])) {
                        $read->results[$spec->name] = $this->collectOrLeave($read, $spec, $budget, $cache, $guard, $now);
                    }
                }
            }
        }

        foreach ($reads as $read) {
            $this->finish($read, $guard);
        }

        $snapshots = [];

        foreach ($instances as $instance) {
            $read = $reads[$instance->key()] ?? null;
            $snapshots[$instance->key()] = new ComponentSnapshot(
                $instance->component,
                $read?->orderedResults() ?? [],
                $read === null ? $errors[$instance->key()] : null,
                $instance->component->isNodeLocal() ? $this->node->name : null,
            );
        }

        return $snapshots;
    }

    /**
     * Also lifts the witness of a request that died. The values of the other web servers cannot be listed:
     * the time of the forget outdates them.
     *
     * @param list<Instance> $instances
     */
    public function forget(array $instances): void
    {
        $guard = new CacheGuard($this->logger, true);
        $now = $this->now();

        foreach ($instances as $instance) {
            $specs = $instance->isFound() ? $this->specs($instance->collectors, $instance, $guard, $now) : [];
            $keys = [$this->keys->witness($instance), $this->keys->build($instance)];

            foreach (is_string($specs) ? [] : $specs as $spec) {
                if ($spec->lifetime !== Lifetime::Day) {
                    $keys[] = $this->keys->result($instance, $spec);
                    $keys[] = $this->keys->failure($instance, $spec);
                }
            }

            $guard->attempt(fn(): bool => $this->cache->deleteItems($keys), false);
            $forgotten = $this->keys->forgotten($instance);

            if ($forgotten !== null) {
                $guard->attempt(fn(): bool => $this->cache->save($this->cache->getItem($forgotten)->set($now)->expiresAfter(self::FORGOTTEN_SECONDS)), false);
            }
        }
    }

    /**
     * A failed build is kept: building a lazy set again may wait on the same server.
     *
     * @return list<CollectorSpec>|string the specs, or why the set cannot be built
     */
    private function specs(CollectorSet $set, Instance $instance, CacheGuard $guard, int $now): array|string
    {
        $key = $this->keys->build($instance);
        $item = null;

        if ($set->isLazy()) {
            $item = $guard->attempt(fn(): CacheItemInterface => $this->cache->getItem($key), null);
            $kept = StoredFailure::fromCache($item?->get());

            if ($kept !== null && $kept->isKept($now)) {
                return $kept->error;
            }
        }

        try {
            return $set->specs();
        } catch (\Throwable $e) {
            $failure = new StoredFailure(self::describe($e), $now, $now + self::BUILD_FAILURE_SECONDS);
            $guard->attempt(fn(): bool => $this->cache->save(($item ?? $this->cache->getItem($key))->set($failure->toCache())->expiresAfter(self::BUILD_FAILURE_SECONDS)), false);

            return $failure->error;
        }
    }

    /**
     * @param list<CollectorSpec> $specs
     */
    private function lookUp(Instance $instance, array $specs, CacheGuard $guard, int $now): InstanceRead
    {
        $witness = $this->keys->witness($instance);
        $forgotten = $this->keys->forgotten($instance);
        $keys = $forgotten === null ? [$witness] : [$witness, $forgotten];

        foreach ($specs as $spec) {
            $keys[] = $this->keys->result($instance, $spec);
            $keys[] = $this->keys->failure($instance, $spec);
        }

        /** @var array<string, CacheItemInterface> $items */
        $items = $guard->attempt(fn(): array => iterator_to_array($this->cache->getItems($keys)), []);
        $forgottenAt = $forgotten === null ? null : ($items[$forgotten] ?? null)?->get();
        $forgottenAt = is_int($forgottenAt) ? $forgottenAt : null;
        $values = [];
        $failures = [];

        foreach ($specs as $spec) {
            $value = StoredValue::fromCache(($items[$this->keys->result($instance, $spec)] ?? null)?->get());
            $failure = StoredFailure::fromCache(($items[$this->keys->failure($instance, $spec)] ?? null)?->get());

            if ($value !== null && $value->isShown($spec->lifetime, $now) && !self::outdated($spec, $value->collectedAt, $forgottenAt)) {
                $values[$spec->name] = $value;
            }

            if ($failure !== null && $failure->isKept($now) && !self::outdated($spec, $failure->failedAt, $forgottenAt)) {
                $failures[$spec->name] = $failure;
            }
        }

        $read = new InstanceRead($instance, $specs, $items, ($items[$witness] ?? null)?->isHit() ?? false, $values, $failures);

        foreach ($specs as $spec) {
            $value = $values[$spec->name] ?? null;
            $failure = $failures[$spec->name] ?? null;

            if ($value !== null && $value->isFresh($spec->lifetime, $now)) {
                $read->results[$spec->name] = $this->shown($spec, $value, $now);
            } elseif ($failure !== null) {
                $read->results[$spec->name] = $this->shown($spec, $value, $now, ResultState::Failed, $failure->error, $failure->until);
            }
        }

        return $read;
    }

    private function collectOrLeave(InstanceRead $read, CollectorSpec $spec, CollectBudget $budget, CacheState $cache, CacheGuard $guard, int $now): CollectorResult
    {
        $value = $read->values[$spec->name] ?? null;

        if ($read->busy) {
            return $this->shown($spec, $value, $now, ResultState::Busy);
        }

        $cacheAllows = $spec->cost === CostClass::Immediate || ($cache === CacheState::Working && $guard->works());

        if (!$cacheAllows || !$budget->allows($spec->cost)) {
            return $this->shown($spec, $value, $now, ResultState::Pending);
        }

        $this->takeWitness($read, $guard);
        $startedAt = $this->seconds();
        $result = $this->collect($read, $spec, $guard);
        $budget->ran($spec->cost, $this->seconds() - $startedAt);

        return $result;
    }

    private function collect(InstanceRead $read, CollectorSpec $spec, CacheGuard $guard): CollectorResult
    {
        $read->ran = true;
        $now = $this->now();

        try {
            $data = $this->runner->run($spec->collector);
        } catch (\Throwable $e) {
            $kept = self::keptFor($e, $spec->cost);
            $failure = new StoredFailure(self::describe($e), $now, $now + $kept);
            $read->write($this->keys->failure($read->instance, $spec), $failure->toCache(), $kept);

            return $this->shown($spec, $read->values[$spec->name] ?? null, $now, ResultState::Failed, $failure->error, $failure->until);
        }

        $delta = $spec->trackDelta ? $guard->attempt(fn(): ?Delta => $this->deltas->track($this->keys->suffix($read->instance, $spec), $data, $now), null) : null;
        $value = new StoredValue($data, $now, $delta);
        $read->write($this->keys->result($read->instance, $spec), $value->toCache(), $spec->lifetime->keptFor());

        return $this->shown($spec, $value, $now);
    }

    private function takeWitness(InstanceRead $read, CacheGuard $guard): void
    {
        $item = $read->items[$this->keys->witness($read->instance)] ?? null;

        if ($read->token !== null || $item === null) {
            return;
        }

        $token = bin2hex(random_bytes(8));
        $read->token = $token;
        $guard->attempt(fn(): bool => $this->cache->save($item->set($token)->expiresAfter(self::WITNESS_SECONDS)), false);
    }

    /**
     * Writes first, so that the next request finds the values once the witness is lifted.
     */
    private function finish(InstanceRead $read, CacheGuard $guard): void
    {
        if ($read->writes !== []) {
            $guard->attempt(function () use ($read): bool {
                foreach ($read->writes as $item) {
                    $this->cache->saveDeferred($item);
                }

                return $this->cache->commit();
            }, false);
        }

        $token = $read->token;

        if ($token !== null) {
            $witness = $this->keys->witness($read->instance);
            $guard->attempt(fn(): bool => $this->cache->getItem($witness)->get() === $token && $this->cache->deleteItem($witness), false);
        }

        if ($read->ran) {
            try {
                $read->instance->collectors?->release();
            } catch (\Throwable $e) {
                $this->logger->error('The collectors of {instance} could not be released: {reason}', [
                    'instance' => $read->instance->key(),
                    'reason' => self::describe($e),
                    'exception' => $e,
                ]);
            }
        }
    }

    private function shown(CollectorSpec $spec, ?StoredValue $value, int $now, ResultState $withoutValue = ResultState::Pending, ?string $error = null, ?int $retryAt = null): CollectorResult
    {
        if ($value === null) {
            return new CollectorResult($spec->name, [], $withoutValue, $error, expiresAt: $retryAt);
        }

        // a web server whose clock is ahead may have dated it in the future
        return new CollectorResult(
            $spec->name,
            $value->data,
            $value->isFresh($spec->lifetime, $now) ? ResultState::Fresh : ResultState::Stale,
            $error,
            min($value->collectedAt, $now),
            $value->delta,
            $value->collectedAt + $spec->lifetime->seconds(),
        );
    }

    /**
     * A day-long value survives a forget: what it reads is not what one fixes and checks again.
     */
    private static function outdated(CollectorSpec $spec, int $at, ?int $forgottenAt): bool
    {
        return $forgottenAt !== null && $at < $forgottenAt && $spec->lifetime !== Lifetime::Day;
    }

    /**
     * As long as retrying costs: a missing prerequisite does not come back soon, an unreachable server
     * makes each attempt wait.
     */
    private static function keptFor(\Throwable $e, CostClass $cost): int
    {
        $causes = [];

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause::class;
        }

        $has = static fn(string $class): bool => array_filter($causes, static fn(string $cause): bool => is_a($cause, $class, true)) !== [];

        return match (true) {
            $has(MissingPrerequisiteException::class) => 3600,
            $has(UnreachableServerException::class) => 60,
            $cost === CostClass::Slow => 600,
            default => 10,
        };
    }

    private static function describe(\Throwable $e): string
    {
        return ErrorMessage::clean(ErrorMessage::of($e));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
