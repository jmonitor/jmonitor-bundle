<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Reading\RedisReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class RedisChecks
{
    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    public static function of(RedisReading $redis, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];
        $product = $redis->product ?? 'Redis';
        $elapsed = $snapshot->delta('redis')?->elapsed;

        if ($redis->policy === 'noeviction' && $redis->memoryPercent !== null && $redis->memoryPercent >= 90) {
            $findings[] = $raise(
                'redis.memory',
                $redis->memoryPercent >= 98 ? Severity::Critical : Severity::Warning,
                sprintf('%s uses %s of its memory limit (%s of %s)', $product, Figures::percent($redis->memoryPercent), Figures::bytes((int) $redis->usedMemory), Figures::bytes((int) $redis->maxMemory)),
                'Under the noeviction policy, a server that reaches maxmemory refuses every write that needs more memory, and the application gets errors. Find the keys that grow without expiring, raise maxmemory if the memory of the server allows it, or choose a policy that evicts keys if the data can be rebuilt.',
                'redis',
            );
        }

        if ($redis->evictedLately !== null && $redis->evictedLately >= 1 && $elapsed !== null) {
            $findings[] = $raise(
                'redis.evictions',
                Severity::Info,
                sprintf('%s evicted %s', $redis->evictedLately === 1 ? '1 key was' : Figures::count($redis->evictedLately) . ' keys were', Figures::lastSeconds($elapsed)),
                sprintf('The server reached maxmemory and removed keys to make room, as its policy%s says. A cache works that way; when these keys hold what the application cannot rebuild, such as sessions or locks, raise maxmemory, or keep them on a server under the noeviction policy.', $redis->policy === null ? '' : ' (' . $redis->policy . ')'),
                'redis',
            );
        }

        if ($redis->rejectedLately !== null && $redis->rejectedLately >= 1 && $elapsed !== null) {
            $findings[] = $raise(
                'redis.rejected_connections',
                Severity::Warning,
                sprintf('%s refused %s', $redis->rejectedLately === 1 ? '1 connection was' : Figures::count($redis->rejectedLately) . ' connections were', Figures::lastSeconds($elapsed)),
                'The server refuses new connections once it holds maxclients, and the requests that needed one fail. Find what holds the connections, such as persistent connections kept by many workers, or raise maxclients.',
                'redis',
            );
        }

        if ($redis->rdbStatus !== null && $redis->rdbStatus !== 'ok' && !$redis->rdbInProgress) {
            $findings[] = $raise(
                'redis.rdb_failed',
                Severity::Critical,
                'The last RDB snapshot failed',
                'The server could not write its snapshot to disk: what changed since the last one that succeeded would be lost on a restart, and under stop-writes-on-bgsave-error, the default, it refuses writes until a snapshot succeeds. Look at the log of the server: the disk may be full, or the fork short of memory.',
                'redis',
            );
        }

        if ($redis->aofEnabled && $redis->aofStatus !== null && $redis->aofStatus !== 'ok' && !$redis->aofInProgress) {
            $findings[] = $raise(
                'redis.aof_failed',
                Severity::Critical,
                'The last rewrite of the AOF failed',
                'The server could not rewrite its append-only file, which keeps growing and takes longer to load on a restart; the same cause may stop the writes to it. Look at the log of the server: the disk may be full, or the fork short of memory.',
                'redis',
            );
        }

        $hitRate = $redis->hitRate;

        if ($hitRate->source === RateSource::Delta && $hitRate->value !== null && $hitRate->value < 80 && $elapsed !== null) {
            $findings[] = $raise(
                'redis.hit_rate',
                Severity::Info,
                sprintf('The hit rate is %s %s', Figures::percent($hitRate->value), Figures::lastSeconds($elapsed)),
                'A lookup that finds no key leaves the application to compute or fetch the value again. A low rate is usual when the application checks for a key before writing it, or right after a restart; otherwise, the keys may expire too soon, or be evicted for lack of memory.',
                'redis',
            );
        }

        return $findings;
    }
}
