<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Cache;

use Jmonitor\JmonitorBundle\Recording\Recorder;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class RecordingTagAwareAdapter extends RecordingAdapter implements TagAwareAdapterInterface, TagAwareCacheInterface
{
    public function __construct(TagAwareAdapterInterface $pool, Recorder $recorder, string $name)
    {
        parent::__construct($pool, $recorder, $name);
    }

    public function invalidateTags(array $tags): bool
    {
        \assert($this->pool instanceof TagAwareAdapterInterface);

        return $this->pool->invalidateTags($tags);
    }
}
