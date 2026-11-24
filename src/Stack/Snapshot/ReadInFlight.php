<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;

/**
 * What a provider runs in its request, for what PHP runs once it stops the request.
 *
 * @internal
 */
final class ReadInFlight
{
    /** whether PHP was given what to run at shutdown, and has not run it yet */
    public bool $watched = false;

    /** the time limit to put back once the read ends */
    public ?int $timeLimit = null;

    /** @var ?array{reads: array<string, InstanceRead>, guard: CacheGuard} the read that may hold witnesses */
    public ?array $reading = null;

    /** @var ?array{read: InstanceRead, spec: CollectorSpec, startedAt: float} */
    public ?array $collecting = null;
}
