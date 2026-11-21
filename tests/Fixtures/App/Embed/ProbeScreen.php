<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Fixtures\App\Embed;

use Jmonitor\JmonitorBundle\Dashboard\ActionScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\BlockScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Dashboard\View\BlockState;
use Jmonitor\JmonitorBundle\Dashboard\View\Card;
use Jmonitor\JmonitorBundle\Dashboard\View\Cell;
use Jmonitor\JmonitorBundle\Dashboard\View\Column;
use Jmonitor\JmonitorBundle\Dashboard\View\Row;
use Jmonitor\JmonitorBundle\Dashboard\View\Table;

final class ProbeScreen implements BlockScreenInterface, ActionScreenInterface
{
    public function __construct(private readonly string $counterFile) {}

    public function id(): string
    {
        return 'probe';
    }

    public function label(): string
    {
        return 'Probe';
    }

    public function heading(): string
    {
        return 'A screen that reads no storage';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function blocks(ScreenRequest $request): array
    {
        $runs = $this->runs();
        $cards = Block::cards([new Card('Runs', (string) $runs)]);

        if ($runs === 0) {
            return [$cards, Block::unavailable(Block::TABLE, BlockState::Empty, 'Nothing probed yet', 'Send the probe.')];
        }

        return [$cards, Block::table(new Table(
            'probes',
            'Probes',
            [new Column('runs', 'Runs')],
            [new Row('probe', ['runs' => new Cell((string) $runs)], ['data-jm-probe-runs' => (string) $runs])],
            noun: 'probe',
        ))];
    }

    public function actions(): array
    {
        return ['ping' => 'Ping'];
    }

    public function run(string $action): void
    {
        file_put_contents($this->counterFile, (string) ($this->runs() + 1));
    }

    private function runs(): int
    {
        return is_file($this->counterFile) ? (int) file_get_contents($this->counterFile) : 0;
    }
}
