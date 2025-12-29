# Development Guidelines for jmonitor-bundle

## Project Overview
`jmonitor-bundle` is a Symfony bundle that integrates the `jmonitor/collector` library to collect metrics from various stack components (PHP, MySQL, Apache, Redis, etc.) and send them to Jmonitor.io.

## Requirements
- **PHP**: ^8.1
- **Symfony**: ^6.4, ^7.0, or ^8.0

## Build & Configuration
The project uses Composer for dependency management. To set up the project locally:
1.  Install dependencies:
    ```bash
    composer install
    ```

## Testing
The project uses PHPUnit for testing.

### Running Tests
To run all tests:
```bash
composer run-script phpunit
# Or directly:
./vendor/bin/phpunit tests
```

### Adding New Tests
- New tests should be placed in the `tests/` directory.
- Test classes should extend `PHPUnit\Framework\TestCase`.
- Namespace should follow `Jmonitor\JmonitorBundle\Tests\...`.
- Mocking is commonly used for `KernelInterface` and other Symfony components.

### Simple Test Example
Below is a simple test case to demonstrate the process. You can create a file `tests/ExampleTest.php`:
```php
<?php

namespace Jmonitor\JmonitorBundle\Tests;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function testExample(): void
    {
        static::assertTrue(true);
    }
}
```
Run it with:
```bash
./vendor/bin/phpunit tests/ExampleTest.php
```

## Code Quality & Style
The project enforces code style and quality using the following tools:

### Linting (Mago)
To check the code style:
```bash
composer run-script lint:check
```
To automatically fix style issues:
```bash
composer run-script lint:fix
```
The configuration is defined in `mago.toml` and follows `PER-CS` rules.

### Static Analysis (PHPStan)
To run PHPStan:
```bash
composer run-script phpstan
```
Configuration is in `phpstan.neon` at level 5.

## Key Components
- `Jmonitor\JmonitorBundle\Command\CollectorCommand`: The main console command `jmonitor:collect`.
- `Jmonitor\JmonitorBundle\Collector\SymfonyCollector`: Collects Symfony-specific metrics.
- `Jmonitor\JmonitorBundle\Controller\JmonitorPhpController`: Controller to expose PHP metrics for web-context collection.

## Development Tips
- **Dry-run**: When testing the collection command, use `--dry-run` to avoid sending data to Jmonitor.io:
  ```bash
  php bin/console jmonitor:collect --dry-run -vvv
  ```
- **Verbose mode**: Use `-vvv` to see detailed debug logs, including the collected metrics.
- **PHP Metrics**: Remember that PHP metrics can differ between CLI and Web contexts. The `JmonitorPhpController` is used to collect metrics from the Web context.
