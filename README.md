# Jmonitor Bundle

Integration of the *jmonitor/collector* library into Symfony to collect metrics from your stack (Php, MySQL, Apache / Nginx / Caddy / FrankenPHP, Redis...) and send them to Jmonitor.io.

- Jmonitor.io: https://jmonitor.io
- Collector library (standalone): https://github.com/jmonitor/collector

## Requirements
- PHP 8.1+ for this bundle. (The standalone collector library supports PHP 7.4.)
- Symfony 6.4+.

## Quick Start

1) Install the bundle:

```bash
composer require jmonitor/jmonitor-bundle
```

2) Create a project on https://jmonitor.io and copy your Project API key.
3) Configure your API key and collectors.


```dotenv
# .env
JMONITOR_API_KEY=your_api_key
```

```yaml
# config/packages/jmonitor.yaml
jmonitor:
    project_api_key: '%env(JMONITOR_API_KEY)%'

    # Optional: use a specific logger service (Symfony's default is "logger").
    # See "Debugging" section below for more informations.
    # logger: 'logger'

    # Optional: provide a custom HTTP client service
    # http_client: 'http_client'
    
    # Enable the collectors you want to use (remove the unused ones).
    # Refer to the collector library for deeper collector-specific doc: https://github.com/jmonitor/collector
    collectors:
        
        # Cpu, Ram, Disk of the server. Linux only.
        system: ~
        # You can use a RandomAdapter on Windows for testing purpose.
        # system:
        #     adapter: 'Jmonitor\\Collector\\System\\Adapter\\RandomAdapter'
        
        # Apache via mod_status.
        # for more information, see https://github.com/jmonitor/collector?tab=readme-ov-file#apache
        apache:
            server_status_url: 'https://localhost/server-status'  
        
        # MySQL variables and status
        mysql:
            db_name: 'your_db_name'
            
        # PHP : some ini keys, apcu, opcache, loaded extensions... 
        # /!\ See below for more informations about CLI vs Web-context metrics
        # use this to collect web metrics
        php: 
            endpoint: 'http://localhost/php-metrics'
        # of for CLI only
        # php: ~
        
        # symfony: some infos, loaded bundles, flex recipes, schedules...
        # you can disable some components by setting them to false
        # symfony
        #     flex: false
        #     scheduler: true
        #     messenger: true
        # or let the bundle auto-detect
        symfony: ~
        # you can provide the recipes command if the default one does not suit you
        # symfony:
        #     flex:
        #         command: "composer.phar recipes -o" # default is "composer recipes -o"
        

        # Redis metrics via INFO command
        redis:
            # you can use either DSN or a service name (adapter). 
            # dsn: '%env(SOME_REDIS_DSN)%'
            # adapter: 'some_redis_service_name'
            
        # Metrics from Caddy / FrankenPHP
        # see https://caddyserver.com/docs/metrics
        caddy:
            endpoint: 'http://localhost:2019/metrics'
```
4) Run a collection manually to verify:
```bash
php bin/console jmonitor:collect -vvv --dry-run
```

## Exposing PHP metrics

Why this matters:
- PHP settings and extensions can differ significantly between CLI and your web server context.
- If you want metrics that reflect your web runtime, you must expose a tiny HTTP endpoint that returns PHP metrics from within that web context.

To do that, create a route config file: 

```yaml
# config/routes/jmonitor.yaml
jmonitor_expose_php_metrics:
    path: '/jmonitor/php-metrics'
    controller: Jmonitor\JmonitorBundle\Controller\JmonitorPhpController

# SECURE THIS ROUTE IN PRODUCTION
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

## Running the collector

```bash
php bin/console jmonitor:collect
```

This command runs as a long-lived worker: it periodically collects metrics from the enabled collectors and sends them to Jmonitor.io.

You can also limit how long it runs:
- `--memory-limit`: stop when the process memory usage exceeds the given limit (e.g. `128M`)
- `--time-limit`: stop after the given number of seconds

    ```bash
    php bin/console jmonitor:collect --memory-limit=128M --time-limit=3600
    ```
In production, it is recommended to run this command under a process manager (e.g. Supervisor or systemd) to ensure it is kept running and restarted if necessary.
For practical guidance, you can follow Symfony Messenger's recommendations:
https://symfony.com/doc/current/messenger.html#deploying-to-production


## Logging and Debugging
- The command is resilient: individual collector failures do not crash the whole run; errors are logged (logging must be enabled in config).
- Log levels:
    - Errors (collector exceptions, HTTP responses with status >= 400): error
    - Collected metrics: debug
    - Summary: info

Useful commands:
```bash
# Verbose with debug logs
php bin/console jmonitor:collect -vvv

# Dry-run (collect but do not send)
php bin/console jmonitor:collect -vvv --dry-run

# Only summary
php bin/console jmonitor:collect -vv
```

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
