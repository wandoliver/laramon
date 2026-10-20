# LaraMon

![Release](https://img.shields.io/badge/release-v0.4.4-blue)
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

**Alerts:** threshold rules over metrics or monitoring data flow are evaluated every minute and recorded internally immediately. Per-rule notification delays default to **10 minutes of continuous breach** before an alarm and **5 minutes of continuously confirmed recovery** before resolution; both accept 0 for immediate action. Short incidents stay visible internally without an alarm/recovery message pair. Missing, stale, or partially exported data cannot confirm recovery. Cooldowns space external alarm notifications. Built-in metrics are selectable before the first event arrives. Teams Workflows webhooks and email recipients are optional per rule and can be used together. Central alarm/recovery email templates are editable with a live preview. No rule or notification channel is enabled automatically. See [Alert notification channels](docs/alert-notifications.md) for setup, continuity checks and delivery status.

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

Normal exports show **Export in progress** for less than five minutes from the first chunk received by the hub, provided the previous complete confirmation, heartbeat and accepted export are still fresh and valid. More chunks and retries never renew that deadline. This neutral state does not open a data-flow incident or confirm recovery; recovery still needs fresh, advancing final confirmation. Actual data-flow incidents display the initially observed reason and any different latest reason. Historical incidents without a recorded reason remain explicitly unrecorded.

The fleet shows confirmed coverage of its 24-hour totals window as a duration and progress bar, for example **6h 40m of 24h confirmed** with **17h 20m still unconfirmed**. **Export coverage: Confirmed** refers to the displayed export interval, not automatically the whole day. Stale or missing confirmation and export gaps leave history unverified; individual metric totals can also remain unconfirmed when their types are not covered.

See [Monitoring data flow and scheduled tasks](docs/monitoring-data-flow.md) for configuration, alert behavior, upgrade order, and the initial warm-up period.

## Operations notes

- **Health:** healthy requires a fresh heartbeat, accepted exports, and confirmed data completeness. Unknown or stale data cannot establish recovery; an old agent can still export history while its completeness stays unverified. Heartbeats come from the instance's scheduler, so stale heartbeats can indicate an application or scheduler outage.
- **Retention:** 5-minute buckets are kept 7 days, hourly rollups 90 days, occurrence samples 14 days (max 20 per fingerprint).
- **Token rotation:** rotating a token (dashboard → Instances) keeps the old one valid for 7 days.
- **Outages:** if the hub is unreachable, the agent holds its watermark and retries next run. Pulse trims fine-grained data after ~1 hour, so longer outages appear as a marked data gap rather than silent flat-lines.
- **Clocks:** instances should run NTP; the hub clamps wildly skewed buckets and flags observed skew.
- **Upgrades:** version 0.4.4 updates the hub only and adds one migration for incident reasons. Existing rule thresholds, destinations, cooldowns and configured notification delays are preserved. The bundled agent remains at 0.4.0, with no dependency or environment changes. Deploy the included frontend assets, run migrations, rebuild caches, and reload PHP-FPM through your normal deployment process; see the [0.4.4 upgrade checklist](docs/install.md#upgrading-to-044). When upgrading from older versions, also follow the [0.4.3 delay checklist](docs/install.md#upgrading-to-043), [0.4.2 history display notes](docs/install.md#upgrading-to-042), [0.4.1 notification checklist](docs/install.md#upgrading-to-041) and [0.4.0 scheduler/data-flow checklist](docs/install.md#upgrading-to-040).

## Development

```bash
php artisan test                          # hub suite
cd packages/agent && vendor/bin/phpunit   # agent suite (Testbench)
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
