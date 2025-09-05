# Jmonitor Bundle

Integration of the [jmonitor/collector](https://github.com/jmonitor/collector), library into Symfony.  

[Jmonitor.io](https://jmonitor.io) is a SaaS monitoring service that provides insights, alerting and premade dashboards from multiple sources commonly found in PHP web project stack (MySQL, Redis, Apache, Nginx, etc.).

This bundle uses **Symfony Scheduler** to send metrics to Jmonitor.io every 15 seconds.  
You can still use it without it, but you will need to set up a cron by yourself.

## Requirements
- PHP 8.1 for the bundle. You can still use Jmonitor with PHP 7.4 with the standalone library.
- Symfony 6.4 or higher.
- [recommended] Symfony Scheduler: https://symfony.com/doc/current/scheduler.html 

## Installation

```bash
composer require jmonitor/jmonitor-bundle
```
## Configuration

Create a project in [jmonitor.io](https://jmonitor.io) and get your API key.  

For more information about collectors (not only), see the readme on [jmonitor/collector](https://github.com/jmonitor/collector). 

### .env
```yaml
JMONITOR_API_KEY=your_api_key
```

### config/packages/jmonitor.yaml
```yaml
jmonitor:
    enabled: true
    project_api_key: '%env(JMONITOR_API_KEY)%'

    # Optional: remove it if you do not want to use symfony scheduler.
    schedule: 'default' 
    
    # Optional: Logger for the collector command. Remove it if you do not want to use logging.
    # See "Debugging" section below for more informations.
    # logger: 'logger'
    
    # Optional: you can use a custom HTTP client.
    # http_client: 'some_http_client'
    
    # Enable the collectors you want to use (remove the unused ones).
    collectors:
        
        # Cpu, Ram, Disk of the server. Linux only.
        system: ~
        # You can use a RandomAdapter on Windows for testing purpose.
        # system:
        #     adapter: 'Jmonitor\\Collector\\System\\Adapter\\RandomAdapter'
        
        # Apache via mod_status.
        apache:
            server_status_url: 'https://localhost/server-status' # for more informations, see https://github.com/jmonitor/collector?tab=readme-ov-file#apache 
        
        # MySQL variables and status
        mysql:
            db_name: 'your_db_name'
            
        # Some ini keys, opcache, loaded extensions...
        php: ~
        
        # Redis metrics via INFO command
        redis:
            # you can use either DSN or a service name (adapter). 
            # Remove the unused one.
            dsn: '%env(SOME_REDIS_DSN)%'
            adapter: 'some_redis_service_name'
            
        # Metrics from FrankenPHP and Caddy.
        # see https://frankenphp.dev/docs/metrics/ 
        frankenphp:
            endpoint: 'http://localhost:2019/metrics'
            
        # see https://caddyserver.com/docs/metrics
        caddy:
            endpoint: 'http://localhost:2019/metrics'
```
## Scheduling

The collecting of metrics is done by a console command: `jmonitor:collect`.  

```bash
php bin/console jmonitor:collect
```

With scheduler enabled, it will run every 15 seconds (see `schedule` option in config). 

Otherwise, you will need to manually call the command in a cron or something.

## Debugging and Error Handling
To avoid breaking collecting if one collector fails, the command will avoid throwing exceptions if possible, but will log them.
Collector's errors are logged in error level, as well as http response errors (statusCode >= 400).

The collected metrics are logged in debug level, and the summary is logged in info level.

You can manually trigger the collecting via a Console command and see the logs.

```bash
# use -vvv to see debug logs. 
php bin/console jmonitor:collect -vvv

# use --dry-run to disable sending. 
php bin/console jmonitor:collect -vvv --dry-run

# Only output the summary
php bin/console jmonitor:collect -vv
```
