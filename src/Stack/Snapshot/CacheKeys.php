<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Snapshot;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\WebNode;

/**
 * @internal
 */
final readonly class CacheKeys
{
    public function __construct(
        private WebNode $node,
    ) {}

    public function result(Instance $instance, CollectorSpec $spec): string
    {
        return 'jmonitor.stack.result.' . $this->suffix($instance, $spec);
    }

    /**
     * Named after the instance, even for a value of the server: a connection that cannot be opened, its password
     * refused for instance, says nothing of the other connections of its server.
     */
    public function failure(Instance $instance, CollectorSpec $spec): string
    {
        return 'jmonitor.stack.failure.' . $this->name($instance, $spec, false);
    }

    /**
     * Also names the rate reference of the collector. A value of the server is named after the server, under
     * an unencoded "%server" segment that cannot collide with an encoded id. A shared value is the same for every
     * instance of its component and every web server, under an unencoded "%shared" segment.
     */
    public function suffix(Instance $instance, CollectorSpec $spec): string
    {
        return $this->name($instance, $spec, $spec->perServer);
    }

    /**
     * Without the name of the server when every collector is shared: one web server reads for all of them.
     */
    public function witness(Instance $instance): string
    {
        $shared = $instance->collectors !== null
            && array_filter($instance->collectors->specs(), static fn(CollectorSpec $spec): bool => !$spec->shared) === [];

        return 'jmonitor.stack.collecting.' . ($shared ? '' : $this->nodePrefix($instance)) . $instance->key();
    }

    /**
     * Without the name of the web server: a forget outdates the values of every web server. A value of the server
     * has the marker of its server, which every instance of the server reads.
     */
    public function forgotten(Instance $instance, bool $ofServer = false): ?string
    {
        return $instance->component->isNodeLocal() ? 'jmonitor.stack.forgotten.' . self::owner($instance, $ofServer) : null;
    }

    public function forgottenFor(Instance $instance, CollectorSpec $spec): ?string
    {
        return $this->forgotten($instance, $spec->perServer);
    }

    /**
     * A name from outside, made a single segment: PSR-6 reserves some characters, and the dot separates segments.
     */
    public static function segment(string $name): string
    {
        return str_replace('.', '%2E', rawurlencode($name));
    }

    private function name(Instance $instance, CollectorSpec $spec, bool $ofServer): string
    {
        if ($spec->shared) {
            return $instance->component->value . '.%shared.' . $spec->name;
        }

        return $this->nodePrefix($instance) . self::owner($instance, $ofServer) . '.' . $spec->name;
    }

    private static function owner(Instance $instance, bool $ofServer): string
    {
        return $ofServer && $instance->server !== null
            ? $instance->component->value . '.%server.' . self::segment($instance->server->key)
            : $instance->key();
    }

    private function nodePrefix(Instance $instance): string
    {
        return $instance->component->isNodeLocal() ? $this->node->keyPrefix() : '';
    }
}
