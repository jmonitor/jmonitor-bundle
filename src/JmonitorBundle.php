<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Flex\SymfonyBundle;
use Symfony\Component\Scheduler\Scheduler;
use Symfony\Component\Messenger\Worker;

final class JmonitorBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
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
                ->scalarNode('project_api_key')->defaultNull()->info('You can find it in your jmonitor.io settings. Let empty to disable.')->end()
                ->scalarNode('http_client')->defaultNull()->info('Name of a Psr\Http\Client\ClientInterface service. Optional. If null, Psr18ClientDiscovery will be used.')->end()
                ->scalarNode('logger')->defaultNull()->info('Name of a Psr\Log\LoggerInterface service.')->end()
                ->arrayNode('collectors')
                    ->addDefaultsIfNotSet() // permet de récup un tableau vide si pas de config
                    ->children()
                        ->arrayNode('mysql')
                            ->canBeEnabled()
                            ->children()
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
                                    ->end()
                                ->end()
                                ->booleanNode('scheduler')
                                    ->defaultValue(class_exists(Scheduler::class))
                                    ->info('Collect Symfony Scheduler metrics.')
                                ->end()
                                ->booleanNode('messenger')
                                    ->defaultValue(class_exists(Worker::class))
                                    ->info('Collect Symfony Messenger metrics.')
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
}
