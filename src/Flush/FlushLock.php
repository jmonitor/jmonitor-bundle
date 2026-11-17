<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Flush;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Psr\Clock\ClockInterface;

final class FlushLock
{
    private const FLUSH_MARKER = 'last-flush';
    private const PURGE_MARKER = 'last-purge';

    /** @var resource|null */
    private $handle;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly int $intervalSeconds,
        private readonly ClockInterface $clock,
    ) {}

    public function isDue(): bool
    {
        return $this->isOlderThan(self::FLUSH_MARKER, $this->intervalSeconds);
    }

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return false;
        }

        $directory = $this->directory->path();
        if ($directory === null) {
            return false;
        }

        $marker = $directory . '/' . self::FLUSH_MARKER;

        return BufferDirectory::quietly(function () use ($marker): bool {
            $handle = fopen($marker, 'c');
            if ($handle === false) {
                $this->directory->invalidate();

                return false;
            }

            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                fclose($handle);

                return false;
            }

            $this->handle = $handle;
            $this->stamp($marker);

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

        BufferDirectory::quietly(static function () use ($handle): void {
            flock($handle, \LOCK_UN);
            fclose($handle);
        });
    }

    public function isPurgeDue(int $intervalSeconds): bool
    {
        return $this->isOlderThan(self::PURGE_MARKER, $intervalSeconds);
    }

    public function markPurged(): void
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return;
        }

        BufferDirectory::quietly(fn() => $this->stamp($directory . '/' . self::PURGE_MARKER));
    }

    private function stamp(string $marker): void
    {
        touch($marker, $this->clock->now()->getTimestamp()) || touch($marker);
    }

    /**
     * A marker dated in the future, as after the clock was set back, counts as due.
     */
    private function isOlderThan(string $name, int $seconds): bool
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return false;
        }

        $marker = $directory . '/' . $name;
        $modified = BufferDirectory::quietly(static function () use ($marker): int|false {
            clearstatcache(true, $marker);

            return filemtime($marker);
        });

        if ($modified === false) {
            return true;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $modified;

        return $elapsed < 0 || $elapsed >= $seconds;
    }
}
