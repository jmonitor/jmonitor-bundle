<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\Utils\UrlFetcher;

/**
 * The release cycles of a product, as published by endoflife.date.
 */
final readonly class EndOfLifeCollector implements CollectorInterface
{
    private const URL = 'https://endoflife.date/api/%s.json';

    /**
     * @param string $product the endoflife.date product, such as "php" or "symfony"
     */
    public function __construct(
        private string $product,
        private UrlFetcher $fetcher,
    ) {}

    /**
     * @return array{cycles: array<string, array{eol: bool|string, support: bool|string|null}>}
     *
     * @throws \RuntimeException the response holds no readable cycle
     */
    public function collect(): array
    {
        $cycles = [];

        foreach ($this->fetcher->fetchJson(sprintf(self::URL, $this->product)) as $entry) {
            if (!is_array($entry) || !is_scalar($entry['cycle'] ?? null) || !EndOfLife::isDate($entry['eol'] ?? null)) {
                continue;
            }

            $support = $entry['support'] ?? null;
            $cycles[(string) $entry['cycle']] = ['eol' => $entry['eol'], 'support' => EndOfLife::isDate($support) ? $support : null];
        }

        if ($cycles === []) {
            throw new \RuntimeException(sprintf('No release cycle of "%s" in the response of endoflife.date.', $this->product));
        }

        return ['cycles' => $cycles];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'eol.' . $this->product;
    }
}
