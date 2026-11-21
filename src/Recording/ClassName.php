<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Recording;

final class ClassName
{
    private const ANONYMOUS = 'class@anonymous';

    public static function of(object $object): string
    {
        $class = $object::class;

        return str_contains($class, '@anonymous') ? self::ANONYMOUS : $class;
    }
}
