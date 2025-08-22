# Jmonitor Bundle

Integration of the [jmonitor/collector](https://github.com/jmonitor/collector), library into Symfony.  
[Jmonitor.io](https://jmonitor.io) is a simple monitoring sass for PHP applications and web servers that provides insights and alerting from various sources like MySQL, Redis, Apache, Nginx...

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

### .env
```yaml
JMONITOR_API_KEY=your_api_key
```

### config/packages/jmonitor.yaml
```yaml
jmonitor:
    enabled: true
    project_api_key: '%env(JMONITOR_API_KEY)%'
    schedule: 'default' # Remove it if you do not want to use symfony scheduler.
    
    # Enable the collectors you want to use (remove the unused ones).
    collectors:
        system: ~
        apache:
            server_status_url: 'https://localhost/server-status' # for more informations, see https://github.com/jmonitor/collector?tab=readme-ov-file#apache 
        mysql:
            db_name: 'your_db_name'
        php: ~
        redis: 
            # you can use either DSN or a service name (adapter). 
            # Remove the unused one.
            dsn: '%env(SOME_REDIS_DSN)%'
            adapter: 'some_redis_service_name'
        frankenphp:
            endpoint: 'http://localhost:2019/metrics' # see https://frankenphp.dev/docs/metrics/ and/or https://caddyserver.com/docs/metrics
        caddy:
            endpoint: 'http://localhost:2019/metrics' # see https://caddyserver.com/docs/metrics
```

####  You can customize the HTTP client used by Jmonitor.
```yaml
jmonitor:
    #...
    http_client: 'some_http_client'
```
