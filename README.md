# Jmonitor Bundle

This bundle integrates the [*jmonitor/collector*](https://github.com/jmonitor/collector) PHP library into **Symfony**.    
It ships a **Symfony-specific collector** and a **console command** that runs the collectors in a **long-lived PHP worker process**.

[![Packagist Version](https://img.shields.io/packagist/v/jmonitor/jmonitor-bundle?style=flat-square)](https://packagist.org/packages/jmonitor/jmonitor-bundle)
[![Tests](https://img.shields.io/github/actions/workflow/status/jmonitor/jmonitor-bundle/tests.yml?branch=2.x&label=tests&style=flat-square)](https://github.com/jmonitor/jmonitor-bundle/actions)
[![License](https://img.shields.io/github/license/jmonitor/jmonitor-bundle?style=flat-square)](LICENSE)
[![Last Commit](https://img.shields.io/github/last-commit/jmonitor/jmonitor-bundle?style=flat-square)](https://github.com/jmonitor/jmonitor-bundle/commits)

<table>
  <tr>
    <th valign="top"><a href="https://github.com/jmonitor/jmonitor">jmonitor/jmonitor</a><br>&nbsp;</th>
    <th valign="top"><a href="https://github.com/jmonitor/collector">jmonitor/collector</a><br>&nbsp;</th>
    <th valign="top">jmonitor/jmonitor-bundle<br><img src="https://img.shields.io/badge/you_are_here-0969da?style=flat-square" alt="you are here"></th>
  </tr>
  <tr>
    <td>Self-hostable backend — not needed with the cloud version</td>
    <td>The collectors — install them in the project to monitor</td>
    <td>Symfony-specific integration of the collectors</td>
  </tr>
  <tr>
    <td colspan="3" align="center">
      <a href="https://github.com/jmonitor/easyadmin-bundle">jmonitor/easyadmin-bundle</a><br>
      Live monitoring pages inside EasyAdmin — no backend, no worker
    </td>
  </tr>
  <tr>
    <td colspan="3" align="center">
      <a href="https://jmonitor.io">https://jmonitor.io</a>
    </td>
  </tr>
</table>

<img src=".github/assets/hero-dashboard.png" alt="Jmonitor symfony dashboard" width="700">

## Supported components

| Category          | Components                                                                                                                                                                                                                                                                                                |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Runtime           | ![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white) ![FrankenPHP](https://img.shields.io/badge/FrankenPHP-444444?style=flat-square)                                                                                                                                  |
| Framework         | ![Symfony](https://img.shields.io/badge/Symfony-000000?style=flat-square&logo=symfony&logoColor=white)                                                                                                                                                                                                     |
| Web servers       | ![Apache](https://img.shields.io/badge/Apache-D22128?style=flat-square&logo=apache&logoColor=white) ![Nginx](https://img.shields.io/badge/Nginx-009639?style=flat-square&logo=nginx&logoColor=white) ![Caddy](https://img.shields.io/badge/Caddy-1F88C0?style=flat-square&logo=caddy&logoColor=white)        |
| Databases & cache | ![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-4169E1?style=flat-square&logo=postgresql&logoColor=white) ![Redis](https://img.shields.io/badge/Redis-FF4438?style=flat-square&logo=redis&logoColor=white) |
| CDN               | ![Cloudflare](https://img.shields.io/badge/Cloudflare-F38020?style=flat-square&logo=cloudflare&logoColor=white)                                                                                                                                                                                           |
| System            | ![Linux](https://img.shields.io/badge/CPU%20·%20RAM%20·%20Disk-FCC624?style=flat-square&logo=linux&logoColor=black)                                                                                                                                                                                        |

## Requirements
- [![PHP Version](https://img.shields.io/packagist/php-v/jmonitor/jmonitor-bundle?style=flat-square&label=PHP)](https://packagist.org/packages/jmonitor/jmonitor-bundle)
- [![Symfony Version](https://img.shields.io/packagist/dependency-v/jmonitor/jmonitor-bundle/symfony%2Fframework-bundle?style=flat-square&label=Symfony)](https://packagist.org/packages/jmonitor/jmonitor-bundle)

## Getting started

### 1. Create your project

Create a project on [jmonitor.io](https://jmonitor.io) — or on your self-hosted instance — and copy its API key.

### 2. Install the bundle

```bash
composer require jmonitor/jmonitor-bundle
```

### 3. Configure the bundle

Store your API key in an environment variable:

```dotenv
# .env
JMONITOR_API_KEY=
```

```dotenv
# .env.prod
JMONITOR_API_KEY=your_api_key
```

Then enable the collectors matching your stack:

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    # project_api_key can be empty to disable sending data to Jmonitor
    # useful for testing purposes in non-production environments
    project_api_key: '%env(JMONITOR_API_KEY)%'

when@prod:
    jmonitor:
        # Optional (recommended): use a specific logger service (Symfony's default is "logger").
        # See "Debugging" section below for more information.
        logger: 'logger'
    
        # Optional: provide a custom HTTP client service
        # http_client: 'http_client'
        
        # Enable the collectors you want to use (remove the unused ones).
        # Refer to the collector library for deeper collector-specific doc: https://github.com/jmonitor/collector
        collectors:
            # Cpu, Ram, Disk... Linux only.

            # You can use a RandomAdapter on Windows for testing purpose.
            # system:
            #     adapter: 'Jmonitor\\Collector\\System\\Adapter\\RandomAdapter'
            system: ~
            
            # Apache via mod_status module.
            # for more information, see https://github.com/jmonitor/collector?tab=readme-ov-file#apache
            apache:
                server_status_url: 'http://localhost/server-status'

            # Nginx via stub_status module.
            # for more information, see https://github.com/jmonitor/collector?tab=readme-ov-file#nginx
            nginx:
                endpoint: 'http://localhost/nginx_status'
            
            # MySQL - multiple sub-collectors available : status, variables, slow_queries, information_schema
            # all sub-collectors are enabled by default, disable some of them by setting them to false
            # Queries run through a Doctrine DBAL connection (default: doctrine.dbal.default_connection).
            # SlowQueries collector is configurable:
            # mysql:
            #     db_name: 'your_db_name'
            #     connection: 'doctrine.dbal.default_connection' # Doctrine DBAL connection service id
            #     slow_queries:
            #         limit: 5 # Maximum number of results to return (1-10)
            #         min_exec_count: 1 # Minimum number of executions required to include a query
            #         min_avg_time_ms: 0 # Minimum average execution time in ms for a query to be included.
            #         order_by: 'avg' # Allowed values: sum, avg, max
            mysql:
                db_name: 'your_db_name'

            # PostgreSQL - multiple sub-collectors available : activity, settings, database, slow_queries
            # all sub-collectors are enabled by default, disable some of them by setting them to false.
            # Queries run through a Doctrine DBAL connection (default: doctrine.dbal.default_connection).
            # The slow_queries sub-collector relies on the pg_stat_statements extension.
            # postgresql:
            #     connection: 'doctrine.dbal.default_connection' # Doctrine DBAL connection service id
            #     schema: 'public'                               # schema inspected by the database sub-collector
            #     slow_queries:
            #         limit: 5                  # Maximum number of results to return (1-10)
            #         min_exec_count: 1         # Minimum number of executions (calls) required to include a query
            #         min_avg_time_ms: 0        # Minimum mean execution time in ms for a query to be included
            #         order_by: 'avg'           # Allowed values: avg, total, max
            #         auto_create_extension: false # CREATE EXTENSION IF NOT EXISTS pg_stat_statements when missing
            postgresql:
                connection: 'doctrine.dbal.default_connection'

            # PHP : some ini keys, apcu, opcache, loaded extensions... 
            # /!\ See below for more informations about CLI vs Web-context metrics
            # CLI only:
            # php: ~
            # web metrics:
            php:
                endpoint: 'http://localhost/jmonitor/php-metrics'
            
            # symfony: some infos, loaded bundles, flex recipes, schedules...
            # Components are auto-detected based on installed packages.
            # You can disable a component by setting it to false, or configure it:
            # symfony:
            #     flex: false       # disable
            #     scheduler: false  # disable
            #     messenger: false  # disable
            #
            #     flex:
            #         command: "composer.phar recipes -o"  # default: "composer recipes -o"
            #         timeout: 10                          # default: 5 (seconds)
            #     Note: flex recipes are checked once per day and cached for the rest of the worker process lifetime.
            #     A failed check (e.g. network timeout) is retried after one hour; if composer cannot be run, the check is disabled until the worker restarts.
            #
            #     messenger:
            #         command: "php bin/console messenger:stats --format=json"  # default: auto
            #         timeout: 5                                                # default: 3 (seconds)
            symfony: ~

            # Redis metrics via INFO command
            # you can use either DSN or a service name (adapter).
            # redis:
            #     dsn: '%env(SOME_REDIS_DSN)%'
            #     adapter: 'some_redis_service_name'
            redis:
                
            # Metrics from Caddy / FrankenPHP
            # see https://caddyserver.com/docs/metrics and https://frankenphp.dev/docs/metrics/
            caddy:
                endpoint: 'http://localhost:2019/metrics'
                frankenphp: true # default is false

            # Cloudflare: what your server cannot see (cache, security, edge latency, Web Analytics, R2, Images)
            # Token permissions: https://github.com/jmonitor/collector?tab=readme-ov-file#cloudflare
            # This collector calls a remote API: the refresh intervals keep it within Cloudflare's rate limits,
            # lowering them is rarely useful.
            # Without any http_client, the collector uses a client with a 5-second timeout. Give the one you pass
            # a timeout too: an unanswered Cloudflare call would otherwise hold up the whole worker.
            # cloudflare:
            #     http_client: 'some_psr18_client' # default: the bundle http_client, then discovery
            #     refresh:                         # seconds
            #         traffic: 60
            #         today: 300
            #         web_analytics: 300
            #         storage: 900                 # r2 and images
            #         zone: 3600
            #     lag: 180                         # seconds: the data window ends at the start of the minute of now - lag
            #     lookback: 5                      # minutes of per-minute traffic re-read on every refresh (60 at most)
            #     window: 15                       # minutes covered by the traffic breakdowns
            cloudflare:
                api_token: '%env(CLOUDFLARE_API_TOKEN)%'
                zone: 'example.com' # zone name or zone id
```

### 4. Run the worker

The bundle ships a **console command that runs the collectors in a long-lived worker process**. This means you
**must not** collect metrics on every web request.

First, check your setup with a dry run — it collects the metrics and prints them without sending anything.
It is often easier to do this in the production environment, since configuring the bundle (or some collectors)
in development is not always possible.

```bash
php bin/console jmonitor:collect -vvv --dry-run
```

You can pass a collector name as an argument to run only that one, which helps to debug a specific integration:

```bash
php bin/console jmonitor:collect mysql -vvv --dry-run
```

Once everything looks right, run it for real:

```bash
php bin/console jmonitor:collect
```

### 5. Run it in production

Run the command under a process manager (Supervisor, systemd…) so it stays up and is restarted periodically.
Symfony Messenger's recommendations apply as-is:
https://symfony.com/doc/current/messenger.html#deploying-to-production

Some metrics are fairly static and remain cached for the lifetime of the process, so among other reasons
(memory…), it is **strongly recommended** to restart the worker regularly, at least once a day. Two options
are available for that:

- `--time-limit`: stop after the given number of seconds
- `--memory-limit`: stop when the process memory usage exceeds the given limit (e.g. `128M`)

```bash
php bin/console jmonitor:collect -vv --memory-limit=32M --time-limit=3600
```

## Application monitoring (preview)

The bundle can also record the HTTP requests your application serves, its console commands, the messages its Messenger workers handle and its scheduled tasks, with the SQL queries, outgoing HTTP calls, cache reads, writes and deletes, exceptions and signed-in users behind them, and show them on a `/jmonitor` page, over the last hour, 6 hours, 24 hours or 7 days:

- **Requests** (`/jmonitor/`): requests, response times (average, p95, max), SQL time and number of queries, time spent in outgoing HTTP calls (`HTTP out`), slow requests, 5xx errors and peak memory, per route.
- **Queries** (`/jmonitor/queries`): executions, total, average and maximum time and slow executions per SQL query, and the queries repeated within one request, command, message or task (N+1).
- **Commands** (`/jmonitor/commands`): runs, average and maximum duration, failures (non-zero exit code), peak memory, last run and SQL time, per console command.
- **Messages** (`/jmonitor/messages`): messages sent and handled, handling time (average, p95, max), failures, retries and SQL time, per message class.
- **Scheduler** (`/jmonitor/scheduler`): the scheduled tasks with their schedule, trigger, next run, last run, runs, average and maximum duration and failures.
- **HTTP client** (`/jmonitor/http-client`): calls, total, average and maximum time, 4xx and 5xx responses and network failures, per called host.
- **Cache** (`/jmonitor/cache`): hits, misses, hit rate, writes and deletes, per cache pool.
- **Exceptions** (`/jmonitor/exceptions`): count and last occurrence per exception class, and per class and route, command, message or task.
- **Users** (`/jmonitor/users`): requests, total and average time, slow requests and 5xx errors, per signed-in user.

A screen only appears when its component is installed and its recording option is on: HTTP client needs the `http_client` service of the framework, Users needs the SecurityBundle, Messages needs `symfony/messenger` and Scheduler needs `symfony/scheduler`, each enabled in the framework configuration.

It needs a Doctrine DBAL connection (MySQL/MariaDB, PostgreSQL or SQLite, typically through `doctrine/doctrine-bundle`) and the TwigBundle; without the TwigBundle the page answers 404. Nothing else to set up: the `jmonitor_aggregates` and `jmonitor_keys` tables are created automatically in the configured connection, upgraded automatically when a new version of the bundle changes them, and Doctrine migrations will not try to drop them. Tables created by an earlier development build of 3.x are dropped and recreated, losing the metrics they held. Your database receives batched writes, never one write per request or per query: web requests and Messenger workers write at most once per `flush_interval` per server, and a command writes when it ends, at most once per second for each user that runs PHP.

If the database user of your application is not allowed to create tables, run `php bin/console jmonitor:setup` at deployment with a user that is. It creates or upgrades the two tables, or tells you they are up to date.

Import the routes (the bundle assets are published by `assets:install`, which Symfony Flex runs automatically):

```yaml
# config/routes/jmonitor.yaml
jmonitor:
    resource: '@JmonitorBundle/config/routes.php'
    prefix: /jmonitor
```

If your routes carry a `host`, give this import the same `host`. If your `access_control` ends with a catch-all rule, add a rule that covers `/jmonitor` before it.

Defaults, all optional:

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    recording:
        enabled: ~            # default: true, except in the "test" environment
        flush_interval: 10    # seconds between two writes to the database (3600 maximum)
        slow_threshold: 1000  # ms above which a request counts as slow
        ignore_routes: []     # route names not recorded, fnmatch patterns allowed (e.g. 'app_health*')
        buffer_dir: ~         # where the buffer gets its private subdirectory, must exist and be writable by every user running PHP; default: the system temporary directory
        sql:
            enabled: true             # record the SQL queries run through Doctrine DBAL
            slow_threshold: 100       # ms from which a query counts as slow
            n_plus_one_threshold: 10  # executions of the same SELECT within one request, command, message or task that make an N+1
        exceptions: true      # count the exceptions behind 5xx responses and those thrown by commands, message handlers and scheduled tasks
        users: true           # record the requests of each signed-in user (needs the SecurityBundle)
        http_client: true     # record the outgoing calls of the http_client service and the scoped clients
        cache: true           # count the reads, writes and deletes of cache.app and of the pools you declare
        commands:
            enabled: true     # record the console commands
            ignore: []        # command names not recorded on top of the default ones, fnmatch patterns allowed (e.g. 'app:sync-*')
        messenger: true       # record the messages handled by the Messenger workers and the messages sent (needs Messenger)
        scheduler: true       # record the scheduled tasks run by the Messenger workers (needs the Scheduler)
    storage:
        connection: 'default' # name of the Doctrine DBAL connection
        retention: 604800     # seconds the data is kept (7 days, 3600 minimum)
    dashboard:
        enabled: true         # false: the /jmonitor page answers 404
        permission: ~         # role or voter attribute required to see the page
    easyadmin:
        enabled: false        # show the screens inside EasyAdmin
        permission: ~         # role or voter attribute required to see them there
```

`recording.enabled` can come from an environment variable, read at runtime, to switch recording off without a new deployment:

```yaml
jmonitor:
    recording:
        enabled: '%env(bool:JMONITOR_RECORDING)%'
```

### Access

- Without `dashboard.permission`, the page is open in debug mode and answers 404 otherwise.
- With `dashboard.permission`, for example `ROLE_ADMIN`, the page requires that permission in every environment. This option needs the SecurityBundle.
- The route must sit behind one of your firewalls: outside any firewall nobody is authenticated, so the page is denied to everyone.

### What is recorded

Requests: main requests only. Not recorded: any route whose name starts with `jmonitor_`, any JMonitor page shown by another host (the EasyAdmin pages among them), the web debug toolbar and profiler, sub-requests, and the routes matching `ignore_routes`. A request that matches no route is recorded as `(unmatched)`. Methods other than GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS are grouped under `OTHER`.

Commands: a command is recorded from its start, the boot of the kernel excluded, to its end, and fails when its exit code is not 0. Not recorded by default: `messenger:consume`, `messenger:failed:*`, `messenger:stats`, `jmonitor:*`, `cache:*`, `assets:*`, `importmap:*`, `asset-map:*`, `secrets:*`, `tailwind:*`, `sass:*`, `list`, `help`, `_complete`, `completion`, `debug:*`, `lint:*`, `server:*` and `doctrine:database:*`; `recording.commands.ignore` adds to this list. A command your build runs without a database is worth adding to it: otherwise its write fails when it ends and is logged as an error. A command that recorded something is written to the database as soon as it ends, at most once per second for each user that runs PHP.

Messages: only the messages a Messenger worker (`messenger:consume`) receives from a transport are measured, each attempt counting as one in `Handled`; `Sent` counts the messages sent to a transport during a recorded request, command, message or task. A message is identified by its class. Not seen: the messages handled synchronously, those handled by a batch handler (`BatchHandlerInterface`), and the time a message waits in its queue. What the worker does after a failure, such as sending the message again for a retry or to the failure transport, is not counted in the message time. A worker writes its buffer to the database between two messages, at most once per `flush_interval`, and when it stops.

Scheduled tasks: a task of the Scheduler shows on the Scheduler screen, not on Messages; when it runs a command (`RunCommandMessage`), that command also shows on Commands, and the label of the task shows the arguments of the command. The Scheduler screen lists the tasks of every schedule, run or not. `Next run` shows `—` for a task whose next run cannot be told from its schedule, such as a periodic task without a starting point (`#[AsPeriodicTask]` by default, or `RecurringMessage::every()` without `from`). A task counts one failure for each failed run.

SQL queries: every query run through Doctrine DBAL during a recorded request, command, message or task, on every connection. The values bound to a query as parameters are never read, so they are never stored. The query text is read with the rules of the database of its connection (MySQL or MariaDB, PostgreSQL, SQLite): its comments are removed, the numbers and strings written in it are replaced by `?`, and its `IN (…)` and multi-row `VALUES (…)` lists are folded. A double-quoted token is a string under MySQL, always replaced; an identifier under PostgreSQL, kept; and under SQLite, which accepts both, replaced only where a value is expected (right after `=`, `<`, `>` or `LIKE`, in a `VALUES (…)` list, or in an `IN (…)` list made only of values), so a double-quoted value anywhere else is kept. A query whose text cannot be delimited with certainty is counted on the `Other queries` row and its text is not kept, for instance an unterminated string or comment, a backslash whose meaning depends on a session setting of the server when the possible readings differ, a MySQL optimizer hint or versioned comment that is not plain (letters, digits, parentheses, commas…), or a text longer than 64 KiB. So is every query of a connection to another database, or of a connection whose `charset` option or a driver option such as an init command names a multi-byte encoding in which a backslash can be part of a character (Big5, GBK, GB18030, Shift_JIS…); such an encoding set another way, by the server default or a `SET NAMES` query, is not detected, and a value can then survive. This reading assumes the server accepts the query: a query the server rejects can be stored with a value in it, so pass sensitive values as bound parameters rather than concatenating them into the SQL. The same `SELECT` run at least `n_plus_one_threshold` times (10 by default) within one request, command, message or task is reported as an N+1.

Exceptions: only the class of the exception is kept: for a request, only when its final response is a 5xx; for a command, a message or a task, when it is thrown out of the command or the handler. Its message, file and stack trace are never kept.

Users: the identifier of the signed-in user (`getUserIdentifier()`, often a username or an email address) is stored in clear in your application database, in the `jmonitor_keys` table. Set `recording.users: false` to go without it. It needs the SecurityBundle. Anonymous requests are not counted, and under `switch_user` the request counts for the impersonated user. A request is attributed to a user only when your application resolved the user during that request: JMonitor never triggers a lazy authentication itself, so the public pages a signed-in user visits may not be counted.

Outgoing HTTP calls: only the calls made through the `http_client` service and the scoped clients declared under `framework.http_client.scoped_clients` are seen, that is the clients your application receives by injection. A client created by hand with `HttpClient::create()`, Guzzle or curl is not seen. Only the host and port of a call are kept, never its scheme, credentials, path or query string. A retried call counts once, and its duration covers every attempt and the pauses between them. `HTTP out` adds up the durations of the calls of a request, so concurrent calls can make it longer than the request itself.

Cache: `cache.app`, whatever its adapter, and the pools declared under `framework.cache.pools` in YAML or PHP are counted. Not counted: the declared pools built on the system adapter (`cache.adapter.system`), those with `early_expiration_message_bus`, and the pools Symfony or other bundles create for their own use. Only counters are kept: hits, misses, writes and deletes per pool, never a key nor a value.

Each flush keeps the 300 most requested routes (method and route) of its batch; the requests of the others are added up on an `Other routes` row. Only the heaviest queries, by total time, are tracked individually, about 200 per server; the others are added up on an `Other queries` row. Likewise, about 200 users, the most active, and 50 hosts, by total time, are tracked individually per server, and each flush keeps 20 cache pools, 50 exception classes, 100 couples of a class and a route, command, message or task, and 100 commands, message classes and tasks; the rest is added up on an `Other …` row. Under heavy load the detail of a request (its queries, user, outgoing calls, cache operations and exception) is sampled, while the requests themselves, with their SQL time, number of queries and time spent in outgoing calls, are always counted.

What is recorded is buffered in files, in a subdirectory of the system temporary directory (or of `buffer_dir`) that only the user running PHP can access; the text of the recorded queries and the identifiers of the signed-in users are kept there too. When the web server and the console run as different users, each keeps and flushes its own buffer. `buffer_dir` must exist and be writable by every user that runs PHP; give it the permissions of `/tmp` (mode `1777`, sticky) rather than a plain `0777`. JMonitor never creates it: while it is missing, the system temporary directory is used instead and a warning is logged once. When that subdirectory cannot be used, nothing is recorded and the page shows "Buffer directory unusable".

Recording is off by default in the `test` environment. Forcing it on with a test setup that keeps the connection inside a transaction (e.g. `dama/doctrine-test-bundle`) only logs refused flushes: nothing is written while a transaction is open.

### Showing the screens inside EasyAdmin

If your application has EasyAdmin 5.10.2 or later, the screens can be shown inside its back office. Nothing else to install. Turn the option on:

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    easyadmin: true
```

Then add a link to the menu of your dashboard:

```php
// src/Controller/Admin/DashboardController.php
public function configureMenuItems(): iterable
{
    // ...
    yield MenuItem::linkToRoute('JMonitor', 'internal:desktop', 'admin_jmonitor_index');
}
```

The pages live under the path of each dashboard: `/admin/jmonitor`, `/admin/jmonitor/queries`… for a dashboard at `/admin`. Their route is named after the route of the dashboard followed by `_jmonitor_index`: `admin_jmonitor_index` for a dashboard whose route is `admin`. The screens follow the light or dark theme of the back office, even when it is switched.

Turned on without EasyAdmin, or with an older version, the option stops the container build with a message that says why.

Access: the pages sit under the path of the dashboard, so the `access_control` rule that protects your back office, such as one on `^/admin`, covers them. A protection that does not depend on the path, such as an `#[IsGranted]` attribute on the dashboard controller, does not: set `permission`, or add an `access_control` rule on `^/admin/jmonitor`.

`permission` is checked on top of it, on every page, refresh and action. It needs the SecurityBundle. Give the menu link the same permission to hide it from the others:

```yaml
jmonitor:
    easyadmin:
        permission: ROLE_ADMIN
```

```php
yield MenuItem::linkToRoute('JMonitor', 'internal:desktop', 'admin_jmonitor_index')
    ->setPermission('ROLE_ADMIN');
```

**Without `permission`, every user of the back office sees the SQL queries of your application and the identifiers of its users.** A `permission` read from an environment variable that turns out empty refuses the pages to everyone.

The pages exist under every dashboard of your application. `dashboard.enabled` and `dashboard.permission` only apply to the `/jmonitor` page.

These pages are not recorded, even when access is refused. When your application has a `csp_nonce` Twig function, the stylesheet and the script of the screens carry its nonce. With the option off, the bundle adds nothing to EasyAdmin.

#### Coming from jmonitor/easyadmin-bundle

This bundle replaces `jmonitor/easyadmin-bundle`. Delete the `jmonitor_easyadmin` configuration first, since Symfony refuses to boot with a configuration no bundle reads, then replace the package and turn `jmonitor.easyadmin` on as above:

```bash
composer remove jmonitor/easyadmin-bundle
composer require jmonitor/jmonitor-bundle
```

| `jmonitor_easyadmin` | Here |
|---|---|
| `permission` | `jmonitor.easyadmin.permission` |
| `cache_pool`, `polling_interval`, `static_ttl`, `exclude_connections`, `components.*` | no equivalent |

The menu link and its route name, `admin_jmonitor_index` for a dashboard whose route is `admin`, do not change. The addresses of the former tabs, such as `/admin/jmonitor/mysql`, answer 404.

The pages show the screens of your application listed above: requests, queries, commands, messages, scheduler, HTTP client, cache, exceptions and users. They have no equivalent of the server tabs of `jmonitor/easyadmin-bundle` 1.x (System, PHP, FrankenPHP, Apache, Nginx, MySQL, PostgreSQL, Redis, Symfony), nor of its Cloudflare tab.

Recording starts by default and creates the `jmonitor_aggregates` and `jmonitor_keys` tables in the default Doctrine connection; `recording.enabled: false` prevents it.

### Showing the screens inside another page

The screens can also be shown inside a page of your own, such as a back office, with its routes, layout and access control. This interface may still change before 3.0.

The `Jmonitor\JmonitorBundle\Embed\JmonitorPage` service offers four calls. `page()`, `fragment()` and `action()` take the request, the path of a screen (`''` for the default screen, `queries`, `cache`…) and your URLs:

- `page()` returns a `PageContent`: the `html` to show (the navigation between the screens and the current screen), its `title`, the `stylesheets` and `scripts` to load and the `headers` to put on your response;
- `fragment()` returns the `Response` the page fetches to refresh itself;
- `action()` runs an action of a screen, posted from the page, and returns a `303` redirect to that screen; it answers 404 when the screen has no such action, and 403 when the request fails the check described below;
- `doNotRecord()` marks the request as not recorded; the three calls above do it too.

An unknown path answers 404. Without the TwigBundle, `page()` and `fragment()` answer 404.

Your application provides:

- three routes with a free path parameter: one for the page, one for the fragment and one for the actions, which accepts `POST`;
- an implementation of `Jmonitor\JmonitorBundle\Embed\HostUrlsInterface` that generates their URLs, with the entries of `$query` (`period`, `sort`, `dir`) as query-string parameters of the same names, since the screens read them from the query string of your request;
- its own layout, with the `html` inside an element of class `jm`, the stylesheets and scripts of the `PageContent` loaded in its `<head>`, the scripts with `defer` since they read the page as soon as they run, and the headers of the `PageContent` on its response.

Access control is entirely yours: `dashboard.enabled` and `dashboard.permission` only apply to the `/jmonitor` page. Call `doNotRecord()` before your own access check, so that a refused request is not recorded, and give your routes the default `_jmonitor: true` (the constant `JmonitorPage::NOT_RECORDED`), so that a request your firewall refuses before it reaches the controller is not recorded either.

The screens are dark by default; `data-jm-theme="light"` on the `jm` element gives the light theme. With `data-jm-theme="auto"`, they follow a Bootstrap colour mode set on an ancestor: light under `data-bs-theme="light"`, dark otherwise.

If your application uses Turbo Drive, put `data-turbo="false"` on the `jm` element, so that every link of the screens loads a full page.

Actions: when your application has CSRF protection and a session and the action route is not stateless, an action needs the CSRF token the page puts in its form; otherwise it must come from the same origin (`Sec-Fetch-Site`, else `Origin`). Behind a reverse proxy, your application must therefore know its public scheme and host (`trusted_proxies`). Declare the page and action routes the same way: both stateless, or neither.

```php
// src/Controller/Admin/MonitoringController.php
namespace App\Controller\Admin;

use Jmonitor\JmonitorBundle\Embed\JmonitorPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MonitoringController extends AbstractController
{
    public function __construct(
        private readonly JmonitorPage $jmonitor,
        private readonly MonitoringUrls $urls,
    ) {}

    public function page(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $content = $this->jmonitor->page($request, $path, $this->urls);

        return $this->render('admin/monitoring.html.twig', ['content' => $content], new Response(headers: $content->headers));
    }

    public function fragment(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->jmonitor->fragment($request, $path, $this->urls);
    }

    public function action(Request $request, string $path): Response
    {
        $this->jmonitor->doNotRecord($request);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->jmonitor->action($request, $path, $this->urls);
    }
}
```

```php
// src/Controller/Admin/MonitoringUrls.php
namespace App\Controller\Admin;

use Jmonitor\JmonitorBundle\Embed\HostUrlsInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MonitoringUrls implements HostUrlsInterface
{
    public function __construct(private readonly UrlGeneratorInterface $router) {}

    public function page(string $path, array $query = []): string
    {
        return $this->router->generate('admin_monitoring', ['path' => $path] + $query);
    }

    public function fragment(string $path, array $query = []): string
    {
        return $this->router->generate('admin_monitoring_fragment', ['path' => $path] + $query);
    }

    public function action(string $path): string
    {
        return $this->router->generate('admin_monitoring_action', ['path' => $path]);
    }
}
```

```yaml
# config/routes/monitoring.yaml
admin_monitoring:
    path: /admin/monitoring/{path}
    controller: App\Controller\Admin\MonitoringController::page
    requirements: { path: '.*' }
    defaults: { path: '', _jmonitor: true }
    methods: [GET]

admin_monitoring_fragment:
    path: /admin/monitoring-fragment/{path}
    controller: App\Controller\Admin\MonitoringController::fragment
    requirements: { path: '.*' }
    defaults: { path: '', _jmonitor: true }
    methods: [GET]

admin_monitoring_action:
    path: /admin/monitoring-action/{path}
    controller: App\Controller\Admin\MonitoringController::action
    requirements: { path: '.*' }
    defaults: { path: '', _jmonitor: true }
    methods: [POST]
```

```twig
{# templates/admin/monitoring.html.twig #}
{% extends 'admin/base.html.twig' %}

{% block title %}{{ content.title }}{% endblock %}

{% block stylesheets %}
    {{ parent() }}
    {% for stylesheet in content.stylesheets %}
        <link rel="stylesheet" href="{{ stylesheet }}">
    {% endfor %}
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    {% for script in content.scripts %}
        <script src="{{ script }}" defer></script>
    {% endfor %}
{% endblock %}

{% block body %}
    <div class="jm" data-jm-theme="light">{{ content.html|raw }}</div>
{% endblock %}
```

## PHP metrics: CLI vs Web context

- PHP settings and extensions can differ significantly between CLI and your web server context.
- If you want metrics that reflect your web runtime, you must expose a tiny HTTP endpoint that returns PHP metrics from within that web context.

To do that, create a route config file: 

```yaml
# config/routes/jmonitor.yaml
jmonitor_expose_php_metrics:
    path: '/jmonitor/php-metrics'
    controller: Jmonitor\JmonitorBundle\Controller\JmonitorPhpController

# Secured route in production with localhost host restriction
# Refer to symfony docs for more information about security
when@prod:
    jmonitor_expose_php_metrics:
        path: '/jmonitor/php-metrics'
        controller: Jmonitor\JmonitorBundle\Controller\JmonitorPhpController
        host: localhost
```

Set up a firewall for this route **before** the main firewall to prevent your app from interfering with it:
```yaml
# config/packages/security.yaml
security:
    firewalls:
        jmonitor:
            pattern: ^/jmonitor/php-metrics$
            security: false
            # instead of disabling security, you can use a stateless firewall if you plan to use ip security or something
            # stateless: true
        main:
        # ...
```

Wire it in your bundle config
```yaml
# config/packages/jmonitor.yaml
jmonitor:
    # ...
    collectors:
        php:
            endpoint: 'http://localhost/jmonitor/php-metrics'
```

## Logging and Debugging
- The command is resilient: individual collector failures do not crash the whole run; errors are logged (logging must be enabled in config).
- Symfony component collectors (scheduler, messenger) that fail at worker startup are disabled for the lifetime of that worker process and an error is logged. Restart the worker to re-enable them.
- Log levels:
    - Errors (collector exceptions, HTTP responses with status >= 400): error
    - Collected metrics: debug
    - Summary: info

## Troubleshooting

### Apache
> mod_status is enabled, but my endpoint is not reachable.

Don't forget to let the request pass through your index.php.  
For example, if you use .htaccess :
```
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_URI} !=/server-status    <---- add this
RewriteRule ^ %{ENV:BASE}/index.php [L]
```

## Need help?
- Anything about this bundle — installation, Symfony configuration, the `jmonitor:collect` command, the Symfony collector: open an issue on this repo https://github.com/jmonitor/jmonitor-bundle/issues
- Anything about a metric — a missing or wrong value, a collector that does not gather what you expect: open an issue on https://github.com/jmonitor/collector/issues
- Anything about the app itself — dashboards, alerts, [dash.jmonitor.io](https://dash.jmonitor.io): open an issue on https://github.com/jmonitor/jmonitor/issues
