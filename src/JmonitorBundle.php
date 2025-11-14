<?php

/*
 * This file is part of the jmonitor/jmonitor-bundle package.
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\JmonitorBundle;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Collector\Caddy\CaddyCollector;
use Jmonitor\Collector\Frankenphp\FrankenphpCollector;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Collector\Php\PhpCollector;
use Jmonitor\Collector\Redis\RedisCollector;
use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;
use Jmonitor\JmonitorBundle\Command\CollectorCommand;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class JmonitorBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        if (!$config['project_api_key']) {
            return;
        }

        $container->services()->set(Jmonitor::class)
            ->args([
                $config['project_api_key'],
                $config['http_client'] ? service($config['http_client']) : null,
            ])
        ;

        $collector = $container->services()->set(CollectorCommand::class)
            ->args([
                service(Jmonitor::class),
                $config['logger'] ? service($config['logger']) : null,
            ])
            ->tag('console.command')
        ;

        if ($config['schedule']) {
            $collector->tag('scheduler.task', [
                'frequency' => 15,
                'schedule' => $config['schedule'],
                'trigger' => 'every',
                'arguments' => null, // https://github.com/symfony/symfony/pull/61307
            ]);
        }


        if ($config['collectors']['mysql'] ?? false) {
            $container->services()->set(DoctrineAdapter::class)
                ->args([
                    service('doctrine.dbal.default_connection'),
                ])
            ;

            $container->services()->set(MysqlQueriesCountCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                    $config['collectors']['mysql']['db_name'],
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.queries_count'])
            ;

            $container->services()->set(MysqlStatusCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.status'])
            ;

            $container->services()->set(MysqlVariablesCollector::class)
                ->args([
                    service(DoctrineAdapter::class),
                ])
                ->tag('jmonitor.collector', ['name' => 'mysql.variables'])
            ;

            $container->services()->get(Jmonitor::class)
                ->call('addCollector', [service(MysqlQueriesCountCollector::class)])
                ->call('addCollector', [service(MysqlStatusCollector::class)])
                ->call('addCollector', [service(MysqlVariablesCollector::class)])
            ;
        }

        if ($config['collectors']['apache'] ?? false) {
            $container->services()->set(ApacheCollector::class)
                ->args([
                    $config['collectors']['apache']['server_status_url'],
                ])
                ->tag('jmonitor.collector', ['name' => 'apache'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(ApacheCollector::class)]);
        }

        if ($config['collectors']['system'] ?? false) {
            if ($config['collectors']['system']['adapter'] ?? null) {
                $container->services()->set($config['collectors']['system']['adapter']);
            }

            $container->services()->set(SystemCollector::class)
                ->args([
                    $config['collectors']['system']['adapter'] ? service($config['collectors']['system']['adapter']) : null,
                ])
                ->tag('jmonitor.collector', ['name' => 'system'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(SystemCollector::class)]);
        }

        if (($config['collectors']['php'] ?? false) !== false) {
            $container->services()->set(PhpCollector::class)
                ->args([
                    $config['collectors']['php']['endpoint'],
                ])
                ->tag('jmonitor.collector', ['name' => 'php'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(PhpCollector::class)]);
        }

        if ($config['collectors']['redis'] ?? false) {
            $container->services()->set(RedisCollector::class)
                ->args([
                    $config['collectors']['redis']['adapter'] ? service($config['collectors']['redis']['adapter']) : $config['collectors']['redis']['dsn'],
                ])
                ->tag('jmonitor.collector', ['name' => 'redis'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(RedisCollector::class)]);
        }

        if ($config['collectors']['caddy'] ?? false) {
            $container->services()->set('jmonitor.caddy.provider', PrometheusMetricsProvider::class)
                ->args([
                    $config['collectors']['caddy']['endpoint'],
                ])
            ;

            $container->services()->set(CaddyCollector::class)
                ->args([
                    service('jmonitor.caddy.provider'),
                ])
                ->tag('jmonitor.collector', ['name' => 'caddy'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(CaddyCollector::class)]);
        }

        if ($config['collectors']['frankenphp'] ?? false) {
            if (
                ($config['collectors']['caddy'] ?? false)
                && $config['collectors']['caddy']['endpoint'] === $config['collectors']['frankenphp']['endpoint']
            ) {
                // créer un alias de service de jmonitor.caddy.provider en jmonitor.frankenphp.provider
                $container->services()->alias('jmonitor.frankenphp.provider', 'jmonitor.caddy.provider');
            } else {
                $container->services()->set('jmonitor.frankenphp.provider', PrometheusMetricsProvider::class)
                    ->args([
                        $config['collectors']['frankenphp']['endpoint'],
                    ])
                ;
            }

            $container->services()->set(FrankenphpCollector::class)
                ->args([
                    service('jmonitor.frankenphp.provider'),
                ])
                ->tag('jmonitor.collector', ['name' => 'frankenphp'])
            ;

            $container->services()->get(Jmonitor::class)->call('addCollector', [service(FrankenphpCollector::class)]);
        }
    }

    /**
     * https://symfony.com/doc/current/components/config/definition.html
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line
        $definition->rootNode()
            ->children() // jmonitor
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('project_api_key')->defaultNull()->info('You can find it in your jmonitor.io settings.')->end()
                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Optional. If null, Psr18ClientDiscovery will be used.')->end()
                // ->scalarNode('cache')->cannotBeEmpty()->defaultValue('cache.app')->info('Name of a Psr\Cache\CacheItemPoolInterface service, default is "cache.app". Required.')->end()
                ->scalarNode('logger')->defaultNull()->info('Name of a Psr\Log\LoggerInterface service.')->end()
                ->scalarNode('schedule')->defaultNull()->info('Name of the schedule used to handle the recurring metrics collection. Must be set to enable use of symfony scheduler.')->end()
                ->arrayNode('collectors')
                    ->addDefaultsIfNotSet() // permet de récup un tableau vide si pas de config
                    // ->useAttributeAsKey()
                    ->children()
                        ->arrayNode('mysql')
                            ->children()
                                ->scalarNode('db_name')->cannotBeEmpty()->info('Db name of your project.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('apache')
                            ->children()
                                ->scalarNode('server_status_url')->defaultValue('https://localhost/server-status')->cannotBeEmpty()->info('Url of apache mod_status.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('system')
                            ->children()
                                ->scalarNode('adapter')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('redis')
                            ->children()
                                ->scalarNode('dsn')->defaultNull()->info('Redis DSN. See https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html')->end()
                                ->scalarNode('adapter')->defaultNull()->info('Redis or Predis service name')->end()
                            ->end()
                        ->end()
                        ->arrayNode('php')
                            ->children()
                                ->scalarNode('endpoint')->defaultNull()->info('Url of exposed php metrics endpoint.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('frankenphp')
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost:2019/metrics')->cannotBeEmpty()->info('Url of FrankenPHP metrics endpoint.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('caddy')
                            ->children()
                                ->scalarNode('endpoint')->defaultValue('http://localhost:2019/metrics')->cannotBeEmpty()->info('Url of Caddy metrics endpoint.')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(fn($config) => isset($config['enabled']) && $config['enabled'] === true && empty($config['project_api_key']))
                ->thenInvalid('The "project_api_key" must be set if "enabled" is true.')
            ->end()
            ->validate()
                ->ifTrue(function ($config): bool {
                    return
                        !empty($config['collectors']['redis']['dsn'])
                        && !empty($config['collectors']['redis']['adapter']);
                })
                ->thenInvalid('You cannot set both "dsn" and "adapter" for Redis collector. Please choose one.')
            ->end()
            ->validate()
            ->ifTrue(function ($config): bool {
                return
                    !empty($config['schedule']['redis']['dsn'])
                    && !class_exists('Symfony\Component\Scheduler\Scheduler');
            })
            ->thenInvalid('You need to install symfony/scheduler to use the "schedule" option.')
            ->end()
        ;
    }
}
