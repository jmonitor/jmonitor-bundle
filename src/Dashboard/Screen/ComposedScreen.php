<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard\Screen;

use Jmonitor\JmonitorBundle\Dashboard\RecordedScreenInterface;
use Jmonitor\JmonitorBundle\Dashboard\Section\SectionInterface;
use Jmonitor\JmonitorBundle\Dashboard\Space;

final class ComposedScreen implements RecordedScreenInterface
{
    /** @var list<SectionInterface> */
    private readonly array $sections;

    /**
     * @param non-empty-string           $id
     * @param iterable<SectionInterface> $sections
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $heading,
        private readonly Space $space,
        iterable $sections,
    ) {
        $this->sections = array_values([...$sections]);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function heading(): string
    {
        return $this->heading;
    }

    public function isAvailable(): bool
    {
        return $this->sections() !== [];
    }

    public function space(): Space
    {
        return $this->space;
    }

    /** @return list<SectionInterface> the available ones */
    public function sections(): array
    {
        return array_values(array_filter($this->sections, static fn(SectionInterface $section): bool => $section->isAvailable()));
    }
}
