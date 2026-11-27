# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `/jmonitor` page: requests, response times and errors per route, stored in the application database.
- `jmonitor:setup` command: creates or upgrades the `jmonitor_aggregates` and `jmonitor_keys` tables.
- `/jmonitor` Queries screen: SQL queries run through Doctrine DBAL with their executions, time and slow count, and the queries repeated within one request (N+1).
- Requests screen: where the average request goes, between your PHP code, SQL and outgoing HTTP calls.
- `recording.sql` option: turns SQL recording off or changes its slow query and N+1 thresholds.
- `/jmonitor` Cache & HTTP tab: outgoing calls of the `http_client` service and scoped clients, per host.
- `/jmonitor` Cache & HTTP tab: reads, hit rate, misses, writes and deletes per cache pool.
- `/jmonitor` Exceptions screen: the exceptions behind 5xx responses, per class and per route.
- `/jmonitor` Users screen: requests, time and errors per signed-in user.
- `recording.exceptions`, `recording.users`, `recording.http_client` and `recording.cache` options: each turns its recording off.
- The `/jmonitor` page says when its buffer directory is unusable.
- `/jmonitor` Background screen: runs, duration, failures and peak memory per console command.
- `/jmonitor` Background screen: messages sent and handled per class, with duration, failures and retries.
- `/jmonitor` Background screen: scheduled tasks with their schedule, next run, last run and failures.
- Exceptions screen: exceptions thrown by console commands and message handlers.
- `recording.commands`, `recording.messenger` and `recording.scheduler` options: each turns its recording off, and `recording.commands.ignore` lists commands that are not recorded.
- `JmonitorPage` service: shows the `/jmonitor` screens inside another page, with the routes, frame and access control of that page.
- Light theme for the screens shown inside another page.
- `easyadmin` option: shows the screens inside an EasyAdmin back office, under every dashboard, with its light or dark theme.
- `data-jm-theme="auto"`: the screens shown inside another page follow the Bootstrap colour mode of that page.
- `/jmonitor` Stack screen: the server, PHP and Symfony read live, with what to check and a detail per component.
- `stack` option: turns the live stack, one of its components or one of its two outgoing calls off, and silences a check with `ignore_checks`.
- `/jmonitor` Stack screen: FrankenPHP, Apache and Nginx read live from their status page, with what to check and a detail each.
- `/jmonitor` Background screen: the messages waiting in each Messenger transport, shown even without storage or recording.
- `stack` options `frankenphp`, `apache`, `nginx` and `symfony.messenger`: turn a web server or the queue count off, or set the address of a status page.
- `/jmonitor` Stack screen: the MySQL and MariaDB servers of the Doctrine connections read live, one card per server, with what to check and a detail per connection.
- `stack` options `mysql` and `exclude_connections`: choose the Doctrine connections the Stack screen reads.
- Stack screen: a server of the application that cannot be reached raises a `Critical` check.
- `/jmonitor` Stack screen: the PostgreSQL databases of the Doctrine connections read live, one card per connection, with what to check and a detail.
- `/jmonitor` Stack screen: the Redis and Valkey servers of the application read live, found in `stack.redis.connections`, the cache, the sessions or `REDIS_URL`.
- `stack` options `postgresql` and `redis`.
- `/jmonitor` navigation: four entries, Overview, Application, Background and Stack, with tabs under the header.
- Live indicator: says how old the figures are, and when a refresh failed.
- `/jmonitor` screens: a sentence that sums up the period, what is worth a look with why it matters and what to do, and a guide to read the screen.
- `/jmonitor` tabs: a badge when something is worth a look.
- `/jmonitor` tables: a bar on what costs the most and a Problems column.
- Background screen: where the average message and command go, between your PHP code, SQL and outgoing HTTP calls.
- Exceptions screen: one table, with under each class where it was thrown.
- `/jmonitor` opens on an Overview: what is worth a look, then a map of your application, from what comes in to what it calls.
- Navigation: a badge on Application and Background when something is worth a look.
- Stack screen: a sentence that sums it up, every point with its explanation in view, and what each component is for.
- Overview: fits on one screen, with a status pill, a short card per thing worth a look and a box per part of the application.

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
