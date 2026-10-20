# RIFA backend — conventions for Claude

PHP 8.3 REST API for the RIFA International site (job applications + contact messages + admin panel).
Plan and story cards: `../plan.md` (§4 spec, §6 schema, §7 API contract, §9 stories). Jira project **RI**.

## Run it

```bash
docker compose up -d          # MySQL 8.0 on 127.0.0.1:3307 (databases rifa_local, rifa_test)
composer install
cp .env.example .env          # then fill Cloudinary keys
composer migrate && composer seed
composer seed:admin -- --name="Name" --email=a@b.com   # password via SEED_ADMIN_PASSWORD or prompt
composer serve                # http://localhost:8000/api/v1/health
composer test                 # PHPUnit: Unit + Integration (uses rifa_test, rebuilt every run)
```

Target runtime is **PHP 8.3** (cPanel). Local PHP may be newer: do not use 8.4+ features
(property hooks, asymmetric visibility, `array_find`, `new` without parentheses chaining, pipe operator).
Composer is pinned with `config.platform.php = 8.3.0`. Check with:
`docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --testsuite Unit`

## Production

- Deployment: `DEPLOY.md` (cPanel) and `docs/vercel-proxy.md` (Vercel frontends → API). Entry files: `deploy/api/`.
- `ConfigCheck` blocks the API with `503 server_misconfigured` on staging/production while settings are unsafe;
  run `php bin/check-config.php` after any `.env` change. Daily cron: `bin/cleanup.php`.
- Security tests in `tests/Integration/SecurityHardeningTest.php` fail if an admin route is added without
  `auth` + `csrf`, if security headers disappear, or if personal data reaches the logs. Keep them green.

## Layout

```
bootstrap.php            → App\Core\Kernel::create(__DIR__) — env, config, services, routes
config/*.php             → Config::get('file.key'); values come from .env via env()
src/Core/                → framework only (no business logic): App, Router, Request, Response,
                           Container, Config, Database, Validator, Session, Logger, Migrator,
                           Exceptions/, Middleware/, Http/UploadedFile
src/Shared/              → cross-module services: Clock, Security/{RateLimiter, CsrfTokenManager, Password},
                           Storage/ (Cloudinary, BE-06), Mail/ (BE-07), Audit/ (BE-14)
src/Modules/<Name>/      → routes.php, Controllers/, Services/, Repositories/, Validators/
database/migrations/     → NNN_description.sql (never edit a migration that has run on staging; add a new one)
database/seeds/          → idempotent *.sql + seed_admin.php
templates/emails/        → *.html.php
tests/Unit, tests/Integration (extend Tests\Integration\IntegrationTestCase)
```

## Module pattern

Every `src/Modules/*/routes.php` is loaded automatically inside the `/api/v1` group:

```php
return static function (Router $router): void {
    $router->post('/messages', [MessageController::class, 'store'], ['throttle:messages,5,3600']);
    $router->group('/admin', ['auth', 'csrf'], function (Router $router): void {
        $router->get('/messages', [AdminMessageController::class, 'index']);
        $router->delete('/applications/{id:\d+}', [AdminApplicationController::class, 'destroy'], ['role:super_admin']);
    });
};
```

- **Controller**: HTTP only — read the Request, call one Service method, return a `Response`. No SQL.
- **Service**: business rules, validation (`Validator::make(...)->validate()`), transactions, emails.
- **Repository**: SQL only, via `Database` (`fetchOne`, `fetchAll`, `fetchValue`, `execute`, `insert`, `transaction`).
- Classes are autowired by constructor type hints — no registration needed. Register a factory in
  `Kernel::registerServices()` only when a constructor needs scalars/config.

## Responses and errors (plan.md §7)

- Success: `Response::data($data, 201)` → `{"data": ...}`; lists: `Response::data($rows, 200, ['page' => 1, 'per_page' => 20, 'total' => 57])`.
- Errors: throw, never build error JSON by hand:
  `ValidationException(['field' => 'msg'])` 422 · `NotFoundException` 404 · `ForbiddenException` 403 ·
  `UnauthenticatedException` 401 · `CsrfMismatchException` 419 · `RateLimitedException($seconds)` 429 ·
  `BadRequestException` 400. Anything else becomes a logged 500 with a generic message.
- Dates in JSON: ISO-8601 with offset (`$clock->now()->format(DATE_ATOM)` → `2026-10-19T10:24:53+06:00`).
- Field names in JSON are snake_case and match the DB/API contract exactly.

## Middleware aliases

| Alias | Use |
|---|---|
| `json` | applied to every route; rejects malformed JSON (400 `invalid_json`) |
| `throttle:{name},{max},{seconds}` | per-IP limit on **successful (2xx)** requests; 429 + `Retry-After` |
| `auth` | logged-in active admin; idle timeout; sets `$request->attribute('admin')` = id, name, email, role |
| `role:{role}[,{role}]` | after `auth`; 403 otherwise |
| `csrf` | non-GET admin requests need header `X-CSRF-Token` (token from `CsrfTokenManager::token()`) |
| `captcha` | public forms: Turnstile token in `captcha_token` (422 otherwise); no-op while `CAPTCHA_ENABLED=false` |

Session keys written at login (BE-11): `admin_id` (int), `session_version` (int, must equal `admins.session_version`), `last_activity` (unix ts).
Bumping `admins.session_version` (password change/reset) logs out all other sessions of that admin.
Audit admin actions with `App\Shared\Audit\AuditLogger::log($adminId, 'applications.status_changed', 'application', $id, $request->ip(), [...])`.

## Validation

`Validator::make($data, $rules, $messages, $attributes)->validate()` — strings trimmed, `''` → `null`,
optional empty fields skip their rules. Use array form when a rule contains `|` (regex).
Rules: `required, required_if:field,v1[,v2], accepted, string, integer, boolean, min, max, email,
regex:/…/, in:a,b, date, before_today, digits_in:10,13,17, file, max_kb:5120, mimes:pdf,jpg,png`.
Build `in:` lists from `LookupsService::values('work_experience')` etc., never hardcode option lists.
Messages shown to users must match the frontend zod schemas (plan.md §4).

## Rules

- SQL: parameters only (`?` or `:name`; a named placeholder may appear once per statement). Never concatenate input.
- Time: use `App\Shared\Clock` (freezable in tests), not `time()`/`date()`. DB session time zone is `+06:00`.
- Never log personal data (NID, passport, address, phone) or secrets. Log ids/reference numbers instead.
- Never `echo`/`print`/`exit` in request code; return a Response.
- Secrets only in `.env` (gitignored). `../credentials.txt` is never read by app code and never committed.
- Applicant documents go to Cloudinary as `type=authenticated`; never return a public URL for them.
- Every story: add/extend PHPUnit tests (integration for endpoints via `$this->request('POST', '/api/v1/...')`),
  run `composer test` until green, then update the Jira item (move to Done + comment).
