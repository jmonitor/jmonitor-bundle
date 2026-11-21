<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

interface ActionScreenInterface extends ScreenInterface
{
    /** @return array<string, string> button label by action name, each name matching [a-z][a-z-]* */
    public function actions(): array;

    public function run(string $action): void;
}
