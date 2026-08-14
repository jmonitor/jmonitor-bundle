# CLAUDE.md

## Project Overview

This is a **Symfony Bundle** (`jmonitor/jmonitor-bundle`) that integrates metrics collection into Symfony applications and sends data to jmonitor.io. It provides a long-running worker command that periodically collects metrics from PHP, MySQL, Redis, web servers, and Symfony itself.

## Commands

Never prepend `cd [path]` before commands, and never use `git -C "[path]"` for git commands. The shell is already running at the project root — use commands directly as-is.

```bash
# Run unit tests
composer phpunit

# Static analysis
composer phpstan

# Lint check / fix
composer lint:check
composer lint:fix

# To run a single test file:
./vendor/bin/phpunit tests/path/to/TestFile.php
```

## CHANGELOG

Record changes under `## [Unreleased]`, creating the section if absent.

Never write a version number or a date in `CHANGELOG.md`, even when the task names a target version. Only the `release` skill assigns one, by renaming `## [Unreleased]` to `## [X.Y.Z] - DATE`.

## Documentation

`README.md` is the public documentation for this bundle. You can read it if you need context on how the bundle works or how it is configured. Keep it up to date whenever changes affect the end user (new options, changed behavior, etc.).
