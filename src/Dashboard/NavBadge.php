<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Dashboard;

final class NavBadge
{
    private function __construct(
        public readonly int $critical,
        public readonly int $warning,
        public readonly string $moment,
    ) {}

    /** @param string $moment when the checks were counted, as in "in the last 6 hours" */
    public static function of(int $critical, int $warning, string $moment): ?self
    {
        return $critical > 0 || $warning > 0 ? new self($critical, $warning, $moment) : null;
    }

    /** @return list<array{tone: 'bad'|'warn', count: int, words: string, mention: string}> the critical one first */
    public function pills(): array
    {
        $pills = [];

        if ($this->critical > 0) {
            $pills[] = $this->pill('bad', $this->critical, 'critical');
        }

        if ($this->warning > 0) {
            $pills[] = $this->pill('warn', $this->warning, $this->warning === 1 ? 'warning' : 'warnings');
        }

        return $pills;
    }

    /**
     * @param 'bad'|'warn' $tone
     *
     * @return array{tone: 'bad'|'warn', count: int, words: string, mention: string}
     */
    private function pill(string $tone, int $count, string $word): array
    {
        $words = $word . ' ' . $this->moment;

        return ['tone' => $tone, 'count' => $count, 'words' => $words, 'mention' => $count . ' ' . $words];
    }
}
