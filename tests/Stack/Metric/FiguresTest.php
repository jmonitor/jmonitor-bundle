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
}
