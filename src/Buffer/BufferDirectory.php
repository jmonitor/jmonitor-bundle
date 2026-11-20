<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Buffer;

use Psr\Log\LoggerInterface;

final class BufferDirectory
{
    private ?string $verified = null;

    /**
     * @param LoggerInterface|(\Closure(): ?LoggerInterface)|null $logger a closure is only called when there is something to log
     */
    public function __construct(
        private readonly ?string $baseDirectory,
        private readonly string $appId,
        private LoggerInterface|\Closure|null $logger = null,
    ) {}

    public function path(): ?string
    {
        if ($this->verified !== null && !is_link($this->verified) && is_dir($this->verified)) {
            return $this->verified;
        }

        return $this->verified = self::quietly(fn(): ?string => $this->resolve());
    }

    public function invalidate(): void
    {
        $this->verified = null;
    }

    public static function quietly(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function resolve(): ?string
    {
        $fallback = $this->baseDirectory !== null && !file_exists($this->baseDirectory);
        $base = rtrim($this->baseDirectory === null || $fallback ? sys_get_temp_dir() : $this->baseDirectory, '/\\');

        $uid = self::effectiveUid($base);
        if ($uid === null) {
            return null;
        }

        $path = $base . '/jmonitor-' . $this->appId . '-u' . $uid;

        $created = mkdir($path, 0o700);
        clearstatcache(true, $path);
        $stat = lstat($path);

        if ($stat === false
            || ($stat['mode'] & 0o170000) !== 0o040000
            || $stat['uid'] !== $uid
            || ($stat['mode'] & 0o077) !== 0
            || !is_writable($path)
        ) {
            return null;
        }

        if ($fallback && $created) {
            $this->warn(\sprintf('The configured buffer directory "%s" does not exist: JMonitor buffers in the system temporary directory instead.', $this->baseDirectory));
        }

        return $path;
    }

    /**
     * Without ext-posix, the owner of a file the process creates: getmyuid() is the owner of the script.
     */
    private static function effectiveUid(string $base): ?int
    {
        if (\function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $probe = tempnam($base, 'jmonitor-');
        if ($probe === false) {
            return null;
        }
        $uid = fileowner($probe);
        unlink($probe);

        return $uid === false ? null : $uid;
    }

    private function warn(string $message): void
    {
        if ($this->logger instanceof \Closure) {
            $this->logger = ($this->logger)();
        }
        $this->logger?->warning($message);
    }
}
