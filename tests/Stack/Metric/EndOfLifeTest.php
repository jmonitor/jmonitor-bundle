<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Metric;

use Jmonitor\JmonitorBundle\Stack\Metric\EndOfLife;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndOfLifeTest extends TestCase
{
    private const CYCLES = ['cycles' => [
        '8.5' => ['eol' => '2029-12-31', 'support' => '2027-12-31'],
        '8.4' => ['eol' => '2028-12-31', 'support' => '2026-10-20'],
        '8.3' => ['eol' => '2027-12-31', 'support' => '2026-10-19'],
        '8.2' => ['eol' => '2026-12-31', 'support' => '2024-12-31'],
        '8.1' => ['eol' => '2025-12-31', 'support' => '2023-11-25'],
        '8.0' => ['eol' => true, 'support' => false],
        '17' => ['eol' => '2029-11-08', 'support' => null],
        '7' => ['eol' => '2026-10-20', 'support' => null],
        '5' => ['eol' => '2026-10-19', 'support' => null],
        '9.6' => ['eol' => false, 'support' => true],
    ]];

    /**
     * @return iterable<string, array{?string, ?SupportStatus}>
     */
    public static function versions(): iterable
    {
        yield 'supported' => ['8.5.1', SupportStatus::Active];
        yield 'security fixes only' => ['8.2.30', SupportStatus::SecurityOnly];
        yield 'past its end of life date' => ['8.1.34', SupportStatus::Ended];
        yield 'flagged end of life' => ['8.0.30-0ubuntu0.22.04.1', SupportStatus::Ended];
        yield 'no support date, before its end of life' => ['17.6 (Debian 17.6-1.pgdg120+1)', SupportStatus::Active];
        yield 'on its last day of active support' => ['8.4.13', SupportStatus::Active];
        yield 'the day after its active support' => ['8.3.26', SupportStatus::SecurityOnly];
        yield 'no support date, on its last day' => ['7.4.33', SupportStatus::Active];
        yield 'no support date, the day after its last day' => ['5.6.40', SupportStatus::Ended];
        yield 'eol: false' => ['9.6.24', SupportStatus::Active];
        yield 'leading v' => ['v8.5.0', SupportStatus::Active];
        yield 'falls back to the major cycle' => ['17.2', SupportStatus::Active];
        yield 'unknown cycle' => ['8.6.0', null];
        yield 'no number' => ['unknown', null];
        yield 'no version' => [null, null];
    }

    #[DataProvider('versions')]
    public function testTheStatusFollowsTheCycle(?string $version, ?SupportStatus $expected): void
    {
        self::assertSame($expected, EndOfLife::status(self::CYCLES, $version, new \DateTimeImmutable('2026-10-20 12:00')));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function unknownShapes(): iterable
    {
        yield 'no cycles' => [['8.5' => ['eol' => '2029-12-31', 'support' => null]]];
        yield 'cycles that are no list' => [['cycles' => '8.5']];
        yield 'a cycle without eol' => [['cycles' => ['8.5' => ['support' => '2027-12-31']]]];
        yield 'a cycle without support' => [['cycles' => ['8.5' => ['eol' => '2029-12-31']]]];
        yield 'an eol that is no date' => [['cycles' => ['8.5' => ['eol' => 'soon', 'support' => null]]]];
    }

    /**
     * @param array<mixed> $cycles
     */
    #[DataProvider('unknownShapes')]
    public function testCyclesOfUnknownShapeGiveNoStatus(array $cycles): void
    {
        self::assertNull(EndOfLife::status($cycles, '8.5.1', new \DateTimeImmutable('2026-10-20')));
    }
}
