# Contributing

Thanks for taking the time to improve LaraMon.

## Development Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Run the hub suite:

```bash
php artisan test
```

Run the agent suite:

```bash
cd packages/agent
composer install
vendor/bin/phpunit
```

## Pull Requests

- Keep changes focused and include tests for behavioral changes.
- Run the hub and agent test suites before opening a PR.
- Rebuild and commit `public/build/` after frontend changes.
- Do not include secrets, real hostnames, customer names, request payloads, or SQL bindings in tests, docs, screenshots, or fixtures.

## Project Direction

LaraMon favors boring, self-hosted operational reliability: pre-aggregated metrics, retry-safe ingestion, explicit retention, and no raw event streaming.
