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

## Dashboard

The `/jmonitor` page, its screens and their blocks:

- Every screen says its space (`ScreenInterface::space()`): `Application`, `Background` or `Stack`. The header lists the spaces that have an available screen, and a space with more than one available screen gets tabs, built by `PageRenderer`.
- The tabs and the title of a screen are in the refreshed part (`_screen.html.twig`), never in `page.html.twig`. A lead that comes first is the `h1` of the page, in place of the title.
- The reading order of a screen is set by `ScreenBuilder` alone: lead, checks, cards, tables, guide. A screen with nothing to show keeps its empty state, without lead nor guide.
- A composed screen (`ComposedScreen`) is made of sections (`SectionInterface`, in `src/Dashboard/Section/`). It is available while one of its sections is; an unavailable section does not exist for the page.
- A section never replaces the page. A cause that touches the whole page is told once, above the sections: no connection, a failed flush, a read that throws and, as soon as one section is empty, recording off or an unusable buffer directory.
- A read of a section that throws stops the next ones: a database that does not answer makes the page wait once.
- The own blocks of a section (`BlockSourceInterface`) are read even without storage; one that throws only breaks its section.
- One sort is chosen per page, named by its section (`sort=<section>.<column>`). The other sections keep their default sort, and an invalid sort chooses nothing.
- A composed screen is a `RecordedScreenInterface`: it never opens `/jmonitor` when nothing is recorded, and `PageRenderer` flushes the buffer before it reads.
- Every variable a block template reads is set by the bundle, by `PageRenderer` or by the `include()` of the block (`block`, `page_title`, and `section`, `null` outside a section), so that a Twig global of the host never stands in for one.
- A link of a block to a screen (a check, a guide) is only rendered when that screen is available (`screens`, set by `PageRenderer`); otherwise its text stays without the link.
- A check of the application (`src/Dashboard/Check/`) is raised by one entry of one of its metrics in the period, and only through `ApplicationChecks`. Its texts live in its class, the same wherever it shows.
- A check of the application has one screen, whose tab carries its badge.
- The totals behind the checks are read once per display, by the screen or else by `ScreenBuilder::raisedChecks()` for the badges, and never after a read of the storage failed in that display.
- A part of a split and a tag always carry their word: neither is told by colour alone.
- The base rule of the links is `.jm :where(a)`, so that a class set on a link keeps its colour.
- `public/jmonitor.js` has no test suite in the repository: a change to it is checked in a browser.

## Live stack

The engine that reads the server, PHP, Symfony, the web server, the databases and Redis on demand, behind a cache:

- Its code lives in `src/Stack/` and `config/stack.php`. It shares nothing with `src/Collector/` nor the `jmonitor.collectors` services of the worker.
- Nothing collects outside a call to `StackReaderInterface`: no event listener or scheduled task. Its only command, `jmonitor:stack:queues`, is only started by a read of the queues.
- The queues are only read by `StackReaderInterface::queues()`, never by the overview.
- A status page is read through `StatusPageFetcher`, a command run through `ProcessRunner`, never through the executor of the library.
- The main collector of a component carries the name of the component.
- What a collector of the library returns is filtered before it is kept.
- Every collector declares a lifetime (`Lifetime`) and a cost class (`CostClass`). A collector that starts a process or calls a service outside the stack over the network is `CostClass::Slow`.
- Detection touches no server: it only says which components exist and builds their collectors.
- A shared value is the same for every instance of its component: its cache keys name the component, never the instance.
- `jmonitor.stack.cache` is not declared under `framework.cache.pools`, or its reads would be counted on the Cache & HTTP screen.
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
- A PHPUnit test only reaches the servers of `tests/compose.yaml`, and skips without its variable (`JMONITOR_TEST_MYSQL_URL`, `JMONITOR_TEST_PGSQL_URL`, `JMONITOR_TEST_REDIS_URL`), or a closed port of the machine (`127.0.0.1:1`) to play a server that is down.
- A failure keeps its kind (`FailureKind`): an unreachable server, a missing prerequisite or another error are kept and judged apart.
- A failure is kept with the connection it happened on, a value with its server. While a remote connection cannot be reached, none of its collectors runs but a shared one; when the collector that cannot reach it is only read for the detail, only the collectors of the detail are held.
- A component says itself whether it is grouped by server (`Component::groupsByServer()`); the server of an instance only says where it leads.
- A connection that failed on a value of its server reads the server again itself, at the end of its pause and after a `Refresh`, and is healthy again only when its own read succeeds.
- A server that does not answer makes one connection wait per display: its failure is copied to the other connections of that server read in the same display.
- A request that PHP stops while it reads lifts its witnesses; the collector it was running fails, as a server that did not answer only when PHP stopped it for its time limit. Once the request is stopped, a cache operation that takes longer than a second leaves the cache alone.
- The time limit of the request is only raised by `SnapshotProvider`, around a remote collector, and put back at the end of the read.
- A Redis server is read through the `RedisLink` of its instance, which opens a connection of its own and only sends `INFO`.
- Of a Redis DSN, only the scheme, the host, the port and the socket come out (`RedisTarget`).
- No query text of a session is kept. A PostgreSQL slow query is only kept when it starts with a statement whose values are replaced, and its text goes through `SqlNormalizer`.
- A PostgreSQL role without privilege is told by the sessions of other roles it cannot read (`hidden_sessions`).

## CHANGELOG

Record changes under `## [Unreleased]`, creating the section if absent.

Never write a version number or a date in `CHANGELOG.md`, even when the task names a target version. Only the `release` skill assigns one, by renaming `## [Unreleased]` to `## [X.Y.Z] - DATE`.

## Documentation

`README.md` is the public documentation for this bundle. You can read it if you need context on how the bundle works or how it is configured. Keep it up to date whenever changes affect the end user (new options, changed behavior, etc.).
