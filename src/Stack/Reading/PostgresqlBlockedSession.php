<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

/**
 * A session waiting for a lock, without the text of its query nor of the one blocking it: either may carry values.
 */
final readonly class PostgresqlBlockedSession
{
    /**
     * @param ?int    $waitSeconds   null when the role may not see it
     * @param ?string $blockingState the state of the session holding the lock, such as "idle in transaction"
     */
    public function __construct(
        public int $pid,
        public ?int $waitSeconds,
        public ?int $blockingPid,
        public ?string $blockingState,
    ) {}
}
