# 33 - Nikolahost to Hostinger Migration Plan

> **Status (5 Sep 2026): planned, not scheduled.** Joy decided HQ deploys on the current VPS first (ADR-012). This plan stays as the runbook for the shop migration and for the second commerce VPS when monitoring shows the current box is near capacity. Nothing here is a prerequisite for Phase 0.

## What moves

| Site | Today | Target | Effort |
|---|---|---|---|
| laladia.com | Laravel 12 on shared cPanel, MariaDB 10.11, queue drained by scheduler, mail on cPanel | Docker: app/web/worker/scheduler/MySQL 8 (already tested locally on MySQL 8.4) behind Traefik | medium |
| bionic.garden | same, fork deployed by `git merge` in docroot | same stack, second compose project; the server-only ad images must be committed or copied | medium |
| econic.store | WordPress 7.1 + WooCommerce, account UNKNOWN | either Docker WordPress (php-fpm + nginx + MySQL) or replatform (doc 31 Q6) | medium / UNKNOWN |
| theazmion.com, bordeguna.com | static SPAs | nginx static containers (Safor pattern) | trivial |
| Mail (laladia.com, bionic.garden) | cPanel mailboxes and forwarders | **decision required (Q25)**: Zoho Mail / Google Workspace / keep Nikolahost for mail only | small but blocking |
| Other sites on `sangramn` (tdki.org, surveys, cmmoin.academy API) | cPanel | out of scope unless the account is being closed; list them before cancelling anything | UNKNOWN |

## Target topology (ADR-008)

Second Hostinger VPS ("commerce"): Ubuntu 24.04, Docker, its own Traefik, ufw, fail2ban, swap. Stacks: `laladia`, `bionic`, `econic`, `static-sites`, shared nothing (each with its own MySQL container), nightly local dumps, weekly platform snapshot (offsite copies are a future enhancement). Sizing: KVM 2 is enough for current traffic (dozens of orders a month); KVM 4 if Econic's WordPress is heavy or ad campaigns scale.

Fallback if one box: upgrade `srv1808022` to KVM 4/8 and add these stacks beside the ERP and HQ, accepting one failure domain.

## What the shops gain

A real `queue:work` process (today jobs run only when the scheduler drains them), `schedule:work` container, PHP-FPM tuning, composer and Vite available at build time (today neither exists on the host, so `vendor/` and `public/build` are committed), proper release via image build instead of `git archive | tar`, no umask trap, and S3-compatible storage for `public/storage` (today 60 files exist only on production).

## Sequence (per site, LaLaDia first as the rehearsal)

1. **Inventory and freeze**: list cPanel accounts, databases, mailboxes, cron entries, `.env` values, `public/storage` and uploads sizes, DNS records (A, MX, TXT for Meta domain verification, SPF/DKIM). Lower DNS TTL to 300 s a day before.
2. **Build the stack locally against a production dump**: the shops already have this rehearsal habit (restore dump, run migrations, serve, walk the journey). Confirm MariaDB -> MySQL 8 compatibility on the real dump (collation `utf8mb4_unicode_ci`, no MariaDB-only syntax; the local MySQL 8.4 suite already passes).
3. **Stand up on the new VPS under a test hostname** (`laladia.commerce.srv...hstgr.cloud`): import dump, copy `public/storage` + uploads, set `.env` (same `APP_KEY` so encrypted settings rows still decrypt), run the Playwright campaign-readiness spec against it, place a test order, verify Meta domain-verification tag, pixel, GTM, CAPI `meta:verify`, SSLCommerz callback URLs (they are domain-based; unchanged), Pathao webhook (unchanged URL), SMS.
4. **Mail first** (Q25): create mailboxes at the new provider, verify SPF/DKIM/DMARC, forward from cPanel during overlap. Mail is the part that silently breaks when hosting DNS moves.
5. **Cutover**: maintenance mode on cPanel, final dump + storage sync, import, switch A record, watch logs, place a real small order, confirm the confirmation email and SMS arrive, confirm admin login, confirm `_fbp`/`_fbc` and CAPI events. Keep cPanel untouched for 14 days as rollback (DNS back = rollback).
6. **Repeat for Bionic** (commit or copy the server-only ad images first), then the static sites, then Econic once Q6 is decided.
7. **Decommission**: export everything from cPanel (files, DBs, mail) to a kept archive off the cPanel account, then cancel.

## Risks

- Mail loss or spam-foldering after DNS move (mitigated by step 4).
- Meta domain verification and pixel are settings rows and DNS TXT/meta tags; moving hosts does not change them, but the TXT record must be recreated if DNS provider changes.
- Bionic's dirty docroot (server-edited ad images that exist nowhere in git) is the one place data can be lost; md5-compare before cutover as was done on 3 Sep.
- Shared IP reputation improves (own IP) but SPF must include the new mail provider.
- HQ webhooks from shops must be re-subscribed if URLs change (they should not; domains stay).

## Interaction with HQ

HQ connects to the shops by domain, so the migration is invisible to HQ. The migration should complete before Phase 4 order automation adds load the shared host cannot take, and it is a prerequisite for shops having a real queue worker, which HQ's webhook delivery relies on.
