<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauge;
use Jmonitor\JmonitorBundle\Dashboard\View\Gauges;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\RedisReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class RedisDetail implements DetailInterface
{
    private const NO_LIMIT = 'no limit';
    private const IN_PROGRESS = 'in progress';

    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array
    {
        $snapshot = Sections::snapshot($instance);

        if ($snapshot === null) {
            return [];
        }

        $redis = RedisReading::of($snapshot, new \DateTimeImmutable('@' . $now));

        return Sections::of($snapshot, 'redis', Block::GAUGES, $now, $reading, static fn(): array => [
            ...self::activity($redis, $snapshot, $findings),
            self::memory($redis),
            self::persistence($redis, $findings, $now),
            self::keyspace($redis),
            self::server($redis),
        ]);
    }

    /**
     * The product and its number, such as "Valkey 8.0.1".
     */
    public static function version(RedisReading $redis): ?string
    {
        return $redis->product === null || $redis->version === null ? null : $redis->product . ' ' . $redis->version;
    }

    /**
     * What it uses of its limit, such as "1.4 GB / 2 GB", or what it uses without one.
     */
    public static function usedMemory(RedisReading $redis): ?string
    {
        if ($redis->usedMemory === null) {
            return null;
        }

        return $redis->maxMemory === null
            ? Figures::bytes($redis->usedMemory)
            : Figures::bytes($redis->usedMemory) . Sections::NBSP . '/' . Sections::NBSP . Figures::bytes($redis->maxMemory);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Block>
     */
    private static function activity(RedisReading $redis, ComponentSnapshot $snapshot, array $findings): array
    {
        $elapsed = $snapshot->delta('redis')?->elapsed;
        $used = $redis->usedMemory === null ? Sections::MISSING : Figures::bytes($redis->usedMemory);

        return [
            Block::gauges(new Gauges('Usage', [
                $redis->maxMemory === null
                    ? new Gauge('Memory', null, $used, hint: self::NO_LIMIT)
                    : new Gauge('Memory', $redis->memoryPercent, Sections::percent($redis->memoryPercent), Sections::mark($findings, 'redis.memory'), Sections::share($redis->usedMemory, $redis->maxMemory)),
                new Gauge('Clients', null, Sections::value(self::count($redis->clients)), hint: $redis->clients === null ? null : 'connected'),
            ])),
            Block::facts(new Facts('Traffic', [
                new Fact('Commands per second', Sections::value(Sections::rate($redis->commandsPerSecond)), hint: Figures::span($redis->commandsPerSecond, $elapsed)),
                new Fact('Hit rate', Sections::value(self::percent($redis->hitRate)), Sections::mark($findings, 'redis.hit_rate'), Figures::span($redis->hitRate, $elapsed)),
                self::lately('Evicted keys', $redis->evictedLately, $redis->evictedKeys, $elapsed, Sections::mark($findings, 'redis.evictions')),
                self::lately('Refused connections', $redis->rejectedLately, $redis->rejectedConnections, $elapsed, Sections::mark($findings, 'redis.rejected_connections')),
            ])),
        ];
    }

    private static function memory(RedisReading $redis): Block
    {
        $bytes = static fn(?int $bytes): string => $bytes === null ? Sections::MISSING : Figures::bytes($bytes);
        $limit = match (true) {
            $redis->maxMemory !== null => Figures::bytes($redis->maxMemory),
            $redis->usedMemory !== null => self::NO_LIMIT,
            default => Sections::MISSING,
        };
        $policy = match (true) {
            $redis->maxMemory === null || $redis->policy === null => null,
            $redis->policy === 'noeviction' => 'writes are refused at the limit',
            str_starts_with($redis->policy, 'allkeys-') => 'any key may be evicted at the limit',
            str_starts_with($redis->policy, 'volatile-') => 'only keys with an expiry are evicted at the limit; without any, writes are refused',
            default => null,
        };

        return Block::facts(new Facts('Memory', [
            new Fact('Used', $bytes($redis->usedMemory)),
            new Fact('Peak', $bytes($redis->peakMemory), hint: $redis->peakMemory === null ? null : RedisReading::SINCE_START),
            new Fact('Limit', $limit),
            new Fact('Policy', Sections::value($redis->policy), hint: $policy),
        ]));
    }

    /**
     * @param list<Finding> $findings
     */
    private static function persistence(RedisReading $redis, array $findings, int $now): Block
    {
        $facts = [
            new Fact('Last RDB snapshot', $redis->rdbInProgress ? self::IN_PROGRESS : Sections::value($redis->rdbStatus), Sections::mark($findings, 'redis.rdb_failed')),
            new Fact('Last saved', $redis->lastSaveAt === null ? Sections::MISSING : Sections::unbroken(Format::ago($redis->lastSaveAt, $now))),
            new Fact('Changes not saved', Sections::value(self::count($redis->unsavedChanges))),
            new Fact('AOF', $redis->aofEnabled ? 'on' : 'off'),
        ];

        if ($redis->aofEnabled) {
            $facts[] = new Fact('Last AOF rewrite', $redis->aofInProgress ? self::IN_PROGRESS : Sections::value($redis->aofStatus), Sections::mark($findings, 'redis.aof_failed'));
        }

        return Block::facts(new Facts('Persistence', $facts));
    }

    private static function keyspace(RedisReading $redis): Block
    {
        if ($redis->databases === []) {
            return Block::unavailable(Block::TABLE, BlockState::Empty, 'No key in this server', 'Each database that holds keys shows here, with its count of keys.');
        }

        return Block::table(new Table(
            'redis-keyspace',
            'Keyspace',
            [
                new Column('name', 'Database', false),
                new Column('keys', 'Keys'),
                new Column('expires', 'With an expiry'),
            ],
            array_map(static fn(array $database): Row => new Row($database['name'], [
                'name' => new Cell($database['name']),
                'keys' => new Cell(Format::number($database['keys'])),
                'expires' => new Cell($database['expires'] === null ? Sections::MISSING : Format::number($database['expires'])),
            ]), $redis->databases),
            noun: 'database',
        ));
    }

    private static function server(RedisReading $redis): Block
    {
        return Block::facts(new Facts('Server', [
            new Fact('Version', Sections::value(self::version($redis)), $redis->support === null ? null : Marks::support($redis->support)),
            new Fact('Mode', Sections::value($redis->mode)),
            new Fact('Role', Sections::value($redis->role)),
            new Fact('Uptime', $redis->uptime === null || $redis->uptime < 0 ? Sections::MISSING : Sections::duration($redis->uptime)),
        ]));
    }

    /**
     * A counter between the last two samples, or what it waits for.
     */
    private static function lately(string $label, ?int $lately, ?int $counter, ?int $elapsed, ?Mark $mark): Fact
    {
        return new Fact($label, Sections::value(self::count($lately)), $mark, match (true) {
            $lately !== null && $elapsed !== null => Figures::lastSeconds($elapsed),
            $counter !== null => Rate::AT_NEXT_REFRESH,
            default => null,
        });
    }

    private static function percent(Rate $rate): ?string
    {
        return $rate->value === null ? null : Figures::percent($rate->value);
    }

    private static function count(?int $count): ?string
    {
        return $count === null ? null : (string) $count;
    }
}
