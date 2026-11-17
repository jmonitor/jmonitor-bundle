<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Buffer;

/**
 * The directory holding the buffer and flush markers of one application for one system user.
 * It is refused unless it is a real directory owned by the effective user with no group or other permission.
 * Without ext-posix the owner cannot be compared, since getmyuid() returns the script owner; a writable directory
 * with no group or other permission then belongs to the process user anyway, unless the process runs as root.
 */
final class BufferDirectory
{
    private ?string $verified = null;

    public function __construct(
        private readonly ?string $baseDirectory,
        private readonly string $appId,
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
        $uid = \function_exists('posix_geteuid') ? posix_geteuid() : null;
        $suffix = $uid ?? getmyuid();
        if ($suffix === false) {
            return null;
        }

        $path = rtrim($this->baseDirectory ?? sys_get_temp_dir(), '/\\') . '/jmonitor-' . $this->appId . '-u' . $suffix;

        mkdir($path, 0o700, true);
        clearstatcache(true, $path);
        $stat = lstat($path);

        if ($stat === false
            || ($stat['mode'] & 0o170000) !== 0o040000
            || ($uid !== null && $stat['uid'] !== $uid)
            || ($stat['mode'] & 0o077) !== 0
            || !is_writable($path)
        ) {
            return null;
        }

        return $path;
    }
}
