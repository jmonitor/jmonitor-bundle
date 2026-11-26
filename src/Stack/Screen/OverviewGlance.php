<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * The overview of the stack read without any slow collector, remembered at each read for the badge of the navigation.
 */
final class OverviewGlance implements StackGlanceInterface
{
    private const PREFIX = 'Stack · ';

    private readonly StackScreenParts $parts;

    /**
     * @param ContainerInterface $services gives StackReaderInterface, StackChecks and StackSummary under their class names
     */
    public function __construct(
        ContainerInterface $services,
        private readonly CardBuilder $cards,
        ClockInterface $clock,
    ) {
        $this->parts = new StackScreenParts($services, $clock);
    }

    public function glance(): StackGlance
    {
        $state = $this->parts->reader()->overview(ReadMode::Page);

        if ($state->error !== null) {
            return new StackGlance(notice: StackScreenParts::engineFailure($state->error));
        }

        $health = $this->parts->health($state);
        $this->parts->summary()->remember($state, $health);

        if ($state->components === []) {
            return new StackGlance();
        }

        $working = $state->cache !== CacheState::OutOfOrder;
        $cards = [];

        foreach ($state->components as $component) {
            foreach ($component->instances as $instance) {
                if ($instance->snapshot !== null) {
                    $cards[] = $this->cards->card($component->component, $instance, $health, StackScreenParts::path($component->component), $working);
                }
            }
        }

        return new StackGlance(
            $cards,
            $this->parts->checkList($health->findings, true, prefix: self::PREFIX)->lines,
            $working ? null : StackScreenParts::cacheOutOfOrder(),
            StackScreenParts::more($state),
        );
    }
}
