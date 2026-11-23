<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Metric;

use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FiguresTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function byteSizes(): iterable
    {
        yield 'bytes' => [1_023, '1,023 B'];
        yield 'one kilobyte' => [1_024, '1 KB'];
        yield 'one decimal below ten' => [1_536, '1.5 KB'];
        yield 'rounded below 1024' => [1_047_961, '1,023 KB'];
        yield 'rounded up to 1024 kilobytes' => [1_048_575, '1 MB'];
        yield 'rounded up to 1024 megabytes' => [1_073_741_823, '1 GB'];
        yield 'the largest unit' => [1_024 ** 5, '1,024 TB'];
    }

    #[DataProvider('byteSizes')]
    public function testBytesNeverShow1024OfAUnitThatHasALargerOne(int $bytes, string $expected): void
    {
        self::assertSame(str_replace(' ', Figures::NBSP, $expected), Figures::bytes($bytes));
    }

    /**
     * @return iterable<string, array{float, string, string}>
     */
    public static function rates(): iterable
    {
        yield 'two decimals below 1' => [0.049, 'req/s', '0.05 req/s'];
        yield 'one decimal below 100' => [3.14159, 'req/s', '3.1 req/s'];
        yield 'no decimal from 100' => [250.4, 'req/s', '250 req/s'];
        yield 'thousands' => [12_345.6, 'req/s', '12,346 req/s'];
        yield 'zero' => [0.0, 's', '0.00 s'];
        yield 'rounded up to 1' => [0.995, 's', '1.0 s'];
        yield 'rounded up to 100' => [99.96, 'req/s', '100 req/s'];
        yield 'without unit' => [2.233062, '', '2.2'];
    }

    #[DataProvider('rates')]
    public function testARateKeepsTheDecimalsItsSizeNeeds(float $value, string $unit, string $expected): void
    {
        self::assertSame(str_replace(' ', Figures::NBSP, $expected), Figures::rate($value, $unit));
    }
}
