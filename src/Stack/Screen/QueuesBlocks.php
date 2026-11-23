<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\BlockSourceInterface;
use Jmonitor\JmonitorBundle\Dashboard\Format;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Fact;
use Jmonitor\JmonitorBundle\Dashboard\View\Facts;
use Jmonitor\JmonitorBundle\Dashboard\View\Notice;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Reading\QueuesReading;
use Jmonitor\JmonitorBundle\Stack\Screen\Detail\Sections;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

final class QueuesBlocks implements BlockSourceInterface
{
    private const FAILED = 'The queues could not be counted';

    private readonly StackScreenParts $parts;

    /**
     * @param ContainerInterface $services gives StackReaderInterface under its class name
     */
    public function __construct(ContainerInterface $services, ClockInterface $clock)
    {
        $this->parts = new StackScreenParts($services, $clock);
    }

    public function blocks(ScreenRequest $request): array
    {
        $state = $this->parts->reader()->queues($request->fragment ? ReadMode::Fragment : ReadMode::Page);

        if ($state === null) {
            return [];
        }

        $blocks = $this->of($state);

        if ($blocks !== [] && StackScreenParts::followUp($state)) {
            $blocks[0] = $blocks[0]->withReading();
        }

        return $blocks;
    }

    /**
     * @return list<Block>
     */
    private function of(StackState $state): array
    {
        if ($state->error !== null) {
            return [self::failed($state->error)];
        }

        $instance = $state->component(Component::Symfony)?->instances[0] ?? null;
        $snapshot = $instance?->snapshot;
        $error = $instance->error ?? $snapshot->error ?? null;

        if ($error !== null) {
            return [self::failed($error)];
        }

        $result = $snapshot?->result('queues');

        if ($snapshot === null || $result === null || $result->state === ResultState::Pending || $result->state === ResultState::Busy) {
            return $state->cache === CacheState::OutOfOrder
                ? [Block::notice(new Notice('warn', 'The queues need a working cache', 'Check the cache.app pool of the application.'))]
                : [Block::unavailable(Block::FACTS, BlockState::Reading, 'Counting the queues…', 'The messages waiting in each transport are being counted.')];
        }

        $now = $this->parts->now();

        if ($result->state === ResultState::Failed) {
            $reason = $result->error ?? 'no reason given';

            return [self::failed($result->expiresAt === null ? $reason : $reason . ' — ' . self::nextTry($result->expiresAt, $now))];
        }

        $reading = QueuesReading::of($snapshot);
        $blocks = $result->state === ResultState::Stale && $result->error !== null
            ? [Block::notice(new Notice('warn', 'Could not be counted again: ' . $result->error, $result->expiresAt === null ? '' : self::nextTry($result->expiresAt, $now)))]
            : [];

        if ($reading->transports === []) {
            return [...$blocks, Block::notice(new Notice('info', 'No transport to count'))];
        }

        return [...$blocks, Block::facts(new Facts('Queues', array_map(
            static fn(array $transport): Fact => $transport['count'] === null
                ? new Fact($transport['name'], 'Uncountable', hint: $transport['reason'])
                : new Fact($transport['name'], Format::number($transport['count'])),
            $reading->transports,
        ), self::note($result, $now)))];
    }

    private static function failed(string $reason): Block
    {
        return Block::notice(new Notice('warn', self::FAILED, rtrim($reason, '.') . '. Turn the count off with jmonitor.stack.symfony.messenger: false.'));
    }

    private static function note(CollectorResult $result, int $now): string
    {
        $note = 'Messages waiting in each transport';

        return $result->collectedAt === null ? $note : $note . ', counted ' . Sections::unbroken(Format::ago($result->collectedAt, $now));
    }

    private static function nextTry(int $expiresAt, int $now): string
    {
        return 'next try ' . Sections::unbroken(Format::in($expiresAt, $now));
    }
}
