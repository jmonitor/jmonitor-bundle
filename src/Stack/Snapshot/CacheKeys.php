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

    public function failure(Instance $instance, CollectorSpec $spec): string
    {
        return 'jmonitor.stack.failure.' . $this->suffix($instance, $spec);
    }

    /**
     * Also names the rate reference of the collector.
     */
    public function suffix(Instance $instance, CollectorSpec $spec): string
    {
        return ($spec->shared ? '' : $this->nodePrefix($instance)) . $instance->key() . '.' . $spec->name;
    }

    public function witness(Instance $instance): string
    {
        return 'jmonitor.stack.collecting.' . $this->nodePrefix($instance) . $instance->key();
    }

    /**
     * Without the name of the server: a forget outdates the values of every web server.
     */
    public function forgotten(Instance $instance): ?string
    {
        return $instance->component->isNodeLocal() ? 'jmonitor.stack.forgotten.' . $instance->key() : null;
    }

    /**
     * A name from outside, made a single segment: PSR-6 reserves some characters, and the dot separates segments.
     */
    public static function segment(string $name): string
    {
        return str_replace('.', '%2E', rawurlencode($name));
    }

    private function nodePrefix(Instance $instance): string
    {
        return $instance->component->isNodeLocal() ? $this->node->keyPrefix() : '';
    }
}
