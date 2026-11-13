# Camplo (Laravel) — guidance for contributors and AI agents

Laravel 13 / PHP 8.3 port of the Node Camplo app. Runs on PostgreSQL (Render) and MySQL 8 / MariaDB 10.6+ (local/WAMP).

## Non-negotiables

* **API compatibility.** `public/app.js` and `public/admin.js` are the Node app's client and must keep working unchanged:
  same paths, input names, response shapes (camelCase keys, ISO timestamps with milliseconds and `Z`, via `Fmt::iso`)
  and error bodies (`Fail::*` → `{ defined, code, status, message, data, reason?, required_plan? }`).
* **Tenant isolation.** Every query filters on `ctx()->tenantId`.
* **Secrets** are sealed with `Crypto::encrypt($value, $purpose)` and never returned to a client (use `Crypto::mask`).
* **Schema changes** go in new Laravel migrations. Never edit `database/sql/*.sql` (they mirror what the Node app shipped).
* SQL is parameterised; column names only come from code.
* **Both databases.** Prefer the query builder. Raw SQL is written in PostgreSQL form and wrapped in `Sql::q()` (which
  translates it for MySQL); use `Sql::insertId`, `Sql::updateReturning`, `Sql::locked` and `Sql::ts` instead of
  `insertGetId`, `RETURNING`, advisory locks and literal timestamps. Cast boolean columns with `(bool)` when serialising
  (MySQL returns 0/1). New schema migrations must work on both; for `database/sql` changes, regenerate the MySQL copy with
  `php database/tools/pg2mysql.php`.
* Never commit secrets — this repository is public.

## Layout

`app/Domain` (pure rules) → `app/Support` (errors, context, crypto, pagination) → `app/Services` (use cases; static
methods taking `Ctx`) → `app/Http/Controllers` (validate with `Input::get`, call a service) → `routes/api.php`.

## Commands

```bash
php artisan test          # Postgres (see phpunit.xml)
DB_CONNECTION=mysql DB_PORT=3306 DB_USERNAME=root DB_PASSWORD= php artisan test   # MySQL
php artisan migrate
php artisan serve
```

Run the tests on both databases before declaring work done, and add feature tests for new endpoints in `tests/Feature`.
