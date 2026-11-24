<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Reading;

use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;

final readonly class QueuesReading
{
    /**
     * @param list<array{name: string, count: ?int, reason: ?string}> $transports in the order of the command
     */
    private function __construct(
        public array $transports,
    ) {}

    public static function of(ComponentSnapshot $snapshot): self
    {
        $data = $snapshot->data('queues');
        $transports = [];

        foreach (is_array($data['transports'] ?? null) ? $data['transports'] : [] as $transport) {
            if (!is_array($transport) || !is_string($transport['name'] ?? null)) {
                continue;
            }

            $count = $transport['count'] ?? null;
            $reason = $transport['reason'] ?? null;
            $transports[] = [
                'name' => $transport['name'],
                'count' => is_int($count) && $count >= 0 ? $count : null,
                'reason' => is_string($reason) ? $reason : null,
            ];
        }

        return new self($transports);
    }
}
