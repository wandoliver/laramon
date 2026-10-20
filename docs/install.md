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

## Upgrading to 0.4.4

This is a hub-only update. The bundled agent remains at 0.4.0; monitored applications need no update for the transfer state or incident reasons. There are no dependency or environment changes since 0.4.3.

1. Back up the hub database and deploy the 0.4.4 hub code with the included `public/build/` assets through your normal deployment process. The server does not need Node or a separate frontend build.
2. Install production dependencies and run `php artisan migrate --force`. The new migration is `2026_10_20_000002_add_alert_event_reasons`; it adds nullable reason fields without rewriting old incident causes.
3. Rebuild caches and reload the web PHP process. Keep the existing hub scheduler running every minute. No new command, queue worker or notification channel is required.
4. Check **Monitoring data flow** and **Alerts**. Normal transfers can show **Export in progress**. Actual data-flow incidents display their initial reason and a different latest reason when applicable. Older incidents without a stored cause show **Reason was not recorded.**

Example deployment commands for PHP 8.4-FPM:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo service php8.4-fpm reload
```

Use the equivalent reload/restart for your hosting setup and PHP version. Existing incident history, rule thresholds, notification destinations, cooldowns and configured delays are preserved. The default alarm and recovery delays remain **10** and **5** minutes; this release does not reset existing rules.

The neutral transfer state requires a fresh, previously complete confirmation, a fresh heartbeat and accepted export, and valid clocks. It applies only while less than five minutes have elapsed since the first chunk was received by the hub. Subsequent chunks, retries and old confirmations cannot renew that deadline. Missing or invalid timing metadata receives no new grace period. Missing, stale or invalid proof, heartbeat or export data still produces an unverified or unhealthy state; an overdue transfer is an actual data-flow issue.

A bounded normal transfer does not create a data-flow incident or send its alarm, and it does not verify metric totals or missing records. An existing data-flow recovery candidate can retain its start time across this state, but recovery cannot start or finish during the transfer. The hub still needs healthy flow and fresh, advancing final confirmation after the required recovery delay. Missing, stale, invalid or overdue data cancels recovery, and scheduler evaluation gaps longer than two minutes restart continuity checks. Other metric breaches continue to be evaluated. See [Monitoring data flow](monitoring-data-flow.md) and [Alert notification channels](alert-notifications.md) for the full behavior.

Incident reasons use fixed server-defined descriptions. Initial causes are preserved once recorded; later actual failures update the latest reason. Successful or in-progress exports do not overwrite the last failure reason. The update does not reconstruct causes for historical incidents.

If upgrading from older versions, also follow the [0.4.3 delay checklist](#upgrading-to-043), [0.4.2 history display notes](#upgrading-to-042), [0.4.1 notification upgrade](#upgrading-to-041) and [0.4.0 scheduler/data-flow upgrade](#upgrading-to-040).

## Upgrading to 0.4.3

This is a hub-only update. The bundled agent remains at 0.4.0; monitored applications need no update for notification delays. There are no dependency or environment changes since 0.4.2.

1. Back up the hub database and deploy the 0.4.3 hub code with the included `public/build/` assets through your normal deployment process. The server does not need Node or a separate frontend build.
2. Install production dependencies and run `php artisan migrate --force`. The new migration is `2026_10_20_000001_add_alert_notification_delays`.
3. Rebuild caches and reload the web PHP process. Keep the existing hub scheduler running every minute. Notification delivery remains synchronous; no queue worker or additional scheduled command is needed.
4. In **Alerts**, review **Alarm delay (minutes)** and **Recovery delay (minutes)** for each rule. Both new and existing rules receive defaults of **10** and **5** respectively. Values must be whole minutes from **0 to 1440**; **0** means immediate action.

Example deployment commands for PHP 8.4-FPM:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo service php8.4-fpm reload
```

Use the equivalent reload/restart for your hosting setup and PHP version. The migration retains thresholds, notification destinations, cooldown settings and incident history. It marks pre-update incidents as already attempted, preventing old alarms from being replayed. Those open incidents still require newly confirmed recovery checks and the configured recovery delay; recovery messages for pre-update incidents are skipped because their original notification destinations cannot be verified.

**The alarm delay is additional to the rule condition.** For example, a 10-minute heartbeat timeout followed by the default 10-minute alarm delay means about 20 minutes without a heartbeat before external notification. For metric rules, the configured observation window is evaluated first, and the breach must then persist for the alarm delay. Review critical rules rather than assuming the migration preserves their previous notification timing.

Internal incidents appear immediately. A six-minute data-flow interruption that then remains confirmed healthy closes internally after recovery confirmation, without an alarm/recovery message pair. Recovery requires fresh, advancing confirmation throughout its delay: missing or stale data resets that timer. Evaluation gaps longer than two minutes also restart continuity checks. The cooldown spaces external alarm attempts separately from these delays. Existing no-retry behavior remains in place.

A recovery message is sent only to channels that successfully received the original alarm and still have matching destinations. Use **Test** to verify configured channels; it sends immediately and bypasses delays and cooldowns. See [Alert notification channels](alert-notifications.md) for the full rule and delivery behavior.

If upgrading from older versions, also follow the [0.4.2 history display notes](#upgrading-to-042), [0.4.1 notification upgrade](#upgrading-to-041), including its two migrations and SMTP guidance, and [0.4.0 scheduler/data-flow upgrade](#upgrading-to-040).

## Upgrading to 0.4.2

This is a hub-only update. The bundled agent remains at 0.4.0, and applications already using it need no update for the fleet history display. There are no new migrations, dependency updates, or configuration changes since 0.4.1.

1. Deploy the 0.4.2 hub code and the included `public/build/` assets through your normal deployment process. The server does not need Node or a separate frontend build.
2. Rebuild caches and reload the web PHP process. Keep the hub scheduler running; no additional server setup is required for this display change.

Example cache/reload commands for PHP 8.4-FPM:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo service php8.4-fpm reload
```

Use the equivalent reload/restart for your hosting setup and PHP version. Your normal dependency-installation and migration deployment steps can remain in place. If upgrading from before 0.4.1, also complete the [0.4.1 notification upgrade](#upgrading-to-041), including its two migrations. Older agents still need the [0.4.0 agent upgrade](#upgrading-to-040) to confirm completeness.

After deployment, each fleet card distinguishes **Export coverage: Confirmed** for the displayed interval from confirmed coverage of its 24-hour totals window. The history indicator shows a duration and progress bar, for example **6h 40m of 24h confirmed**, plus **17h 20m still unconfirmed**. A stale, pending, missing, or interrupted confirmation leaves progress unverified. Even with 24 hours of confirmed time, metric types without their own coverage remain unconfirmed. Missing data never establishes zero errors or automatic recovery. See [Monitoring data flow and scheduled tasks](monitoring-data-flow.md) for the coverage rules.

## Upgrading to 0.4.1

This is a hub-only update. The bundled agent remains at 0.4.0; applications already using that agent need no update for notification channels or the metric dropdown.

1. Back up the hub database and deploy the 0.4.1 hub code through your normal deployment process.
2. Install production dependencies and run the two new migrations: `2026_10_19_000001_add_alert_notification_channels` and `2026_10_19_000002_create_alert_email_templates_table`. Existing Teams-only rules retain their webhook. No email recipients are added automatically.
3. For email, configure a real mail transport in the hub's `.env`. SMTP uses `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. Use `MAIL_SCHEME=smtp` for an SMTP connection with STARTTLS negotiation (commonly port 587), or `MAIL_SCHEME=smtps` for implicit TLS (commonly port 465), as specified by your provider. `MAIL_ENCRYPTION` is not read by the shipped mail configuration. The default `log` mailer does not deliver alert email.
4. Rebuild caches and reload the web PHP process so both CLI and browser requests load the new code and configuration. Keep the hub scheduler running.

Example deployment commands for PHP 8.4-FPM:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo service php8.4-fpm reload
```

Use the equivalent reload/restart for your hosting setup and PHP version. SMTP credentials stay in server configuration; never commit them.

5. In **Alerts**, set each rule's **Email recipients**, optional **Teams webhook URL**, or neither for internal-only incidents. Use **Test** and verify actual receipt. Edit shared alarm/recovery subjects and messages under **Alerts → Email templates**. Both `job:failed` and `scheduled_task:failed` are now available before any failures have been received.

Delivery status is recorded separately for each channel and for trigger/recovery. A failed delivery is visible in Recent events but is not retried automatically. Existing alert conditions, cooldowns, confirmed-data recovery checks, and manual resolution are unchanged. See [Alert notification channels](alert-notifications.md) for details.

## Upgrading to 0.4.0

1. Deploy the 0.4.0 hub first, following your normal Composer, migration, cache, and process-reload deployment steps. The scheduler/data-flow changes add no new database migration to an existing installation.
2. Update the agent in each monitored application to the 0.4.0 code through that application's dependency/deployment process. Updating the hub alone does not update installed agents.
3. If the application has a published `config/monitor-agent.php`, merge the new defaults into it. Retain the `scheduled_task_last_success` and `scheduled_task_last_failure` entries in `pulse_types`; configure `expected_scheduled_tasks` only for critical named tasks. Keep existing application settings and collectors.
4. Rebuild configuration caches and restart long-running application workers through your normal deployment process so they load the updated agent and configuration. Keep both schedulers running. Verify connectivity with `php artisan monitor-agent:test`.
5. Check **Monitoring data flow** on the instance page. Fresh heartbeats and accepted exports can appear before completeness is verified. The agent and hub first establish new five-minute observation baselines; wait for continuous confirmed intervals. Metric alert recovery additionally needs the rule's full window. History is not retroactively declared complete.
6. Create a rule in **Alerts** using **data flow (missing, stale or incomplete)** and select its instance scope. In 0.4.1, both external channels are optional: enter email recipients, a compatible Teams Workflows webhook URL, both, or neither for internal-only incidents. Configure any chosen external service separately and use **Test** to verify delivery. No rule, recipients, or webhook is created by deployment.

Older agents remain compatible with the ingest and heartbeat APIs. Their received history remains visible, but completeness stays unverified and cannot automatically resolve an incident. An empty chart or missing record is never proof that no errors occurred.

See [Monitoring data flow and scheduled tasks](monitoring-data-flow.md) for expected-run examples, completeness limitations, and recovery requirements.
