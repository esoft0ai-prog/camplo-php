# Camplo (Laravel)

Camplo — the watchtower for your campaigns and leads — rebuilt on **Laravel 13 / PHP 8.3** with **PostgreSQL**.

This is a port of the Node version. The JSON API keeps the same paths, inputs and response shapes, so the web client
in `public/` (the single-page app, the Super Admin console and the docs) runs unchanged. The database schema is
identical, and secrets, password hashes and login tokens are compatible, so an existing Camplo database can be
pointed at this app (reuse its `ENCRYPTION_KEY` and `JWT_SECRET`).

## Status

The port is happening in stages:

| Stage | Scope | State |
| --- | --- | --- |
| 1 | Schema, login (password, magic link, refresh, reset), workspace, team & permissions, notifications, workspace log, search, settings, plans | **done** |
| 2 | Campaigns, leads (signed webhooks, respond/assign, audit), SLA timers & alerts, notes, team notes, insights & recommendations feed, live updates, retrospectives (PDF), scheduled sweeps, demo data | **done** |
| 3 | Hosted pages (ZIP upload, redeploy/rollback, pause, custom domains, serving), integrations catalogue, inbound/outbound webhooks, cross-tool SLA, AI provider settings, Telegram alerts with Acknowledge buttons, Polar billing webhooks, error monitoring (+ Sentry) | **done** |
| 4 | AI insights, chat & morning brief, memory, MCP server/client, skills & plugins, Super Admin API | next |

## Run locally

Requirements: PHP 8.3 with `pdo_pgsql`, Composer, PostgreSQL 14+.

```bash
composer install
cp .env.example .env          # set DB_* (or DATABASE_URL)
php artisan migrate
php artisan serve             # http://localhost:8000
```

Demo workspace (on an empty database): `php artisan camplo:seed-demo` → sign in as `marcus@northbeam.demo` / `camplo-demo`.

Background rules (SLA alerts, daily digests, webhook health, early-warning windows, cleanup) run every minute via
`php artisan schedule:run` (the Docker image runs it for you), or once with `php artisan camplo:sweep`. An external
cron can call `GET /api/cron` with `Authorization: Bearer $CRON_SECRET` instead.

Tests (need a `camplo_test` database; connection settings are in `phpunit.xml`):

```bash
php artisan test
```

## Deploy to Render

1. Render dashboard → **New → Blueprint** → choose this repository.
2. Render reads `render.yaml`, creates the web service (Docker) and a Postgres database, and generates
   `ENCRYPTION_KEY`, `JWT_SECRET`, `APP_KEY` and `CRON_SECRET`. It asks only for the optional Super Admin login.
3. Each deploy runs the database migrations on start. The health check is `GET /api/health`.

Free Render services sleep after 15 idle minutes and free databases expire after 30 days; use paid plans for real
customers.

## Layout

| Path | What |
| --- | --- |
| `database/sql/*.sql` | The Camplo schema exactly as the Node app shipped it, applied by `2026_01_01_000000_camplo_schema` |
| `app/Support` | API errors (`Fail`), request context (`Ctx`), encryption (`Crypto`), tokens (`Jwt`), pagination, storage |
| `app/Domain` | Plans, features, limits (`Rules`) and teammate permissions (`Permissions`) — no I/O |
| `app/Services` | Use cases (auth, workspace, settings), platform settings, email, Polar |
| `app/Http/Controllers` | Thin controllers: validate input, call a service |
| `routes/api.php` | Every `/api` endpoint |
| `public/` | The web client (unchanged from the Node version) |
| `app/Services/Sweeps.php` | Every time-based rule, as idempotent sweeps run each minute |
| `app/Services/DemoSeeder.php` | The demo workspace |

Hosted pages are served at `/sites/{subdomain}/` on the app host, at `{subdomain}.{PAGES_BASE_DOMAIN}`, and on verified
custom domains (point a CNAME at the page's host). Requests for any host other than the app's own go to Laravel
(see `docker/vhost.conf`), and `GET /api/caddy/ask` lets a Caddy on-demand-TLS front end issue certificates only for
live pages and verified domains.

Live updates: the browser keeps its `EventSource` channels; each request returns the events since its last event id
and closes, and the browser reconnects a few seconds later. No PHP worker is held open.
