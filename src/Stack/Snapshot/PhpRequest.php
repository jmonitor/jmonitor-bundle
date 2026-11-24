<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

/**
 * The PHP request a read runs in: what runs once PHP stops it.
 *
 * @internal
 */
final readonly class PhpRequest
{
    /**
     * @param \Closure(\Closure): void $atShutdown register_shutdown_function()
     */
    public function __construct(
        private \Closure $atShutdown,
    ) {}

    public static function current(): self
    {
        return new self(static function (\Closure $callback): void {
            register_shutdown_function($callback);
        });
    }

    public function atShutdown(\Closure $callback): void
    {
        ($this->atShutdown)($callback);
    }
}
