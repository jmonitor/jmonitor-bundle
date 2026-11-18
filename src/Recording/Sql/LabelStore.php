<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording\Sql;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;

/**
 * The files hold SQL text of the application, so they live in the private buffer directory only; the
 * modification time of a label is 1 until a flush stores it, then the time it was stored.
 */
final class LabelStore
{
    public const MAX_FILES = 5000;
    public const STORE_EVERY = 3600;

    private const DIRECTORY = 'labels';
    private const FULL = '.full';
    private const TEMPORARY_PREFIX = '.tmp-';
    private const NEVER_STORED = 1;
    private const MAX_BYTES = 4 * SqlNormalizer::MAX_LENGTH;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly int $maxFiles = self::MAX_FILES,
    ) {}

    public function exists(string $id): bool
    {
        $path = $this->path($id);

        return $path !== null && BufferDirectory::quietly(static fn(): bool => is_file($path));
    }

    public function write(string $id, string $text): bool
    {
        $path = $this->path($id);
        if ($path === null) {
            return false;
        }

        return BufferDirectory::quietly(static function () use ($path, $text): bool {
            if (is_file($path)) {
                return true;
            }

            $directory = \dirname($path);
            if (file_exists($directory . '/' . self::FULL)) {
                return false;
            }

            if (!is_dir($directory) && !mkdir($directory, 0o700) && !is_dir($directory)) {
                return false;
            }

            $temporary = $directory . '/' . self::TEMPORARY_PREFIX . bin2hex(random_bytes(6));
            $handle = fopen($temporary, 'x');
            if ($handle === false) {
                return false;
            }

            chmod($temporary, 0o600);
            $complete = fwrite($handle, $text) === \strlen($text);
            fclose($handle);

            if ($complete && touch($temporary, self::NEVER_STORED) && rename($temporary, $path)) {
                return true;
            }

            unlink($temporary);

            return false;
        });
    }

    public function read(string $id): ?string
    {
        $path = $this->path($id);
        if ($path === null) {
            return null;
        }

        $text = BufferDirectory::quietly(static fn(): string|false => is_file($path) ? file_get_contents($path, false, null, 0, self::MAX_BYTES) : false);

        return \is_string($text) && $text !== '' ? $text : null;
    }

    public function isDue(string $id, int $now): bool
    {
        $path = $this->path($id);
        if ($path === null) {
            return false;
        }

        $modified = BufferDirectory::quietly(static function () use ($path): int|false {
            clearstatcache(true, $path);

            return filemtime($path);
        });

        return $modified !== false && ($modified === self::NEVER_STORED || $now - $modified >= self::STORE_EVERY);
    }

    public function markStored(string $id, int $now): void
    {
        $path = $this->path($id);
        if ($path === null) {
            return;
        }

        BufferDirectory::quietly(static fn(): bool => is_file($path) && touch($path, $now));
    }

    /**
     * @param list<string> $keepIds
     */
    public function prune(array $keepIds, int $now): void
    {
        $base = $this->directory->path();
        if ($base === null) {
            return;
        }

        $directory = $base . '/' . self::DIRECTORY;
        $keep = array_flip($keepIds);
        $maxFiles = $this->maxFiles;

        BufferDirectory::quietly(static function () use ($directory, $keep, $now, $maxFiles): void {
            $names = is_dir($directory) ? scandir($directory) : false;
            if ($names === false) {
                return;
            }

            $count = 0;
            foreach ($names as $name) {
                $isLabel = self::isValid($name);
                if (!$isLabel && !str_starts_with($name, self::TEMPORARY_PREFIX)) {
                    continue;
                }

                $path = $directory . '/' . $name;
                if (!isset($keep[$name])) {
                    $changed = filectime($path);
                    if ($changed !== false && $now - $changed > self::STORE_EVERY && unlink($path)) {
                        continue;
                    }
                }

                if ($isLabel) {
                    ++$count;
                }
            }

            $full = $directory . '/' . self::FULL;
            if ($count >= $maxFiles) {
                touch($full);
            } elseif (file_exists($full)) {
                unlink($full);
            }
        });
    }

    private function path(string $id): ?string
    {
        if (!self::isValid($id)) {
            return null;
        }

        $base = $this->directory->path();

        return $base === null ? null : $base . '/' . self::DIRECTORY . '/' . $id;
    }

    private static function isValid(string $id): bool
    {
        return \strlen($id) === 16 && strspn($id, '0123456789abcdef') === 16;
    }
}
