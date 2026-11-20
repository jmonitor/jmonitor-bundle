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

    /** @var array<int, array{string, float, array<string, array<string, array{int, float, float, int}>>}> token => [type, started at, dialect value => raw SQL => stats], in opening order */
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
            $totalMs = 0.0;
            $count = 0;
            foreach ($unit[2] as $texts) {
                foreach ($texts as [$executions, $ms]) {
                    $count += $executions;
                    $totalMs += $ms;
                }
            }
            $attributes['sql_ms'] = round($totalMs, 3);
            $attributes['sql_n'] = $count;

            try {
                $pressure = $this->buffer->isUnderPressure();
                if ($pressure === false || ($pressure === true && ($this->sampler)())) {
                    $children = $this->queries->digest($unit[2], $unit[1])->children;
                    if ($pressure && $children !== []) {
                        $attributes['cw'] = self::SAMPLE_WEIGHT;
                    }
                }
            } catch (\Throwable $e) {
                $this->logFailure($e);
            }
        }

        try {
            $this->buffer->append(new Entry($unit[0], $key, $unit[1], $durationMs, $attributes, $children));
        } catch (\Throwable $e) {
            $this->logFailure($e);
        }
    }

    public function addSql(string $sql, float $durationMs, SqlDialect $dialect): void
    {
        if ($this->paused || $this->units === [] || $this->queries === null) {
            return;
        }

        $token = array_key_last($this->units);
        $bucket = $dialect->value;
        if (!isset($this->units[$token][2][$bucket][$sql])) {
            if ($dialect === SqlDialect::Unknown || \strlen($sql) > self::MAX_SQL_BYTES || $this->sqlEntries($token) >= self::MAX_SQL_PER_UNIT) {
                $bucket = SqlDialect::Unknown->value;
                $sql = QueryDigester::OTHER;
            }
            $this->units[$token][2][$bucket][$sql] ??= [0, 0.0, 0.0, 0];
        }

        $stats = &$this->units[$token][2][$bucket][$sql];
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

    private function sqlEntries(int $token): int
    {
        $entries = 0;
        foreach ($this->units[$token][2] as $texts) {
            $entries += \count($texts);
        }

        return $entries;
    }

    /**
     * @return array{string, float, array<string, array<string, array{int, float, float, int}>>}|null
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
