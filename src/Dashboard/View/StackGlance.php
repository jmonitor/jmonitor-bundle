<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class StackGlance
{
    /**
     * @param list<ComponentCard> $cards  one per instance read, each with the path of its detail
     * @param list<CheckLine>     $lines  the most severe first, each with the path of its detail
     * @param ?Notice             $notice shown above the cards, or in their place when there is none
     * @param ?string             $more   what was left out, as a sentence
     */
    public function __construct(
        public readonly array $cards = [],
        public readonly array $lines = [],
        public readonly ?Notice $notice = null,
        public readonly ?string $more = null,
    ) {}
}
