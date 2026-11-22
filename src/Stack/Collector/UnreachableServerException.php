<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

/**
 * The server could not be reached, or could not give its status: its other collectors would fail
 * the same way, and asking again would cost the same wait.
 */
final class UnreachableServerException extends \RuntimeException
{
    /**
     * Chains the root cause of $e, the one that names what failed.
     */
    public static function withRootCause(string $message, \Throwable $e): self
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return new self($message, 0, $e);
    }
}
