<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Screen\Detail;

use Jmonitor\JmonitorBundle\Dashboard\View\Block;
use Jmonitor\JmonitorBundle\Stack\Check\Finding;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;

interface DetailInterface
{
    /**
     * @param list<Finding> $findings the checks raised on this instance
     * @param bool          $reading  false when nothing more will be read for now: what was never read is left out
     *
     * @return list<Block> the sections under the checks of the component
     */
    public function sections(InstanceState $instance, array $findings, int $now, bool $reading = true): array;
}
