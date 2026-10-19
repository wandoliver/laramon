# Changelog

All notable changes to LaraMon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
