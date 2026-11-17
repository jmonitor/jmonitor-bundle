<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Storage\Dbal;

use Doctrine\DBAL\Schema\AbstractAsset;

final class SchemaAssetFilter
{
    private const PREFIX = 'jmonitor_';

    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;
        $dot = strrpos($name, '.');

        return !str_starts_with($dot === false ? $name : substr($name, $dot + 1), self::PREFIX);
    }
}
