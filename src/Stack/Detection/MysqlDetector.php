<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Detection;

use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\DbalLink;
use Jmonitor\JmonitorBundle\Stack\Collector\EndOfLifeCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSizesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlSlowQueriesCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlStatusCollector;
use Jmonitor\JmonitorBundle\Stack\Collector\MysqlVariablesCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\Utils\UrlFetcher;

/**
 * A MySQL or MariaDB server behind each Doctrine connection of the MySQL driver.
 */
final readonly class MysqlDetector implements ComponentDetectorInterface
{
    /**
     * @param array<string, array<string, mixed>> $connections the names of the connections to read; empty: all of them
     */
    public function __construct(
        private DatabaseInstances $instances,
        private array $connections,
        private bool $endOfLife,
        private ?UrlFetcher $endOfLifeFetcher = null,
    ) {}

    public function component(): Component
    {
        return Component::Mysql;
    }

    public function detect(): Detection
    {
        return $this->instances->detect(Component::Mysql, 'mysql', 'MySQL', $this->connections, $this->collectors(...), defaultPort: 3306);
    }

    /**
     * The product is only known once its version is read, so both support lookups are set.
     *
     * @return list<CollectorSpec>
     */
    private function collectors(ConnectionTarget $target, DbalLink $link): array
    {
        $specs = [
            new CollectorSpec(new MysqlStatusCollector($link), Lifetime::Dynamic, CostClass::Network, trackDelta: true, perServer: true),
            new CollectorSpec(new MysqlVariablesCollector($link), Lifetime::Static, CostClass::Network, perServer: true),
        ];

        if ($target->database !== null && $target->database !== '') {
            $specs[] = new CollectorSpec(new MysqlSizesCollector($link, $target->database), Lifetime::Static, CostClass::Slow, detailOnly: true);
            $specs[] = new CollectorSpec(new MysqlSlowQueriesCollector($link, $target->database), Lifetime::Minute, CostClass::Slow, detailOnly: true);
        }

        if ($this->endOfLife) {
            $fetcher = $this->endOfLifeFetcher ?? new UrlFetcher(3);
            $specs[] = new CollectorSpec(new EndOfLifeCollector('mysql', $fetcher), Lifetime::Day, CostClass::Slow, shared: true);
            $specs[] = new CollectorSpec(new EndOfLifeCollector('mariadb', $fetcher), Lifetime::Day, CostClass::Slow, shared: true);
        }

        return $specs;
    }
}
