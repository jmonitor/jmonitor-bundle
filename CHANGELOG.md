# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
