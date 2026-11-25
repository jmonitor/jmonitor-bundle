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

The header of the page has three entries, `Application`, `Background` and `Stack`, with tabs under it:

- **Application**, in five tabs:
  - **Requests** (`/jmonitor/`, or `/jmonitor/requests` when nothing is recorded and the page opens on Stack): per route, requests, total time, response times (average, p95, max), where the average request goes between your PHP code, SQL and outgoing HTTP calls (`HTTP out`), with the number of SQL queries per request, its problems (5xx errors, slow requests, `N+1`) and peak memory. Its key figures are the average, p95 and slowest response times, the share of 5xx errors and the number of slow requests.
  - **Queries** (`/jmonitor/queries`): per SQL query, executions, total, average and maximum time, and its problems (slow executions, `N+1`); then the queries repeated within one request, command, message or task (N+1), with where they run. Its key figures are the number of queries, the SQL time, the slow executions and the repeated queries.
  - **Cache & HTTP** (`/jmonitor/cache-http`), in two sections:
    - `Cache` (`#jm-cache`): hits, misses, hit rate, writes and deletes, per cache pool.
    - `Outgoing HTTP` (`#jm-http`): calls, total, average and maximum time, 4xx and 5xx responses and network failures, per called host.
  - **Exceptions** (`/jmonitor/exceptions`): count and last occurrence per exception class, and per class and route, command, message or task.
  - **Users** (`/jmonitor/users`): requests, total and average time, slow requests and 5xx errors, per signed-in user.
- **Background** (`/jmonitor/background`), in three sections:
  - `Messages` (`#jm-messages`): the messages waiting in each transport, counted live (see [The queues of the Background screen](#the-queues-of-the-background-screen)), then messages sent and handled, handling time (average, p95, max), failures, retries and SQL time, per message class.
  - `Scheduled tasks` (`#jm-tasks`): the scheduled tasks with their schedule, trigger, next run, last run, runs, average and maximum duration and failures.
  - `Commands` (`#jm-commands`): runs, average and maximum duration, failures (non-zero exit code), peak memory, last run and SQL time, per console command.
- **Stack** (`/jmonitor/stack`): the server, PHP, Symfony, the web server (FrankenPHP, Apache or Nginx), the MySQL, MariaDB and PostgreSQL databases of your Doctrine connections and your Redis or Valkey servers, read live rather than recorded, with what to check; see [The live stack](#the-live-stack).

A tab or a section only appears when its component is installed and its recording option is on: Outgoing HTTP needs the `http_client` service of the framework, Users needs the SecurityBundle, Messages needs `symfony/messenger` and Scheduled tasks needs `symfony/scheduler`, each enabled in the framework configuration. Messages also appears when its recording is off, as long as the queues are counted. A screen left without any section disappears, and its address answers 404: without Messenger, the Scheduler and the recording of commands, there is no `Background` entry.

The page refreshes itself every 10 seconds while it is in view. The button at the end of the toolbar tells how old the figures are: `Live · updated 3 s ago` while it refreshes, `Paused · data from 2 min ago` once you click it to stop the refresh, a choice your browser remembers, and `Not updated · for 40 s · retrying` after a refresh failed. A refused refresh, for instance once your session has expired, stops it: the page reloads, or the button says `Not updated` without `retrying` until you click it. Without JavaScript, the button is hidden and the page does not refresh.

It needs a Doctrine DBAL connection (MySQL/MariaDB, PostgreSQL or SQLite, typically through `doctrine/doctrine-bundle`), except for the Stack screen and the queues of the Background screen, and the TwigBundle; without the TwigBundle the page answers 404. Nothing else to set up: the `jmonitor_aggregates` and `jmonitor_keys` tables are created automatically in the configured connection, upgraded automatically when a new version of the bundle changes them, and Doctrine migrations will not try to drop them. Tables created by an earlier development build of 3.x are dropped and recreated, losing the metrics they held. Your database receives batched writes, never one write per request or per query: web requests and Messenger workers write at most once per `flush_interval` per server, and a command writes when it ends, at most once per second for each user that runs PHP.

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
        enabled: ~               # default: true, except in the "test" environment
        flush_interval: 10       # seconds between two writes to the database (3600 maximum)
        slow_threshold: 1000     # ms from which a request counts as slow
        ignore_routes: []        # route names not recorded, fnmatch patterns allowed (e.g. 'app_health*')
        buffer_dir: ~            # where the buffer gets its private subdirectory, must exist and be writable by every user running PHP; default: the system temporary directory
        sql:
            enabled: true             # record the SQL queries run through Doctrine DBAL
            slow_threshold: 100       # ms from which a query counts as slow
            n_plus_one_threshold: 10  # executions of the same SELECT within one request, command, message or task that make an N+1
        exceptions: true         # count the exceptions behind 5xx responses and those thrown by commands, message handlers and scheduled tasks
        users: true              # record the requests of each signed-in user (needs the SecurityBundle)
        http_client: true        # record the outgoing calls of the http_client service and the scoped clients
        cache: true              # count the reads, writes and deletes of cache.app and of the pools you declare
        commands:
            enabled: true        # record the console commands
            ignore: []           # command names not recorded on top of the default ones, fnmatch patterns allowed (e.g. 'app:sync-*')
        messenger: true          # record the messages handled by the Messenger workers and the messages sent (needs Messenger)
        scheduler: true          # record the scheduled tasks run by the Messenger workers (needs the Scheduler)
    storage:
        connection: 'default'    # name of the Doctrine DBAL connection
        retention: 604800        # seconds the data is kept (7 days, 3600 minimum)
    dashboard:
        enabled: true            # false: the /jmonitor page answers 404
        permission: ~            # role or voter attribute required to see the page
    easyadmin:
        enabled: false           # show the screens inside EasyAdmin
        permission: ~            # role or voter attribute required to see them there
    stack:                       # the Stack screen, see "The live stack" below
        enabled: true
        end_of_life: true        # look up support dates on endoflife.date
        ignore_checks: []        # identifiers of the checks to silence
        exclude_connections: []  # Doctrine connections never read, fnmatch patterns allowed
        system: auto
        php: auto
        symfony:
            enabled: auto
            flex: true           # run `composer recipes` to list the outdated Flex recipes
            messenger: true      # count the messages waiting in each transport
        frankenphp:
            enabled: auto
            endpoint: 'http://localhost:2019/metrics'
        apache:
            enabled: auto
            endpoint: 'http://localhost/server-status'
        nginx:
            enabled: auto
            endpoint: 'http://localhost/nginx_status'
        mysql:
            enabled: auto
            connections: []      # names of the Doctrine connections to read; all of them when empty
        postgresql:
            enabled: auto
            connections: []      # names of the Doctrine connections to read; all of them when empty
            schema: public       # schema whose tables are counted and sized
        redis:
            enabled: auto
            connections: {}      # DSN of the servers to read, each under an id; when empty, those of the cache, of the sessions or REDIS_URL
```

`recording.enabled` can come from an environment variable, read at runtime, to switch recording off without a new deployment:

```yaml
jmonitor:
    recording:
        enabled: '%env(bool:JMONITOR_RECORDING)%'
```

### Access

- Without `dashboard.permission`, the page is open in debug mode and answers 404 otherwise. Open that way, it also shows whoever reaches it the host names, versions and settings of your servers, on the Stack screen.
- With `dashboard.permission`, for example `ROLE_ADMIN`, the page requires that permission in every environment. This option needs the SecurityBundle.
- The route must sit behind one of your firewalls: outside any firewall nobody is authenticated, so the page is denied to everyone.

### What is recorded

Requests: main requests only. Not recorded: any route whose name starts with `jmonitor_`, any JMonitor page shown by another host (the EasyAdmin pages among them), the web debug toolbar and profiler, sub-requests, and the routes matching `ignore_routes`. A request that matches no route is recorded as `(unmatched)`. Methods other than GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS are grouped under `OTHER`.

Commands: a command is recorded from its start, the boot of the kernel excluded, to its end, and fails when its exit code is not 0. Not recorded by default: `messenger:consume`, `messenger:failed:*`, `messenger:stats`, `jmonitor:*`, `cache:*`, `assets:*`, `importmap:*`, `asset-map:*`, `secrets:*`, `tailwind:*`, `sass:*`, `list`, `help`, `_complete`, `completion`, `debug:*`, `lint:*`, `server:*` and `doctrine:database:*`; `recording.commands.ignore` adds to this list. A command your build runs without a database is worth adding to it: otherwise its write fails when it ends and is logged as an error. A command that recorded something is written to the database as soon as it ends, at most once per second for each user that runs PHP.

Messages: only the messages a Messenger worker (`messenger:consume`) receives from a transport are measured, each attempt counting as one in `Handled`; `Sent` counts the messages sent to a transport during a recorded request, command, message or task. A message is identified by its class. Not seen: the messages handled synchronously, those handled by a batch handler (`BatchHandlerInterface`), and the time a message waits in its queue. What the worker does after a failure, such as sending the message again for a retry or to the failure transport, is not counted in the message time. A worker writes its buffer to the database between two messages, at most once per `flush_interval`, and when it stops.

Scheduled tasks: a task of the Scheduler shows in the `Scheduled tasks` section of the Background screen, not under `Messages`; when it runs a command (`RunCommandMessage`), that command also shows under `Commands`, and the label of the task shows the arguments of the command. The `Scheduled tasks` section lists the tasks of every schedule, run or not. `Next run` shows `—` for a task whose next run cannot be told from its schedule, such as a periodic task without a starting point (`#[AsPeriodicTask]` by default, or `RecurringMessage::every()` without `from`). A task counts one failure for each failed run.

SQL queries: every query run through Doctrine DBAL during a recorded request, command, message or task, on every connection. The values bound to a query as parameters are never read, so they are never stored. The query text is read with the rules of the database of its connection (MySQL or MariaDB, PostgreSQL, SQLite): its comments are removed, the numbers and strings written in it are replaced by `?`, and its `IN (…)` and multi-row `VALUES (…)` lists are folded. A double-quoted token is a string under MySQL, always replaced; an identifier under PostgreSQL, kept; and under SQLite, which accepts both, replaced only where a value is expected (right after `=`, `<`, `>` or `LIKE`, in a `VALUES (…)` list, or in an `IN (…)` list made only of values), so a double-quoted value anywhere else is kept. A query whose text cannot be delimited with certainty is counted on the `Other queries` row and its text is not kept, for instance an unterminated string or comment, a backslash whose meaning depends on a session setting of the server when the possible readings differ, a MySQL optimizer hint or versioned comment that is not plain (letters, digits, parentheses, commas…), or a text longer than 64 KiB. So is every query of a connection to another database, or of a connection whose `charset` option or a driver option such as an init command names a multi-byte encoding in which a backslash can be part of a character (Big5, GBK, GB18030, Shift_JIS…); such an encoding set another way, by the server default or a `SET NAMES` query, is not detected, and a value can then survive. This reading assumes the server accepts the query: a query the server rejects can be stored with a value in it, so pass sensitive values as bound parameters rather than concatenating them into the SQL. The same `SELECT` run at least `n_plus_one_threshold` times (10 by default) within one request, command, message or task is reported as an N+1.

Exceptions: only the class of the exception is kept: for a request, only when its final response is a 5xx; for a command, a message or a task, when it is thrown out of the command or the handler. Its message, file and stack trace are never kept.

Users: the identifier of the signed-in user (`getUserIdentifier()`, often a username or an email address) is stored in clear in your application database, in the `jmonitor_keys` table. Set `recording.users: false` to go without it. It needs the SecurityBundle. Anonymous requests are not counted, and under `switch_user` the request counts for the impersonated user. A request is attributed to a user only when your application resolved the user during that request: JMonitor never triggers a lazy authentication itself, so the public pages a signed-in user visits may not be counted.

Outgoing HTTP calls: only the calls made through the `http_client` service and the scoped clients declared under `framework.http_client.scoped_clients` are seen, that is the clients your application receives by injection. A client created by hand with `HttpClient::create()`, Guzzle or curl is not seen. Only the host and port of a call are kept, never its scheme, credentials, path or query string. A retried call counts once, and its duration covers every attempt and the pauses between them. `HTTP out` adds up the durations of the calls of a request, so concurrent calls can make it longer than the request itself; the PHP part of the average request is then 0 on the Requests screen.

Cache: `cache.app`, whatever its adapter, and the pools declared under `framework.cache.pools` in YAML or PHP are counted. Not counted: the declared pools built on the system adapter (`cache.adapter.system`), those with `early_expiration_message_bus`, and the pools Symfony or other bundles create for their own use. Only counters are kept: hits, misses, writes and deletes per pool, never a key nor a value.

Each flush keeps the 300 most requested routes (method and route) of its batch; the requests of the others are added up on an `Other routes` row. Only the heaviest queries, by total time, are tracked individually, about 200 per server; the others are added up on an `Other queries` row. Likewise, about 200 users, the most active, and 50 hosts, by total time, are tracked individually per server, and each flush keeps 20 cache pools, 50 exception classes, 100 couples of a class and a route, command, message or task, and 100 commands, message classes and tasks; the rest is added up on an `Other …` row. Under heavy load the detail of a request (its queries, user, outgoing calls, cache operations and exception) is sampled, while the requests themselves, with their SQL time, number of queries and time spent in outgoing calls, are always counted.

What is recorded is buffered in files, in a subdirectory of the system temporary directory (or of `buffer_dir`) that only the user running PHP can access; the text of the recorded queries and the identifiers of the signed-in users are kept there too. When the web server and the console run as different users, each keeps and flushes its own buffer. `buffer_dir` must exist and be writable by every user that runs PHP; give it the permissions of `/tmp` (mode `1777`, sticky) rather than a plain `0777`. JMonitor never creates it: while it is missing, the system temporary directory is used instead and a warning is logged once. When that subdirectory cannot be used, nothing is recorded and the page shows "Buffer directory unusable".

Recording is off by default in the `test` environment. Forcing it on with a test setup that keeps the connection inside a transaction (e.g. `dama/doctrine-test-bundle`) only logs refused flushes: nothing is written while a transaction is open.

### Reading Requests and Queries

The Requests and Queries screens read from top to bottom: a sentence that sums up the period, with the curve of the requests or of the queries; `Worth a look`, where each check found says what happened, why it matters and what to do, or `Nothing to flag` with what was checked; the key figures, each with its definition; the table, sorted by total time by default, with a bar on the total time; then `How to read this screen`. A screen with nothing recorded only says so.

| Check | Severity | Shown on | Raised by | Threshold |
|---|---|---|---|---|
| `requests.server_errors` | Critical | Requests | a response with a 5xx status | none |
| `requests.slow` | Warning | Requests | a request that took the threshold or more | `recording.slow_threshold` |
| `queries.slow` | Warning | Queries | a query execution that took the threshold or more | `recording.sql.slow_threshold` |
| `queries.repeated` | Warning | Queries | the same `SELECT` run the threshold number of times or more within one request, command, message or task (N+1) | `recording.sql.n_plus_one_threshold` |

A check is raised as soon as one occurrence is recorded in the period shown. When it can, it names the route or query that counts the most occurrences, or, for `queries.repeated`, the route, command, message or task where a repeated query costs the most time. There is no option to silence one: `stack.ignore_checks` only applies to the checks of the Stack screen. `queries.repeated` also shows on Requests, with a link to Queries, when one of the routes listed there repeats a query.

The `Requests` and `Queries` tabs carry a badge while one of their checks is raised: the number of checks and the highest severity, such as `1 warning` or `2 critical`, detailed when you hover it.

### The live stack

The **Stack** screen (`/jmonitor/stack`) tells whether the server, PHP, Symfony, the web server that answers, and the MySQL, MariaDB, PostgreSQL, Redis and Valkey servers of your application are fine right now, and where to look when they are not. Nothing is stored in the database: they are read when the screen is displayed.

- **To check**: what needs attention, each line with its severity (`Critical`, `Warning` or `Info`), the component it concerns and, under `Why and what to do`, what to change.
- **One card per component**, with its verdict (`Healthy`, `Warning`, `Critical` or `Unknown`) and its main figures: load, memory and disk for Server; version, OPcache memory and hit rate, and PHP-FPM workers for PHP; version, environment, debug mode and Flex recipes for Symfony; version, busy threads, queue and mode for FrankenPHP; version, busy workers and requests per second for Apache; version, connections and requests per second for Nginx; version, connections, queries per second and buffer pool hit rate for each MySQL or MariaDB server; version, connections of the server, cache hit rate and transactions per second for each PostgreSQL connection; version, memory, hit rate and commands per second for each Redis or Valkey server. A version past its end of life, or that only receives security fixes, says so.
- **One detail per component**, reached from its card or from the tabs of the Stack screen, after `All components`: `/jmonitor/stack/system`, `/jmonitor/stack/php`, `/jmonitor/stack/symfony`, `/jmonitor/stack/frankenphp`, `/jmonitor/stack/apache`, `/jmonitor/stack/nginx`, `/jmonitor/stack/mysql`, `/jmonitor/stack/postgresql` and `/jmonitor/stack/redis`, with the gauges and settings behind the card (OPcache, APCu, PHP-FPM, bundles, outdated recipes, FrankenPHP workers, Apache scoreboard, Nginx settings, the size and slowest queries of a database, the blocked sessions of PostgreSQL, the persistence and keys of Redis…).

Server is read on Linux only; on another system, the screen says so in place of its card. In a container, the load is the one of the host node, so a high load is only an `Info` there.

The `Stack` entry of the navigation carries the number of critical and warning checks found at the last display of the Stack screen, for 15 minutes. When the application records nothing, because `recording.enabled` is off or there is no Doctrine DBAL connection, the `/jmonitor` page opens on the Stack screen. Under EasyAdmin, the Stack screen shows too.

Nothing is read while nobody looks at it: no listener or scheduled task reads the stack, and the other screens only read the count of their badge from the cache. Its only console command, `jmonitor:stack:queues`, is started by a display of the Background screen. What is read is kept in the cache for a lifetime of its own, 10 seconds for the figures of the server, PHP, Symfony, the web server, the databases and Redis: however many people watch the screen, each web server reads them at most once every 10 seconds, and a database or a Redis server is read at most once every 10 seconds for all your web servers. A value that takes longer to read shows `Reading…` and fills in within a few seconds, or with the `Load the rest` link. `Refresh` forgets what the cache holds and reads it again, the status page of the web server included, apart from the end-of-life dates; on a detail screen it only reads that component. It works at most once every 10 seconds for the same screen: a click within that time changes nothing.

#### The web server

The web server that serves the page gets its own card. It is recognised from what PHP sees, without any request:

- **FrankenPHP** when PHP runs inside it.
- **Apache** when PHP runs as its module (`mod_php`), or when Apache passes a `SERVER_SOFTWARE` starting with `Apache` to PHP-FPM.
- **Nginx** when it passes a `SERVER_SOFTWARE` starting with `nginx` to PHP-FPM, as the `fastcgi_params` file it ships does.

Behind another server, such as Caddy in front of PHP-FPM, none is recognised, and the `Not detected` line says `Web server: FrankenPHP, Apache and Nginx were not found serving this page`.

Its figures come from its status page, which you expose. PHP reads it with `allow_url_fopen`, within 2 seconds, without following redirects, so the address must be reachable from PHP itself: from inside its container when PHP runs in one. Set it with `endpoint` when the default does not fit.

**Apache**: turn `mod_status` on, and expose its page to local requests only. It is read at `http://localhost/server-status`, in its machine-readable form (`?auto`):

```apache
<Location "/server-status">
    SetHandler server-status
    Require local
</Location>
```

If a `.htaccess` sends every address without a file to `index.php`, as the one of `symfony/apache-pack` does, `/server-status` may reach your application instead. Leave the page out of the rewriting with `RewriteCond %{REQUEST_URI} !=/server-status` before its `RewriteRule`.

**Nginx**: add a `stub_status` location to the `server` block that answers `http://localhost`. It is read at `http://localhost/nginx_status`:

```nginx
location = /nginx_status {
    stub_status;
    allow 127.0.0.1;
    allow ::1;
    deny all;
}
```

When PHP and the web server run in two containers, the request comes from the address of the PHP container, which `Require local` and `allow 127.0.0.1` refuse. Open the page to that address, or to its network, and set `endpoint` to the name of the web server, such as `http://nginx/nginx_status`.

Its version and settings (`worker_processes`, `worker_connections`, `keepalive_timeout`, `gzip`…) come from `nginx -V` and `nginx -T`, and the CPU count from `getconf _NPROCESSORS_ONLN`, run on the machine or in the container of PHP. Only the version and those settings are kept, never the rest of the configuration. Without the `nginx` command there, as when Nginx and PHP-FPM run in two containers, the version comes from the `SERVER_SOFTWARE` that Nginx passes, unless `server_tokens off` hides it. `nginx -T` only gives the settings when PHP is allowed to run it, as root in practice; without them, the detail says the settings could not be read, its connections gauge says `connection limit unknown`, and the `nginx.connections` check cannot run.

**FrankenPHP**: turn the Caddy metrics on with the `metrics` global option of the Caddyfile. They are read at `http://localhost:2019/metrics`, served by the Caddy admin API, which is on by default:

```caddyfile
{
	metrics
}
```

Its version comes from `frankenphp version`.

A status page that cannot be read leaves the card `Unknown`, with the reason and what to do. An address that answers something other than a status page (a 404 or a redirection to a login page of your application, for instance) is tried again an hour later; one where nothing answers, a minute later. That probe leaves no line on the Requests screen, unless your application has a catch-all route: a request is not recorded when it carries the `X-Jmonitor-Probe` header, matches no route, comes from a loopback or private address (or from the address of the server), and carries no relay header (`X-Forwarded-For`, `Forwarded`, `X-Real-IP`, `Via`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `CF-Connecting-IP`, `True-Client-IP`). Behind a proxy that adds none of these headers, a visitor can thus hide their requests to addresses that do not exist.

The same servers are set in two places: under `collectors.*` for the worker that sends the metrics to Jmonitor, and under `stack.*` for this screen.

#### The databases

Every Doctrine DBAL connection of your application whose driver is MySQL (`pdo_mysql`, `mysqli`, a `driverClass` that extends the MySQL driver of DBAL, or a `mysql://` or `mysql2://` URL) is read, whether the server behind it is MySQL or MariaDB. Where a connection leads is read from the parameters DoctrineBundle gives it, not by building it: no connection is built, and none is opened, to learn its server. A connection whose parameters cannot be read, such as one whose environment variable is missing, shows as unavailable with the reason when it is listed under `stack.mysql.connections`; otherwise it is left out, and the reason shows on the `Not detected` line when no MySQL connection is found. A URL whose password holds a `/`, `?` or `#` that is not encoded cannot be read: encode the password (`%2F`, `%3F`, `%23`).

`stack.mysql.connections` names the connections to read; empty, every MySQL connection is read. A name that is not a Doctrine connection shows as unavailable, with `Unknown Doctrine connection`. `stack.exclude_connections` leaves out every connection whose name matches one of its patterns (`*` and `?`), such as a replica or a backup the screen should never open; a connection both listed and excluded stops the container build. An application without a MySQL connection has no MySQL card, and the `Not detected` line says `MySQL: No MySQL Doctrine connection found.`, or `MySQL: No Doctrine DBAL connection is configured.` without DoctrineBundle.

**One card per server.** The connections that lead to one server share a card, which names the first of them and says how many lead there (`2 connections`). Two connections lead to one server when their configuration gives the same host and port: `localhost` and `127.0.0.1` count as two servers, and so do a host name and its address, since nothing tells PHP that they are the same. A Unix socket is a server of its own. The overview reads the first connection of each server, up to 8 servers, and says how many more there are.

**One detail per connection**, at `/jmonitor/stack/mysql?connection=<name>`, reached from the card. Its selector lists the servers and, for the server shown, the connections that lead there with their database. It shows the connections, traffic, InnoDB, temporary tables, locks and settings of the server, then the size of the database of the connection and its slowest queries.

| What | How often | Where |
|---|---|---|
| The status of the server (`SHOW GLOBAL STATUS`): connections, queries, buffer pool, temporary tables, locks | at most once every 10 seconds for each server, shared by all your web servers through the cache | cards and details |
| Its settings (`SHOW GLOBAL VARIABLES`): version, `max_connections`, `innodb_buffer_pool_size`… | once an hour for each server | cards and details |
| The size of the data and of the indexes of the database (`information_schema.TABLES`) | once an hour for each connection | detail only |
| The slowest queries of the database (`performance_schema.events_statements_summary_by_digest`) | once a minute for each connection | detail only |

The status and the settings need no privilege. The size counts the tables the database user of the connection can see. The slowest queries need `SELECT` on `performance_schema.events_statements_summary_by_digest`; without it the detail says the user may not read that table, and the rest is read as usual:

```sql
GRANT SELECT ON performance_schema.events_statements_summary_by_digest TO 'app'@'%';
```

MariaDB turns `performance_schema` off by default: set `performance_schema = ON` in the configuration of the server and restart it, or the detail says it is off and shows no slowest query.

The slowest queries are the digests of `performance_schema`, the 10 slowest on average: every value is replaced by `?`, so no value of your data shows. Their counts and times add up since the server started. The queries that name `performance_schema` or `information_schema` are left out, those of the screen among them, and so are the queries of your application that name them, such as the introspection of Doctrine Migrations.

On MySQL 8, `information_schema` keeps the sizes in a cache of its own for a day (`information_schema_stats_expiry`): they can be up to a day old, and the detail says so. The query that renews that cache takes longer: 4 to 7 seconds for 2,000 tables, measured on MySQL 8.4 and 8.0. A reading of the sizes that takes more than 10 seconds shows as unreadable on the detail only, and the slowest queries with it for the same time: the server is not said to be down.

A connection the screen opened, by building it or by trying again after it was lost, is closed at the end of the read, and its wait for an answer is bounded to 10 seconds; a connection your application had already opened is used as it is and left open. Under the worker mode of FrankenPHP, nothing the screen opened stays open from one request to the next. A persistent connection (`persistent: true`, or `PDO::ATTR_PERSISTENT` in its `options`) is the exception: closing it hands it back to PHP, which keeps it for the next request of your application, so the screen does not bound its wait.

A primary/replica connection is read on its primary. When the screen opened it, it is closed whole at the end of the read, its replica included.

A server that cannot be reached raises a `Critical` check, `MySQL cannot be reached`, with the reason, and its card keeps its last reading, if it had one. The reason never shows the password, the database user nor the URL of the connection. What it costs depends on how the server fails:

- a closed port, such as a stopped server on a host that answers: nothing, the refusal comes at once;
- a host that does not answer at all: a display waits up to 30 seconds;
- a server that accepts the connection and then stops answering: a display waits 10 seconds, when the screen opens the connection itself and PHP lets it call `ini_set` (not listed in `disable_functions`); otherwise it waits as long as the timeouts of your application.

A connection your application already holds open, as under the worker mode of FrankenPHP once a request has used it, or a persistent one, is used with the timeouts of your application: a server that stops answering can then keep a display waiting much longer.

After such a wait, none of the readings of that connection is asked again for five times the wait, one minute at least and ten minutes at most, and its card says why. `Refresh`, on the Stack screen or on the detail of any connection, asks every connection again at once.

The wait is for each connection shown. The overview reads one connection per server, so a server that does not answer makes one display wait; the detail of another connection of that server waits once too. A connection that fails for a reason of its own, such as a refused password, does not make the other connections of its server show as failed. It stays `Critical` until it is read again itself, at the end of its pause or after a `Refresh`, even while another connection reads its server.

**The time limit of PHP.** Before it reads a database or a Redis server, the screen raises the time limit of its own request (`max_execution_time`) to 45 seconds when it is shorter, so that a server that keeps it waiting fails on the bound of its connection rather than on the limit of PHP. The former limit is put back at the end of the read. A limit of 0, or of 45 seconds or more, is left as it is, and so is any limit when `set_time_limit` is listed in `disable_functions`. If PHP stops the request anyway while it waits for a server, the next display shows that server `Critical`, with `The server did not answer before PHP stopped the request reading it.`, and does not wait for it again before the end of its pause. Stopped for another reason, such as its memory limit, the request leaves `PHP stopped the request before this was read.` on the reading it was running, which is asked again like any reading that failed. Under PHP-FPM, `request_terminate_timeout` ends the process without letting PHP know: when it is shorter than the wait, the screen keeps its former readings and stays in its reading state five minutes before it asks again.

**PostgreSQL.** Every Doctrine DBAL connection whose driver is PostgreSQL (`pdo_pgsql`, `pgsql`, a `driverClass` that extends the PostgreSQL driver of DBAL, or a `postgres://`, `postgresql://` or `pgsql://` URL) is read, found the same way as a MySQL one, without building it. `stack.postgresql.connections` names the connections to read, and `stack.exclude_connections` applies to them too. An application without a PostgreSQL connection has no PostgreSQL card, and the `Not detected` line says `PostgreSQL: No PostgreSQL Doctrine connection found.`

Under DoctrineBundle 3 and DBAL 4, a PostgreSQL connection without `server_version` nor `charset` cannot be opened, by your application or by the screen: its card shows the error of DBAL.

**One card per connection**, since the figures PostgreSQL gives are those of one database: its cache hit rate and transactions per second are those of the database of the connection, while its connections are those of the whole server, every database included. The overview reads up to 8 connections, and says how many more there are. The detail, at `/jmonitor/stack/postgresql?connection=<name>`, shows the connections, traffic and cache of the database, its sessions and the sessions waiting for a lock, the rows and sizes of its tables, the settings of the server and its slowest queries.

| What | How often | Where |
|---|---|---|
| The statistics of the database (`pg_stat_database`), its sessions (`pg_stat_activity`) and the connections of the server | at most once every 10 seconds for each connection, shared by all your web servers through the cache | cards and details |
| The settings of the server (`pg_settings`): version, `max_connections`, `shared_buffers`, `autovacuum`… | once an hour for each connection | cards and details |
| The rows and sizes of the tables of the schema (`pg_stat_user_tables`), and the size of the database | once a minute for each connection | checks and details |
| The slowest statements of the database (`pg_stat_statements`) | once a minute for each connection | detail only |

The tables counted are those of one schema, `public` by default, set with `stack.postgresql.schema` for every connection; the size of the database covers all its schemas.

A role without privilege only sees the state of its own sessions. The screen says so, with how many sessions of other roles it cannot see, and judges the transactions left idle and the sessions waiting for a lock among the sessions it can read only: a check raised says so, and no check says that the others are fine. Grant the role `pg_monitor` to see everything:

```sql
GRANT pg_monitor TO app;
```

The slowest statements need the `pg_stat_statements` extension, loaded by the server and created in the database:

```ini
# postgresql.conf, then restart the server
shared_preload_libraries = 'pg_stat_statements'
```

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

Without it, the detail says so and the rest is read as usual. A role sees the text of its own statements only: those of other roles among the slowest read are counted, not shown, unless the role has `pg_read_all_stats` or `pg_monitor`. Only the statements that start with `SELECT`, `INSERT`, `UPDATE`, `DELETE` or `WITH`, once their comments are removed, are shown, the 10 slowest on average: PostgreSQL keeps the text of the others as it was written, a password among them in `CREATE ROLE … PASSWORD`. Each text is read again with the rules of the Queries screen, its comments removed and its values replaced by `?`, and left out when it cannot be read; the statements that only differ by the size of a list are merged. The statements of the screen are left out. Their counts and times add up since the statistics were reset. The text of the queries of the sessions, blocked or blocking, is never kept nor shown.

A connection the screen opened is closed at the end of the read; one your application had already opened is used as it is and left open. PostgreSQL has no bound of its own on the wait for an answer:

- a closed port: nothing, the refusal comes at once;
- a server that accepts the connection and then stops answering: a display waits up to 30 seconds, once, then shows its connections `Critical`;
- a connection your application already holds open, as under the worker mode of FrankenPHP once a request has used it: a server that stops answering keeps the display waiting as long as it does not answer, and the worker with it.

A server that keeps a display waiting 2 seconds or more makes the other connections to it, read in the same display, show its failure at once rather than wait in turn. After that, the pause and `Refresh` work as for MySQL.

#### Redis

The Redis and Valkey servers of your application each get a card. They are found without opening a connection, from the first of these that gives one:

1. `stack.redis.connections`, each DSN under an id of yours; an environment variable is accepted, such as `'%env(REDIS_URL)%'`, and a `%` written in a DSN is doubled (`%%`), as in any Symfony parameter;
2. the DSN of the cache pools on Redis, `cache.app` among them, and of the sessions when `framework.session.handler_id` is a Redis DSN;
3. the `REDIS_URL` environment variable.

The DSN of Messenger and of the Lock component are not looked for: name such a server under `stack.redis.connections`. Without a server, the `Not detected` line says `Redis: No Redis DSN found: set jmonitor.stack.redis.connections, use Redis for a cache pool or the sessions, or set the REDIS_URL environment variable.`

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    stack:
        redis:
            connections:
                cache: '%env(REDIS_URL)%'
                queues: 'redis://queues:6379'
```

**One card per server**, at the host and port, or the socket, of its DSN: two DSN that only differ by their database lead to one card, since `INFO` is the same for every database of a server, but `localhost` and `127.0.0.1` count as two servers. A server found in the application is named after its address; a configured one, after its id. The overview reads up to 8 servers, and says how many more there are. The detail, at `/jmonitor/stack/redis?connection=<id>`, shows the memory and its policy, the clients, the traffic, the evicted keys and refused connections, the persistence (RDB and AOF), the number of keys of each database, and the version, mode, role and uptime of the server.

Only `INFO` is sent, besides the password and the choice of the database the DSN gives, at most once every 10 seconds for each server, shared by all your web servers through the cache. `CONFIG` is never sent, and no key is read. Of a DSN, only the scheme, the host, the port and the socket are kept and shown, never the user nor the password.

Reading Redis needs the `redis` extension or the `predis/predis` package; without them, the card says so. The screen opens a connection of its own, never one of your application: 1 second to connect, 2 seconds for the answer, never persistent, closed at the end of the read. The options of the DSN that would keep it open or let it wait longer (`timeout`, `read_timeout`, `retry_interval`, `persistent`, `persistent_id`, `lazy`, `class`) are left out of it; the others, such as `dbindex` or `ssl`, are kept. A server that does not answer makes one display wait 2 seconds, then shows `Critical`; a host name that the DNS does not answer can make it wait longer, since PHP does not bound the resolution. A user that may not run `INFO`, or may not select the database of the DSN, raises a `Warning` with the reason, not a server that cannot be reached.

Redis Cluster, Sentinel and the DSN with several hosts are not read: configured, the card says so; found in the application, the `Not detected` line says why and asks to name a server under `stack.redis.connections`. So does a found DSN whose password holds a `/`, `?` or `#` that is not encoded. Valkey is recognised, by its schemes `valkey://` and `valkeys://` and by what its server tells, and its support dates are those of Valkey.

When the Redis server also holds `cache.app`, its failure takes down the cache of the stack too: the screen answers at once, says that the live stack needs a working cache, and only shows what PHP reads in its own process until the server is back. Under the worker mode of FrankenPHP, a connection of the `redis` extension that lost its server does not open again: `cache.app`, and the cache of the stack with it, stays out of order until the worker restarts.

#### Configuration

Defaults, all optional:

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    stack:
        enabled: true            # false: no Stack screen and nothing read; `stack: false` does the same
        end_of_life: true        # look up the support dates of PHP, Symfony, Apache, Nginx, MySQL, MariaDB, PostgreSQL, Redis and Valkey on endoflife.date, once a day
        ignore_checks: []        # identifiers of the checks to silence, e.g. ['symfony.flex_recipes']
        exclude_connections: []  # Doctrine connections never read, as fnmatch patterns, e.g. ['*_replica']
        system: auto             # auto: read when found, otherwise the reason shows on the "Not detected" line; true: its card stays, with the reason, when it cannot be read; false: never read
        php: auto                # same values
        symfony:
            enabled: auto        # same values; `symfony: false` does the same
            flex: true           # run `composer recipes` once an hour to list the outdated Flex recipes
            messenger: true      # count the messages waiting in each transport, on the Background screen
        frankenphp:
            enabled: auto        # same values; `frankenphp: false` does the same
            endpoint: 'http://localhost:2019/metrics'
        apache:
            enabled: auto        # same values; `apache: false` does the same
            endpoint: 'http://localhost/server-status'
        nginx:
            enabled: auto        # same values; `nginx: false` does the same
            endpoint: 'http://localhost/nginx_status'
        mysql:
            enabled: auto        # same values; `mysql: false` does the same
            connections: []      # names of the Doctrine connections to read, e.g. ['default', 'reports']; all of them when empty
        postgresql:
            enabled: auto        # same values; `postgresql: false` does the same
            connections: []      # names of the Doctrine connections to read; all of them when empty
            schema: public       # schema whose tables are counted and sized, for every connection
        redis:
            enabled: auto        # same values; `redis: false` does the same
            connections: {}      # DSN of the servers to read, each under an id, e.g. { cache: '%env(REDIS_URL)%' }; when empty, those of the cache, of the sessions or REDIS_URL
```

What the stack reads outside the process of PHP, each with its switch under `stack`:

| What | Time limit | How often | Switch |
|---|---|---|---|
| The status page of the web server | 2 s | at most once every 10 seconds on each web server | `frankenphp`, `apache`, `nginx` |
| An HTTPS request to `endoflife.date` for PHP, Symfony, Apache, Nginx, MySQL, MariaDB, PostgreSQL, Redis and Valkey | 3 s | once a day for each, shared by all your web servers through the cache | `end_of_life` |
| `composer recipes`, in the project directory, when `symfony/flex` is installed | 5 s | once an hour on each web server | `symfony.flex` |
| `nginx -V`, `nginx -T` and `getconf _NPROCESSORS_ONLN`, when Nginx serves the page | 3 s each | once an hour on each web server | `nginx` |
| `frankenphp version`, when FrankenPHP serves the page | 3 s | once an hour on each web server | `frankenphp` |
| The console of your application, `bin/console jmonitor:stack:queues`, when the Background screen is displayed | 3 s | at most once every 30 seconds, shared by all your web servers through the cache | `symfony.messenger` |
| The MySQL or MariaDB server of each Doctrine connection, as described in [The databases](#the-databases) | 10 s without an answer; opening a connection to a host that drops the packets, up to 30 s; longer on a connection your application holds open, or a persistent one | its status at most once every 10 seconds, shared by all your web servers through the cache; the rest once a minute to once an hour | `mysql` |
| The PostgreSQL database of each Doctrine connection, as described in [The databases](#the-databases) | opening a connection, up to 30 s; no bound once it is open | its statistics at most once every 10 seconds, shared by all your web servers through the cache; the rest once a minute to once an hour | `postgresql` |
| `INFO` on each Redis or Valkey server, as described in [Redis](#redis) | 1 s to connect, 2 s for the answer | at most once every 10 seconds, shared by all your web servers through the cache | `redis` |

A command runs in the environment of PHP, without the headers of the web request (the `HTTP_*` variables), as its user, who must be able to run it. When it cannot, the screen says so: a check on the Stack screen, or a notice in the `Queues` block or in the detail of Nginx. A command that cannot be found is tried again an hour later. Turned off, `end_of_life` shows versions without their support status and skips the end-of-life and security-fixes checks; `symfony.flex` hides the Flex recipes.

None of these delays the first display of the screen: they run when the page reads what it still lacks or what has gone out of date.

`stack` and `stack.enabled` cannot be read from an environment variable: the services of the stack are registered when the container is built.

The values live in the `jmonitor.stack.cache` pool, built on `cache.app` with keys of their own. It shows in `cache:pool:list` and in the Cache panel of the profiler, `cache:pool:clear jmonitor.stack.cache` empties it, and its reads are not counted on the Cache & HTTP screen. The stack needs a cache that keeps its values from one request to the next: with `cache.app` on the `array` or `null` adapter, or a cache that is down, such as a Redis server that cannot be reached, the screen says so and only shows what PHP reads in its own process, without the end-of-life dates, the Flex recipes or the queues, and `Refresh` does nothing.

Each web server keeps its own figures in that cache, under its host name, and the screen names the one that answered. In containers, give each web server its own host name: two containers that share one, through a fixed `hostname` in Compose for instance, mix their values. The badge of the navigation and the figures of the server, PHP, Symfony and the web server are those of the web server that answered, those of the databases and of Redis are shared by all of them, while the 10-second bound of `Refresh` is shared by all the web servers that share the cache.

#### Checks

To silence a check, add its identifier to `ignore_checks`: it is no longer shown, nor counted in the verdict of its component or in the badge. The identifiers:

| Identifier | Raised when |
|---|---|
| `system.memory` | memory is 85% used or more (`Critical` from 95%) |
| `system.disk` | the disk is 85% full or more (`Critical` from 95%) |
| `system.load` | the load over 1 minute reaches 100% of the CPU cores (`Critical` from 200%, `Info` in a container) |
| `php.opcache_disabled` | OPcache is not running, or its status cannot be read (`Info` in debug mode) |
| `php.opcache_memory` | OPcache memory is 90% full or more, wasted memory counting as used (`Critical` when full) |
| `php.opcache_hit_rate` | the OPcache hit rate is below 85% over at least 1,000 lookups, outside debug mode |
| `php.apcu_memory` | APCu memory is 90% full or more |
| `php.fpm_queue` | requests are waiting for a PHP-FPM worker |
| `php.fpm_max_children` | PHP-FPM reached `pm.max_children` between two readings (`Info` when only since it started) |
| `php.display_errors` | `display_errors` is on, outside debug mode |
| `php.realpath_cache_size` | `realpath_cache_size` is below 4,096,000 bytes (`Info`) |
| `symfony.debug_in_prod` | debug mode is on in the `prod` environment (`Critical`) |
| `symfony.flex_recipes` | Flex recipes are outdated (`Info`) |
| `frankenphp.queue` | requests are waiting for a FrankenPHP thread |
| `frankenphp.worker_queue` | requests are waiting for a worker of a worker script |
| `frankenphp.worker_crashes` | a worker crashed between two readings (`Info` when only since FrankenPHP started) |
| `apache.workers` | 90% or more of the slots of the scoreboard are taken (`Critical` at 100%); the scoreboard can hold more slots than `MaxRequestWorkers` |
| `apache.idle_workers` | no worker is idle (`Info`) |
| `nginx.connections` | open connections reach 85% of `worker_processes` × `worker_connections` (`Critical` from 95%); needs the settings from `nginx -T` |
| `nginx.refused` | Nginx refused connections between two readings (`Info` when only since it started) |
| `mysql.connections` | the server holds 80% of its `max_connections` or more (`Critical` from 95%) |
| `mysql.buffer_pool_hit_rate` | the buffer pool hit rate is below 95%, over at least 1,000 reads between two readings, otherwise since the server started once it has run an hour |
| `mysql.disk_temporary_tables` | 25% or more of the temporary tables were written to disk, over at least 100 tables between two readings, otherwise since the server started once it has run an hour |
| `mysql.table_lock_waits` | 5% or more of the table locks had to wait, over at least 100 locks between two readings |
| `postgresql.connections` | the server holds 80% of its `max_connections` or more, every database included (`Critical` from 95%) |
| `postgresql.cache_hit_rate` | the cache hit rate of the database is below 95%, over at least 10,000 blocks between two readings, otherwise over at least 100,000 blocks since the statistics were reset |
| `postgresql.rollbacks` | 10% or more of the transactions were rolled back, over at least 100 transactions between two readings |
| `postgresql.dead_rows` | 20% or more of the rows of the schema are dead, and at least 10,000 |
| `postgresql.idle_in_transaction` | a session has been idle in a transaction for 5 minutes or more; for a role without privilege, among the sessions it can read |
| `postgresql.blocked_sessions` | a session has been waiting for a lock for 30 seconds or more; for a role without privilege, among the sessions it can read |
| `postgresql.autovacuum_off` | `autovacuum` or `track_counts` is off (`Critical`) |
| `postgresql.limited_role` | the role of the connection cannot see the state of the sessions of other roles (`Info`) |
| `redis.memory` | under a policy that has nothing to evict, `noeviction` or a `volatile-*` one while no key has an expiry, the server uses 90% of its `maxmemory` or more (`Critical` from 98%) |
| `redis.evictions` | keys were evicted between two readings (`Info`) |
| `redis.rejected_connections` | connections were refused between two readings |
| `redis.rdb_failed` | the last RDB snapshot failed (`Critical`) |
| `redis.aof_failed` | the last rewrite of the AOF failed (`Critical`) |
| `redis.hit_rate` | the hit rate is below 80%, over at least 1,000 lookups between two readings (`Info`) |
| `php.end_of_life`, `symfony.end_of_life`, `apache.end_of_life`, `nginx.end_of_life`, `mysql.end_of_life`, `postgresql.end_of_life`, `redis.end_of_life` | the version has reached its end of life (`Critical`) |
| `php.security_only`, `symfony.security_only`, `apache.security_only`, `nginx.security_only`, `mysql.security_only`, `postgresql.security_only`, `redis.security_only` | the version only receives security fixes |
| `system.failed`, `php.failed`, `symfony.failed` | the component could not be read |
| `mysql.failed`, `postgresql.failed`, `redis.failed` | the server cannot be reached (`Critical`), or could not be read |
| `frankenphp.failed`, `apache.failed`, `nginx.failed` | the metrics or the status page could not be read (`Info`; a `Warning` when the component itself could not be read) |
| `symfony.flex.failed` | `composer recipes` could not run (`Info`) |
| `nginx.config.failed` | `nginx -V` or `nginx -T` ran longer than its time limit (`Info`) |
| `frankenphp.version.failed` | `frankenphp version` could not run (`Info`) |
| `mysql.variables.failed`, `mysql.sizes.failed`, `mysql.slow_queries.failed` | the settings of the server, the size of the database or its slowest queries could not be read (`Info`) |
| `postgresql.settings.failed`, `postgresql.database.failed`, `postgresql.slow_queries.failed` | the settings of the server, the tables of the database or its slowest statements could not be read (`Info`) |

A check is a `Warning` unless the table says otherwise. A component that was read before but cannot be read again keeps its last reading on its card, and its `.failed` check says why. A database server that cannot be reached is `Critical`: the application cannot reach it either. The support status of MySQL and MariaDB is not looked up for Percona Server, TiDB and Aurora, whose versions follow cycles of their own.

### The queues of the Background screen

At the top of the `Messages` section of the Background screen, the `Queues` block shows how many messages wait in each Messenger transport. Like the Stack screen, it is read live: it needs neither a database nor the recording. Without them, the screen still shows, with this block, and says once, above its sections, `No storage configured` or `Recording is off`. Below the block, the recorded messages; when they are not recorded, the section says `Messages are not recorded.`

The count is kept 30 seconds, shared by all your web servers through the cache: however many people watch the screen, the transports are counted at most once every 30 seconds. `Refresh` on the Stack screen forgets the count too, so the next display of the Background screen counts them again. The first count starts a second or two after the page opens, which shows `Counting the queues…` until then.

A transport that cannot be counted shows `Uncountable`, with the reason under it:

- `this transport cannot count`: it does not implement `MessageCountAwareInterface`, like the in-memory transport;
- the error of the transport, such as a broker that cannot be reached, without the credentials of the addresses it names;
- `did not answer in time`: the count took longer than 3 seconds;
- `not counted: an earlier transport did not answer in time`.

The transports are counted by the console of your application, `bin/console jmonitor:stack:queues`, a hidden command started in a process of its own, so that a transport that hangs cannot hold the page. It needs:

- `bin/console` in the project directory;
- a PHP of the command line that the web server can run. It is found as Symfony does, through `PhpExecutableFinder`. FrankenPHP alone is not enough: its image must also ship `php`, as the official one does.

Counting a transport connects to it as `messenger:stats` does: a Doctrine transport left with `auto_setup` creates its table on the first count, and an AMQP transport left with `auto_setup` declares its queues. The scheduler and `sync://` transports are left out, and at most 50 transports are counted.

A count that fails as a whole, because the command fails or lists nothing within 3 seconds, shows `The queues could not be counted` with the reason and the time of the next try: 10 minutes later, or an hour later when `bin/console` or the PHP of the command line is missing. `cache:pool:clear jmonitor.stack.cache` asks again at once.

Set `stack.symfony.messenger: false` to turn the count off: the block goes, and the command is never started. The same holds when Messenger has no transport to count. Without the block and with `recording.messenger: false`, the `Messages` section goes too.

### Showing the screens inside EasyAdmin

If your application has EasyAdmin 5.10.2 or later, the screens can be shown inside its back office. Nothing else to install, and the `/jmonitor` route import is not needed for these pages. Turn the option on:

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

When you turn the option off, remove the link too: EasyAdmin fails on a link to a route that no longer exists.

The pages live under the path of each dashboard: `/admin/jmonitor`, `/admin/jmonitor/queries`… for a dashboard at `/admin`. Their route is named after the route of the dashboard followed by `_jmonitor_index`: `admin_jmonitor_index` for a dashboard whose route is `admin`. A dashboard at `/` puts these pages at `/jmonitor`, the address of the standalone page: the first of the two declared wins, so give the `/jmonitor` route import another prefix, or leave it out. The screens follow the light or dark theme of the back office, even when it is switched.

Turned on without EasyAdmin, or with an older version, the option stops the container build with a message that says why. The option cannot be read from an environment variable, since its routes are fixed when the container is built; `permission` can.

Access: the pages sit under the path of the dashboard, so the `access_control` rule that protects your back office, such as one on `^/admin`, covers them. A protection that does not depend on the path, such as an `#[IsGranted]` attribute on the dashboard controller, does not: set `permission`, or add an `access_control` rule on `^/admin/jmonitor`.

`permission` is checked on top of it, on every page, refresh and action. It needs the SecurityBundle. Write the block below instead of `easyadmin: true`, and give the menu link the same permission to hide it from the others:

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    easyadmin:
        permission: ROLE_ADMIN
```

```php
yield MenuItem::linkToRoute('JMonitor', 'internal:desktop', 'admin_jmonitor_index')
    ->setPermission('ROLE_ADMIN');
```

**Without `permission`, every user of the back office sees the SQL queries of your application and the identifiers of its users.** The pages exist under every dashboard of your application, including one opened to less trusted users: set `permission`, or add an `access_control` rule on `^/<dashboard path>/jmonitor`. A `permission` read from an environment variable that turns out empty refuses the pages to everyone.

`dashboard.enabled` and `dashboard.permission` only apply to the `/jmonitor` page.

These pages are not recorded, even when access is refused. When your application has a `csp_nonce` Twig function, the stylesheet and the script of the screens carry its nonce. With the option off, the bundle adds nothing to EasyAdmin.

#### Coming from jmonitor/easyadmin-bundle

To show these screens in a back office that uses `jmonitor/easyadmin-bundle`, delete the `jmonitor_easyadmin` configuration first, since Symfony refuses to boot with a configuration no bundle reads, then swap the packages, update EasyAdmin to 5.10.2 or later if needed, and turn `jmonitor.easyadmin` on as above. The option refuses to work while `jmonitor/easyadmin-bundle` is installed, since both declare the same routes:

```bash
composer remove jmonitor/easyadmin-bundle
composer require jmonitor/jmonitor-bundle
```

| `jmonitor_easyadmin` | Here |
|---|---|
| `permission` | `jmonitor.easyadmin.permission` |
| `components.system.enabled`, `components.php.enabled`, `components.symfony.enabled`, `components.symfony.flex` | `jmonitor.stack.system`, `jmonitor.stack.php`, `jmonitor.stack.symfony.enabled`, `jmonitor.stack.symfony.flex` |
| `components.frankenphp`, `components.apache`, `components.nginx` (`enabled` and `endpoint`) | `jmonitor.stack.frankenphp`, `jmonitor.stack.apache`, `jmonitor.stack.nginx` (`enabled` and `endpoint`) |
| `components.symfony.messenger` | `jmonitor.stack.symfony.messenger` |
| `components.mysql.enabled`, `components.mysql.connections` | `jmonitor.stack.mysql.enabled`, `jmonitor.stack.mysql.connections` |
| `components.postgresql.enabled`, `components.postgresql.connections`, `components.postgresql.schema` | `jmonitor.stack.postgresql.enabled`, `jmonitor.stack.postgresql.connections`, `jmonitor.stack.postgresql.schema` |
| `components.redis.enabled`, `components.redis.connections` given as DSN | `jmonitor.stack.redis.enabled`, `jmonitor.stack.redis.connections` |
| `exclude_connections` | `jmonitor.stack.exclude_connections` |
| `cache_pool`, `polling_interval`, `static_ttl`, the `schema` of a single PostgreSQL connection, a Redis connection given as `{ service: … }`, the other `components.*` | no equivalent |

The menu link and its route name, `admin_jmonitor_index` for a dashboard whose route is `admin`, do not change. The addresses of the former tabs, such as `/admin/jmonitor/mysql`, answer 404: the detail of MySQL is now at `/admin/jmonitor/stack/mysql`, those of PostgreSQL and Redis at `/admin/jmonitor/stack/postgresql` and `/admin/jmonitor/stack/redis`.

The pages show the screens of your application listed above: Requests, Queries, Cache & HTTP, Exceptions, Users and Background. The System, PHP, Symfony, FrankenPHP, Apache, Nginx, MySQL, PostgreSQL and Redis tabs of `jmonitor/easyadmin-bundle` 1.x have their equivalent on the Stack screen (see [The live stack](#the-live-stack), [The databases](#the-databases) and [Redis](#redis)), and its Messenger transports are counted on the Background screen (see [The queues of the Background screen](#the-queues-of-the-background-screen)). Its Cloudflare tab has no equivalent yet.

Recording starts by default and creates the `jmonitor_aggregates` and `jmonitor_keys` tables in the default Doctrine connection; `recording.enabled: false` prevents it.

### Showing the screens inside another page

The screens can also be shown inside a page of your own, such as a back office, with its routes, layout and access control. This interface may still change before 3.0.

The `Jmonitor\JmonitorBundle\Embed\JmonitorPage` service offers four calls. `page()`, `fragment()` and `action()` take the request, the path of a screen (`''` for the default screen, `queries`, `background`…) and your URLs:

- `page()` returns a `PageContent`: the `html` to show (the navigation between the screens and the current screen), its `title`, the `stylesheets` and `scripts` to load and the `headers` to put on your response;
- `fragment()` returns the `Response` the page fetches to refresh itself;
- `action()` runs an action of a screen, posted from the page, and returns a `303` redirect to that screen; it answers 404 when the screen has no such action, and 403 when the request fails the check described below;
- `doNotRecord()` marks the request as not recorded; the three calls above do it too.

An unknown path answers 404. Without the TwigBundle, `page()` and `fragment()` answer 404.

Your application provides:

- three routes with a free path parameter: one for the page, one for the fragment and one for the actions, which accepts `POST`;
- an implementation of `Jmonitor\JmonitorBundle\Embed\HostUrlsInterface` that generates their URLs, with the entries of `$query` (`period`, `sort`, `dir`, `connection`) as query-string parameters of the same names, since the screens read them from the query string of your request;
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
