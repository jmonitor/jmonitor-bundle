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
    private const METRICS = [
        Metric::REQUEST_STATUS_PREFIX . '5xx',
        Metric::REQUEST_SLOW,
        Metric::QUERY_SLOW,
        Metric::NPLUS1_CALLS,
        Metric::HTTP_FAILED,
        Metric::HTTP_STATUS_PREFIX . '4xx',
        Metric::HTTP_STATUS_PREFIX . '5xx',
        Metric::CACHE_HIT,
        Metric::CACHE_MISS,
        Metric::MESSAGE_FAILED,
        Metric::TASK_FAILED,
        Metric::COMMAND_FAILED,
    ];
    private const IDS = [
        'requests.server_errors',
        'requests.slow',
        'queries.slow',
        'queries.repeated',
        'http.failures',
        'http.errors',
        'cache.low_hit_rate',
        'messages.failed',
        'tasks.failed',
        'commands.failed',
    ];

    public function testTheTenChecksAreRegisteredInOrderWithTheirThresholds(): void
    {
        $checks = $this->checks(['recording' => ['slow_threshold' => 700, 'sql' => ['slow_threshold' => 150, 'n_plus_one_threshold' => 8]]]);
        $reader = new FixedReader(
            totals: array_fill_keys(self::METRICS, new MetricValue(1, 0.0, 0.0)),
            keyTotals: [Metric::CACHE_MISS => [md5('cache.app') => new MetricValue(20, 0.0, 0.0)]],
        );

        $raised = $checks->raised($reader, Period::OneHour, self::NOW);
        $findings = $checks->findings($reader, Period::OneHour, self::NOW, $raised, self::IDS);
        $sentences = array_column(array_map(static fn(Finding $finding): array => [$finding->id, $finding->sentence], $findings), 1, 0);

        static::assertSame([self::METRICS], array_column($reader->totalRequests, 2));
        static::assertSame([[Metric::CACHE_HIT, Metric::CACHE_MISS]], $reader->keyedRequests);
        static::assertSame(
            ['requests.server_errors', 'http.failures', 'messages.failed', 'tasks.failed', 'commands.failed', 'requests.slow', 'queries.slow', 'queries.repeated', 'http.errors', 'cache.low_hit_rate'],
            array_keys($sentences),
        );
        static::assertSame('Requests that took 700 ms or more in the last hour.', $sentences['requests.slow']);
        static::assertSame('Queries that took 150 ms or more in the last hour.', $sentences['queries.slow']);
        static::assertSame('Queries repeated 8 times or more inside one request in the last hour.', $sentences['queries.repeated']);
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
