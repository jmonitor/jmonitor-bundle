<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\ErrorMessage;
use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
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
    private const SILENT_SERVER_SECONDS = 2;
    private const LAPSED_FAILURE_SECONDS = 86400;
    private const FORGOTTEN_SECONDS = 604800;
    private const REFUSED = 'The cache refused to keep this value, it may be too large.';
    private const STOPPED_WAITING = 'The server did not answer before PHP stopped the request reading it.';
    private const STOPPED = 'PHP stopped the request before this was read.';

    /** Longer than a remote server can keep a collector waiting before it fails on its own bound. */
    private const REMOTE_TIME_LIMIT = 45;

    private LoggerInterface $logger;
    private CacheKeys $keys;
    private Randomizer $random;
    private PhpRequest $request;
    private ReadInFlight $inFlight;

    public function __construct(
        private CollectorRunner $runner,
        private DeltaCalculator $deltas,
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private WebNode $node,
        ?LoggerInterface $logger = null,
        ?Randomizer $random = null,
        ?PhpRequest $request = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->keys = new CacheKeys($node);
        $this->random = $random ?? new Randomizer();
        $this->request = $request ?? PhpRequest::current();
        $this->inFlight = new ReadInFlight();
    }

    /**
     * @param list<Instance> $instances
     * @param bool           $detail    whether the collectors only read for a detail are read too
     *
     * @return array<string, ComponentSnapshot> keyed by Instance::key()
     */
    public function read(array $instances, CollectBudget $budget, CacheState $cache, bool $detail = false): array
    {
        try {
            return $this->readInstances($instances, $budget, $cache, $detail);
        } finally {
            $this->putBackTimeLimit();
        }
    }

    /**
     * @param list<Instance> $instances
     *
     * @return array<string, ComponentSnapshot> keyed by Instance::key()
     */
    private function readInstances(array $instances, CollectBudget $budget, CacheState $cache, bool $detail): array
    {
        $guard = new CacheGuard($this->logger, $cache !== CacheState::OutOfOrder);
        $now = $this->now();
        $errors = [];
        $reads = [];

        foreach ($instances as $instance) {
            if ($instance->isFound()) {
                $specs = array_values(array_filter($instance->collectors->specs(), static fn(CollectorSpec $spec): bool => $detail || !$spec->detailOnly));
                $reads[$instance->key()] = $this->lookUp($instance, $specs, $guard, $now);
            } else {
                $errors[$instance->key()] = ErrorMessage::clean((string) $instance->error);
            }
        }

        foreach ($reads as $read) {
            if ($this->mayCollect($read, $budget, $cache, $guard)) {
                $this->takeWitness($read, $guard);
            }
        }

        /** @var array<string, CollectorResult> $reused by result key */
        $reused = [];

        foreach (CostClass::cases() as $cost) {
            foreach ($reads as $read) {
                foreach ($read->specs as $spec) {
                    if ($spec->cost !== $cost || isset($read->results[$spec->name])) {
                        continue;
                    }

                    $key = $this->keys->result($read->instance, $spec);
                    $result = $this->kept($read, $spec, $now) ?? (isset($read->lapsed[$spec->name]) ? null : $reused[$key] ?? null);

                    if ($result === null) {
                        $result = $this->collectOrLeave($read, $spec, $budget, $cache, $guard, $now, $reads);

                        if (self::reusable($spec, $result)) {
                            $reused[$key] = $result;
                        }
                    }

                    $read->results[$spec->name] = $result;
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
     * a new forget marker outdates them. A failure of a value of the server is not forgotten but ended: the
     * connection reads the server again at once, itself, instead of taking the value another connection reads.
     *
     * @param list<Instance> $instances
     */
    public function forget(array $instances): void
    {
        $guard = new CacheGuard($this->logger, true);
        $markers = [];

        foreach ($instances as $instance) {
            $keys = [$this->keys->witness($instance)];
            $ofServer = [];

            foreach ($instance->collectors?->specs() ?? [] as $spec) {
                if ($spec->lifetime !== Lifetime::Day) {
                    $keys[] = $this->keys->result($instance, $spec);

                    if ($spec->perServer) {
                        $ofServer[] = $this->keys->failure($instance, $spec);
                    } else {
                        $keys[] = $this->keys->failure($instance, $spec);
                    }
                }
            }

            $guard->attempt(fn(): bool => $this->cache->deleteItems($keys), false);

            foreach ([$this->keys->forgotten($instance), $this->keys->forgotten($instance, true)] as $forgotten) {
                if ($forgotten !== null && !array_key_exists($forgotten, $markers)) {
                    $markers[$forgotten] = null;
                    $guard->attempt(function () use ($forgotten, &$markers): bool {
                        $markers[$forgotten] = $this->token();

                        return $this->cache->save($this->cache->getItem($forgotten)->set($markers[$forgotten])->expiresAfter(self::FORGOTTEN_SECONDS));
                    }, false);
                }
            }

            if ($ofServer !== []) {
                $this->endFailures($ofServer, $markers[$this->keys->forgotten($instance) ?? ''] ?? null, $guard);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param ?string      $forgotten the forget marker of the instance just written
     */
    private function endFailures(array $keys, ?string $forgotten, CacheGuard $guard): void
    {
        $now = $this->now();

        $guard->attempt(function () use ($keys, $forgotten, $now): bool {
            $ended = false;

            foreach ($this->cache->getItems($keys) as $item) {
                $failure = $item->isHit() ? StoredFailure::fromCache($item->get()) : null;

                if ($failure !== null) {
                    $this->cache->saveDeferred($item->set((new StoredFailure($failure->error, $now, $forgotten, $failure->kind))->toCache())->expiresAfter(self::LAPSED_FAILURE_SECONDS));
                    $ended = true;
                }
            }

            return !$ended || $this->cache->commit();
        }, false);
    }

    /**
     * @param list<CollectorSpec> $specs
     */
    private function lookUp(Instance $instance, array $specs, CacheGuard $guard, int $now): InstanceRead
    {
        $witness = $this->keys->witness($instance);
        $keys = [$witness, ...array_filter(array_unique([$this->keys->forgotten($instance), $this->keys->forgotten($instance, true)]))];

        foreach ($specs as $spec) {
            $keys[] = $this->keys->result($instance, $spec);
            $keys[] = $this->keys->failure($instance, $spec);
        }

        /** @var array<string, CacheItemInterface> $items */
        $items = $guard->attempt(fn(): array => iterator_to_array($this->cache->getItems($keys)), []);
        $ownMarker = self::marker($items, $this->keys->forgotten($instance));
        $markers = [];
        $values = [];
        $failures = [];
        $lapsed = [];

        foreach ($specs as $spec) {
            $marker = $markers[$spec->name] = self::marker($items, $this->keys->forgottenFor($instance, $spec));
            $value = StoredValue::fromCache(($items[$this->keys->result($instance, $spec)] ?? null)?->get());
            $failure = StoredFailure::fromCache(($items[$this->keys->failure($instance, $spec)] ?? null)?->get());

            if ($value !== null && $value->isShown($spec->lifetime, $now) && !self::outdated($spec, $value->forgotten, $marker)) {
                $values[$spec->name] = $value;
            }

            if ($failure === null || self::outdated($spec, $failure->forgotten, $ownMarker)) {
                continue;
            }

            if ($failure->isKept($now)) {
                $failures[$spec->name] = $failure;
            } elseif ($spec->perServer) {
                $lapsed[$spec->name] = $failure;
            }
        }

        $read = new InstanceRead($instance, $specs, $items, ($items[$witness] ?? null)?->isHit() ?? false, $markers, $ownMarker, $values, $failures, $lapsed);

        foreach ($specs as $spec) {
            if (isset($failures[$spec->name])) {
                $read->holdOn($spec, $failures[$spec->name]);
            }
        }

        foreach ($specs as $spec) {
            $result = $this->kept($read, $spec, $now);

            if ($result !== null) {
                $read->results[$spec->name] = $result;
            }
        }

        return $read;
    }

    /**
     * What a collector shows without being collected: its fresh value, or else the failure that keeps it from
     * being asked again. A value of the server read through another connection does not hide the failure of this one,
     * and once that failure is no longer kept, the connection reads the server again itself.
     */
    private function kept(InstanceRead $read, CollectorSpec $spec, int $now): ?CollectorResult
    {
        $value = $read->values[$spec->name] ?? null;
        $failure = $read->failures[$spec->name] ?? $read->heldBy($spec);

        if ($failure === null && isset($read->lapsed[$spec->name])) {
            return null;
        }

        if ($value !== null && $value->isFresh($spec->lifetime, $now) && ($failure === null || !$spec->perServer)) {
            return $this->shown($spec, $value, $now);
        }

        return $failure === null ? null : $this->shown($spec, $value, $now, ResultState::Failed, $failure);
    }

    /**
     * @param array<string, InstanceRead> $reads every read of the display
     */
    private function collectOrLeave(InstanceRead $read, CollectorSpec $spec, CollectBudget $budget, CacheState $cache, CacheGuard $guard, int $now, array $reads): CollectorResult
    {
        $value = $read->values[$spec->name] ?? null;
        $lapsed = $read->lapsed[$spec->name] ?? null;

        if ($read->busy) {
            return $this->shown($spec, $value, $now, $lapsed === null ? ResultState::Busy : ResultState::Failed, $lapsed);
        }

        if (!self::cacheAllows($spec, $cache, $guard) || !$budget->allows($spec->cost)) {
            return $this->shown($spec, $value, $now, $lapsed === null ? ResultState::Pending : ResultState::Failed, $lapsed);
        }

        $startedAt = $this->seconds();
        $result = $this->collect($read, $spec, $guard, $startedAt, $reads);
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

    /**
     * @param array<string, InstanceRead> $reads every read of the display
     */
    private function collect(InstanceRead $read, CollectorSpec $spec, CacheGuard $guard, float $startedAt, array $reads): CollectorResult
    {
        $read->ran = true;
        $this->watch($read, $spec, $guard, $startedAt, $reads);

        try {
            $data = $this->runner->run($spec->collector);
        } catch (\Throwable $e) {
            $this->inFlight->collecting = null;

            return $this->failed($read, $spec, $e, $startedAt, $reads);
        }

        $this->inFlight->collecting = null;
        $now = $this->now();
        $delta = $spec->trackDelta ? $guard->attempt(fn(): ?Delta => $this->deltas->track($this->keys->suffix($read->instance, $spec), $data, $now), null) : null;
        $value = new StoredValue($data, $now, $delta, $read->markers[$spec->name] ?? null);
        $read->write($spec, $this->keys->result($read->instance, $spec), $value->toCache(), $spec->lifetime->keptFor(), value: true);

        if (isset($read->lapsed[$spec->name])) {
            $read->drops[] = $this->keys->failure($read->instance, $spec);
        }

        return $this->shown($spec, $value, $now);
    }

    /**
     * @param array<string, InstanceRead> $reads every read of the display
     */
    private function failed(InstanceRead $read, CollectorSpec $spec, \Throwable $e, float $startedAt, array $reads): CollectorResult
    {
        $now = $this->now();
        $kind = FailureKind::of($e);
        $waited = $this->seconds() - $startedAt;
        $kept = self::keptFor($kind, $spec->cost, $waited);
        $failure = new StoredFailure(self::describe($e), $now + $kept, $read->forgotten, $kind);
        $read->write($spec, $this->keys->failure($read->instance, $spec), $failure->toCache(), self::failureKeptFor($spec, $kept), value: false);
        $read->holdOn($spec, $failure);

        if ($waited >= self::SILENT_SERVER_SECONDS && $read->holdsAll($spec, $failure)) {
            $this->holdItsServer($read, $failure, $reads, $now);
        }

        return $this->shown($spec, $read->values[$spec->name] ?? null, $now, ResultState::Failed, $failure);
    }

    /**
     * Before a collector runs: PHP is given, once until it runs it, what to do if it stops the request meanwhile,
     * and a remote server is left the time to fail on its own bound.
     *
     * @param array<string, InstanceRead> $reads every read of the display
     */
    private function watch(InstanceRead $read, CollectorSpec $spec, CacheGuard $guard, float $startedAt, array $reads): void
    {
        $inFlight = $this->inFlight;

        if (!$inFlight->watched) {
            $inFlight->watched = true;
            $provider = \WeakReference::create($this);
            $this->request->atShutdown(static function () use ($inFlight, $provider): void {
                $inFlight->watched = false;
                $provider->get()?->stopped();
            });
        }

        if (!$read->instance->component->isNodeLocal()) {
            $inFlight->timeLimit ??= $this->request->raiseTimeLimit(self::REMOTE_TIME_LIMIT);
        }

        $inFlight->collecting = ['read' => $read, 'spec' => $spec, 'startedAt' => $startedAt, 'reads' => $reads, 'guard' => $guard];
    }

    /**
     * Once PHP stopped the request, the collector it was running fails as if it had thrown, and the read finishes
     * without closing what it opened, which could keep the request waiting as long again.
     */
    private function stopped(): void
    {
        $collecting = $this->inFlight->collecting;
        $this->inFlight->collecting = null;

        if ($collecting === null) {
            return;
        }

        ['read' => $read, 'spec' => $spec, 'reads' => $reads, 'guard' => $guard] = $collecting;

        try {
            $waiting = !$read->instance->component->isNodeLocal() && !$spec->shared;
            $this->failed($read, $spec, $waiting ? new UnreachableServerException(self::STOPPED_WAITING) : new \RuntimeException(self::STOPPED), $collecting['startedAt'], $reads);

            foreach ($reads as $each) {
                $this->finish($each, $guard, release: false);
            }
        } catch (\Throwable $e) {
            try {
                $this->logger->error('The read of the live stack stopped by PHP could not be finished: {reason}', [
                    'reason' => self::describe($e),
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
            }
        }
    }

    private function putBackTimeLimit(): void
    {
        if ($this->inFlight->timeLimit !== null) {
            $this->request->putBackTimeLimit($this->inFlight->timeLimit);
            $this->inFlight->timeLimit = null;
        }
    }

    /**
     * A server that kept the read waiting would keep each of its other connections waiting as long: those of
     * the display take its failure on their main collector, under their own key, unless they already keep a failure
     * to reach it that lasts as long. A result they already show stays.
     *
     * @param array<string, InstanceRead> $reads
     */
    private function holdItsServer(InstanceRead $read, StoredFailure $failure, array $reads, int $now): void
    {
        $server = $read->instance->server;

        if ($server === null) {
            return;
        }

        foreach ($reads as $other) {
            $main = $other->main();

            if ($other === $read || $main === null || $other->instance->component !== $read->instance->component || $other->instance->server?->key !== $server->key) {
                continue;
            }

            $theirs = new StoredFailure($failure->error, $failure->until, $other->forgotten, $failure->kind);
            $shown = $other->results[$main->name] ?? null;

            if ($shown?->failure !== FailureKind::Unreachable || $shown->expiresAt < $failure->until) {
                $other->write($main, $this->keys->failure($other->instance, $main), $theirs->toCache(), self::failureKeptFor($main, $failure->until - $now), value: false);
            }

            $other->holdOn($main, $theirs);
        }
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
    private function finish(InstanceRead $read, CacheGuard $guard, bool $release = true): void
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

        if ($read->drops !== []) {
            $guard->attempt(fn(): bool => $this->cache->deleteItems($read->drops), false);
        }

        if ($read->token !== null) {
            $this->liftWitness($read->instance, $read->token, $guard);
        }

        if ($read->ran && $release) {
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

    private function shown(CollectorSpec $spec, ?StoredValue $value, int $now, ResultState $withoutValue = ResultState::Pending, ?StoredFailure $failure = null): CollectorResult
    {
        if ($value === null) {
            return new CollectorResult($spec->name, [], $withoutValue, $failure?->error, expiresAt: $failure?->until, failure: $failure?->kind);
        }

        // a web server whose clock is ahead may have dated it in the future
        return new CollectorResult(
            $spec->name,
            $value->data,
            $failure === null && $value->isFresh($spec->lifetime, $now) ? ResultState::Fresh : ResultState::Stale,
            $failure?->error,
            min($value->collectedAt, $now),
            $value->delta,
            $failure->until ?? $value->collectedAt + $spec->lifetime->seconds(),
            $failure?->kind,
        );
    }

    /**
     * Within a read, what the other instances take from the first one: a value of the server, and a shared result
     * even when its lookup failed, since it is the same for all of them.
     */
    private static function reusable(CollectorSpec $spec, CollectorResult $result): bool
    {
        return match (true) {
            $spec->shared => $result->state === ResultState::Fresh || $result->state === ResultState::Failed || $result->error !== null,
            $spec->perServer => $result->state === ResultState::Fresh,
            default => false,
        };
    }

    /**
     * @param array<string, CacheItemInterface> $items
     */
    private static function marker(array $items, ?string $key): ?string
    {
        $marker = $key === null ? null : ($items[$key] ?? null)?->get();

        return is_string($marker) ? $marker : null;
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
     * is not asked again sooner than it made the read wait.
     */
    private static function keptFor(FailureKind $kind, CostClass $cost, float $waited): int
    {
        return match ($kind) {
            FailureKind::MissingPrerequisite => 3600,
            FailureKind::Unreachable => min(600, max(60, (int) ceil(5 * $waited))),
            FailureKind::Other => self::keptAfterFailure($cost),
        };
    }

    /**
     * A failure of a value of the server outlives its guard: until the connection reads the server again itself,
     * it does not take the value another connection read.
     */
    private static function failureKeptFor(CollectorSpec $spec, int $guard): int
    {
        return $spec->perServer ? $guard + self::LAPSED_FAILURE_SECONDS : $guard;
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
