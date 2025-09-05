# Jmonitor Bundle

Integration of the *jmonitor/collector* library into Symfony to collect metrics from your stack (Php, MySQL, Apache / Nginx / Caddy / FrankenPHP, Redis...) and send them to Jmonitor.io.

- Jmonitor.io: https://jmonitor.io
- Collector library (standalone): https://github.com/jmonitor/collector

## Requirements
- PHP 8.1+ for this bundle. (The standalone collector library supports PHP 7.4.)
- Symfony 6.4+.
- Optional: Symfony Scheduler (recommended): https://symfony.com/doc/current/scheduler.html 
- Linux recommended for the System collector. On Windows, use the RandomAdapter for testing.

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
    enabled: true
    project_api_key: '%env(JMONITOR_API_KEY)%'

    # Optional: Symfony Scheduler transport name, or remove to disable scheduling.
    schedule: 'default'

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
            
        # Some ini keys, opcache, loaded extensions...
        php: ~
        
        # Redis metrics via INFO command
        redis:
            # you can use either DSN or a service name (adapter). 
            # dsn: '%env(SOME_REDIS_DSN)%'
            # adapter: 'some_redis_service_name'
            
        # Metrics from FrankenPHP
        # see https://frankenphp.dev/docs/metrics/ 
        frankenphp:
            endpoint: 'http://localhost:2019/metrics'

        # Metrics from Caddy
        # see https://caddyserver.com/docs/metrics
        caddy:
            endpoint: 'http://localhost:2019/metrics'
```
4) Run a collection manually to verify:
```bash
php bin/console jmonitor:collect -vvv --dry-run
```

## Scheduling

- Command: `jmonitor:collect`.
- With Symfony Scheduler enabled and schedule configured, the command runs every 15 seconds.
- Without Scheduler, schedule the command yourself (e.g., cron, systemd timers, container orchestration schedules).

```bash
# Run once
php bin/console jmonitor:collect
```

## Logging and Debugging
- The command is resilient: individual collector failures do not crash the whole run; errors are logged.
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
