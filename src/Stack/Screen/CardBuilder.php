<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\ApacheReading;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\NginxReading;
use Jmonitor\JmonitorBundle\Stack\Reading\PhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\RedisReading;
use Jmonitor\JmonitorBundle\Stack\Reading\ServerReading;
use Jmonitor\JmonitorBundle\Stack\Reading\SymfonyReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\ApacheDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\FrankenPhpDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\MysqlDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\NginxDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\PostgresqlDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\RedisDetail;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\Sections;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Psr\Clock\ClockInterface;

/**
 * The card of an instance: its verdict and the few figures that tell at a glance whether it copes.
 */
final class CardBuilder
{
    public const HOST_LOAD = 'Load is measured on the host node';

    private const NBSP = Figures::NBSP;
    private const READING = 'Reading…';
    private const MISSING = '—';
    private const FLEX = 'symfony.flex';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param bool $reading false when nothing more will be read for now: what was never read shows as missing
     */
    public function card(Component $component, InstanceState $instance, StackHealth $health, ?string $path, bool $reading = true): ComponentCard
    {
        $snapshot = $instance->snapshot;
        $main = $snapshot?->result($component->value);
        $reason = $instance->error ?? $snapshot->error ?? ($main?->state === ResultState::Failed ? $main->error ?? 'no reason given' : null);
        $waiting = $reading && $reason === null && ($main === null || $main->state === ResultState::Pending || $main->state === ResultState::Busy);
        [$version, $vitals, $support, $note] = $snapshot === null || $reason !== null ? [null, [], null, null] : $this->figures($component, $snapshot, $reading);

        if ($reason !== null && $snapshot !== null && $component === Component::Apache) {
            $apache = ApacheReading::of($snapshot, $this->clock->now());
            [$version, $support] = [$apache->version, $apache->support];
        }

        if ($reason !== null && $snapshot !== null && $component === Component::Mysql) {
            $mysql = MysqlReading::of($snapshot, $this->clock->now());
            [$version, $support] = [MysqlDetail::version($mysql), $mysql->support];
        }

        if ($reason !== null && $snapshot !== null && $component === Component::Postgresql) {
            $postgresql = PostgresqlReading::of($snapshot, $this->clock->now());
            [$version, $support] = [PostgresqlDetail::version($postgresql), $postgresql->support];
        }

        $note = implode(' · ', array_filter([
            $note,
            $instance->server?->address === $instance->id ? null : $instance->server?->address,
            $instance->siblings > 0 ? ($instance->siblings + 1) . ' connections' : null,
            $reason === null && $main?->state === ResultState::Stale ? $this->age($main) : null,
        ])) ?: null;

        $badge = $support === null ? null : Marks::support($support);

        return new ComponentCard(
            $instance->id === null ? $component->value : $component->value . '.' . $instance->id,
            $instance->id === null ? $component->label() : $component->label() . ' · ' . $instance->id,
            Marks::verdict($health->verdict($component, $instance->id)),
            $vitals,
            $version,
            $path,
            $waiting,
            $reason,
            $badge === null ? [] : [$badge],
            $note,
            $main?->ok() === true ? $main->collectedAt : null,
            $path === null || $instance->id === null ? [] : [ScreenRequest::INSTANCE => $instance->id],
        );
    }

    /**
     * @return array{?string, list<Vital>, ?SupportStatus, ?string} the version, the vitals, the support, a note
     */
    private function figures(Component $component, ComponentSnapshot $snapshot, bool $reading): array
    {
        $main = self::collector($snapshot, $component->value, $reading);

        return match ($component) {
            Component::System => self::server(ServerReading::of($snapshot), $main),
            Component::Php => self::php(PhpReading::of($snapshot, $this->clock->now()), $snapshot, $main),
            Component::Symfony => self::symfony(SymfonyReading::of($snapshot, $this->clock->now()), $snapshot, $main, $reading),
            Component::FrankenPhp => self::frankenPhp(FrankenPhpReading::of($snapshot), $main),
            Component::Apache => self::apache(ApacheReading::of($snapshot, $this->clock->now()), $snapshot, $main),
            Component::Nginx => self::nginx(NginxReading::of($snapshot, $this->clock->now()), $snapshot, $main),
            Component::Mysql => self::mysql(MysqlReading::of($snapshot, $this->clock->now()), $snapshot, $main),
            Component::Postgresql => self::postgresql(PostgresqlReading::of($snapshot, $this->clock->now()), $snapshot, $main),
            Component::Redis => self::redis(RedisReading::of($snapshot, $this->clock->now()), $snapshot, $main),
        };
    }

    /**
     * @param \Closure(?string): string $main the value shown, or what it waits for
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function server(ServerReading $server, \Closure $main): array
    {
        return [
            $server->os,
            [
                new Vital('Load', $main(self::percent($server->loadPercent))),
                new Vital('Memory', $main(self::percent($server->memoryPercent))),
                new Vital('Disk', $main(self::percent($server->diskPercent))),
            ],
            null,
            $server->container ? self::HOST_LOAD : null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function php(PhpReading $php, ComponentSnapshot $snapshot, \Closure $main): array
    {
        $hitRate = $php->opcacheHitRate;
        $vitals = [
            new Vital('OPcache memory', $main(self::percent($php->opcacheMemoryPercent))),
            new Vital('OPcache hit rate', $main(self::percent($hitRate->value)), Figures::span($hitRate, $snapshot->delta('php')?->elapsed)),
        ];

        if ($php->fpm) {
            $workers = $php->fpmActive === null || $php->fpmTotal === null ? null : $php->fpmActive . self::NBSP . '/' . self::NBSP . $php->fpmTotal;
            $vitals[] = new Vital('FPM workers', $main($workers));
        }

        return [$php->version, $vitals, $php->support, null];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function symfony(SymfonyReading $symfony, ComponentSnapshot $snapshot, \Closure $main, bool $reading): array
    {
        $vitals = [
            new Vital('Environment', $main($symfony->environment)),
            new Vital('Debug', $main($symfony->debug === null ? null : ($symfony->debug ? 'on' : 'off'))),
        ];

        if ($snapshot->result(self::FLEX) !== null) {
            $outdated = count($symfony->outdatedRecipes);
            $recipes = match (true) {
                $outdated > 0 => $outdated . self::NBSP . 'outdated',
                $symfony->recipesUpToDate === true => 'up' . self::NBSP . 'to' . self::NBSP . 'date',
                default => null,
            };
            $vitals[] = new Vital('Flex recipes', self::collector($snapshot, self::FLEX, $reading)($recipes));
        }

        return [$symfony->version, $vitals, $symfony->support, null];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function frankenPhp(FrankenPhpReading $frankenPhp, \Closure $main): array
    {
        return [
            $frankenPhp->version,
            [
                new Vital('Threads', $main(FrankenPhpDetail::threads($frankenPhp))),
                new Vital('Queue', $main($frankenPhp->queue === null ? null : (string) $frankenPhp->queue)),
            ],
            null,
            match ($frankenPhp->mode) {
                'worker' => 'Worker mode',
                'classic' => 'Classic mode',
                default => null,
            },
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function apache(ApacheReading $apache, ComponentSnapshot $snapshot, \Closure $main): array
    {
        return [
            $apache->version,
            [
                new Vital('Workers', $main(ApacheDetail::workers($apache))),
                self::requests($apache->requestsPerSecond, $snapshot->delta('apache')?->elapsed, $main),
            ],
            $apache->support,
            null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function nginx(NginxReading $nginx, ComponentSnapshot $snapshot, \Closure $main): array
    {
        return [
            $nginx->version,
            [
                new Vital('Connections', $main(NginxDetail::connections($nginx))),
                self::requests($nginx->requestsPerSecond, $snapshot->delta('nginx')?->elapsed, $main),
            ],
            $nginx->support,
            null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function mysql(MysqlReading $mysql, ComponentSnapshot $snapshot, \Closure $main): array
    {
        $elapsed = $snapshot->delta('mysql')?->elapsed;
        $hitRate = $mysql->bufferPoolHitRate;

        return [
            MysqlDetail::version($mysql),
            [
                new Vital('Connections', $main(MysqlDetail::connections($mysql))),
                new Vital('Queries', $main(Sections::rate($mysql->queriesPerSecond)), Figures::span($mysql->queriesPerSecond, $elapsed)),
                new Vital('Buffer pool hit rate', $main(self::percent($hitRate->value)), Figures::span($hitRate, $elapsed)),
            ],
            $mysql->support,
            null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function postgresql(PostgresqlReading $postgresql, ComponentSnapshot $snapshot, \Closure $main): array
    {
        $elapsed = $snapshot->delta('postgresql')?->elapsed;
        $hitRate = $postgresql->cacheHitRate;
        $transactions = $postgresql->transactionsPerSecond;

        return [
            PostgresqlDetail::version($postgresql),
            [
                new Vital('Connections', $main(PostgresqlDetail::connections($postgresql))),
                new Vital('Cache hit rate', $main(self::percent($hitRate->value)), Figures::span($hitRate, $elapsed)),
                new Vital('Transactions', $main(Sections::rate($transactions)), Figures::span($transactions, $elapsed)),
            ],
            $postgresql->support,
            null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     *
     * @return array{?string, list<Vital>, ?SupportStatus, ?string}
     */
    private static function redis(RedisReading $redis, ComponentSnapshot $snapshot, \Closure $main): array
    {
        $elapsed = $snapshot->delta('redis')?->elapsed;
        $hitRate = $redis->hitRate;
        $commands = $redis->commandsPerSecond;

        return [
            RedisDetail::version($redis),
            [
                new Vital('Memory', $main(RedisDetail::usedMemory($redis))),
                new Vital('Hit rate', $main(self::percent($hitRate->value)), Figures::span($hitRate, $elapsed)),
                new Vital('Commands', $main(Sections::rate($commands)), Figures::span($commands, $elapsed)),
            ],
            $redis->support,
            null,
        ];
    }

    /**
     * @param \Closure(?string): string $main
     */
    private static function requests(Rate $rate, ?int $elapsed, \Closure $main): Vital
    {
        return new Vital('Requests', $main(Sections::rate($rate)), Figures::span($rate, $elapsed));
    }

    /**
     * @return \Closure(?string): string what a vital read by this collector shows
     */
    private static function collector(ComponentSnapshot $snapshot, string $name, bool $reading): \Closure
    {
        $state = $snapshot->result($name)?->state;
        $waiting = $reading && ($state === null || $state === ResultState::Pending || $state === ResultState::Busy);

        return static fn(?string $value): string => $waiting ? self::READING : $value ?? self::MISSING;
    }

    private function age(CollectorResult $main): string
    {
        $read = $main->collectedAt === null ? 'Read earlier' : 'Read ' . Sections::unbroken(Format::ago($main->collectedAt, $this->clock->now()->getTimestamp()));

        return $main->error === null ? $read : $read . ', could not be read again';
    }

    private static function percent(?float $percent): ?string
    {
        return $percent === null ? null : Figures::percent($percent);
    }
}
