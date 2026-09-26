# HQ staging (defined, not started)

**Status: a definition only.** Written for docs/88 A point 2 (stream Q). Nothing in the release process
starts it, and nobody should start it until the owner decides to, after the server upgrade (docs/82 N3).

## Why it exists

Gate P3-G3 is the first time a real Meta webhook reaches HQ. docs/72 §F and docs/70 J4 say that must not
happen for the first time on production. Staging is where the Meta app's **test Page** (development
mode, only admins and testers can message it) is pointed first: the webhook, the signature check, the
normalisers, the conversation core and the inbox are exercised end to end with no real customer.

## What makes it separate

- Its own compose project, `bordeguna-hq-staging`, so `docker compose` never confuses its containers or
  volumes with production's `bordeguna-hq`.
- Its own database, redis, storage and public volumes, created empty.
- Its own domain (`HQ_STAGING_DOMAIN`), never `hq.bordeguna.com`; its own Traefik router names.
- Its own secrets, all new (see `.env.example`). Production's `APP_KEY` would decrypt production's
  stored credentials, so it is never copied.
- It reads nothing real: no ERP URL, no shop tokens, no alert bot.
- `deploy.sh`, `rollback.sh` and `verify-only.sh` name `deploy/docker-compose.yml` only.

## Starting it (a person's decision, not a release step)

From the repository root on the server, after writing `deploy/staging/.env` from the template:

```
docker compose -f deploy/staging/docker-compose.yml --env-file deploy/staging/.env up -d --build
docker compose -f deploy/staging/docker-compose.yml --env-file deploy/staging/.env exec app php artisan db:seed --force
```

Then create the operator account, store the TEST Page's credentials on `/credentials`, and switch
`channels.meta.inbound` on in staging only. Production's flags are untouched by anything here.

## Stopping it

```
docker compose -f deploy/staging/docker-compose.yml --env-file deploy/staging/.env down
```

`down -v` also deletes staging's volumes; it can never touch production's, which belong to another
project.
