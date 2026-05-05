# MessengerStatsCollector — Commande configurable — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendre la commande (et le timeout) de `MessengerStatsCollector` configurables via le bundle, avec `null` comme valeur par défaut (comportement actuel).

**Architecture:** Deux tâches indépendantes : (1) mettre à jour le collector lui-même via TDD, (2) mettre à jour la config du bundle et l'enregistrement du service via TDD.

**Tech Stack:** PHP 8.1+, Symfony Bundle, PHPUnit

---

## Fichiers concernés

| Action   | Fichier                                                          | Rôle                                         |
|----------|------------------------------------------------------------------|----------------------------------------------|
| Modify   | `src/Collector/Components/MessengerStatsCollector.php`           | Ajouter `command` et `timeout` en constructeur, bifurquer dans `collect()` |
| Create   | `tests/Collector/Components/MessengerStatsCollectorTest.php`     | Tests unitaires du collector                 |
| Modify   | `src/JmonitorBundle.php` (lignes 149–152)                        | Transformer `booleanNode('messenger')` en `arrayNode` |
| Modify   | `config/services.php` (lignes 239–245)                           | Passer `command` et `timeout` au service     |
| Modify   | `tests/JmonitorBundleTest.php`                                   | Tests d'intégration bundle pour messenger    |

---

## Task 1 : MessengerStatsCollector — TDD

### Fichiers
- Create: `tests/Collector/Components/MessengerStatsCollectorTest.php`
- Modify: `src/Collector/Components/MessengerStatsCollector.php`

- [ ] **Étape 1 : Écrire les tests**

Créer `tests/Collector/Components/MessengerStatsCollectorTest.php` avec ce contenu exact :

```php
<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
use PHPUnit\Framework\TestCase;

class MessengerStatsCollectorTest extends TestCase
{
    public function testCollectWithDefaultCommandUsesRunPhpProcess(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runPhpProcess')
            ->with(['bin/console', 'messenger:stats', '--format=json'], 3)
            ->willReturn(['exit_code' => 0, 'output' => '[]', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        $result = $collector->collect();

        static::assertSame([], $result);
    }

    public function testCollectWithCustomCommandUsesRunProcess(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->expects($this->once())
            ->method('runProcess')
            ->with('/usr/bin/php bin/console messenger:stats --format=json', 5)
            ->willReturn(['exit_code' => 0, 'output' => '[]', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner, '/usr/bin/php bin/console messenger:stats --format=json', 5);
        $result = $collector->collect();

        static::assertSame([], $result);
    }

    public function testCollectReturnsEmptyArrayOnNonZeroExitCode(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 1, 'output' => '', 'error_output' => 'error']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }

    public function testCollectReturnsEmptyArrayOnInvalidJson(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => 'not-json', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }

    public function testCollectReturnsDecodedArray(): void
    {
        $data = [
            ['name' => 'async', 'messages' => 42],
            ['name' => 'failed', 'messages' => 0],
        ];

        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => json_encode($data), 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame($data, $collector->collect());
    }

    public function testCollectReturnsEmptyArrayWhenJsonIsNotAnArray(): void
    {
        $commandRunner = $this->createMock(CommandRunner::class);
        $commandRunner
            ->method('runPhpProcess')
            ->willReturn(['exit_code' => 0, 'output' => '"string"', 'error_output' => '']);

        $collector = new MessengerStatsCollector($commandRunner);
        static::assertSame([], $collector->collect());
    }
}
```

- [ ] **Étape 2 : Vérifier que les tests échouent**

```
./vendor/bin/phpunit tests/Collector/Components/MessengerStatsCollectorTest.php
```

Attendu : `FAIL` — les nouveaux args du constructeur n'existent pas encore.

- [ ] **Étape 3 : Mettre à jour `MessengerStatsCollector.php`**

Remplacer le contenu complet de `src/Collector/Components/MessengerStatsCollector.php` par :

```php
<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Collector\Components;

use Jmonitor\JmonitorBundle\Collector\CommandRunner;

/**
 * Collects the messenger stats via the messenger:stats command
 *
 * To avoid "Mysql server has gone away" errors, the command is run in a separate process
 * (a "php bin/console messenger:stats" instead of using the Application::doRun() method)
 */
final class MessengerStatsCollector implements ComponentCollectorInterface
{
    private CommandRunner $commandRunner;
    private ?string $command;
    private int $timeout;

    public function __construct(CommandRunner $commandRunner, ?string $command = null, int $timeout = 3)
    {
        $this->commandRunner = $commandRunner;
        $this->command = $command;
        $this->timeout = $timeout;
    }

    public function collect(): array
    {
        if ($this->command !== null) {
            $run = $this->commandRunner->runProcess($this->command, $this->timeout);
        } else {
            $run = $this->commandRunner->runPhpProcess(['bin/console', 'messenger:stats', '--format=json'], $this->timeout);
        }

        if ($run['exit_code'] !== 0) {
            return [];
        }

        try {
            $decoded = json_decode($run['output'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
```

- [ ] **Étape 4 : Vérifier que les tests passent**

```
./vendor/bin/phpunit tests/Collector/Components/MessengerStatsCollectorTest.php
```

Attendu : `OK (6 tests, ...)`

- [ ] **Étape 5 : Vérifier que la suite complète passe toujours**

```
composer phpunit
```

Attendu : tous les tests verts.

- [ ] **Étape 6 : Commit**

```bash
git add src/Collector/Components/MessengerStatsCollector.php tests/Collector/Components/MessengerStatsCollectorTest.php
git commit -m "feat: make MessengerStatsCollector command and timeout configurable"
```

---

## Task 2 : Config bundle + enregistrement du service — TDD

### Fichiers
- Modify: `tests/JmonitorBundleTest.php`
- Modify: `src/JmonitorBundle.php`
- Modify: `config/services.php`

- [ ] **Étape 1 : Ajouter les tests dans `JmonitorBundleTest.php`**

Ajouter l'import en haut du fichier, après les imports existants :

```php
use Jmonitor\JmonitorBundle\Collector\Components\MessengerStatsCollector;
```

Puis ajouter ces quatre méthodes à la fin de la classe `JmonitorBundleTest` :

```php
public function testMessengerCollectorRegistersWithBooleanTrue(): void
{
    $container = $this->loadBundle([
        'project_api_key' => 'key',
        'collectors' => [
            'symfony' => [
                'messenger' => true,
            ],
        ],
    ]);

    static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
    $def = $container->getDefinition(MessengerStatsCollector::class);
    static::assertNull($def->getArgument(1), 'Default command should be null');
    static::assertSame(3, $def->getArgument(2), 'Default timeout should be 3');
}

public function testMessengerCollectorNotRegisteredWithBooleanFalse(): void
{
    $container = $this->loadBundle([
        'project_api_key' => 'key',
        'collectors' => [
            'symfony' => [
                'messenger' => false,
            ],
        ],
    ]);

    static::assertFalse($container->hasDefinition(MessengerStatsCollector::class));
}

public function testMessengerCollectorRegistersWithCustomCommandAndTimeout(): void
{
    $container = $this->loadBundle([
        'project_api_key' => 'key',
        'collectors' => [
            'symfony' => [
                'messenger' => [
                    'command' => '/usr/bin/php bin/console messenger:stats --format=json',
                    'timeout' => 10,
                ],
            ],
        ],
    ]);

    static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
    $def = $container->getDefinition(MessengerStatsCollector::class);
    static::assertSame('/usr/bin/php bin/console messenger:stats --format=json', $def->getArgument(1));
    static::assertSame(10, $def->getArgument(2));
}

public function testMessengerCollectorRegistersWithDefaultsWhenEnabledExplicitly(): void
{
    $container = $this->loadBundle([
        'project_api_key' => 'key',
        'collectors' => [
            'symfony' => [
                'messenger' => [
                    'enabled' => true,
                ],
            ],
        ],
    ]);

    static::assertTrue($container->hasDefinition(MessengerStatsCollector::class));
    $def = $container->getDefinition(MessengerStatsCollector::class);
    static::assertNull($def->getArgument(1));
    static::assertSame(3, $def->getArgument(2));
}
```

- [ ] **Étape 2 : Vérifier que les nouveaux tests échouent**

```
./vendor/bin/phpunit tests/JmonitorBundleTest.php --filter testMessenger
```

Attendu : `FAIL` — la config ne supporte pas encore les nouveaux paramètres.

- [ ] **Étape 3 : Mettre à jour `JmonitorBundle.php`**

Localiser et remplacer ces lignes (autour de la ligne 149) :

```php
                        ->booleanNode('messenger')
                            ->defaultValue(class_exists(Worker::class))
                            ->info('Collect Symfony Messenger metrics.')
                        ->end()
```

Par :

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

- [ ] **Étape 4 : Mettre à jour `config/services.php`**

Localiser et remplacer ces lignes (autour de la ligne 239) :

```php
        if ($symfonyConfig['messenger']) {
            $services->set(MessengerStatsCollector::class)
                ->args([
                    service(CommandRunner::class),
                ])
                ->tag('jmonitor.symfony.component_collector', ['index' => 'messenger'])
            ;
        }
```

Par :

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

- [ ] **Étape 5 : Vérifier que les nouveaux tests passent**

```
./vendor/bin/phpunit tests/JmonitorBundleTest.php --filter testMessenger
```

Attendu : `OK (4 tests, ...)`

- [ ] **Étape 6 : Vérifier que la suite complète passe**

```
composer phpunit
```

Attendu : tous les tests verts.

- [ ] **Étape 7 : Commit**

```bash
git add src/JmonitorBundle.php config/services.php tests/JmonitorBundleTest.php
git commit -m "feat: expose messenger command and timeout in bundle config"
```
