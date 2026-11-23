<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\Rate;
use Jmonitor\JmonitorBundle\Stack\Metric\RateSource;
use Jmonitor\JmonitorBundle\Stack\Metric\Ratios;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class ApacheReading
{
    private const SINCE_START = 'since Apache started';

    /** The scoreboard states of mod_status, in the order of its legend. */
    private const STATES = [
        '_' => 'Waiting for connection',
        'S' => 'Starting up',
        'R' => 'Reading request',
        'W' => 'Sending reply',
        'K' => 'Keepalive',
        'D' => 'DNS lookup',
        'C' => 'Closing connection',
        'L' => 'Logging',
        'G' => 'Gracefully finishing',
        'I' => 'Idle cleanup',
        '.' => 'Open slot',
    ];

    /**
     * @param ?string            $version    the number, or the server string when ServerTokens hides it
     * @param ?int               $usedSlots  the slots neither waiting for a connection nor open
     * @param array<string, int> $scoreboard label => slots, for the states holding at least one
     */
    private function __construct(
        public ?string $version,
        public ?SupportStatus $support,
        public ?string $mpm,
        public ?int $uptime,
        public ?int $busyWorkers,
        public ?int $idleWorkers,
        public ?int $usedSlots,
        public ?int $slots,
        public ?float $slotsPercent,
        public array $scoreboard,
        public Rate $requestsPerSecond,
        public Rate $bytesPerSecond,
        public Rate $bytesPerRequest,
        public Rate $secondsPerRequest,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $data = $snapshot->data('apache');
        $delta = $snapshot->delta('apache');

        $serverVersion = Values::string($data['server_version'] ?? null);
        $number = $serverVersion !== null && preg_match('~\bApache/(\d+(?:\.\d+){1,3})\b~i', $serverVersion, $matches) === 1 ? $matches[1] : null;

        $slots = [];

        foreach (is_array($data['scoreboard'] ?? null) ? $data['scoreboard'] : [] as $state => $count) {
            $count = Values::int($count);

            if ($count !== null && $count >= 0) {
                $slots[$state] = $count;
            }
        }

        $scoreboard = [];

        foreach (self::STATES as $state => $label) {
            if (($slots[$state] ?? 0) > 0) {
                $scoreboard[$label] = $slots[$state];
            }
        }

        $allSlots = $slots === [] ? null : Values::int(array_sum($slots));
        $usedSlots = $allSlots === null ? null : $allSlots - ($slots['_'] ?? 0) - ($slots['.'] ?? 0);

        $accesses = Values::int($data['total_accesses'] ?? null);
        $bytes = Values::int($data['total_bytes'] ?? null);
        $duration = Ratios::average($delta, 'total_duration', 'total_accesses', Values::float($data['total_duration'] ?? null), $accesses, 's', self::SINCE_START);
        $millisecondsPerRequest = Values::float($data['duration_per_request'] ?? null);

        return new self(
            $number ?? $serverVersion,
            EndOfLife::status($snapshot->data('eol.apache-http-server'), $number, $today),
            Values::string($data['server_mpm'] ?? null),
            Values::int($data['uptime'] ?? null),
            Values::int($data['workers']['busy'] ?? null),
            Values::int($data['workers']['idle'] ?? null),
            $usedSlots,
            $allSlots,
            Values::percent($usedSlots, $allSlots),
            $scoreboard,
            Ratios::perSecond($delta, 'total_accesses', 'req/s', $accesses),
            Ratios::perSecond($delta, 'total_bytes', 'B/s', $bytes),
            Ratios::average($delta, 'total_bytes', 'total_accesses', $bytes, $accesses, 'B', self::SINCE_START),
            !$duration->isUnavailable() || $millisecondsPerRequest === null ? $duration : new Rate($millisecondsPerRequest / 1000, RateSource::SinceStart, 's', since: self::SINCE_START),
        );
    }
}
