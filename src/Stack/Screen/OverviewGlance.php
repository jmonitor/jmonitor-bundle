<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen;

use Jmonitor\JmonitorBundle\Dashboard\ScreenRequest;
use Jmonitor\JmonitorBundle\Dashboard\StackGlanceInterface;
use Jmonitor\JmonitorBundle\Dashboard\View\Alert;
use Jmonitor\JmonitorBundle\Dashboard\View\StackGlance;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Check\Severity;
use Jmonitor\JmonitorBundle\Stack\Read\ReadMode;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * The overview of the stack read without any slow collector, remembered at each read for the badge of the navigation.
 */
final class OverviewGlance implements StackGlanceInterface
{
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
            self::alerts($health->findings),
            $working ? null : StackScreenParts::cacheOutOfOrder(),
            StackScreenParts::more($state),
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Alert> the critical and warning ones, in the order of $findings
     */
    private static function alerts(array $findings): array
    {
        $alerts = [];

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Info) {
                continue;
            }

            $alerts[] = new Alert(
                $finding->id,
                Marks::severity($finding->severity),
                $finding->sentence,
                $finding->component->label(),
                StackScreenParts::path($finding->component),
                $finding->instance === null ? [] : [ScreenRequest::INSTANCE => $finding->instance],
            );
        }

        return $alerts;
    }
}
