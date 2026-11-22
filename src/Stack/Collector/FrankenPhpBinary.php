<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

final class FrankenPhpBinary
{
    private const NAME = 'frankenphp';

    /**
     * The running FrankenPHP when /proc tells it, the "frankenphp" of the PATH otherwise.
     *
     * @param ?string $self the target of /proc/self/exe, read when null
     */
    public static function path(?string $self = null): string
    {
        $self ??= @readlink('/proc/self/exe') ?: '';

        return basename($self) === self::NAME ? $self : self::NAME;
    }
}
