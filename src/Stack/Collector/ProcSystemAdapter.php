<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\System\Adapter\LinuxAdapter;
use Jmonitor\Utils\ShellExecutor;

/**
 * Reads the memory and the cores from files instead of commands, within the limits of the cgroup when it has some.
 */
final class ProcSystemAdapter extends LinuxAdapter
{
    /** cgroup v1 writes "no limit" as a number close to the largest 64-bit integer */
    private const V1_NO_LIMIT = 1 << 62;

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(
        private readonly string $procDir = '/proc',
        private readonly string $cgroupDir = '/sys/fs/cgroup',
    ) {
        parent::__construct(new class extends ShellExecutor {
            public function execute(string $command): ?string
            {
                throw new \LogicException(sprintf('The server is read from files, "%s" is not run.', $command));
            }
        });
    }

    public function getTotalMemory(): ?int
    {
        return $this->limitBelowHost() ?? $this->meminfo('MemTotal');
    }

    public function getAvailableMemory(): ?int
    {
        $limit = $this->limitBelowHost();

        if ($limit === null) {
            return $this->meminfo('MemAvailable');
        }

        $usage = $this->memoryUsage();

        return $usage === null ? null : max(0, $limit - $usage);
    }

    public function getDiskTotalSpace(string $path): int
    {
        return (int) self::silently(static fn(): float|false => disk_total_space($path));
    }

    public function getDiskFreeSpace(string $path): int
    {
        return (int) self::silently(static fn(): float|false => disk_free_space($path));
    }

    public function getCoreCount(): ?int
    {
        return $this->remember('cores', function (): ?int {
            $cpuinfo = $this->read($this->procDir . '/cpuinfo');
            $cores = $cpuinfo === null ? 0 : (int) preg_match_all('/^processor\s*:/m', $cpuinfo);

            return $cores > 0 ? $cores : null;
        });
    }

    /**
     * @return ?int in bytes, null without limit
     */
    public function memoryLimit(): ?int
    {
        return $this->remember('memory_limit', function (): ?int {
            $v2 = $this->read($this->cgroupDir . '/memory.max');

            if ($v2 !== null) {
                return self::integer($v2);
            }

            $v1 = self::integer($this->read($this->cgroupDir . '/memory/memory.limit_in_bytes'));

            return $v1 === null || $v1 >= self::V1_NO_LIMIT ? null : $v1;
        });
    }

    /**
     * @return ?float cores, null without quota
     */
    public function cpuQuota(): ?float
    {
        return $this->remember('cpu_quota', function (): ?float {
            $v2 = $this->read($this->cgroupDir . '/cpu.max');

            if ($v2 !== null) {
                $parts = explode(' ', trim($v2));

                return self::ratio(self::integer($parts[0]), self::integer($parts[1] ?? null));
            }

            return self::ratio(
                self::integer($this->read($this->cgroupDir . '/cpu/cpu.cfs_quota_us')),
                self::integer($this->read($this->cgroupDir . '/cpu/cpu.cfs_period_us')),
            );
        });
    }

    public function reset(): void
    {
        parent::reset();
        $this->values = [];
    }

    private function limitBelowHost(): ?int
    {
        $limit = $this->memoryLimit();
        $host = $this->meminfo('MemTotal');

        return $limit !== null && ($host === null || $limit < $host) ? $limit : null;
    }

    /**
     * The memory the cgroup uses, without the file cache the kernel takes back under pressure.
     */
    private function memoryUsage(): ?int
    {
        return $this->remember('memory_usage', function (): ?int {
            $current = $this->read($this->cgroupDir . '/memory.current');

            if ($current !== null) {
                $usage = self::integer($current);
                $reclaimable = self::keyValues($this->read($this->cgroupDir . '/memory.stat'), ' ')['inactive_file'] ?? 0;
            } else {
                $usage = self::integer($this->read($this->cgroupDir . '/memory/memory.usage_in_bytes'));
                $stat = self::keyValues($this->read($this->cgroupDir . '/memory/memory.stat'), ' ');
                $reclaimable = $stat['total_inactive_file'] ?? $stat['inactive_file'] ?? 0;
            }

            return $usage === null ? null : max(0, $usage - $reclaimable);
        });
    }

    private function meminfo(string $name): ?int
    {
        /** @var array<string, int> $meminfo */
        $meminfo = $this->remember('meminfo', fn(): array => self::keyValues($this->read($this->procDir . '/meminfo'), ':'));

        return isset($meminfo[$name]) ? $meminfo[$name] * 1024 : null;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function remember(string $key, \Closure $read): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            $this->values[$key] = $read();
        }

        return $this->values[$key];
    }

    private function read(string $path): ?string
    {
        $content = self::silently(static fn(): string|false => file_get_contents($path));

        return $content === false ? null : $content;
    }

    /**
     * Swallows the warnings of a missing file, a permission or open_basedir, even under a handler that throws.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private static function silently(\Closure $call): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, int> the leading number of each value
     */
    private static function keyValues(?string $content, string $separator): array
    {
        $values = [];

        foreach (explode("\n", $content ?? '') as $line) {
            $parts = explode($separator, $line, 2);

            if (count($parts) === 2 && preg_match('/^\s*(\d+)/', $parts[1], $matches) === 1) {
                $values[trim($parts[0])] = (int) $matches[1];
            }
        }

        return $values;
    }

    private static function integer(?string $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) ? (int) $value : null;
    }

    private static function ratio(?int $quota, ?int $period): ?float
    {
        return $quota === null || $period === null || $period === 0 ? null : $quota / $period;
    }
}
