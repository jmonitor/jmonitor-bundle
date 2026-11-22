<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\View\ComponentCard;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Vital;
use Jmonitor\JmonitorBundle\Stack\Check\StackHealth;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Reading\PhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\ServerReading;
use Jmonitor\JmonitorBundle\Stack\Reading\SymfonyReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Psr\Clock\ClockInterface;

/**
 * The card of an instance on the overview: its verdict and the few figures that tell at a glance whether it copes.
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
                $symfony->recipesUpToDate === true => 'up' . self::NBSP . 'to' . self::NBSP . 'date',
                $outdated > 0 => $outdated . self::NBSP . 'outdated',
                default => null,
            };
            $vitals[] = new Vital('Flex recipes', self::collector($snapshot, self::FLEX, $reading)($recipes));
        }

        return [$symfony->version, $vitals, $symfony->support, null];
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

    private static function percent(?float $percent): ?string
    {
        return $percent === null ? null : Figures::percent($percent);
    }
}
