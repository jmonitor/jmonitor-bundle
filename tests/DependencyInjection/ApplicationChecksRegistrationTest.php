<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\DependencyInjection;

use Jmonitor\JmonitorBundle\Aggregation\Metric;
use Jmonitor\JmonitorBundle\Aggregation\Period;
use Jmonitor\JmonitorBundle\Dashboard\Check\ApplicationChecks;
use Jmonitor\JmonitorBundle\Dashboard\Check\Finding;
use Jmonitor\JmonitorBundle\JmonitorBundle;
use Jmonitor\JmonitorBundle\Storage\Read\MetricValue;
use Jmonitor\JmonitorBundle\Tests\Dashboard\FixedReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ApplicationChecksRegistrationTest extends TestCase
{
    private const NOW = 1800000000;
    private const METRICS = [Metric::REQUEST_STATUS_PREFIX . '5xx', Metric::REQUEST_SLOW, Metric::QUERY_SLOW, Metric::NPLUS1_CALLS];
    private const IDS = ['requests.server_errors', 'requests.slow', 'queries.slow', 'queries.repeated'];

    public function testTheFourChecksAreRegisteredInOrderWithTheirThresholds(): void
    {
        $checks = $this->checks(['recording' => ['slow_threshold' => 700, 'sql' => ['slow_threshold' => 150, 'n_plus_one_threshold' => 8]]]);
        $reader = new FixedReader(totals: array_fill_keys(self::METRICS, new MetricValue(1, 0.0, 0.0)));

        $raised = $checks->raised($reader, Period::OneHour, self::NOW);
        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, self::IDS);

        static::assertSame([self::METRICS], array_column($reader->totalRequests, 2));
        static::assertSame(['requests', 'queries'], $checks->screens());
        static::assertSame(self::IDS, array_map(static fn(Finding $finding): string => $finding->id, $findings));
        static::assertSame('Requests that took 700 ms or more in the last hour.', $findings[1]->sentence);
        static::assertSame('Queries that took 150 ms or more in the last hour.', $findings[2]->sentence);
        static::assertSame('Queries repeated 8 times or more inside one request in the last hour.', $findings[3]->sentence);
    }

    public function testTheChecksOfQueriesStayWhileSqlRecordingIsOff(): void
    {
        $checks = $this->checks(['recording' => ['sql' => ['enabled' => false]]]);
        $reader = new FixedReader();

        $checks->raised($reader, Period::OneHour, self::NOW);

        static::assertSame([self::METRICS], array_column($reader->totalRequests, 2));
    }

    /** @param array<string, mixed> $config */
    private function checks(array $config): ApplicationChecks
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', '/srv/app');
        $container->setParameter('kernel.bundles', []);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new JmonitorBundle();
        $bundle->getContainerExtension()?->load([$config], $container);
        $bundle->build($container);
        $container->getDefinition(ApplicationChecks::class)->setPublic(true);
        $container->compile();

        $checks = $container->get(ApplicationChecks::class);
        static::assertInstanceOf(ApplicationChecks::class, $checks);

        return $checks;
    }
}
