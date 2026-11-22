<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Metric;

final readonly class Rate
{
    public const SINCE_SERVER_START = 'since server start';
    public const AT_NEXT_REFRESH = 'at the next refresh';
    public const AFTER_A_MINUTE = 'after a minute';

    /**
     * $part and $total are the counters behind a ratio, over the same span as its value.
     * $since names where cumulated counters start, shown with a SinceStart value.
     * $expected tells when a Pending value comes.
     */
    public function __construct(
        public ?float $value,
        public RateSource $source,
        public string $unit = '',
        public int|float|null $part = null,
        public int|float|null $total = null,
        public string $since = self::SINCE_SERVER_START,
        public string $expected = self::AT_NEXT_REFRESH,
    ) {}

    public static function unavailable(string $unit = ''): self
    {
        return new self(null, RateSource::Unavailable, $unit);
    }

    public static function pending(string $unit = '', string $expected = self::AT_NEXT_REFRESH): self
    {
        return new self(null, RateSource::Pending, $unit, expected: $expected);
    }

    public function isSinceStart(): bool
    {
        return $this->source === RateSource::SinceStart;
    }

    public function hint(): ?string
    {
        return $this->isSinceStart() ? $this->since : null;
    }

    public function isUnavailable(): bool
    {
        return $this->source === RateSource::Unavailable || $this->isPending();
    }

    public function isPending(): bool
    {
        return $this->source === RateSource::Pending;
    }

    public function fraction(): ?string
    {
        return $this->part === null || $this->total === null ? null : $this->part . ' / ' . $this->total;
    }
}
