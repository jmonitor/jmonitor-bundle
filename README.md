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
      <a href="https://jmonitor.io">Website and cloud edition</a> ·
      <a href="https://hub.docker.com/r/jmonitor/jmonitor">Docker Hub image for self-hosting</a>
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
- Symfony component collectors (flex, scheduler, messenger) that fail at worker startup are disabled for the lifetime of that worker process and an error is logged. Restart the worker to re-enable them.
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
