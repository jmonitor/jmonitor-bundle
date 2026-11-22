<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Mark;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Screen\Marks;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;

/**
 * What the sections of a detail share: the state of the collector they read, and how their values are written.
 */
final class Sections
{
    public const MISSING = '—';
    public const NBSP = Figures::NBSP;

    /**
     * Null when the instance could not be read at all.
     */
    public static function snapshot(InstanceState $instance): ?ComponentSnapshot
    {
        $snapshot = $instance->snapshot;

        return $instance->error !== null || $snapshot?->error !== null ? null : $snapshot;
    }

    /**
     * @param string                  $type     the type of the first block, shown while the collector was never read
     * @param \Closure(): list<Block> $sections built only when the collector has a value
     * @param ?string                 $failure  the title of a notice telling a failure in place of the sections; none without it
     *
     * @return list<Block>
     */
    public static function of(ComponentSnapshot $snapshot, string $collector, string $type, int $now, bool $reading, \Closure $sections, ?string $failure = null): array
    {
        $result = $snapshot->result($collector);

        if ($result === null || $result->state === ResultState::Pending || $result->state === ResultState::Busy) {
            return $reading ? [Block::unavailable($type, BlockState::Reading)] : [];
        }

        if ($result->state === ResultState::Failed) {
            return $failure === null ? [] : [Block::notice(new Notice('warn', $failure, $result->error ?? 'no reason given'))];
        }

        return $result->state === ResultState::Stale ? [Block::notice(self::outdated($result, $now)), ...$sections()] : $sections();
    }

    /**
     * @param list<Finding> $findings
     */
    public static function mark(array $findings, string $check): ?Mark
    {
        foreach ($findings as $finding) {
            if ($finding->id === $check) {
                return Marks::severity($finding->severity);
            }
        }

        return null;
    }

    public static function value(?string $value): string
    {
        return $value ?? self::MISSING;
    }

    public static function percent(?float $percent): string
    {
        return $percent === null ? '' : Figures::percent($percent);
    }

    public static function bytes(int $bytes): string
    {
        return self::unbroken(Format::bytes($bytes));
    }

    public static function share(?int $part, ?int $total): ?string
    {
        return $part === null || $total === null ? null : self::bytes($part) . ' of ' . self::bytes($total);
    }

    public static function fraction(int $part, int $total): string
    {
        return $part . self::NBSP . '/' . self::NBSP . $total;
    }

    public static function decimal(float $value, int $decimals): string
    {
        $written = number_format($value, $decimals, '.', '');

        return str_contains($written, '.') ? rtrim(rtrim($written, '0'), '.') : $written;
    }

    public static function duration(int $seconds): string
    {
        [$days, $hours, $minutes] = [intdiv($seconds, 86_400), intdiv($seconds % 86_400, 3_600), intdiv($seconds % 3_600, 60)];

        return str_replace(' ', self::NBSP, match (true) {
            $days > 0 => $days . ' d' . ($hours > 0 ? ' ' . $hours . ' h' : ''),
            $hours > 0 => $hours . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : ''),
            $minutes > 0 => $minutes . ' min',
            default => $seconds . ' s',
        });
    }

    /**
     * Keeps a number with its unit.
     */
    public static function unbroken(string $text): string
    {
        return (string) preg_replace('/(?<=\d) (?=\S)/', self::NBSP, $text);
    }

    private static function outdated(CollectorResult $result, int $now): Notice
    {
        if ($result->error === null) {
            return new Notice('info', $result->collectedAt === null ? 'Read earlier' : 'Read ' . self::unbroken(Format::ago($result->collectedAt, $now)));
        }

        return new Notice(
            'warn',
            'Could not be read again: ' . $result->error,
            $result->expiresAt === null ? '' : 'next try ' . self::unbroken(Format::in($result->expiresAt, $now)),
        );
    }
}
