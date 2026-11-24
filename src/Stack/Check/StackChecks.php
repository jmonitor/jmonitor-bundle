<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Check;

use Jmonitor\JmonitorBundle\Stack\Collector\FailureKind;
use Jmonitor\JmonitorBundle\Stack\Component;
use Jmonitor\JmonitorBundle\Stack\Metric\Figures;
use Jmonitor\JmonitorBundle\Stack\Metric\SupportStatus;
use Jmonitor\JmonitorBundle\Stack\Read\InstanceState;
use Jmonitor\JmonitorBundle\Stack\Read\StackState;
use Jmonitor\JmonitorBundle\Stack\Reading\ApacheReading;
use Jmonitor\JmonitorBundle\Stack\Reading\FrankenPhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\MysqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\NginxReading;
use Jmonitor\JmonitorBundle\Stack\Reading\PhpReading;
use Jmonitor\JmonitorBundle\Stack\Reading\PostgresqlReading;
use Jmonitor\JmonitorBundle\Stack\Reading\ServerReading;
use Jmonitor\JmonitorBundle\Stack\Reading\SymfonyReading;
use Jmonitor\JmonitorBundle\Stack\Snapshot\CollectorResult;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ComponentSnapshot;
use Jmonitor\JmonitorBundle\Stack\Snapshot\ResultState;
use Psr\Clock\ClockInterface;

final class StackChecks
{
    private const REALPATH_CACHE_SIZE = 4_096_000;

    /**
     * @param list<string> $ignored ids of the findings to leave out
     */
    public function __construct(
        private readonly array $ignored,
        private readonly bool $debug,
        private readonly ClockInterface $clock,
    ) {}

    public function health(StackState $state): StackHealth
    {
        $now = $this->clock->now();
        $findings = [];
        $verdicts = [];

        foreach ($state->components as $component) {
            foreach ($component->instances as $instance) {
                $found = array_values(array_filter(
                    $this->instance($component->component, $instance, $now),
                    fn(Finding $finding): bool => !in_array($finding->id, $this->ignored, true),
                ));
                array_push($findings, ...$found);
                $verdicts[] = ['component' => $component->component, 'instance' => $instance->id, 'verdict' => self::verdict($component->component, $instance, $found)];
            }
        }

        return new StackHealth($findings, $verdicts);
    }

    /**
     * @param list<Finding> $findings
     */
    private static function verdict(Component $component, InstanceState $instance, array $findings): Verdict
    {
        if (($instance->error ?? $instance->snapshot?->error) !== null || $instance->snapshot?->has($component->value) !== true) {
            foreach ($findings as $finding) {
                if ($finding->id === $component->value . '.failed' && $finding->severity === Severity::Critical) {
                    return Verdict::Critical;
                }
            }

            return Verdict::Unknown;
        }

        return Verdict::worst(Verdict::Healthy, ...array_map(static fn(Finding $finding): Verdict => match ($finding->severity) {
            Severity::Critical => Verdict::Critical,
            Severity::Warning => Verdict::Warning,
            Severity::Info => Verdict::Healthy,
        }, $findings));
    }

    /**
     * @return list<Finding>
     */
    private function instance(Component $component, InstanceState $instance, \DateTimeImmutable $now): array
    {
        $snapshot = $instance->snapshot;
        $error = $instance->error ?? $snapshot?->error;

        if ($error !== null) {
            return [new Finding(
                $component->value . '.failed',
                $component,
                $instance->id,
                Severity::Warning,
                sprintf('%s is not available: %s', $component->label(), $error),
                'None of its checks can run until it is available. Fix the cause given, then refresh.',
            )];
        }

        if ($snapshot === null) {
            return [];
        }

        $raise = static fn(string $id, Severity $severity, string $sentence, string $explanation, string ...$judged): Finding => new Finding(
            $id,
            $component,
            $instance->id,
            $severity,
            $sentence,
            $explanation,
            self::age($snapshot, $now->getTimestamp(), ...$judged),
        );

        return [
            ...self::failures($component, $snapshot, $raise),
            ...match ($component) {
                Component::System => self::server(ServerReading::of($snapshot), $raise),
                Component::Php => $this->php(PhpReading::of($snapshot, $now), $snapshot, $raise),
                Component::Symfony => self::symfony(SymfonyReading::of($snapshot, $now), $raise),
                Component::FrankenPhp => WebServerChecks::frankenPhp(FrankenPhpReading::of($snapshot), $snapshot, $raise),
                Component::Apache => self::apache(ApacheReading::of($snapshot, $now), $raise),
                Component::Nginx => self::nginx(NginxReading::of($snapshot, $now), $snapshot, $raise),
                Component::Mysql => self::mysql(MysqlReading::of($snapshot, $now), $snapshot, $raise),
                Component::Postgresql => self::postgresql(PostgresqlReading::of($snapshot, $now), $snapshot, $raise),
                Component::Redis => [],
            },
        ];
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function failures(Component $component, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];

        foreach ($snapshot->results as $name => $result) {
            $mainSeverity = self::mainSeverity($component, $result);

            if ($name === $component->value && $result->state === ResultState::Stale && $result->error !== null) {
                $findings[] = $mainSeverity === Severity::Critical
                    ? self::cannotBeReached($component, $result->error, $raise, $name)
                    : $raise(
                        $name . '.failed',
                        $mainSeverity,
                        sprintf('%s could not be read again: %s', self::subject($component), $result->error),
                        'What is shown is the last value read, and the checks judge it until it can be read again. Fix the cause given, then refresh.',
                        $name,
                    );
            }

            if ($result->state !== ResultState::Failed || str_starts_with($name, 'eol.') || $snapshot->failedWithItsServer($name)) {
                continue;
            }

            $reason = $result->error ?? 'no reason given';
            $findings[] = match ($name) {
                $component->value => $mainSeverity === Severity::Critical ? self::cannotBeReached($component, $reason, $raise) : $raise(
                    $name . '.failed',
                    $mainSeverity,
                    sprintf('%s could not be read: %s', self::subject($component), $reason),
                    match ($component) {
                        Component::FrankenPhp => 'FrankenPHP serves this page, so it is running: only its metrics cannot be read, so its live figures and the checks that need them are not available. Follow the reason given, then refresh.',
                        Component::Apache, Component::Nginx => sprintf('%s serves this page, so it is running: only its status page cannot be read, so its live figures and the checks that need them are not available. Follow the reason given, then refresh.', $component->label()),
                        Component::System, Component::Php, Component::Symfony, Component::Mysql, Component::Postgresql, Component::Redis => 'None of its checks can run without its values. Fix the cause given, then refresh.',
                    },
                ),
                'symfony.flex' => $raise(
                    $name . '.failed',
                    Severity::Info,
                    sprintf('The Flex recipes could not be checked: %s', $reason),
                    'Outdated recipes cannot be told until this check works. It runs composer recipes in the project directory, so composer must be available to the web server.',
                ),
                default => $raise(
                    $name . '.failed',
                    Severity::Info,
                    sprintf('%s could not be read: %s', match ($name) {
                        'nginx.config' => 'The Nginx settings',
                        'frankenphp.version' => 'The FrankenPHP version',
                        'mysql.variables' => 'The MySQL settings',
                        'mysql.sizes' => 'The size of the database',
                        'mysql.slow_queries', 'postgresql.slow_queries' => 'The slowest queries',
                        'postgresql.settings' => 'The PostgreSQL settings',
                        'postgresql.database' => 'The tables of the database',
                        default => $name,
                    }, $reason),
                    'What it reads is left out of the checks until it can be read.',
                ),
            };
        }

        return $findings;
    }

    /**
     * A server the application reaches over the network: when the main collector cannot reach it, neither can the application.
     */
    private static function mainSeverity(Component $component, CollectorResult $result): Severity
    {
        return match (true) {
            $component->isWebServer() => Severity::Info,
            !$component->isNodeLocal() && $result->failure === FailureKind::Unreachable => Severity::Critical,
            default => Severity::Warning,
        };
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     */
    private static function cannotBeReached(Component $component, string $reason, \Closure $raise, string ...$judged): Finding
    {
        return $raise(
            $component->value . '.failed',
            Severity::Critical,
            sprintf('%s cannot be reached: %s', self::subject($component), $reason),
            'The application reaches this server through the same connection: when it cannot be reached from here, the application cannot use it either. Fix the cause given, then refresh.',
            ...$judged,
        );
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function server(ServerReading $server, \Closure $raise): array
    {
        $findings = [];

        if ($server->memoryPercent !== null && $server->memoryPercent >= 85) {
            $findings[] = $raise(
                'system.memory',
                $server->memoryPercent >= 95 ? Severity::Critical : Severity::Warning,
                sprintf('Memory is %s used', Figures::percent($server->memoryPercent)),
                'When memory runs out, the system swaps or stops processes, and requests slow down or fail. Find what uses it, or give the server more memory.',
                'system',
            );
        }

        if ($server->diskPercent !== null && $server->diskPercent >= 85) {
            $findings[] = $raise(
                'system.disk',
                $server->diskPercent >= 95 ? Severity::Critical : Severity::Warning,
                sprintf('Disk is %s full', Figures::percent($server->diskPercent)),
                'When the disk is full, writes fail: logs, cache, sessions and uploads. Free some space, such as old logs and backups, or grow the disk.',
                'system',
            );
        }

        if ($server->loadPercent !== null && $server->loadPercent >= 100) {
            $findings[] = $raise(
                'system.load',
                match (true) {
                    $server->container => Severity::Info,
                    $server->loadPercent >= 200 => Severity::Critical,
                    default => Severity::Warning,
                },
                sprintf(
                    'The load over 1 minute is %s of %s',
                    Figures::percent($server->loadPercent),
                    $server->cores === null ? 'the CPU capacity' : sprintf('the %d CPU %s', $server->cores, $server->cores === 1 ? 'core' : 'cores'),
                ),
                $server->container
                    ? 'In a container, the load is the one of the host node: other containers add to it, so it may not come from this application. Look at the host if requests are slow.'
                    : 'Above 100%, processes wait for a CPU and every request takes longer. Find what keeps the CPU busy, or add cores.',
                'system',
            );
        }

        return $findings;
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private function php(PhpReading $php, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        $findings = [];
        $opcacheOff = $this->debug ? Severity::Info : Severity::Warning;

        if ($php->opcacheAbsent) {
            $findings[] = $raise(
                'php.opcache_disabled',
                $opcacheOff,
                'OPcache is not running, or its status cannot be read',
                'Without OPcache, PHP compiles every script on every request, which makes the application several times slower. Load the extension and set opcache.enable=1; if it already runs, opcache.restrict_api keeps its status from being read here.',
                'php',
            );
        } elseif ($php->opcacheRunning === false) {
            $findings[] = $raise(
                'php.opcache_disabled',
                $opcacheOff,
                'OPcache is not running',
                'Without OPcache, PHP compiles every script on every request, which makes the application several times slower. Set opcache.enable=1.',
                'php',
            );
        }

        if ($php->opcacheEnabled && $php->opcacheFull) {
            $findings[] = $raise(
                'php.opcache_memory',
                Severity::Critical,
                'OPcache is full',
                'OPcache caches no new script once full, and PHP compiles those on every request. Raise opcache.memory_consumption, or opcache.max_accelerated_files when the application has more scripts than it allows.',
                'php',
            );
        } elseif ($php->opcacheEnabled && $php->opcacheMemoryPercent !== null && $php->opcacheMemoryPercent >= 90) {
            $findings[] = $raise(
                'php.opcache_memory',
                Severity::Warning,
                sprintf('OPcache memory is %s full', Figures::percent($php->opcacheMemoryPercent)),
                'Once full, OPcache caches no new script, and PHP compiles those on every request. Raise opcache.memory_consumption before it fills up.',
                'php',
            );
        }

        $hitRate = $php->opcacheHitRate;

        if (!$this->debug && $php->opcacheEnabled && $hitRate->value !== null && $hitRate->total >= PhpReading::OPCACHE_HIT_RATE_LOOKUPS && $hitRate->value < 85) {
            $findings[] = $raise(
                'php.opcache_hit_rate',
                Severity::Warning,
                sprintf('OPcache hit rate is %s %s', Figures::percent($hitRate->value), Figures::span($hitRate, $snapshot->delta('php')?->elapsed) ?? $hitRate->since),
                'Every miss compiles a script again. The cache may be too small for the application (opcache.memory_consumption, opcache.max_accelerated_files), or reset too often.',
                'php',
            );
        }

        if ($php->apcuEnabled && $php->apcuMemoryPercent !== null && $php->apcuMemoryPercent >= 90) {
            $findings[] = $raise(
                'php.apcu_memory',
                Severity::Warning,
                sprintf('APCu memory is %s full', Figures::percent($php->apcuMemoryPercent)),
                'Once full, APCu evicts entries before they expire, and the application computes them again. Raise apc.shm_size.',
                'php',
            );
        }

        if ($php->fpmQueue !== null && $php->fpmQueue >= 1) {
            $findings[] = $raise(
                'php.fpm_queue',
                Severity::Warning,
                $php->fpmQueue === 1 ? '1 request is waiting for a PHP-FPM worker' : sprintf('%d requests are waiting for a PHP-FPM worker', $php->fpmQueue),
                'Every PHP-FPM worker is busy, so requests wait before PHP even starts. Raise pm.max_children if the memory allows it, or find the slow requests that hold the workers.',
                'php',
            );
        }

        $elapsed = $snapshot->delta('php')?->elapsed;

        if ($php->fpmMaxChildrenReachedRecently !== null && $php->fpmMaxChildrenReachedRecently >= 1 && $elapsed !== null) {
            $findings[] = $raise(
                'php.fpm_max_children',
                Severity::Warning,
                sprintf('PHP-FPM reached pm.max_children %s %s', Figures::times((int) $php->fpmMaxChildrenReachedRecently), Figures::lastSeconds($elapsed)),
                'Requests had to wait for a free worker. Raise pm.max_children if the memory allows it, or find the slow requests that hold the workers.',
                'php',
            );
        } elseif ($php->fpmMaxChildrenReached !== null && $php->fpmMaxChildrenReached >= 1) {
            $findings[] = $raise(
                'php.fpm_max_children',
                Severity::Info,
                sprintf('PHP-FPM reached pm.max_children %s %s', Figures::times($php->fpmMaxChildrenReached), PhpReading::SINCE_FPM_START),
                'At some point, requests had to wait for a free worker. If it happens again, raise pm.max_children, as far as the memory allows it.',
                'php',
            );
        }

        if ($php->displayErrors === true && !$this->debug) {
            $findings[] = $raise(
                'php.display_errors',
                Severity::Warning,
                'display_errors is on',
                'Errors shown in the responses reveal file paths, queries and code to visitors. Set display_errors=Off in production and keep the errors in the logs.',
                'php',
            );
        }

        if ($php->realpathCacheSize !== null && $php->realpathCacheSize < self::REALPATH_CACHE_SIZE) {
            $findings[] = $raise(
                'php.realpath_cache_size',
                Severity::Info,
                sprintf('realpath_cache_size is %s', Figures::bytes($php->realpathCacheSize)),
                'When this cache is too small for the files of a Symfony application, PHP resolves the same paths again on every request. Set realpath_cache_size=4096K.',
                'php',
            );
        }

        array_push($findings, ...self::support('php', 'php', 'PHP', $php->version, $php->support, $raise, 'php'));

        return $findings;
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function symfony(SymfonyReading $symfony, \Closure $raise): array
    {
        $findings = self::support('symfony', 'symfony', 'Symfony', $symfony->version, $symfony->support, $raise, 'symfony');

        if ($symfony->environment === 'prod' && $symfony->debug === true) {
            $findings[] = $raise(
                'symfony.debug_in_prod',
                Severity::Critical,
                'Debug mode is on in the prod environment',
                'Debug mode slows down every request and can show errors, configuration and secrets to visitors. Set APP_DEBUG=0 in production.',
                'symfony',
            );
        }

        $outdated = count($symfony->outdatedRecipes);

        if ($outdated > 0) {
            $findings[] = $raise(
                'symfony.flex_recipes',
                Severity::Info,
                $outdated === 1 ? '1 Flex recipe is outdated' : sprintf('%d Flex recipes are outdated', $outdated),
                'Recipes carry the configuration that newer versions of the packages expect. Run composer recipes to list the changes, and composer recipes:update to apply them.',
                'symfony.flex',
            );
        }

        return $findings;
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function apache(ApacheReading $apache, \Closure $raise): array
    {
        return [
            ...WebServerChecks::apache($apache, $raise),
            ...self::support('apache', 'apache-http-server', 'Apache', $apache->version, $apache->support, $raise, 'apache'),
        ];
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function nginx(NginxReading $nginx, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        return [
            ...WebServerChecks::nginx($nginx, $snapshot, $raise),
            ...self::support('nginx', 'nginx', 'Nginx', $nginx->version, $nginx->support, $raise, 'nginx.config'),
        ];
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function mysql(MysqlReading $mysql, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        return [
            ...MysqlChecks::of($mysql, $snapshot, $raise),
            ...self::support('mysql', $mysql->product === 'MariaDB' ? 'mariadb' : 'mysql', $mysql->product ?? 'MySQL', $mysql->version, $mysql->support, $raise, 'mysql.variables'),
        ];
    }

    /**
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     *
     * @return list<Finding>
     */
    private static function postgresql(PostgresqlReading $postgresql, ComponentSnapshot $snapshot, \Closure $raise): array
    {
        return [
            ...PostgresqlChecks::of($postgresql, $snapshot, $raise),
            ...self::support('postgresql', 'postgresql', 'PostgreSQL', $postgresql->version, $postgresql->support, $raise, 'postgresql.settings'),
        ];
    }

    /**
     * @param string                                                         $id      the component the findings are named after
     * @param string                                                         $product its name on endoflife.date
     * @param \Closure(string, Severity, string, string, string...): Finding $raise
     * @param string                                                         $source  the collector the version comes from
     *
     * @return list<Finding>
     */
    private static function support(string $id, string $product, string $label, ?string $version, ?SupportStatus $support, \Closure $raise, string $source): array
    {
        return match ($support) {
            SupportStatus::Ended => [$raise(
                $id . '.end_of_life',
                Severity::Critical,
                sprintf('%s %s has reached its end of life', $label, $version),
                'Security issues found in this version are not fixed anymore. Upgrade to a supported version.',
                $source,
                'eol.' . $product,
            )],
            SupportStatus::SecurityOnly => [$raise(
                $id . '.security_only',
                Severity::Warning,
                sprintf('%s %s only receives security fixes', $label, $version),
                'Bugs are not fixed anymore, and security fixes stop at its end of life. Plan the upgrade to a version under active support.',
                $source,
                'eol.' . $product,
            )],
            default => [],
        };
    }

    private static function subject(Component $component): string
    {
        return match ($component) {
            Component::FrankenPhp => 'The FrankenPHP metrics',
            Component::Apache, Component::Nginx => sprintf('The %s status page', $component->label()),
            Component::System, Component::Php, Component::Symfony, Component::Mysql, Component::Postgresql, Component::Redis => $component->label(),
        };
    }

    /**
     * The age of the oldest outdated value among the results judged.
     */
    private static function age(ComponentSnapshot $snapshot, int $now, string ...$judged): ?int
    {
        $ages = [];

        foreach ($judged as $name) {
            $result = $snapshot->result($name);

            if ($result?->state === ResultState::Stale && $result->collectedAt !== null) {
                $ages[] = max(0, $now - $result->collectedAt);
            }
        }

        return $ages === [] ? null : max($ages);
    }
}
