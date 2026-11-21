<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Read;

use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final class InstanceState
{
    /**
     * @param ?ComponentSnapshot $snapshot null when the instance is listed without being read
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $detail,
        public readonly ?string $error,
        public readonly ?ComponentSnapshot $snapshot,
    ) {}
}
