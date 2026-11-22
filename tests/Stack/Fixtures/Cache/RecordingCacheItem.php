<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Cache;

use Psr\Cache\CacheItemInterface;

final class RecordingCacheItem implements CacheItemInterface
{
    public function __construct(
        public readonly CacheItemInterface $inner,
        private readonly RecordingCachePool $pool,
    ) {}

    public function getKey(): string
    {
        return $this->inner->getKey();
    }

    public function get(): mixed
    {
        return $this->inner->get();
    }

    public function isHit(): bool
    {
        return $this->inner->isHit();
    }

    public function set(mixed $value): static
    {
        $this->inner->set($value);

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->inner->expiresAt($expiration);

        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        $this->inner->expiresAfter($time);
        $this->pool->record($this->getKey(), is_int($time) ? $time : null);

        return $this;
    }
}
