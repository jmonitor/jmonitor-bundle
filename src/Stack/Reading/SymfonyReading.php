<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class SymfonyReading
{
    /**
     * @param list<string> $bundles
     * @param ?bool        $recipesUpToDate null until the recipes are read, or when their check is turned off
     * @param list<string> $outdatedRecipes
     */
    private function __construct(
        public ?string $version,
        public ?SupportStatus $support,
        public ?string $environment,
        public ?bool $debug,
        public array $bundles,
        public ?bool $recipesUpToDate,
        public array $outdatedRecipes,
    ) {}

    public static function of(ComponentSnapshot $snapshot, \DateTimeImmutable $today): self
    {
        $data = $snapshot->data('symfony');
        $flex = $snapshot->has('symfony.flex') ? $snapshot->data('symfony.flex') : [];
        $version = Values::string($data['version'] ?? null);

        return new self(
            $version,
            EndOfLife::status($snapshot->data('eol.symfony'), $version, $today),
            Values::string($data['env'] ?? null),
            Values::bool($data['debug'] ?? null),
            self::strings($data['bundles'] ?? null),
            Values::bool($flex['up_to_date'] ?? null),
            self::strings($flex['outdated_recipes'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
