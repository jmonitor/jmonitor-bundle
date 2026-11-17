<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Buffer;

use Jmonitor\JmonitorBundle\Recording\Entry;
use Psr\Clock\ClockInterface;

final class FileBuffer implements BufferInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly string $appId,
        private readonly int $slotSeconds,
        private readonly ClockInterface $clock,
        private readonly int $maxSlotBytes = 16_777_216,
        private readonly int $maxSlotAge = 3600,
    ) {}

    public function append(Entry $entry): void
    {
        $line = $entry->toLine() . "\n";
        $path = $this->path(intdiv($this->clock->now()->getTimestamp(), $this->slotSeconds));

        self::quietly(fn() => $this->write($path, $line));
    }

    public function drain(callable $consumer): int
    {
        $now = $this->clock->now()->getTimestamp();
        $currentSlot = intdiv($now, $this->slotSeconds);

        $files = [];
        foreach ($this->slotFiles() as $slot => $file) {
            if ($slot >= $currentSlot) {
                continue;
            }

            if ($now - $slot * $this->slotSeconds > $this->maxSlotAge) {
                self::quietly(static fn(): bool => unlink($file));

                continue;
            }

            $files[] = $file;
        }

        if ($files === []) {
            return 0;
        }

        $count = 0;
        $consumer($this->read($files, $count));

        foreach ($files as $file) {
            self::quietly(static fn(): bool => unlink($file));
        }

        return $count;
    }

    private function write(string $path, string $line): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            return;
        }

        if (!is_writable($this->directory)) {
            return;
        }

        clearstatcache(true, $path);
        if (is_file($path) && (filesize($path) ?: 0) >= $this->maxSlotBytes) {
            return;
        }

        $handle = fopen($path, 'a');
        if ($handle === false) {
            return;
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
     * @return array<int, string> slot number => path, ascending
     */
    private function slotFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $pattern = '/^jmonitor-' . preg_quote($this->appId, '/') . '-(\d+)\.ndjson$/';
        $files = [];

        foreach (new \DirectoryIterator($this->directory) as $item) {
            if ($item->isFile() && preg_match($pattern, $item->getFilename(), $matches) === 1) {
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
            $handle = self::quietly(static fn(): mixed => fopen($file, 'r'));
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

    private static function quietly(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function path(int $slot): string
    {
        return sprintf('%s/jmonitor-%s-%d.ndjson', $this->directory, $this->appId, $slot);
    }
}
