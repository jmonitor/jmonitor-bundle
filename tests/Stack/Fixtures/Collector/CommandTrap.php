<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Puts commands with the given names first in the PATH, which only note that they were started.
 */
final class CommandTrap
{
    private readonly string $dir;
    private readonly string|false $path;

    public function __construct(string ...$names)
    {
        $this->dir = sys_get_temp_dir() . '/jmonitor-trap-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        foreach ($names as $name) {
            file_put_contents($this->dir . '/' . $name, "#!/bin/sh\ntouch '" . $this->dir . "/started'\n");
            chmod($this->dir . '/' . $name, 0o755);
        }

        $this->path = getenv('PATH');
        putenv('PATH=' . $this->dir . ':' . $this->path);
    }

    public function started(): bool
    {
        return file_exists($this->dir . '/started');
    }

    public function release(): void
    {
        putenv($this->path === false ? 'PATH' : 'PATH=' . $this->path);
        (new Filesystem())->remove($this->dir);
    }
}
