<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Psr\Clock\ClockInterface;

final class FlushLock
{
    /** @var resource|null */
    private $handle;

    private readonly string $directory;
    private readonly string $flushMarker;
    private readonly string $purgeMarker;

    public function __construct(
        string $directory,
        string $appId,
        private readonly int $intervalSeconds,
        private readonly ClockInterface $clock,
    ) {
        $this->directory = $directory;
        $this->flushMarker = sprintf('%s/jmonitor-%s.flush', $directory, $appId);
        $this->purgeMarker = sprintf('%s/jmonitor-%s.purge', $directory, $appId);
    }

    public function isDue(): bool
    {
        $elapsed = $this->elapsedSince($this->flushMarker);

        return $elapsed >= $this->intervalSeconds
            && ($elapsed !== \PHP_INT_MAX || self::quietly(fn(): bool => is_writable($this->directory)));
    }

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return false;
        }

        return self::quietly(function (): bool {
            $handle = fopen($this->flushMarker, 'c');
            if ($handle === false) {
                return false;
            }

            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                fclose($handle);

                return false;
            }

            $this->handle = $handle;
            touch($this->flushMarker, $this->clock->now()->getTimestamp());

            return true;
        });
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        self::quietly(static function () use ($handle): void {
            flock($handle, \LOCK_UN);
            fclose($handle);
        });
    }

    public function isPurgeDue(int $intervalSeconds): bool
    {
        return $this->elapsedSince($this->purgeMarker) >= $intervalSeconds;
    }

    public function markPurged(): void
    {
        self::quietly(fn(): bool => touch($this->purgeMarker, $this->clock->now()->getTimestamp()));
    }

    private function elapsedSince(string $marker): int
    {
        $modified = self::quietly(static function () use ($marker): int|false {
            clearstatcache(true, $marker);

            return filemtime($marker);
        });

        if ($modified === false) {
            return \PHP_INT_MAX;
        }

        return $this->clock->now()->getTimestamp() - $modified;
    }

    private static function quietly(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
