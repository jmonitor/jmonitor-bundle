<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

use Jmonitor\JmonitorBundle\Dashboard\Check\RaisedChecks;

final class ScreenView
{
    /**
     * @param list<Card>  $cards
     * @param list<Table> $tables
     * @param list<Tile>  $tiles  shown in place of the cards
     */
    public function __construct(
        public readonly array $cards = [],
        public readonly array $tables = [],
        public readonly ?Lead $lead = null,
        public readonly ?CheckList $checks = null,
        public readonly ?Guide $guide = null,
        public readonly ?RaisedChecks $raised = null,
        public readonly ?Brief $brief = null,
        public readonly ?Map $map = null,
        public readonly ?Places $places = null,
        public readonly ?Status $status = null,
        public readonly ?Alerts $alerts = null,
        public readonly array $tiles = [],
    ) {}

    public function isEmpty(): bool
    {
        return !isset($this->tables[0]) || $this->tables[0]->rows === [];
    }
}
