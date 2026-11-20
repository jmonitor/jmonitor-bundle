<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Buffer;

use Jmonitor\JmonitorBundle\Recording\Entry;
use Psr\Clock\ClockInterface;

final class FileBuffer implements BufferInterface
{
    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly int $slotSeconds,
        private readonly ClockInterface $clock,
        private readonly int $maxSlotBytes = 16_777_216,
        private readonly int $maxSlotAge = 3600,
        private readonly int $maxSlotsPerDrain = 60,
        private readonly int $graceSeconds = 1,
    ) {}

    public function append(Entry $entry): void
    {
        $line = $entry->toLine() . "\n";
        $directory = $this->directory->path();
        if ($directory === null) {
            return;
        }

        $path = $this->currentSlot($directory);

        BufferDirectory::quietly(fn() => $this->write($path, $line));
    }

    public function isUnderPressure(): ?bool
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return null;
        }

        $path = $this->currentSlot($directory);

        return BufferDirectory::quietly(static function () use ($path): int {
            clearstatcache(true, $path);

            return filesize($path) ?: 0;
        }) >= intdiv($this->maxSlotBytes, 4);
    }

    public function drain(callable $consumer, bool $includeCurrent = false): int
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return 0;
        }

        $now = $this->clock->now()->getTimestamp();
        $slots = $this->slotFiles($directory);

        $files = [];
        foreach ($slots as ['start' => $start, 'path' => $path, 'taken' => $taken]) {
            if (\count($files) === $this->maxSlotsPerDrain) {
                break;
            }

            // An append that resolved its path just before the slot ended may still be writing.
            if ($taken || $start + $this->slotSeconds + $this->graceSeconds <= $now) {
                $files[] = $path;

                continue;
            }

            if ($includeCurrent) {
                $path = $this->take($directory, $start, $path);
                if ($path !== null) {
                    $files[] = $path;
                }
            }
        }

        if ($files === []) {
            return 0;
        }

        $count = 0;

        try {
            $consumer($this->read($files, $count));
        } catch (\Throwable $e) {
            foreach ($slots as ['start' => $start, 'path' => $path]) {
                if ($now - $start - $this->slotSeconds > $this->maxSlotAge) {
                    self::discard($path);
                }
            }

            throw $e;
        }

        foreach ($files as $file) {
            self::discard($file);
        }

        return $count;
    }

    private function currentSlot(string $directory): string
    {
        return sprintf('%s/%d.ndjson', $directory, intdiv($this->clock->now()->getTimestamp(), $this->slotSeconds) * $this->slotSeconds);
    }

    private static function discard(string $file): void
    {
        BufferDirectory::quietly(static fn(): bool => unlink($file) || file_put_contents($file, '') === 0);
    }

    /**
     * A drain takes a slot by renaming it: a writer that opened it before then finds, once it holds the lock,
     * that its path leads to another file, and starts over on the one replacing it.
     */
    private function write(string $path, string $line): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            clearstatcache(true, $path);
            $exists = is_file($path);
            if ($exists && (filesize($path) ?: 0) >= $this->maxSlotBytes) {
                return;
            }

            $handle = fopen($path, 'a');
            if ($handle === false) {
                $this->directory->invalidate();

                return;
            }

            if (!$exists) {
                chmod($path, 0o600);
            }

            try {
                if (!flock($handle, \LOCK_EX)) {
                    return;
                }

                clearstatcache(true, $path);
                $current = stat($path);
                $opened = fstat($handle);
                if ($current !== false && $opened !== false && $current['ino'] === $opened['ino']) {
                    fwrite($handle, $line);

                    return;
                }
            } finally {
                flock($handle, \LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function take(string $directory, int $start, string $path): ?string
    {
        $taken = sprintf('%s/%d-%s.ndjson', $directory, $start, bin2hex(random_bytes(4)));

        return BufferDirectory::quietly(static fn(): bool => rename($path, $taken)) ? $taken : null;
    }

    /**
     * @return list<array{start: int, path: string, taken: bool}> oldest first, taken slots before the one of the same start
     */
    private function slotFiles(string $directory): array
    {
        $files = [];

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isFile() && preg_match('/^(\d+)(-[0-9a-f]{8})?\.ndjson$/', $item->getFilename(), $matches) === 1) {
                $files[] = ['start' => (int) $matches[1], 'path' => $item->getPathname(), 'taken' => isset($matches[2])];
            }
        }

        usort($files, static fn(array $a, array $b): int => [$a['start'], $a['path']] <=> [$b['start'], $b['path']]);

        return $files;
    }

    /**
     * @param list<string> $files
     *
     * @return \Generator<int, Entry>
     */
    private function read(array $files, int &$count): \Generator
    {
        foreach ($files as $file) {
            $handle = BufferDirectory::quietly(static fn(): mixed => fopen($file, 'r'));
            if ($handle === false) {
                continue;
            }

            try {
                flock($handle, \LOCK_SH);
                while (($line = fgets($handle)) !== false) {
                    $entry = Entry::fromLine(rtrim($line, "\r\n"));
                    if ($entry !== null) {
                        ++$count;

                        yield $entry;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }
}
