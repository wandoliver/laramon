# Installation

This guide expands the quick-start from the README with a generic self-hosted setup.

## Hub

Requirements:

- PHP 8.4 or newer
- MySQL, MariaDB, or SQLite for evaluation
- Redis optional
- A scheduler entry that runs Laravel's scheduler every minute

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan monitor:make-user "Your Name" you@example.com
php artisan monitor:make-instance "Production"
```

The instance command prints an API token once. Store it in the monitored application as `MONITOR_HUB_TOKEN`.

Run the scheduler every minute:

```cron
* * * * * cd /path/to/laramon && php artisan schedule:run >> /dev/null 2>&1
```

## Monitored Application

Install Laravel Pulse and the LaraMon agent:

```bash
composer require laravel/pulse laramon/agent
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

Configure the agent:

```dotenv
PULSE_ENABLED=true
MONITOR_AGENT_ENABLED=true
MONITOR_HUB_URL=https://monitor.example.com
MONITOR_HUB_TOKEN=lm_...
```

Verify connectivity:

```bash
php artisan monitor-agent:test
```

The agent registers its own scheduled commands through Laravel, so no manual kernel wiring is required.
