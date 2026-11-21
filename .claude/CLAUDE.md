# CLAUDE.md

## Project Overview

This is a **Symfony Bundle** (`jmonitor/jmonitor-bundle`) that integrates metrics collection into Symfony applications and sends data to jmonitor.io. It provides a long-running worker command that periodically collects metrics from PHP, MySQL, Redis, web servers, and Symfony itself. It also records the activity of the application (requests, queries, commands, messages…) in its database and shows it on the `/jmonitor` page and, when the `jmonitor.easyadmin` option is on, inside an EasyAdmin back office.

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

## EasyAdmin integration

The `jmonitor.easyadmin` option shows the screens under every EasyAdmin dashboard. EasyAdmin is only a development dependency (`require-dev` and `suggest`), so:

- Every class or service that names an EasyAdmin type lives in `src/EasyAdmin/` and `config/easyadmin.php`, and its template in `templates-easyadmin/` (registered as `@JmonitorEasyAdmin` by `EasyAdminTemplatesPass`, only when the option is on). Nothing elsewhere: `tests/EasyAdmin/IsolationTest.php` enforces it.
- A test that loads an EasyAdmin class, even through a container built with the option on, carries `#[Group('easyadmin')]`, and no test class extends or implements an EasyAdmin type. The CI runs the suite once without EasyAdmin, with `--exclude-group easyadmin`.
- `JmonitorController` is instantiated by EasyAdmin while the routes load: it only receives the `JmonitorPage` service and a service locator, and the permission is checked when a page is asked, by `Access`.

## CHANGELOG

Record changes under `## [Unreleased]`, creating the section if absent.

Never write a version number or a date in `CHANGELOG.md`, even when the task names a target version. Only the `release` skill assigns one, by renaming `## [Unreleased]` to `## [X.Y.Z] - DATE`.

## Documentation

`README.md` is the public documentation for this bundle. You can read it if you need context on how the bundle works or how it is configured. Keep it up to date whenever changes affect the end user (new options, changed behavior, etc.).
