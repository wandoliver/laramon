# Changelog

All notable changes to LaraMon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
