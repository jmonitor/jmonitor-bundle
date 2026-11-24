<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

/**
 * The PHP request a read runs in: its time limit, and what runs once PHP stops it.
 *
 * @internal
 */
final readonly class PhpRequest
{
    /**
     * @param ?\Closure(int): bool     $setTimeLimit set_time_limit(), null when PHP forbids it
     * @param \Closure(): int          $timeLimit    the time limit in force, in seconds, 0 for none
     * @param \Closure(\Closure): void $atShutdown   register_shutdown_function()
     * @param \Closure(): ?array{type: int, message: string, file: string, line: int} $lastError error_get_last()
     */
    public function __construct(
        private ?\Closure $setTimeLimit,
        private \Closure $timeLimit,
        private \Closure $atShutdown,
        private \Closure $lastError,
    ) {}

    public static function current(): self
    {
        return new self(
            \function_exists('set_time_limit') ? set_time_limit(...) : null,
            static fn(): int => (int) \ini_get('max_execution_time'),
            static function (\Closure $callback): void {
                register_shutdown_function($callback);
            },
            error_get_last(...),
        );
    }

    /**
     * Setting a time limit counts it again from zero.
     *
     * @return ?int the time limit to put back, null when it was left as it was
     */
    public function raiseTimeLimit(int $seconds): ?int
    {
        $limit = ($this->timeLimit)();

        if ($this->setTimeLimit === null || $limit <= 0 || $limit >= $seconds) {
            return null;
        }

        ($this->setTimeLimit)($seconds);

        return $limit;
    }

    public function putBackTimeLimit(int $seconds): void
    {
        if ($this->setTimeLimit !== null) {
            ($this->setTimeLimit)($seconds);
        }
    }

    /**
     * Once PHP stopped the request, whether it was for its time limit.
     */
    public function stoppedByTimeLimit(): bool
    {
        $error = ($this->lastError)();

        return $error !== null && $error['type'] === \E_ERROR && str_starts_with($error['message'], 'Maximum execution time');
    }

    public function atShutdown(\Closure $callback): void
    {
        ($this->atShutdown)($callback);
    }
}
