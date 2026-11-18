<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class Recorder implements ResetInterface
{
    public const SAMPLE_WEIGHT = 10;
    public const MAX_DEPTH = 8;
    public const MAX_SQL_PER_UNIT = 200;

    /** @var array<int, array{string, float, array<string, array{int, float, float, int}>}> token => [type, started at, SQL], in opening order */
    private array $units = [];
    private int $lastToken = 0;
    private bool $paused = false;
    private bool $failureLogged = false;
    private readonly \Closure $sampler;

    /**
     * @param (\Closure(): bool)|null $sampler whether a unit under pressure keeps its children; one in ten by default
     */
    public function __construct(
        private readonly BufferInterface $buffer,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $sampler = null,
        private readonly ?QueryDigester $queries = null,
        private readonly int $sqlSlowThresholdMs = 100,
    ) {
        $this->sampler = $sampler ?? static fn(): bool => mt_rand(1, self::SAMPLE_WEIGHT) === 1;
    }

    public function begin(string $type, float $startedAt): int
    {
        if (\count($this->units) >= self::MAX_DEPTH) {
            $this->units = [];
        }

        $this->units[++$this->lastToken] = [$type, $startedAt, []];

        return $this->lastToken;
    }

    /**
     * @param array<string, int|float|string> $attributes
     */
    public function end(int $token, string $key, float $durationMs, array $attributes = []): void
    {
        $unit = $this->close($token);
        if ($unit === null) {
            return;
        }

        $children = [];
        if ($unit[2] !== [] && $this->queries !== null) {
            try {
                $digest = $this->queries->digest($unit[2], $unit[1]);
                $attributes['sql_ms'] = round($digest->totalMs, 3);
                $attributes['sql_n'] = $digest->count;
                $children = $digest->children;
            } catch (\Throwable $e) {
                $this->logFailure($e);
            }
        }

        try {
            $this->buffer->append($this->entry($unit, $key, $durationMs, $attributes, $children));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function addSql(string $sql, float $durationMs): void
    {
        if ($this->paused || $this->units === [] || $this->queries === null) {
            return;
        }

        $token = array_key_last($this->units);
        if (!isset($this->units[$token][2][$sql])) {
            if (\count($this->units[$token][2]) >= self::MAX_SQL_PER_UNIT) {
                $sql = QueryDigester::OTHER;
            }
            $this->units[$token][2][$sql] ??= [0, 0.0, 0.0, 0];
        }

        $stats = &$this->units[$token][2][$sql];
        ++$stats[0];
        $stats[1] += $durationMs;
        $stats[2] = max($stats[2], $durationMs);
        $stats[3] += (int) ($durationMs >= $this->sqlSlowThresholdMs);
    }

    public function discard(int $token): void
    {
        $this->close($token);
    }

    public function isRecording(): bool
    {
        return $this->units !== [] && !$this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function reset(): void
    {
        $this->units = [];
        $this->paused = false;
    }

    /**
     * @return array{string, float, array<string, array{int, float, float, int}>}|null
     */
    private function close(int $token): ?array
    {
        if (!isset($this->units[$token])) {
            return null;
        }

        $unit = $this->units[$token];
        do {
            $last = array_key_last($this->units);
            unset($this->units[$last]);
        } while ($last !== $token);

        return $unit;
    }

    /**
     * @param array{string, float, array<string, array{int, float, float, int}>} $unit
     * @param array<string, int|float|string> $attributes
     * @param list<Entry> $children
     */
    private function entry(array $unit, string $key, float $durationMs, array $attributes, array $children): Entry
    {
        if ($children !== [] && $this->buffer->isUnderPressure()) {
            if (($this->sampler)()) {
                $attributes['cw'] = self::SAMPLE_WEIGHT;
            } else {
                $children = [];
            }
        }

        return new Entry($unit[0], $key, $unit[1], $durationMs, $attributes, $children);
    }

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        $this->logger?->error('JMonitor could not record a unit of work: ' . $e->getMessage(), ['exception' => $e]);
    }
}
