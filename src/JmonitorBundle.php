<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle;

use Jmonitor\JmonitorBundle\DependencyInjection\CachePoolPass;
use Jmonitor\JmonitorBundle\DependencyInjection\ComponentAvailabilityPass;
use Jmonitor\JmonitorBundle\DependencyInjection\EasyAdminTemplatesPass;
use Jmonitor\JmonitorBundle\DependencyInjection\HttpClientPass;
use Jmonitor\JmonitorBundle\DependencyInjection\StackConnectionsPass;
use Jmonitor\JmonitorBundle\DependencyInjection\StackRedisPass;
use Jmonitor\JmonitorBundle\DependencyInjection\StorageConnectionPass;
use Jmonitor\JmonitorBundle\EasyAdmin\Requirements;
use Jmonitor\JmonitorBundle\Stack\Detection\RedisDsns;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Flex\SymfonyBundle;
use Symfony\Component\Scheduler\Scheduler;
use Symfony\Component\Messenger\Worker;

final class JmonitorBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new StorageConnectionPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new ComponentAvailabilityPass());
        $container->addCompilerPass(new HttpClientPass());
        $container->addCompilerPass(new EasyAdminTemplatesPass());
        $container->addCompilerPass(new StackConnectionsPass());
        $container->addCompilerPass(new StackRedisPass(), PassConfig::TYPE_BEFORE_REMOVING);
        // After the CacheCollectorPass of debug mode: the profiler keeps tracing the pools from inside the wrappers.
        $container->addCompilerPass(new CachePoolPass(), PassConfig::TYPE_BEFORE_REMOVING, -1);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $pools = [];
        foreach ($builder->getExtensionConfig('framework') as $config) {
            foreach ($config['cache']['pools'] ?? [] as $name => $pool) {
                $pools[] = \is_string($name) ? $name : ($pool['name'] ?? null);
            }
        }

        $builder->setParameter(CachePoolPass::DECLARED_POOLS, array_values(array_unique(array_filter($pools, '\is_string'))));
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $environment = $builder->getParameter('kernel.environment');
        $projectDir = $builder->getParameter('kernel.project_dir');
        $permission = $config['dashboard']['permission'];

        if ($permission === '') {
            throw new \LogicException('The "jmonitor.dashboard.permission" option must not be empty.');
        }

        $bundles = $builder->getParameter('kernel.bundles');

        if ($permission !== null && !isset($bundles['SecurityBundle'])) {
            throw new \LogicException('The "jmonitor.dashboard.permission" option requires the SecurityBundle.');
        }

        $easyAdminEnabled = $config['easyadmin']['enabled'];
        $easyAdminPermission = null;

        if ($easyAdminEnabled) {
            Requirements::assertMet($bundles);

            $easyAdminPermission = $config['easyadmin']['permission'];

            if ($easyAdminPermission === '') {
                throw new \LogicException('The "jmonitor.easyadmin.permission" option must not be empty.');
            }

            if ($easyAdminPermission !== null && !isset($bundles['SecurityBundle'])) {
                throw new \LogicException('The "jmonitor.easyadmin.permission" option requires the SecurityBundle.');
            }
        }

        $recordingEnabled = $config['recording']['enabled'] ?? $environment !== 'test';
        $stackEnabled = $config['stack']['enabled'] && isset($bundles['FrameworkBundle']);

        $builder->setParameter('jmonitor.recording.enabled', $recordingEnabled);
        $builder->setParameter('jmonitor.recording.flush_interval', $config['recording']['flush_interval']);
        $builder->setParameter('jmonitor.recording.slow_threshold', $config['recording']['slow_threshold']);
        $builder->setParameter('jmonitor.recording.ignore_routes', $config['recording']['ignore_routes']);
        $builder->setParameter('jmonitor.recording.buffer_dir', $config['recording']['buffer_dir']);
        $builder->setParameter('jmonitor.recording.sql.enabled', $config['recording']['sql']['enabled']);
        $builder->setParameter('jmonitor.recording.sql.slow_threshold', $config['recording']['sql']['slow_threshold']);
        $builder->setParameter('jmonitor.recording.sql.n_plus_one_threshold', $config['recording']['sql']['n_plus_one_threshold']);
        $builder->setParameter('jmonitor.recording.exceptions', $config['recording']['exceptions']);
        $builder->setParameter('jmonitor.recording.users', $config['recording']['users']);
        $builder->setParameter('jmonitor.recording.http_client', $config['recording']['http_client']);
        $builder->setParameter('jmonitor.recording.cache', $config['recording']['cache']);
        $builder->setParameter('jmonitor.recording.commands.enabled', $config['recording']['commands']['enabled']);
        $builder->setParameter('jmonitor.recording.commands.ignore', $config['recording']['commands']['ignore']);
        $builder->setParameter('jmonitor.recording.messenger', $config['recording']['messenger']);
        $builder->setParameter('jmonitor.recording.scheduler', $config['recording']['scheduler']);
        $builder->setParameter('jmonitor.recording.app_id', substr(hash('xxh128', $projectDir . '|' . $environment), 0, 12));
        $builder->setParameter('jmonitor.storage.connection', $config['storage']['connection']);
        $builder->setParameter('jmonitor.storage.retention', $config['storage']['retention']);
        $builder->setParameter('jmonitor.dashboard.enabled', $config['dashboard']['enabled']);
        $builder->setParameter('jmonitor.dashboard.permission', $permission);
        $builder->setParameter('jmonitor.dashboard.permission_required', $permission !== null);
        $builder->setParameter('jmonitor.easyadmin.enabled', $easyAdminEnabled);
        $builder->setParameter('jmonitor.easyadmin.permission', $easyAdminPermission);
        $builder->setParameter('jmonitor.easyadmin.permission_required', $easyAdminPermission !== null);
        $builder->setParameter('jmonitor.logger', $config['logger'] ?: null);
        $builder->setParameter('jmonitor.stack.enabled', $stackEnabled);
        $builder->setParameter('jmonitor.stack.end_of_life', $config['stack']['end_of_life']);
        $builder->setParameter('jmonitor.stack.symfony.flex', $config['stack']['symfony']['flex']);
        $builder->setParameter('jmonitor.stack.symfony.messenger', $config['stack']['symfony']['messenger']);
        $builder->setParameter('jmonitor.stack.ignore_checks', $config['stack']['ignore_checks']);
        $builder->setParameter('jmonitor.stack.exclude_connections', $config['stack']['exclude_connections']);
        $builder->setParameter('jmonitor.stack.components', [
            'system' => ['enabled' => $config['stack']['system']],
            'php' => ['enabled' => $config['stack']['php']],
            'symfony' => ['enabled' => $config['stack']['symfony']['enabled']],
            'frankenphp' => ['enabled' => $config['stack']['frankenphp']['enabled']],
            'apache' => ['enabled' => $config['stack']['apache']['enabled']],
            'nginx' => ['enabled' => $config['stack']['nginx']['enabled']],
            'mysql' => ['enabled' => $config['stack']['mysql']['enabled']],
            'postgresql' => ['enabled' => $config['stack']['postgresql']['enabled']],
            'redis' => ['enabled' => $config['stack']['redis']['enabled']],
        ]);
        $builder->setParameter('jmonitor.stack.frankenphp.endpoint', $config['stack']['frankenphp']['endpoint']);
        $builder->setParameter('jmonitor.stack.apache.endpoint', $config['stack']['apache']['endpoint']);
        $builder->setParameter('jmonitor.stack.nginx.endpoint', $config['stack']['nginx']['endpoint']);
        $builder->setParameter('jmonitor.stack.mysql.connections', $config['stack']['mysql']['connections']);
        $builder->setParameter('jmonitor.stack.postgresql.connections', $config['stack']['postgresql']['connections']);
        $builder->setParameter('jmonitor.stack.postgresql.schema', $config['stack']['postgresql']['schema']);

        $container->import('../config/recording.php');
        $container->import('../config/dashboard.php');

        if ($easyAdminEnabled) {
            $container->import('../config/easyadmin.php');
        }

        if ($stackEnabled) {
            $container->import('../config/stack.php');

            $builder->getDefinition(RedisDsns::class)->replaceArgument(1, array_map(
                static fn(array $connection): mixed => $connection['dsn'],
                $config['stack']['redis']['connections'],
            ));
        }

        if (!$config['project_api_key']) {
            return;
        }

        $builder->setParameter('jmonitor.bundle_config', $config);

        $container->import('../config/services.php');
    }

    /**
     * https://symfony.com/doc/current/components/config/definition.html
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children() // jmonitor
                ->scalarNode('project_api_key')->defaultNull()->info('You can find it in your jmonitor.io settings. Leave empty to disable sending.')->end()
                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Optional. If null, Psr18ClientDiscovery will be used.')->end()
                ->scalarNode('logger')->defaultNull()->info('Name of a Psr\Log\LoggerInterface service.')->end()
                ->arrayNode('collectors')
                    ->addDefaultsIfNotSet() // permet de récup un tableau vide si pas de config
                    ->children()
                        ->arrayNode('mysql')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->cannotBeEmpty()->info('Doctrine DBAL connection service id used to query MySQL.')->end()
                                ->scalarNode('db_name')->cannotBeEmpty()->info('Db name of your project.')->end()
                                ->arrayNode('status')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('variables')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('slow_queries')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                        ->integerNode('limit')->defaultValue(5)->min(1)->max(10)->info('Maximum number of results to return (1–10).')->end()
                                        ->integerNode('min_exec_count')->defaultValue(1)->info('Minimum number of executions required to include a query.')->end()
                                        ->integerNode('min_avg_time_ms')->defaultValue(0)->info('Minimum average execution time in ms for a query to be included.')->end()
                                        ->scalarNode('order_by')
                                            ->defaultValue('avg')
                                            ->info('Column used for sorting. Allowed values: sum, avg, max.')
                                            ->validate()
                                                ->ifTrue(static fn($v) => !in_array($v, ['sum', 'avg', 'max'], true))
                                                ->thenInvalid('Invalid order_by value "%s". Allowed: sum, avg, max')
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                                ->arrayNode('information_schema')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('postgresql')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->cannotBeEmpty()->info('Doctrine DBAL connection service id used to query PostgreSQL.')->end()
                                ->scalarNode('schema')->defaultValue('public')->cannotBeEmpty()->info('Schema inspected by the database collector.')->end()
                                ->arrayNode('activity')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('settings')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('database')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                    ->end()
                                ->end()
                                ->arrayNode('slow_queries')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                        ->integerNode('limit')->defaultValue(5)->min(1)->max(10)->info('Maximum number of results to return (1–10).')->end()
                                        ->integerNode('min_exec_count')->defaultValue(1)->info('Minimum number of executions (calls) required to include a query.')->end()
                                        ->floatNode('min_avg_time_ms')->defaultValue(0)->info('Minimum mean execution time in ms for a query to be included.')->end()
                                        ->scalarNode('order_by')
                                            ->defaultValue('avg')
                                            ->info('Column used for sorting. Allowed values: avg, total, max.')
                                            ->validate()
                                                ->ifTrue(static fn($v) => !in_array($v, ['avg', 'total', 'max'], true))
                                                ->thenInvalid('Invalid order_by value "%s". Allowed: avg, total, max')
                                            ->end()
                                        ->end()
                                        ->booleanNode('auto_create_extension')->defaultFalse()->info('Automatically run "CREATE EXTENSION IF NOT EXISTS pg_stat_statements" when missing. Requires sufficient privileges.')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('apache')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('server_status_url')->defaultValue('http://localhost/server-status')->cannotBeEmpty()->info('Url of apache mod_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('nginx')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost/nginx_status')->cannotBeEmpty()->info('Url of nginx stub_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('system')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('adapter')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('redis')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('dsn')->defaultNull()->info('Redis DSN. See https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html')->end()
                                ->scalarNode('adapter')->defaultNull()->info('Redis or Predis service name')->end()
                            ->end()
                        ->end()
                        ->arrayNode('php')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultNull()->info('Url of exposed php metrics endpoint.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('caddy')
                            ->canBeEnabled()
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost:2019/metrics')->cannotBeEmpty()->info('Url of Caddy (or FrankenPHP) metrics endpoint.')->end()
                                ->booleanNode('frankenphp')->defaultFalse()->info('Enable FrankenPhp metrics collection')->end()
                            ->end()
                        ->end()
                        ->arrayNode('cloudflare')
                            ->canBeEnabled()
                            ->validate()
                                ->ifTrue(static fn(array $v): bool => $v['enabled'] && (
                                    trim((string) ($v['api_token'] ?? '')) === '' || trim((string) ($v['zone'] ?? '')) === ''
                                ))
                                ->thenInvalid('The "api_token" and "zone" options must not be empty to enable the cloudflare collector.')
                            ->end()
                            ->children()
                                ->scalarNode('api_token')->info('Cloudflare API token. See the permissions it needs: https://github.com/jmonitor/collector?tab=readme-ov-file#cloudflare')->end()
                                ->scalarNode('zone')->info('Zone name (example.com) or zone id.')->end()
                                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Defaults to the bundle "http_client", then to Psr18ClientDiscovery.')->end()
                                ->arrayNode('refresh')
                                    ->addDefaultsIfNotSet()
                                    ->info('Seconds between two refreshes of each block.')
                                    ->children()
                                        ->integerNode('traffic')->defaultValue(60)->min(1)->end()
                                        ->integerNode('today')->defaultValue(300)->min(1)->end()
                                        ->integerNode('web_analytics')->defaultValue(300)->min(1)->end()
                                        ->integerNode('storage')->defaultValue(900)->min(1)->info('R2 and Images.')->end()
                                        ->integerNode('zone')->defaultValue(3600)->min(1)->end()
                                    ->end()
                                ->end()
                                ->integerNode('lag')->defaultValue(180)->min(1)->info('Seconds: the data window ends at the start of the minute of now - lag.')->end()
                                ->integerNode('lookback')->defaultValue(5)->min(1)->max(60)->info('Minutes of per-minute traffic re-read on every refresh.')->end()
                                ->integerNode('window')->defaultValue(15)->min(1)->info('Minutes covered by the traffic breakdowns.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('symfony')
                            ->canBeEnabled()
                            ->children()
                                ->arrayNode('flex')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultValue(class_exists(SymfonyBundle::class))->end()
                                        ->scalarNode('command')->defaultValue('composer recipes -o')->info('Command to collect Flex recipes metrics')->end()
                                        ->integerNode('timeout')->defaultValue(5)->info('Timeout in seconds for the Flex recipes command')->end()
                                    ->end()
                                ->end()
                                ->booleanNode('scheduler')
                                    ->defaultValue(class_exists(Scheduler::class))
                                    ->info('Collect Symfony Scheduler metrics.')
                                ->end()
                                ->arrayNode('messenger')
                                    ->treatFalseLike(['enabled' => false])
                                    ->treatTrueLike(['enabled' => true])
                                    ->treatNullLike(['enabled' => true])
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->booleanNode('enabled')->defaultValue(class_exists(Worker::class))->end()
                                        ->scalarNode('command')->defaultNull()->info('Command to collect messenger stats. Default null uses "php bin/console messenger:stats --format=json".')->end()
                                        ->integerNode('timeout')->defaultValue(3)->info('Timeout in seconds for the messenger stats command.')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('recording')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultNull()->info('Record HTTP requests, console commands, messages and scheduled tasks. Defaults to true, except in the "test" environment.')->end()
                        ->integerNode('flush_interval')->defaultValue(10)->min(1)->max(3600)->info('Seconds between two flushes of the buffer to the storage.')->end()
                        ->integerNode('slow_threshold')->defaultValue(1000)->min(1)->info('Duration in ms from which a request counts as slow.')->end()
                        ->arrayNode('ignore_routes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Route names that are not recorded.')
                        ->end()
                        ->scalarNode('buffer_dir')->defaultNull()->info('Existing directory, writable by every user that runs PHP, in which the recording buffer gets its private subdirectory. Defaults to the system temporary directory, also used while the directory is missing: it is never created.')->end()
                        ->arrayNode('sql')
                            ->canBeDisabled()
                            ->info('Record the SQL queries run through Doctrine DBAL during a recorded request, command, message or task.')
                            ->children()
                                ->integerNode('slow_threshold')->defaultValue(100)->min(1)->info('Duration in ms from which a query counts as slow.')->end()
                                ->integerNode('n_plus_one_threshold')->defaultValue(10)->min(1)->info('Executions of the same SELECT within one request, command, message or task from which it counts as an N+1.')->end()
                            ->end()
                        ->end()
                        ->booleanNode('exceptions')->defaultTrue()->info('Count the exceptions behind the 5xx responses and those thrown by console commands, message handlers and scheduled tasks.')->end()
                        ->booleanNode('users')->defaultTrue()->info('Record the requests of each signed-in user. Requires the SecurityBundle.')->end()
                        ->booleanNode('http_client')->defaultTrue()->info('Record the outgoing calls made through the http_client service and its scoped clients, per host.')->end()
                        ->booleanNode('cache')->defaultTrue()->info('Count the reads, writes and deletes of cache.app and of the pools declared under framework.cache.pools.')->end()
                        ->arrayNode('commands')
                            ->canBeDisabled()
                            ->info('Record the console commands.')
                            ->children()
                                ->arrayNode('ignore')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([])
                                    ->info('Command names that are not recorded, on top of the default ones. fnmatch patterns allowed.')
                                ->end()
                            ->end()
                        ->end()
                        ->booleanNode('messenger')->defaultTrue()->info('Record the messages handled by the Messenger workers, and the messages sent. Requires Messenger.')->end()
                        ->booleanNode('scheduler')->defaultTrue()->info('Record the tasks of the Scheduler run by the Messenger workers. Requires the Scheduler.')->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('connection')->defaultValue('default')->info('Name of the Doctrine DBAL connection that stores the metrics.')->end()
                        ->integerNode('retention')->defaultValue(604800)->min(3600)->info('Seconds the metrics are kept.')->end()
                    ->end()
                ->end()
                ->arrayNode('dashboard')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->info('Serve the /jmonitor page.')->end()
                        ->scalarNode('permission')->defaultNull()->info('Role or attribute required to see the /jmonitor page. Requires the SecurityBundle.')->end()
                    ->end()
                ->end()
                ->arrayNode('easyadmin')
                    ->canBeEnabled()
                    ->beforeNormalization()
                        ->ifTrue(static fn(mixed $v): bool => \is_string($v) || (\is_array($v) && \is_string($v['enabled'] ?? null)))
                        ->then(static fn(): never => throw new \LogicException('The "jmonitor.easyadmin" option cannot be read from an environment variable: its routes are fixed when the container is built.'))
                    ->end()
                    ->info('Show the screens inside an EasyAdmin back office. Requires easycorp/easyadmin-bundle.')
                    ->children()
                        ->scalarNode('permission')->defaultNull()->info('Role or attribute required to see the screens in EasyAdmin. Requires the SecurityBundle.')->end()
                    ->end()
                ->end()
                ->arrayNode('stack')
                    ->canBeDisabled()
                    ->info('Read the server, PHP, Symfony, the web server and the databases live on the Stack screen.')
                    ->beforeNormalization()
                        ->ifTrue(static fn(mixed $v): bool => \is_string($v) || (\is_array($v) && \is_string($v['enabled'] ?? null)))
                        ->then(static fn(): never => throw new \LogicException('The "jmonitor.stack" option cannot be read from an environment variable: its services are registered when the container is built.'))
                    ->end()
                    ->validate()
                        ->always(static function (array $stack): array {
                            foreach (['mysql', 'postgresql'] as $component) {
                                foreach (array_keys($stack[$component]['connections']) as $name) {
                                    foreach ($stack['exclude_connections'] as $pattern) {
                                        if (fnmatch($pattern, (string) $name)) {
                                            throw new InvalidConfigurationException(\sprintf('The Doctrine connection "%s" is both listed under "jmonitor.stack.%s.connections" and excluded by "jmonitor.stack.exclude_connections".', $name, $component));
                                        }
                                    }
                                }
                            }

                            return $stack;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('end_of_life')->defaultTrue()->info('Look up support dates on endoflife.date, once a day per product.')->end()
                        ->arrayNode('ignore_checks')
                            ->info("Identifiers of the checks to silence, e.g. ['symfony.flex_recipes'].")
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->validate()
                                ->ifTrue(static fn(array $ids): bool => array_filter($ids, static fn(mixed $id): bool => !\is_string($id)) !== [])
                                ->thenInvalid('The "ignore_checks" option takes a list of strings.')
                            ->end()
                        ->end()
                        ->arrayNode('exclude_connections')
                            ->info('Doctrine connections the Stack screen never reads, as shell patterns (*, ?).')
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn(string $pattern): array => [$pattern])
                            ->end()
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                            ->validate()
                                ->ifTrue(static fn(array $patterns): bool => array_filter($patterns, static fn(mixed $pattern): bool => !\is_string($pattern)) !== [])
                                ->thenInvalid('The "exclude_connections" option takes a list of shell patterns.')
                            ->end()
                        ->end()
                        ->enumNode('system')->values(['auto', true, false])->defaultValue('auto')->end()
                        ->enumNode('php')->values(['auto', true, false])->defaultValue('auto')->end()
                        ->arrayNode('symfony')
                            ->addDefaultsIfNotSet()
                            ->beforeNormalization()
                                ->ifTrue(static fn(mixed $v): bool => \is_string($v) || \is_bool($v))
                                ->then(static fn(string|bool $v): array => ['enabled' => $v])
                            ->end()
                            ->children()
                                ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->end()
                                ->booleanNode('flex')->defaultTrue()->info('Run "composer recipes" once an hour to list outdated recipes.')->end()
                                ->booleanNode('messenger')->defaultTrue()->info('Count the messages waiting in each Messenger transport, with a command run in a separate process.')->end()
                            ->end()
                        ->end()
                        ->append(self::webServerNode('frankenphp', 'http://localhost:2019/metrics', 'URL of the Caddy metrics, served by its admin API.'))
                        ->append(self::webServerNode('apache', 'http://localhost/server-status', 'URL of the mod_status page, read in its machine-readable form (?auto).'))
                        ->append(self::webServerNode('nginx', 'http://localhost/nginx_status', 'URL of the stub_status page.'))
                        ->arrayNode('mysql')
                            ->info('Read the MySQL and MariaDB servers of the Doctrine connections.')
                            ->addDefaultsIfNotSet()
                            ->beforeNormalization()
                                ->ifTrue(static fn(mixed $v): bool => \is_string($v) || \is_bool($v))
                                ->then(static fn(string|bool $v): array => ['enabled' => $v])
                            ->end()
                            ->children()
                                ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->end()
                                ->append(self::connectionsNode())
                            ->end()
                        ->end()
                        ->arrayNode('postgresql')
                            ->info('Read the PostgreSQL servers of the Doctrine connections.')
                            ->addDefaultsIfNotSet()
                            ->beforeNormalization()
                                ->ifTrue(static fn(mixed $v): bool => \is_string($v) || \is_bool($v))
                                ->then(static fn(string|bool $v): array => ['enabled' => $v])
                            ->end()
                            ->children()
                                ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->end()
                                ->append(self::connectionsNode())
                                ->scalarNode('schema')
                                    ->info('Schema whose tables are counted and sized.')
                                    ->defaultValue('public')
                                    ->cannotBeEmpty()
                                    ->validate()
                                        ->ifTrue(static fn(mixed $schema): bool => !\is_string($schema))
                                        ->thenInvalid('The "schema" option takes the name of a schema.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('redis')
                            ->info('Read the Redis and Valkey servers of the application.')
                            ->addDefaultsIfNotSet()
                            ->beforeNormalization()
                                ->ifTrue(static fn(mixed $v): bool => \is_string($v) || \is_bool($v))
                                ->then(static fn(string|bool $v): array => ['enabled' => $v])
                            ->end()
                            ->children()
                                ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->end()
                                ->arrayNode('connections')
                                    ->info('DSN of the servers to read, each under an id; those of the cache, of the sessions or REDIS_URL when empty.')
                                    ->normalizeKeys(false)
                                    ->useAttributeAsKey('id')
                                    ->beforeNormalization()
                                        ->ifTrue(static fn(mixed $v): bool => \is_array($v) && $v !== [] && array_is_list($v))
                                        ->then(static fn(): never => throw new InvalidConfigurationException('Each Redis connection needs an id.'))
                                    ->end()
                                    ->arrayPrototype()
                                        ->beforeNormalization()
                                            ->ifString()
                                            ->then(static fn(string $dsn): array => ['dsn' => $dsn])
                                        ->end()
                                        ->children()
                                            ->scalarNode('dsn')->isRequired()->cannotBeEmpty()->end()
                                        ->end()
                                        ->validate()
                                            ->ifTrue(static fn(array $connection): bool => !\is_string($connection['dsn']))
                                            ->thenInvalid('The DSN of a Redis connection is a string.')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn($config): bool
                    => !empty($config['collectors']['redis']['dsn'])
                    && !empty($config['collectors']['redis']['adapter']))
                ->thenInvalid('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.')
            ->end()
        ;
    }

    private static function connectionsNode(): ArrayNodeDefinition
    {
        $connections = (new ArrayNodeDefinition('connections'))
            ->info('Names of the Doctrine connections to read; all of them when empty.')
            ->normalizeKeys(false)
            ->useAttributeAsKey('name');
        $connections->arrayPrototype()->end();
        $connections->beforeNormalization()
            ->ifTrue(static fn(mixed $v): bool => \is_array($v) && array_is_list($v) && array_filter($v, static fn(mixed $name): bool => !\is_string($name)) === [])
            ->then(static fn(array $names): array => array_fill_keys($names, []))
        ->end();

        return $connections;
    }

    private static function webServerNode(string $name, string $endpoint, string $info): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition($name))
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifTrue(static fn(mixed $v): bool => \is_string($v) || \is_bool($v))
                ->then(static fn(string|bool $v): array => ['enabled' => $v])
            ->end()
            ->children()
                ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->end()
                ->scalarNode('endpoint')->defaultValue($endpoint)->cannotBeEmpty()->info($info)->end()
            ->end();
    }
}
