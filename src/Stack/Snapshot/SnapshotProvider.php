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
use Random\Randomizer;

/**
 * Reads the collectors of instances behind the cache, within the budget of a display, one request at a time:
 * each value serves every reader for its lifetime, and an outdated one still shows while it is renewed.
 */
final readonly class SnapshotProvider
{
    private const WITNESS_SECONDS = 300;
    private const BUILD_FAILURE_SECONDS = 60;
    private const FORGOTTEN_SECONDS = 604800;
    private const REFUSED = 'The cache refused to keep this value, it may be too large.';

    private LoggerInterface $logger;
    private CacheKeys $keys;
    private Randomizer $random;

    public function __construct(
        private CollectorRunner $runner,
        private DeltaCalculator $deltas,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private WebNode $node,
        ?LoggerInterface $logger = null,
        ?Randomizer $random = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->keys = new CacheKeys($node);
        $this->random = $random ?? new Randomizer();
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
            $specs = $instance->isFound() ? $this->specs($instance->collectors, $instance, $guard, $now) : ErrorMessage::clean((string) $instance->error);

            if (is_string($specs)) {
                $errors[$instance->key()] = $specs;
            } else {
                $reads[$instance->key()] = $this->lookUp($instance, $specs, $guard, $now);
            }
        }

        foreach ($reads as $read) {
            if ($this->mayCollect($read, $budget, $cache, $guard)) {
                $this->takeWitness($read, $guard);
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
     * a new forget marker outdates them.
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
                $guard->attempt(fn(): bool => $this->cache->save($this->cache->getItem($forgotten)->set($this->token())->expiresAfter(self::FORGOTTEN_SECONDS)), false);
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
            $failure = new StoredFailure(self::describe($e), $now + self::BUILD_FAILURE_SECONDS, null);
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
        $marker = $forgotten === null ? null : ($items[$forgotten] ?? null)?->get();
        $marker = is_string($marker) ? $marker : null;
        $values = [];
        $failures = [];

        foreach ($specs as $spec) {
            $value = StoredValue::fromCache(($items[$this->keys->result($instance, $spec)] ?? null)?->get());
            $failure = StoredFailure::fromCache(($items[$this->keys->failure($instance, $spec)] ?? null)?->get());

            if ($value !== null && $value->isShown($spec->lifetime, $now) && !self::outdated($spec, $value->forgotten, $marker)) {
                $values[$spec->name] = $value;
            }

            if ($failure !== null && $failure->isKept($now) && !self::outdated($spec, $failure->forgotten, $marker)) {
                $failures[$spec->name] = $failure;
            }
        }

        $read = new InstanceRead($instance, $specs, $items, ($items[$witness] ?? null)?->isHit() ?? false, $marker, $values, $failures);

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

        if (!self::cacheAllows($spec, $cache, $guard) || !$budget->allows($spec->cost)) {
            return $this->shown($spec, $value, $now, ResultState::Pending);
        }

        $startedAt = $this->seconds();
        $result = $this->collect($read, $spec, $guard);
        $budget->ran($spec->cost, $this->seconds() - $startedAt);

        return $result;
    }

    /**
     * Whether a collector without a result may run now: the budget and the cache only allow less as the read goes on.
     */
    private function mayCollect(InstanceRead $read, CollectBudget $budget, CacheState $cache, CacheGuard $guard): bool
    {
        if ($read->busy) {
            return false;
        }

        foreach ($read->specs as $spec) {
            if (!isset($read->results[$spec->name]) && self::cacheAllows($spec, $cache, $guard) && $budget->allows($spec->cost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Without a cache that keeps its values, a collector that costs a call would run at every display.
     */
    private static function cacheAllows(CollectorSpec $spec, CacheState $cache, CacheGuard $guard): bool
    {
        return $spec->cost === CostClass::Immediate || ($cache === CacheState::Working && $guard->works());
    }

    private function collect(InstanceRead $read, CollectorSpec $spec, CacheGuard $guard): CollectorResult
    {
        $read->ran = true;

        try {
            $data = $this->runner->run($spec->collector);
        } catch (\Throwable $e) {
            $now = $this->now();
            $kept = self::keptFor($e, $spec->cost);
            $failure = new StoredFailure(self::describe($e), $now + $kept, $read->forgotten);
            $read->write($spec, $this->keys->failure($read->instance, $spec), $failure->toCache(), $kept, value: false);

            return $this->shown($spec, $read->values[$spec->name] ?? null, $now, ResultState::Failed, $failure->error, $failure->until);
        }

        $now = $this->now();
        $delta = $spec->trackDelta ? $guard->attempt(fn(): ?Delta => $this->deltas->track($this->keys->suffix($read->instance, $spec), $data, $now), null) : null;
        $value = new StoredValue($data, $now, $delta, $read->forgotten);
        $read->write($spec, $this->keys->result($read->instance, $spec), $value->toCache(), $spec->lifetime->keptFor(), value: true);

        return $this->shown($spec, $value, $now);
    }

    private function takeWitness(InstanceRead $read, CacheGuard $guard): void
    {
        $item = $read->items[$this->keys->witness($read->instance)] ?? null;

        if ($read->token !== null || $item === null) {
            return;
        }

        $token = $guard->attempt(function () use ($item): ?string {
            $token = $this->token();

            return $this->cache->save($item->set($token)->expiresAfter(self::WITNESS_SECONDS)) ? $token : null;
        }, null);

        if ($token === null) {
            $guard->refused('the witness', true);
        }

        $read->token = $token;
    }

    /**
     * Writes first, so that the next request finds the values once the witness is lifted.
     */
    private function finish(InstanceRead $read, CacheGuard $guard): void
    {
        if ($read->writes !== []) {
            $committed = $guard->attempt(function () use ($read): bool {
                foreach ($read->writes as $write) {
                    $this->cache->saveDeferred($write['item']);
                }

                return $this->cache->commit();
            }, false);

            if (!$committed && $guard->works()) {
                $guard->refused('the values of ' . $read->instance->key(), false);
                $this->writeOneByOne($read, $guard);
            }
        }

        if ($read->token !== null) {
            $this->liftWitness($read->instance, $read->token, $guard);
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

    /**
     * A value the cache refuses, one too large for it, leaves a failure in its place: without it, its collector
     * would run again at every display.
     */
    private function writeOneByOne(InstanceRead $read, CacheGuard $guard): void
    {
        foreach ($read->writes as ['item' => $item, 'spec' => $spec, 'value' => $value]) {
            $saved = $guard->attempt(fn(): bool => $this->cache->save($item), false);

            if ($saved || !$value || $spec->cost === CostClass::Immediate) {
                continue;
            }

            $failure = $read->items[$this->keys->failure($read->instance, $spec)] ?? null;

            if ($failure !== null) {
                $kept = self::keptAfterFailure($spec->cost);
                $refused = new StoredFailure(self::REFUSED, $this->now() + $kept, $read->forgotten);
                $guard->attempt(fn(): bool => $this->cache->save($failure->set($refused->toCache())->expiresAfter($kept)), false);
            }
        }
    }

    /**
     * A witness left behind holds every reader off.
     */
    private function liftWitness(Instance $instance, string $token, CacheGuard $guard): void
    {
        $witness = $this->keys->witness($instance);

        $guard->lift(function () use ($witness, $token): void {
            if ($this->cache->getItem($witness)->get() === $token) {
                $this->cache->deleteItem($witness);
            }
        });
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
            $retryAt ?? $value->collectedAt + $spec->lifetime->seconds(),
        );
    }

    /**
     * Compares forget markers, not times: the web servers sharing the cache may not share a clock.
     * A day-long value survives a forget: what it reads is not what one fixes and checks again.
     */
    private static function outdated(CollectorSpec $spec, ?string $writtenUnder, ?string $marker): bool
    {
        return $writtenUnder !== $marker && $spec->lifetime !== Lifetime::Day;
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
            default => self::keptAfterFailure($cost),
        };
    }

    private static function keptAfterFailure(CostClass $cost): int
    {
        return $cost === CostClass::Slow ? 600 : 10;
    }

    private static function describe(\Throwable $e): string
    {
        return ErrorMessage::clean(ErrorMessage::of($e));
    }

    private function token(): string
    {
        return bin2hex($this->random->getBytes(8));
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
