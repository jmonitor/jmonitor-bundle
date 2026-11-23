<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector;

final class Failures
{
    public static function thrownBy(\Closure $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }

        throw new \LogicException('Nothing was thrown.');
    }

    /**
     * @return list<class-string<\Throwable>>
     */
    public static function causes(\Throwable $e): array
    {
        $causes = [];

        for (; $e !== null; $e = $e->getPrevious()) {
            $causes[] = $e::class;
        }

        return $causes;
    }
}
