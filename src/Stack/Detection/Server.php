<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

/**
 * The server an instance is read on, shared by the instances that reach it.
 */
final readonly class Server
{
    public function __construct(
        public string $key,
        public string $address,
    ) {}
}
