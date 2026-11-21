<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\EasyAdminBundle;

final class Requirements
{
    public const MINIMUM_VERSION = '5.10.2';

    /**
     * @param array<string, class-string> $bundles
     */
    public static function assertMet(array $bundles): void
    {
        if (!isset($bundles['EasyAdminBundle'])) {
            throw new \LogicException(\sprintf('The "jmonitor.easyadmin" option requires the EasyAdminBundle: install easycorp/easyadmin-bundle %s or later, or turn the option off.', self::MINIMUM_VERSION));
        }

        self::assertVersion(EasyAdminBundle::VERSION);
    }

    public static function assertVersion(string $installed): void
    {
        if (version_compare($installed, self::MINIMUM_VERSION, '<')) {
            throw new \LogicException(\sprintf('The "jmonitor.easyadmin" option requires EasyAdmin %s or later, %s is installed.', self::MINIMUM_VERSION, $installed));
        }
    }
}
