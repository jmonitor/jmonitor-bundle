# MessengerStatsCollector — Commande configurable

## Contexte

`MessengerStatsCollector` exécutait en dur `php bin/console messenger:stats --format=json`. Certains projets ont besoin de personnaliser cette commande (chemin différent, binaire PHP spécifique, etc.).

## Objectif

Permettre de configurer la commande exécutée par `MessengerStatsCollector` au niveau du bundle, avec `null` comme valeur par défaut (comportement actuel).

## Configuration (`JmonitorBundle.php`)

Le `booleanNode('messenger')` devient un `arrayNode` avec rétrocompatibilité totale :

```php
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
```

Les configs existantes `messenger: true` / `messenger: false` continuent de fonctionner via `treatTrueLike`/`treatFalseLike`.

## Collector (`MessengerStatsCollector.php`)

Le constructeur reçoit `?string $command` et `int $timeout` :

- `command = null` → `runPhpProcess(['bin/console', 'messenger:stats', '--format=json'], timeout)` (comportement actuel)
- `command = 'some command'` → `runProcess(command, timeout)` (commande utilisée telle quelle)

## Enregistrement du service (`services.php`)

```php
if ($symfonyConfig['messenger']['enabled']) {
    $services->set(MessengerStatsCollector::class)
        ->args([
            service(CommandRunner::class),
            $symfonyConfig['messenger']['command'],
            $symfonyConfig['messenger']['timeout'],
        ])
        ->tag('jmonitor.symfony.component_collector', ['index' => 'messenger'])
    ;
}
```

## Exemple de configuration utilisateur

```yaml
jmonitor:
  collectors:
    symfony:
      messenger: true          # rétrocompat, command: null, timeout: 3
      # ou
      messenger:
        command: '/usr/bin/php8.3 bin/console messenger:stats --format=json'
        timeout: 5
```

## Tests

- `MessengerStatsCollectorTest` : vérifier le comportement avec `command = null` (appel à `runPhpProcess`) et avec une commande fournie (appel à `runProcess`).
- Vérifier que `messenger: true` et `messenger: false` fonctionnent toujours (rétrocompat config).
