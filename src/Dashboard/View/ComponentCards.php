<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\View;

final class ComponentCards
{
    /**
     * @param list<ComponentCard> $cards
     * @param list<string>        $notDetected one sentence per component looked for and not found
     * @param ?string             $more        what was left out, as a sentence
     */
    public function __construct(
        public readonly array $cards,
        public readonly array $notDetected = [],
        public readonly ?string $group = null,
        public readonly ?string $more = null,
    ) {}
}
