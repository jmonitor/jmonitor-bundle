# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- FrankenPHP support: when running under FrankenPHP (`PHP_SAPI === 'frankenphp'`), component collectors now spawn subprocesses via `frankenphp php-cli` instead of the standard PHP binary.

## [1.1.1] - 2026-03-27

### Changed
- MessengerStatsCollector now runs ```messenger:stats``` in a separate process to avoid "MySQL server has gone away" errors.

## [1.1.0] - 2026-03-20

### Added
- Minor improvements

## [1.0.0] - 2026-03-20

### Added
- Initial release
