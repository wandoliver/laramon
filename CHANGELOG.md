# Changelog

All notable changes to LaraMon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.4] - 2026-10-20

### Added

- A neutral **Export in progress** state for normal transfers with fresh prior completeness confirmation, heartbeat and accepted export. The hub grants less than five minutes from its first chunk receipt; subsequent chunks and retries cannot extend the deadline.
- Recorded initial and latest data-flow incident reasons, displayed using a fixed set of server-defined descriptions. Historical incidents without a recorded reason show **Reason was not recorded.**
- Regression tests for bounded transfers, immutable deadlines, invalid or stale proofs, strict recovery, incident reason rendering, and unaffected metric breaches.

### Changed

- A bounded normal transfer does not open a data-flow incident or send its alarm. It may preserve an existing recovery candidate, but cannot start or complete recovery. Resolution still requires healthy flow and fresh, advancing final confirmation; missing, stale, invalid or overdue data cannot confirm recovery.
- Undelivered alarms show **held during recovery** while recovery is being confirmed.

### Fixed

- Routine chunked exports no longer create transient data-flow incidents or repeatedly interrupt otherwise valid recovery candidates. Real heartbeat, clock, completeness and export failures remain visible and prevent recovery.

This release updates the hub only and adds `2026_10_20_000002_add_alert_event_reasons`. The migration adds nullable reason fields without inventing historical causes. Existing thresholds, destinations, cooldowns and configured notification delays are preserved; the defaults remain 10 minutes before an alarm and 5 minutes before recovery. The bundled agent remains at 0.4.0. No dependency, environment or agent configuration changes are required. See the [upgrade checklist](docs/install.md#upgrading-to-044).

## [0.4.3] - 2026-10-20

### Added

- Per-rule alarm and recovery delays, defaulting to 10 and 5 minutes respectively. Each accepts whole minutes from 0 to 1440; 0 means immediate action.
- Visible waiting, recovery-confirmation, cooldown and suppressed-delivery states in alert history, while incidents are recorded internally as soon as a breach is observed.
- Regression tests for delayed notification and recovery, interrupted or unverified observations, independent instance timers, cooldowns, legacy incidents, changed destinations, duplicate evaluation, and delay settings.

### Changed

- Alarm delivery requires a continuously breached condition for its configured delay. Recovery requires continuously confirmed healthy data and fresh, advancing coverage; missing or stale data resets recovery confirmation. A scheduler evaluation gap longer than two minutes resets continuity timers.
- Recovery notifications are sent only to channels that successfully received the alarm and whose destinations still match. Short incidents close internally without an alarm/recovery message pair.
- Cooldowns space external alarm attempts rather than hiding internal incidents. Rule edits, disabling and re-enabling restart continuity checks for open incidents. The Test action still sends immediately.

### Fixed

- Notification attempts are claimed before delivery to prevent duplicate sends during overlapping evaluations. Transport failures remain visible without automatic retries.
- The migration marks historical incidents as already attempted so deployment cannot replay old alarms. Their delivery history is preserved; recovery notifications for pre-update incidents are skipped because the original destinations cannot be verified.

This release updates the hub only and adds `2026_10_20_000001_add_alert_notification_delays`. **Existing rules also receive the 10-minute alarm and 5-minute recovery delays**; thresholds, destinations and cooldown settings are retained. The alarm delay is additional to existing rule conditions, including heartbeat timeouts. The bundled agent remains at 0.4.0. No dependency, environment or agent configuration changes are required. See the [upgrade checklist](docs/install.md#upgrading-to-043).

## [0.4.2] - 2026-10-19

### Added

- Fleet cards show how much of their 24-hour totals window is confirmed, the remaining unconfirmed duration, and an accessible progress bar.
- Regression tests for partial and full history, stale or missing confirmation, pending exports, export gaps, and independently unverified metric types.

### Changed

- The shared data-flow panel uses **Export coverage: Confirmed** for its displayed interval, distinguishing it from full-day history coverage.
- Unverified metric totals explicitly say **24h total unconfirmed** or **observed · 24h unconfirmed**; failed-job history uses the same time scope.

### Fixed

- Fleet history progress remains unverified when confirmations are stale, missing, pending, or interrupted by an export gap. Full time coverage alone cannot verify an unsupported metric type or turn missing history into zero errors.

This release updates the hub only. The bundled agent remains at 0.4.0. There are no new migrations, dependency updates, or configuration changes since 0.4.1. Alert evaluation and recovery rules are unchanged.

## [0.4.1] - 2026-10-19

### Added

- Optional, independent email and Teams notification channels per alert rule. Rules with neither channel still record internal incidents.
- Central, editable alarm and recovery email subjects and plain-text templates with bounded placeholders and a live preview.
- Separate trigger/recovery delivery status per channel in the alert history. Incidents are persisted before external delivery; one channel failing does not prevent the other.
- Regression tests for notification channels, template validation and escaping, recipient privacy, delivery failures, and the built-in metric catalog.
- Public notification setup and upgrade guidance, including required database migrations and SMTP configuration.

### Changed

- The Teams webhook is optional; existing Teams-only rules keep their configuration. Email recipients are configured explicitly and never enabled by deployment.
- Known metrics, including `job:failed` and `scheduled_task:failed`, are always selectable when creating rules, before the first event arrives. Received custom metric types remain available.

### Fixed

- Non-delivering mail transports cannot report successful alert email delivery. SMTP credentials and message contents are excluded from delivery error messages.

This release updates the hub only. The bundled agent remains at 0.4.0. Existing breach, cooldown, confirmed-recovery, and manual-resolution behavior is unchanged. Delivery failures are recorded without automatic retries.

## [0.4.0] - 2026-10-19

### Added

- Scheduler history includes failure-only tasks, actual background completion outcomes, and exact completion timestamps. Runtime averages use only timed runs.
- Opt-in expected-run monitoring for explicitly named scheduled tasks, with timezone-aware deadlines and conservative handling of missing or unverified data.
- A monitoring data-flow panel showing heartbeat freshness, accepted exports, and confirmed completeness separately, plus a data-flow alert rule type.
- Agent confirmation of settled, fully exported Pulse intervals, including quiet intervals, after every data chunk has been acknowledged.
- Regression tests for scheduler outcomes, expected runs, export confirmation/retries, completeness display, and alert recovery.
- Public deployment and configuration guidance for scheduler and data-flow monitoring.

### Changed

- Instance health now requires confirmed, fresh monitoring data flow. A heartbeat alone no longer establishes health.
- Metric alert recovery requires a fresh, complete observation window whose end follows the incident start. Heartbeat and data-flow recovery require newly confirmed healthy flow. Missing, stale, partially exported, or unsupported metric streams cannot confirm recovery.
- Legacy agents can continue exporting history but cannot attest completeness or automatically resolve incidents. Deploy the hub before updating agents.

### Fixed

- Unsupported Redis/custom Pulse ingestion or storage cannot attest an empty database as complete. Returning to supported direct database capture starts a new observation baseline.
- Export failures retain the watermark and pending samples for retry; duplicate acknowledgements cannot refresh a stalled completeness proof.

## [0.3.1] - 2026-06-19

### Security

- Updated `guzzlehttp/guzzle` to 7.12.1 to resolve medium-severity HTTP client advisories.
- Updated `guzzlehttp/psr7` to 2.12.1 to resolve a medium-severity CRLF injection advisory.

## [0.3.0] - 2026-06-19

### Added

- Slow-request drill-downs now render exported occurrence diagnostics, including request duration, database query count, database time, memory peak, and safe `context.*` metadata.
- Slow-request occurrence rows include an expandable raw sample payload so custom agent metadata remains inspectable without hub UI changes.

## [0.2.0] - 2026-06-17

### Added

- Exception groups can now be marked resolved from their detail page, including optional resolution comments and the resolving user.
- Resolved exception groups automatically reopen when a newer occurrence is ingested.
- Manual alert resolution from the Alerts screen, including optional resolution comments and the resolving user.
- Alert event history now keeps manual resolution metadata for follow-up verification.

## [0.1.0] - 2026-06-15

### Added

- Initial public release of the LaraMon hub.
- Laravel Pulse based agent package for exporting closed metric buckets to the hub.
- Fleet overview with instance health, throughput, latency, exceptions, queue state, active users, and custom business metrics.
- Per-instance drill-downs for routes, requests, slow queries, and exception samples.
- Alert rules with Microsoft Teams workflow notifications and cooldown handling.
- Token-authenticated ingest and heartbeat APIs with retry-safe batch handling.
- Retention pruning and hourly rollups for long-range dashboard queries.

### Changed

- Renamed the public agent package to `laramon/agent` and namespace to `LaraMon\Agent`.
- Switched newly generated instance API tokens to the `lm_` prefix while keeping legacy `ahm_` tokens valid.

### Security

- Stores only hashes of instance API tokens.
- Excludes request payloads and SQL bindings from exported occurrence samples.
