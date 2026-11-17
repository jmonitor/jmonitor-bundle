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

        $path = sprintf('%s/%d.ndjson', $directory, intdiv($this->clock->now()->getTimestamp(), $this->slotSeconds) * $this->slotSeconds);

        BufferDirectory::quietly(fn() => $this->write($path, $line));
    }

    public function drain(callable $consumer): int
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return 0;
        }

        $now = $this->clock->now()->getTimestamp();
        $slots = $this->slotFiles($directory);

        $files = [];
        foreach ($slots as $start => $file) {
            // An append that resolved its path just before the slot ended may still be writing.
            if ($start + $this->slotSeconds + $this->graceSeconds > $now || \count($files) === $this->maxSlotsPerDrain) {
                break;
            }

            $files[] = $file;
        }

        if ($files === []) {
            return 0;
        }

        $count = 0;

        try {
            $consumer($this->read($files, $count));
        } catch (\Throwable $e) {
            foreach ($slots as $start => $file) {
                if ($now - $start > $this->maxSlotAge) {
                    self::discard($file);
                }
            }

            throw $e;
        }

        foreach ($files as $file) {
            self::discard($file);
        }

        return $count;
    }

    private static function discard(string $file): void
    {
        BufferDirectory::quietly(static fn(): bool => unlink($file) || file_put_contents($file, '') === 0);
    }

    private function write(string $path, string $line): void
    {
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
            if (flock($handle, \LOCK_EX)) {
                fwrite($handle, $line);
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, string> slot start => path, ascending
     */
    private function slotFiles(string $directory): array
    {
        $files = [];

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isFile() && preg_match('/^(\d+)\.ndjson$/', $item->getFilename(), $matches) === 1) {
                $files[(int) $matches[1]] = $item->getPathname();
            }
        }

        ksort($files);

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
