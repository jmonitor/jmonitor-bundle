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

`composer phpunit` lifts the memory limit, which the full suite needs, and Composer's time limit on scripts; a direct call of `vendor/bin/phpunit` on the whole suite needs `-d memory_limit=-1`.

## EasyAdmin integration

The `jmonitor.easyadmin` option shows the screens under every EasyAdmin dashboard. EasyAdmin is only a development dependency (`require-dev` and `suggest`), so:

- Every class or service that names an EasyAdmin type lives in `src/EasyAdmin/` and `config/easyadmin.php`, and its template in `templates-easyadmin/` (registered as `@JmonitorEasyAdmin` by `EasyAdminTemplatesPass`, only when the option is on). Nothing elsewhere: `tests/EasyAdmin/IsolationTest.php` enforces it.
- A test that loads an EasyAdmin class, even through a container built with the option on, carries `#[Group('easyadmin')]`, and no test class extends or implements an EasyAdmin type. The CI runs the suite once without EasyAdmin, with `--exclude-group easyadmin`.
- `JmonitorController` is instantiated by EasyAdmin while the routes load: it only receives the `JmonitorPage` service and a service locator, and any new dependency goes through that locator, never into its constructor. The permission is checked by `Access` on the page, on its refresh and on the actions, at request time.

## Live stack

The engine that reads the server, PHP, Symfony, the web server and the databases on demand, behind a cache:

- Its code lives in `src/Stack/` and `config/stack.php`. It shares nothing with `src/Collector/` nor the `jmonitor.collectors` services of the worker.
- Nothing collects outside a call to `StackReaderInterface`: no event listener or scheduled task. Its only command, `jmonitor:stack:queues`, is only started by a read of the queues.
- The queues are only read by `StackReaderInterface::queues()`, never by the overview.
- A status page is read through `StatusPageFetcher`, a command run through `ProcessRunner`, never through the executor of the library.
- The main collector of a component carries the name of the component.
- What a collector of the library returns is filtered before it is kept.
- Every collector declares a lifetime (`Lifetime`) and a cost class (`CostClass`). A collector that starts a process or calls a service outside the stack over the network is `CostClass::Slow`.
- Detection touches no server: it only says which components exist and builds their collectors.
- A failure of a collector only read for the detail holds the other collectors of the detail, never the others.
- A shared value is the same for every instance of its component: its cache keys name the component, never the instance.
- `jmonitor.stack.cache` is not declared under `framework.cache.pools`, or its reads would be counted on the Cache screen.
- No test calls the network or `composer`: fake fetchers and process runners stand in for them, and the test kernel turns `stack.end_of_life`, `stack.symfony.flex` and `stack.symfony.messenger` off unless a test sets `stack` itself.
- No PHPUnit test starts the console in a process: the test kernel plays the command counting the queues (`queuesOutput`) and the web servers with their status pages (`fakeWebServer`). Only the kernel of `TestKernel::fromEnvironment()`, used by the end-to-end runs, starts the real command.
- Its screens live in `src/Stack/Screen/`: `StackScreen` and `ComponentScreen` get the reader, the checks and the summary through a service locator, read by `StackScreenParts`, so that the reader and the checks are only built when a Stack screen is displayed.
- An application screen costs the stack one cache read, the summary behind the badge of the navigation, and builds no reader.
- Only the display of the overview (`StackScreen::blocks()`) writes that summary (`StackSummary::remember()`).
- Every check of `StackChecks` has a stable identifier, which users put in `stack.ignore_checks` and the README lists, and a case at its threshold and just below in `StackChecksTest`.
- The percentages and byte sizes a check judges and a screen shows are formatted by `Figures`, so that both show the same value.
- A block of `src/Dashboard/View/` names no type of `src/Stack/`.
- A `Mark` always carries a word: a verdict or a severity is never told by colour alone.
- Where a Doctrine connection leads is read by `DoctrineConnections`, from its parameters, never by building the connection.
- A database collector queries through the `DbalLink` of its instance, which alone asks the container for the connection, and closes it at the end of the read when it opened it.
- A collector only read on the detail is `detailOnly`; a value shared by the connections of one server is `perServer`.
- A PHPUnit test only reaches the databases of `tests/compose.yaml`, and skips without `JMONITOR_TEST_MYSQL_URL`, or a closed port of the machine (`127.0.0.1:1`) to play a server that is down.
- A failure keeps its kind (`FailureKind`): an unreachable server, a missing prerequisite or another error are kept and judged apart.
- A failure is kept with the connection it happened on, a value with its server; while a remote connection cannot be reached, none of its collectors runs.

## CHANGELOG

Record changes under `## [Unreleased]`, creating the section if absent.

Never write a version number or a date in `CHANGELOG.md`, even when the task names a target version. Only the `release` skill assigns one, by renaming `## [Unreleased]` to `## [X.Y.Z] - DATE`.

## Documentation

`README.md` is the public documentation for this bundle. You can read it if you need context on how the bundle works or how it is configured. Keep it up to date whenever changes affect the end user (new options, changed behavior, etc.).
