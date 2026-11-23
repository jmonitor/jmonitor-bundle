<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;

final readonly class CollectorSpec
{
    public string $name;

    /**
     * @param bool $shared     the same value for every web server: its entry does not carry the name of the server
     * @param bool $detailOnly only read for the detail of its instance
     * @param bool $perServer  the same value for every instance of its server
     */
    public function __construct(
        public CollectorInterface $collector,
        public Lifetime $lifetime,
        public CostClass $cost,
        public bool $trackDelta = false,
        public bool $shared = false,
        public bool $detailOnly = false,
        public bool $perServer = false,
    ) {
        $this->name = $collector->getName();
    }
}
