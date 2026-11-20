<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

use Jmonitor\JmonitorBundle\Buffer\BufferInterface;
use Jmonitor\JmonitorBundle\Recording\Sql\QueryDigester;
use Jmonitor\JmonitorBundle\Recording\Sql\SqlDialect;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class Recorder implements ResetInterface
{
    public const SAMPLE_WEIGHT = 10;
    public const MAX_DEPTH = 8;
    public const MAX_SQL_PER_UNIT = 200;
    public const MAX_SQL_BYTES = 65_536;
    public const MAX_KEYS_PER_TYPE = 50;

    /** @var array<int, Unit> token => unit, in opening order */
    private array $units = [];
    private int $lastToken = 0;
    private bool $paused = false;
    private bool $failureLogged = false;
    private readonly \Closure $sampler;

    /**
     * @param LoggerInterface|(\Closure(): ?LoggerInterface)|null $logger a closure is only called on the first failure to log, which keeps
     *                                                              the logger out of the graph of the Doctrine connection
     * @param (\Closure(): bool)|null                             $sampler whether a unit under pressure keeps its children
     */
    public function __construct(
        private readonly BufferInterface $buffer,
        private LoggerInterface|\Closure|null $logger = null,
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

        $this->units[++$this->lastToken] = new Unit($type, $startedAt);

        return $this->lastToken;
    }

    /** The innermost open unit, null when none is open or the recorder is paused. */
    public function current(): ?int
    {
        return $this->paused || $this->units === [] ? null : array_key_last($this->units);
    }

    /**
     * @param array<string, int> $counters
     */
    public function add(?int $unit, string $type, string $key, float $durationMs = 0.0, array $counters = []): void
    {
        if ($unit !== null && isset($this->units[$unit])) {
            $this->units[$unit]->add($type, $key, $durationMs, $counters);
        }
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
        if (!$unit->isEmpty()) {
            $attributes = array_replace($attributes, $unit->totals());

            try {
                $pressure = $this->buffer->isUnderPressure();
                if ($pressure === false || ($pressure === true && ($this->sampler)())) {
                    $children = $unit->children($this->queries);
                    if ($pressure && $children !== []) {
                        $attributes['cw'] = self::SAMPLE_WEIGHT;
                    }
                }
            } catch (\Throwable $e) {
                $this->logFailure($e);
            }
        }

        try {
            $this->buffer->append(new Entry($unit->type, $key, $unit->startedAt, $durationMs, $attributes, $children));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function addSql(string $sql, float $durationMs, SqlDialect $dialect): void
    {
        if ($this->paused || $this->units === [] || $this->queries === null) {
            return;
        }

        $unit = $this->units[array_key_last($this->units)];
        $bucket = $dialect->value;
        if ($dialect === SqlDialect::Unknown || \strlen($sql) > self::MAX_SQL_BYTES) {
            $bucket = SqlDialect::Unknown->value;
            $sql = QueryDigester::OTHER;
        }
        if (!isset($unit->sql[$bucket][$sql])) {
            if ($unit->sqlTexts() >= self::MAX_SQL_PER_UNIT) {
                $bucket = SqlDialect::Unknown->value;
                $sql = QueryDigester::OTHER;
            }
            $unit->sql[$bucket][$sql] ??= [0, 0.0, 0.0, 0];
        }

        $stats = &$unit->sql[$bucket][$sql];
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

    private function close(int $token): ?Unit
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

    private function logFailure(\Throwable $e): void
    {
        if ($this->failureLogged) {
            return;
        }

        $this->failureLogged = true;
        if ($this->logger instanceof \Closure) {
            $this->logger = ($this->logger)();
        }
        $this->logger?->error('JMonitor could not record a unit of work: ' . $e->getMessage(), ['exception' => $e]);
    }
}
