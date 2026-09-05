# BordeGuna HQ application (`apps/hq`)

Laravel 12 + Inertia + React 19 + TypeScript (strict) + PostgreSQL 17 (+ pgvector) + Redis + Horizon.
Phase 0 = foundation only: auth with mandatory TOTP 2FA, RBAC, domain model, encrypted integration
credentials, feature flags, append-only audit log, structured JSON logging, event inbox/outbox,
durable scheduler with a no-op tool, health endpoints. No agents, no model calls, no integrations yet.

## Local setup (Windows with Laragon, or any machine with the services below)

Requirements: PHP 8.3 with `pdo_pgsql pgsql redis intl zip gd bcmath`, Composer 2, Node 22, PostgreSQL 17
(pgvector optional locally; the migration skips it when absent), Redis 7.

```bash
cd apps/hq
composer install                      # Windows: composer.json declares ext-pcntl/ext-posix platform overrides for Horizon
cp .env.example .env && php artisan key:generate
# .env: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=hq DB_USERNAME=postgres DB_PASSWORD=...
#       CACHE_STORE=redis QUEUE_CONNECTION=redis LOG_STACK=daily
createdb -U postgres hq && createdb -U postgres hq_test        # hq_test is what phpunit.xml uses
php artisan migrate --seed            # roles/permissions (additive) + domain model from config/hq-seed.php
php artisan hq:user:create you@example.com --role=owner         # registration is closed; this is the only way in
npm ci
```

Run it (four processes):

```bash
php artisan serve                     # http://127.0.0.1:8000
npm run dev                           # Vite
php artisan horizon                   # queue workers (Linux/WSL; on Windows use: php artisan queue:work redis --queue=scheduler,default)
php artisan schedule:work             # the scheduler tick every minute
```

First login: password, then you are forced to enrol TOTP (scan the QR with any authenticator), confirm a
code, save the recovery codes. Every later session asks for a code once.

## Commands

| Task | Command |
|---|---|
| Tests (Pest, Postgres `hq_test`) | `php artisan test` or `php artisan test --compact` |
| Code style | `vendor/bin/pint --test` (check) / `vendor/bin/pint` (fix) |
| TypeScript | `npx tsc --noEmit` |
| Build assets | `npm run build` |
| Migrate | `php artisan migrate` (prod: `--force`, run by the app container entrypoint when `RUN_MIGRATIONS=1`) |
| Seed | `php artisan db:seed` (idempotent, production-safe) |
| Create user | `php artisan hq:user:create email --role=owner|operator|brand_manager|support_agent|finance_viewer [--generate-password]` |
| Scheduler tick by hand | `php artisan hq:scheduler:tick [--now=ISO8601]` |
| Event drain by hand | `php artisan hq:events:process` |
| Health | `GET /up` (liveness), `GET /api/health` (application, database, redis, queue, scheduler) |

## Layout

```
app/Models/                 Business Brand Store Seller Channel PlatformAccount IntegrationCredential FeatureFlag
                            AuditLog AiRun ToolCall ConnectorCall DomainEvent OwnerPreference DecisionRecord
                            ScheduledJob JobRun User
app/Scheduling/             NextRunCalculator, ScheduledJobService, Commands/SchedulerTick, Jobs/RunScheduledJob,
                            Tools/{Tool,ToolRegistry,ToolContext,ToolResult,NoopTool,FailingTool}, Enums/
app/Support/Audit/Auditor   the only writer of audit_logs (redacts secrets)
app/Support/Events/         EventRecorder (inbox/outbox, dedupe), ProcessDomainEvents (retries, dead-letter), EventHandler
app/Support/Features/       Features (flags with environment precedence + business rollout)
app/Support/Credentials/    OAuthManager skeleton + OAuthProvider contract (no providers yet)
app/Support/Logging/        RedactSecretsProcessor (every log channel)
app/Http/Middleware/        AssignCorrelationIds (request_id/correlation_id via Laravel Context), EnsureTwoFactorEnabled, EnsureTwoFactorVerified
app/Http/Controllers/       Health, Security (2FA setup, challenge, sessions), Scheduling, Admin (dashboard, audit, flags, credentials, businesses)
config/hq.php               tool registry, scheduler and event defaults, alert config
config/hq-seed.php          the domain seed (what exists today, nothing invented)
database/migrations/2026_09_05_*   the Phase 0 schema
resources/js/pages/         dashboard, scheduler/, audit/, flags/, credentials/, businesses/, security/two-factor, auth/two-factor-challenge, settings/sessions
tests/Feature/              Scheduler acceptance, audit append-only, foundation (credentials, flags, events, seed), 2FA + RBAC, route registration
```

## Rules baked in

- The scheduler never claims exactly-once: one logical slot, one run row (unique index), at-least-once execution,
  idempotent tools, `reconcile()` before a re-attempt (ADR-011).
- `audit_logs` cannot be updated or deleted: PostgreSQL trigger plus model guard.
- Secrets: `integration_credentials.payload` is `encrypted:array`; logs pass `RedactSecretsProcessor`; the
  health endpoint returns statuses only.
- Every request has `request_id` and `correlation_id` (also returned as headers); jobs inherit them through Laravel Context.
- PostgreSQL sessions are forced to UTC (`config/database.php`); `timestamptz` columns round-trip wrong otherwise.
- A statement that can fail must never run inside a pgsql migration transaction (see the pgvector migration).
