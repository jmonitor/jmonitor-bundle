# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Symfony Bundle** (`jmonitor/jmonitor-bundle`) that integrates metrics collection into Symfony applications and sends data to jmonitor.io. It provides a long-running worker command that periodically collects metrics from PHP, MySQL, Redis, web servers, and Symfony itself.

## Commands

```bash
# Run unit tests
composer phpunit

# Static analysis
composer phpstan

# Lint check / fix
composer lint:check
composer lint:fix
```

To run a single test file:
```bash
./vendor/bin/phpunit tests/path/to/TestFile.php
```

## Architecture

### Bundle Entry Point

`src/JmonitorBundle.php` — extends `AbstractBundle`. Reads configuration (project_api_key, enabled collectors, etc.) and conditionally registers services in the DI container. If `project_api_key` is missing, no services are registered.

### Service Registration

`config/services.php` — wires all services. Collectors are conditionally registered based on the bundle configuration. `SymfonyCollector` receives component collectors via `tagged_iterator`.

### Main Worker

`src/Command/CollectorCommand.php` — long-running Symfony console command (`jmonitor:collect`). Runs a loop that:
1. Calls configured collectors to gather metrics
2. POSTs data to the jmonitor.io API
3. Handles responses: 2xx (success), 4xx (fatal config error), 5xx (exponential backoff), 429 (rate limit)
4. Listens for SIGINT/SIGTERM/SIGQUIT for graceful shutdown
5. Respects time/memory limits via `src/Command/Dto/Limits.php`

### Collectors

- **`src/Collector/SymfonyCollector.php`** — collects Symfony-specific metadata (bundles, environment, version, directories) and delegates to component collectors
- **`src/Collector/Components/`** — component collectors implementing `ComponentCollectorInterface`:
  - `SchedulerCollector` — parses `debug:scheduler` output
  - `FlexRecipesCollector` — runs `composer recipes` to detect outdated Flex recipes
  - `MessengerStatsCollector` — runs `messenger:stats --format=json`
- All other collectors (System, Apache, Nginx, MySQL, PHP, Redis, Caddy) come from the upstream `jmonitor/collector` library

### Command Runner

`src/Collector/CommandRunner.php` — utility used by component collectors to either run Symfony console commands in-process or spawn external processes with timeout support.

### HTTP Endpoint

`src/Controller/JmonitorPhpController.php` — exposes `/jmonitor/php-metrics` for collecting web-context PHP metrics (OpCache, APCu). This complements the CLI command which runs in CLI context.

## Key Configuration

Bundle config goes in `config/packages/jmonitor.yaml`. The `project_api_key` (set via `JMONITOR_API_KEY` env var) is required for any services to load. Collectors are opt-in via the config file.
