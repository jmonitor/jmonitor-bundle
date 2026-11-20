<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

enum Family: string
{
    case Request = 'request';
    case Query = 'query';
    case NPlusOne = 'nplus1';
    case Exception = 'exception';
    case ExceptionWhere = 'exception_where';
    case User = 'user';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Request, self::Exception, self::ExceptionWhere => false,
            self::Query, self::NPlusOne, self::User => true,
        };
    }

    public function cap(): int
    {
        return match ($this) {
            self::Request => 300,
            self::Query, self::User => 200,
            self::NPlusOne, self::ExceptionWhere => 100,
            self::Exception => 50,
        };
    }

    public function weightMetric(): string
    {
        return match ($this) {
            self::Request => Metric::REQUEST_DURATION,
            self::Query => Metric::QUERY_DURATION,
            self::NPlusOne => Metric::NPLUS1_DURATION,
            self::Exception => Metric::EXCEPTION_COUNT,
            self::ExceptionWhere => Metric::EXCEPTION_WHERE_COUNT,
            self::User => Metric::USER_DURATION,
        };
    }

    /**
     * @param array<string, array{int, float, float}> $counters count, sum and max of each metric type of a key
     */
    public function weight(array $counters): float
    {
        if ($this === self::User) {
            return ($counters[Metric::USER_DURATION][0] ?? 0)
                + 10 * (($counters[Metric::USER_SLOW][0] ?? 0) + ($counters[Metric::USER_ERROR][0] ?? 0));
        }

        $measure = $counters[$this->weightMetric()] ?? null;
        if ($measure === null) {
            return 0.0;
        }

        return $this->isOpen() ? $measure[1] : (float) $measure[0];
    }

    public static function ofMetric(string $metricType): ?self
    {
        return self::tryFrom(strstr($metricType, '.', true) ?: $metricType);
    }
}
