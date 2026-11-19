<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

enum Family: string
{
    case Request = 'request';
    case Query = 'query';
    case NPlusOne = 'nplus1';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Request => false,
            self::Query, self::NPlusOne => true,
        };
    }

    public function cap(): int
    {
        return match ($this) {
            self::Request => 300,
            self::Query => 200,
            self::NPlusOne => 100,
        };
    }

    public function weightMetric(): string
    {
        return match ($this) {
            self::Request => Metric::REQUEST_DURATION,
            self::Query => Metric::QUERY_DURATION,
            self::NPlusOne => Metric::NPLUS1_DURATION,
        };
    }

    public static function ofMetric(string $metricType): ?self
    {
        return self::tryFrom(strstr($metricType, '.', true) ?: $metricType);
    }
}
