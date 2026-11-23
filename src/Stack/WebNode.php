<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack;

use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheKeys;

/**
 * The web server answering the request, which a load balancer may pick among several.
 */
final readonly class WebNode
{
    /** null when the server has no name */
    public ?string $name;

    /**
     * @param ?string $name its host name by default, read when the service is built
     */
    public function __construct(?string $name = null)
    {
        $name ??= function_exists('gethostname') ? (string) gethostname() : '';
        $this->name = $name === '' ? null : $name;
    }

    /**
     * Namespace of the cache entries of this server: the unencoded "%node" segment cannot collide with an encoded id.
     */
    public function keyPrefix(): string
    {
        return $this->name === null ? '' : '%node.' . CacheKeys::segment($this->name) . '.';
    }
}
