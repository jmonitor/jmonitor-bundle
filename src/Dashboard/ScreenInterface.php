<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

interface ScreenInterface
{
    /** @return non-empty-string matching [a-z][a-z-]* */
    public function id(): string;

    public function label(): string;

    /** The sentence under the title, without the period nor the final full stop. */
    public function heading(): string;

    public function isAvailable(): bool;

    public function space(): Space;
}
