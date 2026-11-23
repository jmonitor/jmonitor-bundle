<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Reading\QueuesReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueuesReadingTest extends TestCase
{
    public function testCountsAndReasonsAreRead(): void
    {
        $reading = QueuesReading::of(self::snapshot([
            'transports' => [
                ['name' => 'async', 'count' => 12, 'reason' => null],
                ['name' => '2024', 'count' => 0, 'reason' => null],
                ['name' => 'failed', 'count' => null, 'reason' => 'this transport cannot count'],
            ],
            'complete' => false,
        ]));

        self::assertSame([
            ['name' => 'async', 'count' => 12, 'reason' => null],
            ['name' => '2024', 'count' => 0, 'reason' => null],
            ['name' => 'failed', 'count' => null, 'reason' => 'this transport cannot count'],
        ], $reading->transports);
        self::assertFalse($reading->complete);
        self::assertTrue(QueuesReading::of(self::snapshot(['transports' => [], 'complete' => true]))->complete);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unknownShapes(): iterable
    {
        yield 'nothing' => [[]];
        yield 'transports that are not a list' => [['transports' => 'async', 'complete' => 'yes']];
        yield 'transports keyed by name' => [['transports' => ['async' => 12]]];
        yield 'transports without name' => [['transports' => [['count' => 3], null, 7, ['name' => 12, 'count' => 1]]]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('unknownShapes')]
    public function testDataOfUnknownShapeGivesNoTransportWithoutWarning(array $data): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            $reading = QueuesReading::of(self::snapshot($data));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $reading->transports);
        self::assertSame([], QueuesReading::of(new ComponentSnapshot(Component::Symfony, []))->transports);
    }

    public function testACountOrAReasonOfAnotherTypeIsLeftOut(): void
    {
        $reading = QueuesReading::of(self::snapshot(['transports' => [
            ['name' => 'async', 'count' => '12', 'reason' => ['x']],
            ['name' => 'batch', 'count' => -1],
        ]]));

        self::assertSame([
            ['name' => 'async', 'count' => null, 'reason' => null],
            ['name' => 'batch', 'count' => null, 'reason' => null],
        ], $reading->transports);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function snapshot(array $data): ComponentSnapshot
    {
        return new ComponentSnapshot(Component::Symfony, ['queues' => new CollectorResult('queues', $data, ResultState::Fresh, collectedAt: 1_780_000_000)]);
    }
}
