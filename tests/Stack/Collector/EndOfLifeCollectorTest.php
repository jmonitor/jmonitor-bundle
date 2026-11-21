<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeUrlFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndOfLifeCollectorTest extends TestCase
{
    public function testItReadsTheCyclesOfItsProduct(): void
    {
        $fetcher = new FakeUrlFetcher(json_encode([
            ['cycle' => '8.5', 'releaseDate' => '2025-11-20', 'eol' => '2029-12-31', 'latest' => '8.5.1', 'lts' => false, 'support' => '2027-12-31'],
            ['cycle' => '8.0', 'releaseDate' => '2020-11-26', 'eol' => true, 'latest' => '8.0.30', 'lts' => false, 'support' => false],
            ['cycle' => '17', 'releaseDate' => '2024-09-26', 'eol' => '2029-11-08', 'latest' => '17.6', 'lts' => false],
            ['cycle' => 7, 'eol' => '2026-10-20', 'support' => 'soon'],
        ], \JSON_THROW_ON_ERROR));
        $collector = new EndOfLifeCollector('php', $fetcher);

        self::assertSame('eol.php', $collector->getName());
        self::assertSame(['cycles' => [
            '8.5' => ['eol' => '2029-12-31', 'support' => '2027-12-31'],
            '8.0' => ['eol' => true, 'support' => false],
            '17' => ['eol' => '2029-11-08', 'support' => null],
            '7' => ['eol' => '2026-10-20', 'support' => null],
        ]], $collector->collect());
        self::assertSame('https://endoflife.date/api/php.json', $fetcher->url);
    }

    public function testMalformedEntriesAreLeftOut(): void
    {
        $fetcher = new FakeUrlFetcher(json_encode([
            ['cycle' => '8.5', 'eol' => 'soon'],
            '8.4',
            ['eol' => '2028-12-31'],
            ['cycle' => ['8.3'], 'eol' => '2027-12-31'],
            ['cycle' => '8.2', 'eol' => '2026-12-31', 'support' => '2024-12-31'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(
            ['cycles' => ['8.2' => ['eol' => '2026-12-31', 'support' => '2024-12-31']]],
            (new EndOfLifeCollector('php', $fetcher))->collect(),
        );
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function failures(): iterable
    {
        yield 'unreachable' => [null];
        yield 'not JSON' => ['<html>'];
        yield 'no cycle' => ['[]'];
        yield 'unexpected entries' => ['[{"cycle": "8.5", "eol": "soon"}, "8.4"]'];
    }

    #[DataProvider('failures')]
    public function testAResponseWithoutAReadableCycleFails(?string $response): void
    {
        $this->expectException(\RuntimeException::class);

        (new EndOfLifeCollector('symfony', new FakeUrlFetcher($response)))->collect();
    }
}
