# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `/jmonitor` page: requests, response times and errors per route, stored in the application database.
- `jmonitor:setup` command: creates or upgrades the `jmonitor_aggregates` and `jmonitor_keys` tables.
- `/jmonitor` Queries screen: SQL queries run through Doctrine DBAL with their executions, time and slow count, and the queries repeated within one request (N+1).
- Requests screen: SQL time and number of queries per request.
- `recording.sql` option: turns SQL recording off or changes its slow query and N+1 thresholds.
- `/jmonitor` HTTP client screen: outgoing calls of the `http_client` service and scoped clients, per host.
- `/jmonitor` Cache screen: hits, misses, writes and deletes per cache pool.
- `/jmonitor` Exceptions screen: the exceptions behind 5xx responses, per class and per route.
- `/jmonitor` Users screen: requests, time and errors per signed-in user.
- Requests screen: `HTTP out` column with the time spent in outgoing HTTP calls.
- `recording.exceptions`, `recording.users`, `recording.http_client` and `recording.cache` options: each turns its recording off.
- The `/jmonitor` page says when its buffer directory is unusable.
- `/jmonitor` Commands screen: runs, duration, failures, peak memory and SQL time per console command.
- `/jmonitor` Messages screen: messages sent and handled per class, with duration, failures and retries.
- `/jmonitor` Scheduler screen: scheduled tasks with their trigger, next run, last run and failures.
- Exceptions screen: exceptions thrown by console commands and message handlers.
- `recording.commands`, `recording.messenger` and `recording.scheduler` options: each turns its recording off, and `recording.commands.ignore` lists commands that are not recorded.

### Changed
- Requires PHP 8.2.
- Requires `symfony/twig-bundle`.
- HTTP requests are recorded by default, except in the `test` environment; `recording.enabled: false` turns this off.
- Recording creates the `jmonitor_aggregates` and `jmonitor_keys` tables in the default Doctrine connection on its first flush, even when the routes are not imported.

## [2.3.0] - 2026-10-31

### Added
- `cloudflare` collector: monitors a Cloudflare zone (edge traffic, cache, security, Web Analytics, R2 and Images) through the Cloudflare APIs.

### Changed
- Requires `jmonitor/collector` `^2.5.1` (was `^2.2`).

## [2.2.1] - 2026-10-22

### Fixed
- A network timeout no longer disables the Flex recipes check or logs an error; the check is retried an hour later.
- A failed Flex recipes check is no longer reported as outdated recipes.

## [2.2.0] - 2026-09-15

### Added
- Scheduler tasks now report their arguments in a separate `arguments` field.

### Fixed
- A scheduled task with arguments (e.g. `#[AsPeriodicTask(..., arguments: ['--apply'])]`) no longer causes the whole `symfony` collector to be skipped: the command name is now correctly extracted from its arguments.
- A scheduled command that cannot be found now reports a `null` description instead of failing.
- An unexpected error in a Symfony component (flex, scheduler, messenger) now only affects that component: at boot it is disabled until worker restart, during collection it is logged and the other components are still collected.

## [2.1.0] - 2026-08-14

### Added
- The bundle's version is now reported alongside the collector's.

### Changed
- Requires `jmonitor/collector` `^2.2` (was `^2.0`).

## [2.0.1] - 2026-07-22

### Fixed
- The worker now resets the logger after each iteration (if it implements a `reset()` interface), preventing a memory leak with buffering handlers (e.g. `fingers_crossed`).

## [2.0.0] - 2026-07-02

### Changed
- **BREAKING**: Requires `jmonitor/collector` `^2.0` (was `^1.3`). This does not change the bundle's own PHP floor (already `^8.1`), but composer will now refuse to resolve `jmonitor/collector` 1.x.

## [1.4.0] - 2026-06-01

### Added
- PostgreSQL monitoring support.
- Option to choose which database connection the MySQL collector uses (`connection` option).

## [1.3.0] - 2026-05-08

### Added
- Configurable command and timeout for the Messenger stats collector via `jmonitor.symfony.messenger.command` and `jmonitor.symfony.messenger.timeout` (default: 3 seconds).
- Collectors that fail at startup (e.g. missing binary or misconfigured service) are now automatically disabled, preventing the worker from crashing.

### Changed
- Flex recipes are now refreshed at least once per day (previously cached for the entire lifetime of the worker process).
- Collector errors are now propagated instead of being silently ignored.

## [1.2.0] - 2026-05-04

### Added
- FrankenPHP support: when running under FrankenPHP (`PHP_SAPI === 'frankenphp'`), component collectors now spawn subprocesses via `frankenphp php-cli` instead of the standard PHP binary.
- Configurable timeout for the Flex recipes collector via `jmonitor.symfony.flex.timeout` (default: 5 seconds).

## [1.1.1] - 2026-03-27

### Changed
- MessengerStatsCollector now runs ```messenger:stats``` in a separate process to avoid "MySQL server has gone away" errors.

## [1.1.0] - 2026-03-20

### Added
- Minor improvements

## [1.0.0] - 2026-03-20

### Added
- Initial release
