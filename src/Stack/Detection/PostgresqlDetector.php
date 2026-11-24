<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlActivityCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlDatabaseCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSettingsCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\PostgresqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;

/**
 * The PostgreSQL database behind each Doctrine connection of the PostgreSQL driver.
 */
final readonly class PostgresqlDetector implements ComponentDetectorInterface
{
    /**
     * @param array<string, array<string, mixed>> $connections the names of the connections to read; empty: all of them
     * @param string                              $schema      the schema whose tables are counted, in each database
     */
    public function __construct(
        private DatabaseInstances $instances,
        private array $connections,
        private string $schema,
        private bool $endOfLife,
        private ?UrlFetcher $endOfLifeFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Postgresql;
    }

    public function detect(): Detection
    {
        return $this->instances->detect(Component::Postgresql, 'postgresql', 'PostgreSQL', $this->connections, $this->collectors(...), defaultPort: 5432);
    }

    /**
     * @return list<CollectorSpec>
     */
    private function collectors(ConnectionTarget $target, DbalLink $link): array
    {
        $specs = [
            new CollectorSpec(new PostgresqlActivityCollector($link), Lifetime::Dynamic, CostClass::Network, trackDelta: true),
            new CollectorSpec(new PostgresqlSettingsCollector($link), Lifetime::Static, CostClass::Network),
            new CollectorSpec(new PostgresqlDatabaseCollector($link, $this->schema), Lifetime::Minute, CostClass::Slow),
            new CollectorSpec(new PostgresqlSlowQueriesCollector($link), Lifetime::Minute, CostClass::Slow, detailOnly: true),
        ];

        if ($this->endOfLife) {
            $specs[] = new CollectorSpec(new EndOfLifeCollector('postgresql', $this->endOfLifeFetcher ?? new UrlFetcher(3)), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return $specs;
    }
}
