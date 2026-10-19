# LaraMon

![Release](https://img.shields.io/badge/release-v0.4.0-blue)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Self-hosted fleet monitoring for Laravel applications — a central hub that every instance you operate reports into. Inspired by [Laravel Nightwatch](https://nightwatch.laravel.com), built for teams who want their telemetry on their own infrastructure.

The dashboard branding follows `APP_NAME` (e.g. `APP_NAME="Acme Monitor"` for a white-labeled install); the product itself is **LaraMon**.

## How it works

```
Client server (per app instance)                     Hub server
Laravel app                                          LaraMon hub
 ├─ laravel/pulse (local capture)        HTTP        ├─ POST /api/v1/ingest + /heartbeat
 ├─ monitor agent package           ──────────────▶  ├─ per-instance metric storage
 │   ├─ exports closed Pulse buckets    Bearer       ├─ hourly rollups, retention pruning
 │   ├─ business metric collectors      token        └─ Livewire dashboard:
 │   └─ heartbeat every minute                           fleet overview + drill-down
```

Each monitored application runs [Laravel Pulse](https://pulse.laravel.com) locally as the capture engine plus the lightweight agent from `packages/agent`. Every five minutes the agent reads closed Pulse aggregate buckets past a watermark, re-buckets them to 5-minute resolution, and ships them to the hub over a token-authenticated API. The hub stores everything per instance and renders dashboards for the whole fleet.

**What you get per instance:** request throughput & latency per route, slow queries, slow jobs & outgoing requests, queue depth & failures, scheduled task runs/runtimes/failures, exception trends (grouped by class + location), cache hit ratio, **active users** (who is using the app right now, with online indicators) — and any **business metrics** the app registers (gauges & counters), which appear on the dashboard automatically.

**Alerts:** threshold rules over any collected metric — error rate, queue backlog, slow requests, business gauges, "instance silent for N minutes", or missing/incomplete monitoring data — evaluated every minute with breach/recovery notifications delivered to a configured **Microsoft Teams** Workflows webhook. Missing, stale, or partially exported data cannot automatically resolve an incident. Cooldowns stop flapping metrics from spamming the channel. No rule or webhook is created automatically.

**Drill-downs:** exceptions, slow queries, and slow requests are clickable. The agent sends the latest occurrences per fingerprint to your self-hosted hub — exception message, stack trace, request URL, slow-query SQL, duration, resource timings, and safe `context.*` metadata — so you can triage straight from the dashboard. Request payloads and query bindings are excluded.

**Design choices, deliberately boring:** pre-aggregated snapshots instead of raw event streaming; one wide time-series table; idempotent batches (retries are always safe); the agent never throws — a dead hub can never take a client application down.

## Hub setup

Standard Laravel 13 app: PHP 8.4, MySQL (or SQLite for evaluation), Redis optional.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate

php artisan monitor:make-user "Your Name" you@example.com   # dashboard login
php artisan monitor:make-instance "Client X Production"     # prints the API token once
```

Built frontend assets ship in the repository — servers never need Node. (For development: `npm install`, then `npm run build` and commit `public/build/` after UI changes.)

Run `php artisan schedule:run` from cron (rollups + retention pruning are scheduled).

Want to look around first? `php artisan db:seed --class=DemoSeeder` fills the dashboard with a demo fleet (login: `demo@laramon.test` / `password`).

## Instance setup (the monitored app)

```bash
composer require laravel/pulse laramon/agent
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

```dotenv
PULSE_ENABLED=true
MONITOR_AGENT_ENABLED=true
MONITOR_HUB_URL=https://monitor.example.com
MONITOR_HUB_TOKEN=lm_...   # from monitor:make-instance
```

Verify with `php artisan monitor-agent:test`. The agent schedules itself — no kernel changes needed.

### Business metrics

Register gauges (point-in-time values) and counters (increments) in any service provider:

```php
use LaraMon\Agent\MonitorAgent;

MonitorAgent::gauge('active_users.client', fn () => User::clients()->active()->count());
MonitorAgent::counter('appointments_booked', fn () => Appointment::where('created_at', '>=', now()->subMinutes(5))->count());
```

They appear on the instance dashboard automatically. A failing collector is logged and skipped — never breaking the export. For anything bigger, implement `LaraMon\Agent\Contracts\BusinessMetricCollector` and list it in `config/monitor-agent.php`.

### Active users

The agent records which authenticated users are active, but **the host app decides what the hub gets to see**. Register a resolver mapping user ids to display labels — anything you don't resolve falls back to `User #id`:

```php
MonitorAgent::resolveUsersUsing(fn (array $ids) => User::whereIn('id', $ids)->get()
    ->mapWithKeys(fn ($user) => [$user->id => $user->isStaff() ? $user->name : 'Client #'.$user->id])
    ->all());
```

Disable the feature entirely with `MONITOR_AGENT_TRACK_USERS=false`.

### Scheduled tasks and data flow

The Scheduler table includes successful and failed runs, including tasks with only failures. Background tasks count when they finish, with their actual exit status. Critical, named tasks can opt in to expected-run monitoring through `expected_scheduled_tasks` in the agent configuration.

The instance's **Monitoring data flow** panel separates heartbeat freshness, accepted exports, and confirmed data completeness. A recent heartbeat alone cannot establish health. The agent confirms only settled, fully exported five-minute intervals from supported, fully sampled Pulse recorders; Redis or custom ingest/storage pipelines remain unverified.

See [Monitoring data flow and scheduled tasks](docs/monitoring-data-flow.md) for configuration, alert behavior, upgrade order, and the initial warm-up period.

## Operations notes

- **Health:** healthy requires a fresh heartbeat, accepted exports, and confirmed data completeness. Unknown or stale data cannot establish recovery; an old agent can still export history while its completeness stays unverified. Heartbeats come from the instance's scheduler, so stale heartbeats can indicate an application or scheduler outage.
- **Retention:** 5-minute buckets are kept 7 days, hourly rollups 90 days, occurrence samples 14 days (max 20 per fingerprint).
- **Token rotation:** rotating a token (dashboard → Instances) keeps the old one valid for 7 days.
- **Outages:** if the hub is unreachable, the agent holds its watermark and retries next run. Pulse trims fine-grained data after ~1 hour, so longer outages appear as a marked data gap rather than silent flat-lines.
- **Clocks:** instances should run NTP; the hub clamps wildly skewed buckets and flags observed skew.
- **Upgrades:** deploy the hub first, then update agents. Keep the new Pulse type mappings when merging published configuration. Existing installations need no new database migration for the 0.4.0 scheduler/data-flow changes; use the [upgrade checklist](docs/install.md#upgrading-to-040).

## Development

```bash
php artisan test                          # hub suite
cd packages/agent && vendor/bin/phpunit   # agent suite (Testbench)
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
