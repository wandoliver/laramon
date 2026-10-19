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

The monitored application's Laravel scheduler must run every minute as well:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

The agent's default export interval is five minutes. Confirmed completeness requires Pulse's direct `storage` ingest driver and `database` storage driver, with enabled, fully sampled supported recorders. Redis or custom Pulse pipelines do not confirm completeness. The exporter reads the configured `pulse.storage.database.connection`.

## Upgrading to 0.4.0

1. Deploy the 0.4.0 hub first, following your normal Composer, migration, cache, and process-reload deployment steps. The scheduler/data-flow changes add no new database migration to an existing installation.
2. Update the agent in each monitored application to the 0.4.0 code through that application's dependency/deployment process. Updating the hub alone does not update installed agents.
3. If the application has a published `config/monitor-agent.php`, merge the new defaults into it. Retain the `scheduled_task_last_success` and `scheduled_task_last_failure` entries in `pulse_types`; configure `expected_scheduled_tasks` only for critical named tasks. Keep existing application settings and collectors.
4. Rebuild configuration caches and restart long-running application workers through your normal deployment process so they load the updated agent and configuration. Keep both schedulers running. Verify connectivity with `php artisan monitor-agent:test`.
5. Check **Monitoring data flow** on the instance page. Fresh heartbeats and accepted exports can appear before completeness is verified. The agent and hub first establish new five-minute observation baselines; wait for continuous confirmed intervals. Metric alert recovery additionally needs the rule's full window. History is not retroactively declared complete.
6. To enable notifications, create a rule in **Alerts** using **data flow (missing, stale or incomplete)** and select its instance scope. The form requires a compatible Teams Workflows webhook URL, which you must configure separately in Teams. No rule or webhook is created by deployment. Use **Test** to verify delivery.

Older agents remain compatible with the ingest and heartbeat APIs. Their received history remains visible, but completeness stays unverified and cannot automatically resolve an incident. An empty chart or missing record is never proof that no errors occurred.

See [Monitoring data flow and scheduled tasks](monitoring-data-flow.md) for expected-run examples, completeness limitations, and recovery requirements.
