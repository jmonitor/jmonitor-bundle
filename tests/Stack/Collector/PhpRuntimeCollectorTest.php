<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Stack\Collector;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorRunner;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSet;
use Jmonitor\JmonitorBundle\Stack\Collector\CollectorSpec;
use Jmonitor\JmonitorBundle\Stack\Collector\CostClass;
use Jmonitor\JmonitorBundle\Stack\Collector\Lifetime;
use Jmonitor\JmonitorBundle\Stack\Collector\PhpRuntimeCollector;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Detection\Instance;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CacheState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectBudget;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\DeltaCalculator;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Jmonitor\JmonitorBundle\Stack\Snapshot\SnapshotProvider;
use Jmonitor\JmonitorBundle\Stack\WebNode;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\Collector\FakeCollector;
use Jmonitor\JmonitorBundle\Tests\Stack\Fixtures\CountingCachePool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PhpRuntimeCollectorTest extends TestCase
{
    private const SECRET = 'SECRET-TOKEN-123';

    public function testTheRequestsOfTheFpmWorkersAreLeftOut(): void
    {
        $inner = new FakeCollector('php', self::fpmPayload());
        $collector = new PhpRuntimeCollector($inner);

        $data = (new CollectorRunner())->run($collector);

        self::assertSame('php', $collector->getName());
        self::assertSame('8.4.1', $data['version']);
        self::assertSame(
            ['pool' => 'www', 'accepted-conn' => 120, 'listen-queue' => 0, 'active-processes' => 1, 'total-processes' => 2, 'max-children-reached' => 0],
            $data['fpm'],
        );
        self::assertStringNotContainsString(self::SECRET, serialize($data));
        self::assertSame([1, 1, 1], [$inner->boots, $inner->collects, $inner->resets]);
    }

    public function testNoRequestOfTheFpmWorkersReachesTheCache(): void
    {
        $pool = new ArrayAdapter();
        $cache = new CountingCachePool($pool);
        $clock = new MockClock('@1791799200');
        $provider = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $clock, new WebNode('web-1'));
        $instance = self::instance(new PhpRuntimeCollector(new FakeCollector('php', self::fpmPayload())));

        $first = self::read($provider, $instance, $clock);
        $clock->sleep(11);
        $second = self::read($provider, $instance, $clock);

        self::assertSame(ResultState::Fresh, $first->result('php')?->state);
        self::assertNotNull($second->delta('php'), 'the rate reference was stored');
        $keys = array_keys($pool->getValues());
        self::assertContains('jmonitor.stack.result.%node.web-1.php.php', $keys);
        self::assertContains('jmonitor.stack.previous.%node.web-1.php.php', $keys);
        self::assertStringNotContainsString(self::SECRET, serialize($pool->getValues()));
        self::assertStringNotContainsString('alice', serialize($pool->getValues()));
    }

    public function testAWarningOfTheInnerCollectorDoesNotFailTheRead(): void
    {
        $inner = new class implements CollectorInterface {
            public function collect(): array
            {
                trigger_error('opcache_get_status(): Zend OPcache API is restricted by "restrict_api" configuration directive', \E_USER_WARNING);

                return ['version' => '8.4.1', 'opcache' => []];
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function getName(): string
            {
                return 'php';
            }
        };
        $cache = new CountingCachePool();
        $clock = new MockClock('@1791799200');
        $provider = new SnapshotProvider(new CollectorRunner(), new DeltaCalculator($cache, 10), $cache, $clock, new WebNode('web-1'));
        $escaped = [];

        set_error_handler(static function (int $type, string $message) use (&$escaped): bool {
            $escaped[] = $message;

            throw new \ErrorException($message, 0, $type);
        });

        try {
            $snapshot = self::read($provider, self::instance(new PhpRuntimeCollector($inner)), $clock);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $escaped);
        self::assertSame(ResultState::Fresh, $snapshot->result('php')?->state);
        self::assertSame(['version' => '8.4.1', 'opcache' => []], $snapshot->data('php'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fpmPayload(): array
    {
        return [
            'version' => '8.4.1',
            'fpm' => [
                'pool' => 'www',
                'accepted-conn' => 120,
                'listen-queue' => 0,
                'active-processes' => 1,
                'total-processes' => 2,
                'max-children-reached' => 0,
                'procs' => [
                    [
                        'pid' => 12,
                        'state' => 'Running',
                        'request-method' => 'GET',
                        'request-uri' => '/sleep.php?reset_token=' . self::SECRET,
                        'query-string' => 'reset_token=' . self::SECRET,
                        'user' => 'alice',
                        'script' => '/srv/app/public/sleep.php',
                    ],
                    ['pid' => 13, 'state' => 'Idle', 'request-uri' => '/', 'query-string' => '', 'user' => '-', 'script' => '-'],
                ],
            ],
        ];
    }

    private static function instance(PhpRuntimeCollector $collector): Instance
    {
        return Instance::found(Component::Php, new CollectorSet(Component::Php, [
            new CollectorSpec($collector, Lifetime::Dynamic, CostClass::Immediate, trackDelta: true),
        ]));
    }

    private static function read(SnapshotProvider $provider, Instance $instance, MockClock $clock): ComponentSnapshot
    {
        return $provider->read([$instance], CollectBudget::fragment($clock), CacheState::Working)[$instance->key()];
    }
}
