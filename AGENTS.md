# Camplo (Laravel) — guidance for contributors and AI agents

Laravel 13 / PHP 8.3 port of the Node Camplo app. PostgreSQL only.

## Non-negotiables

* **API compatibility.** `public/app.js` and `public/admin.js` are the Node app's client and must keep working unchanged:
  same paths, input names, response shapes (camelCase keys, ISO timestamps with milliseconds and `Z`, via `Fmt::iso`)
  and error bodies (`Fail::*` → `{ defined, code, status, message, data, reason?, required_plan? }`).
* **Tenant isolation.** Every query filters on `ctx()->tenantId`.
* **Secrets** are sealed with `Crypto::encrypt($value, $purpose)` and never returned to a client (use `Crypto::mask`).
* **Schema changes** go in new Laravel migrations. Never edit `database/sql/*.sql` (they mirror what the Node app shipped).
* SQL is parameterised; column names only come from code.
* Never commit secrets — this repository is public.

## Layout

`app/Domain` (pure rules) → `app/Support` (errors, context, crypto, pagination) → `app/Services` (use cases; static
methods taking `Ctx`) → `app/Http/Controllers` (validate with `Input::get`, call a service) → `routes/api.php`.

## Commands

```bash
php artisan test          # needs Postgres (see phpunit.xml)
php artisan migrate
php artisan serve
```

Run the tests before declaring work done, and add feature tests for new endpoints in `tests/Feature`.
