<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Detection\Server;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class InstanceState
{
    /**
     * @param ?ComponentSnapshot $snapshot null when the instance is listed without being read
     * @param int                $siblings the other instances that reach the same server, counted for a component grouped by server
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $detail,
        public readonly ?string $error,
        public readonly ?ComponentSnapshot $snapshot,
        public readonly ?Server $server = null,
        public readonly int $siblings = 0,
    ) {}
}
