# 139. Public repository exposure review (17 October 2026)

Written for Joy, as ordered by ruling docs/138 section 2. The repository `rhjoyofficial/BordeGuna-HeadQuarter` became public on 17 Oct 2026. This review looked at everything a stranger can now reach. No secret value appears in this document; where a value is named, only its first four characters are shown.

## 1. Verdict in five lines

- **No real credential, token, password, private key or API key was found** anywhere in the public history, the branch tips, the tag, the CI logs or the CI artifacts.
- **No customer or employee personal data was found.** Every phone number is a made-up test number, except LaLaDia's public support line. Every customer name is a placeholder.
- **Nothing needs rotating and no history rewrite is needed.** Rewriting would not undo the exposure anyway; it would only be needed if a real secret had been found, and then rotation would come first.
- **What is exposed and accepted by Joy:** his personal e-mail (also the HQ login name), the VPS address and host name, the shop and ERP host names, and the full design, deploy and incident documents. These help an attacker map the system; they do not let anyone in.
- **GitHub settings need hardening** (section 7): no branch protection on `main`, GitHub secret scanning and push protection off, Dependabot alerts off, fork pull requests from returning contributors run CI without approval.

## 2. Method

- A fresh mirror clone (`git clone --mirror`) of the public repository, taken on 17 Oct 2026, so every branch, tag and pull-request ref that GitHub serves was included, not only what the working clone has.
- Automated scan 1: gitleaks in `git` mode over the whole mirror history (`--log-opts=--all`), default rule set.
- Automated scan 2: gitleaks in `dir` mode over the exported file tree of every branch tip and the tag.
- Automated scan 3: a purpose-written regex and entropy sweep over every added line in `git log --all -p`: private key headers, AWS, GitHub, OpenAI, Anthropic, Google, Slack, Telegram bot, Facebook/Meta, Stripe and Backblaze key shapes, JWTs, Laravel `APP_KEY` values, credentials inside URLs, password/secret/token assignments, any string of 32+ characters with entropy above 4.5, Bangladeshi phone numbers, e-mail addresses, IPv4 addresses and 10, 13 and 17 digit numbers (national ID shapes).
- Automated scan 4: gitleaks plus the same key patterns over every GitHub Actions artifact (downloaded, unpacked, binary blobs turned into text) and over the logs of every Actions run.
- Read-only GitHub API checks with `gh`: releases, tags, artifacts, runs, secrets and variables (names only), environments, Actions permissions, fork pull-request approval policy, branch protection and rulesets, collaborators, deploy keys, webhooks, Pages, security features.
- A human review of every automated finding, classified as real, test fake or false positive.
- Nothing was changed on GitHub. No SSH. No setting touched.

## 3. Tool and verification

- gitleaks 8.30.1 (latest release, published 21 Mar 2026), `gitleaks_8.30.1_windows_x64.zip`, downloaded from the official `gitleaks/gitleaks` GitHub release.
- SHA-256 `d29144deff3a68aa93ced33dddf84b7fdc26070add4aa0f4513094c8332afc4e`, identical to the line in the release's `gitleaks_8.30.1_checksums.txt`. Checked before use.
- Kept outside the repository, in a scratch tools folder, and not committed.

## 4. Coverage

- 662 commits reachable from all refs (gitleaks reports 547 scanned; the rest are merge commits with no content of their own, whose content is scanned through their parents).
- 98 branches, 1 tag (`hq-rc-2026-10-17`), 35 pull-request refs (`refs/pull/*`), all inside the mirror.
- 99 tree snapshots scanned file by file (98 branch tips plus the tag).
- 1,666 distinct file paths ever committed; about 18 MB of patch text.
- 0 GitHub releases, so 0 release assets.
- 161 Actions artifacts (all unexpired), every one downloaded and scanned.
- 172 Actions runs (101 push, 70 pull_request, 1 manual). Logs of all 135 runs that produced logs were downloaded (625 log files) and scanned. The other 37 runs never started (GitHub refused them for a billing reason), so they have no log.

## 5. Findings by class

### 5.1 Real secrets: 0

- None in history, branch tips, the tag, commit messages, artifacts or run logs.
- The tracked `.env.example` files (`apps/hq/.env.example`, `deploy/.env.example`, `deploy/staging/.env.example`), in every version ever committed, carry empty values and generation hints only (`openssl rand ...`). `.env` and `.env.*` are git-ignored; no real `.env` was ever committed.
- The CI database password `postgres` in `.github/workflows/hq-ci.yml` is for a throwaway service container that exists only for the length of a CI job. Not used anywhere else.

### 5.2 Test fakes: 10 distinct values, all in `apps/hq/tests/`

- gitleaks: `RedactionAndAuditTest.php` (commit `426e2095`), two constants starting `sk-l` and `tok-`, deliberately fake secrets the redaction tests try to leak.
- The regex sweep found the same family: values starting `sk-a`, `sk-l`, `sk-p` and `sk-t` in `CredentialReplacementTest.php`, `AttemptThreeCorrectionsTest.php`, `ArgumentContractAndReplyCaptureTest.php`, `OpenAiProviderTest.php` and `AiSafetyPostureTest.php`. They read as words ("do-not-leak", "secret-value", "old", "new", an alphabet run) or are short counters; none has the length or shape of a real OpenAI or Anthropic key.
- Two fake shop admin and Meta values in `LaravelShopAdapterTest.php`, `MetaNormaliserAndWebhookTest.php` and `tests/Pest.php`.
- Verdict: fakes by design. No action.

### 5.3 False positives

- gitleaks, 3 findings: `generic-api-key` on 64-character hex values in `tests/fixtures/erp/sales-orders/manifest.json` (commit `ba0ff687`, 2 findings) and `tests/fixtures/erp/sales-order-drafts/manifest.json` (commit `9da742f4`). These are SHA-256 digests of the fixture files, as the manifest's own `canonical` line explains.
- gitleaks on artifacts, 640 findings, all one value starting `1198`: the `GPG_KEYS` line inherited from the official `php` Docker base image. It lists the public fingerprints of PHP's release managers. Public by design.
- Entropy sweep, 598 strings: 582 are integrity hashes in `package-lock.json` and `composer.lock`; the rest are URLs in research docs, shell variable names in `deploy/hq-monitor.sh` and `deploy/rehearsal/rehearse.sh`, product image hashes in shop fixtures and Meta message ids in test fixtures. The one WhatsApp message id was decoded: it carries the fake test number `8801711223344`.
- 10 and 13 digit numbers: Unix timestamps, the byte count `1073741824`, Meta test page ids and the barcode-shaped test SKU `8940000000011`.

## 6. Personal data

- **Customer phone numbers: none real.** 59 distinct Bangladeshi number shapes were found. All but one are obvious placeholders in tests, fixtures, the conversation simulator and two docs (patterns such as `01711000001`, `01700000000`, `8801711223344`, `01999888777`). The one real number is LaLaDia's published support line `01334943783`, used in support-script tests and a research doc. Public by design.
- **Customer names and addresses: none real.** The only named delivery address is the placeholder "Rahim Uddin, House 4, ..., Fakirhat, Bagerhat" with the fake number `+880 1711-000001`, in ERP sales-order tests and fixtures.
- **Employee data: none.** No salary amounts, national ID numbers, dates of birth or staff addresses. "Salary" appears only in policy text (what the AI must never do).
- **SQL and data files:** the only `.sql` files are the rehearsal seeds and read-only check scripts under `deploy/rehearsal/sql/` and `deploy/tools/`. `seed-realistic.sql` and `seed-corruption.sql` generate synthetic rows (names such as `RLOCAL LaLaDia customer 1`, generated phone numbers) and say so in their headers. No `.dump`, `.csv`, `.xlsx`, `.sqlite`, `.bak`, archive, `.pem`, `.key` or `.p12` file was ever committed.
- **Production evidence in docs:** docs/121 section 5 and section 6 and later release docs quote production results from read-only runs. They are counts and internal row numbers only ("order fact #16", "customer #1", event totals); the scripts were written to print no values. No phone, name, message text or address from production appears.
- **E-mail addresses:** Joy's personal Gmail appears in commit author metadata (1,276 entries) and in docs/63, RESUME-HERE-2026-10-10 and RESUME-HERE-2026-10-11 as the HQ login and the `--by` / `--owner` value in commands. `joy@bordeguna.com`, `hq@bordeguna.com`, `owner@bordeguna.com`, `no-reply@bordeguna.com`, `bordeguna@gmail.com`, `orders@laladia.com`, `care@laladia.com` and `support@laladia.com` appear in docs and tests. The other ~90 addresses are open-source package authors inside `composer.lock` and `package-lock.json`. Joy accepted the exposure of his e-mail. Consequence: an attacker knows the HQ login name, so the password and the two-factor check carry the whole load (both are already enforced: `EnsureTwoFactorEnabled`, `EnsureTwoFactorVerified`).
- Meta identifiers in docs and config: the ad account ids (`2816...`, `3798...`) and the business portfolio id. These are identifiers, not credentials; nothing can be done with them without a token.

## 7. GitHub surface

- **Releases and assets:** none.
- **Tags:** one, `hq-rc-2026-10-17`, scanned with the rest.
- **Actions secrets, variables, environments:** none defined. The workflow references no `secrets.*` value. So no Actions run, from any branch or fork, can read a secret, because there is none to read.
- **Workflow:** one file, `.github/workflows/hq-ci.yml` (4 versions in history). Triggers: `push` to `main`, `pull_request`, `workflow_dispatch`. No `pull_request_target`, no `workflow_run`, no self-hosted runner, no image push, no deploy step. It runs tests and builds images with `push: false`.
- **Token scope:** the repository default `GITHUB_TOKEN` permission is `read`, and Actions may not approve pull requests. Good. The workflow file itself has no `permissions:` block, so it relies on that repository default.
- **Fork pull requests:** the approval policy is `first_time_contributors`. A stranger's first pull request waits for Joy's approval; a second one runs CI automatically. With no secrets and a read-only token, the realistic harm is wasted runner minutes or a misleading green check, not a leak. Still worth tightening (section 9).
- **Third-party actions:** `actions/checkout@v4`, `actions/setup-node@v4`, `shivammathur/setup-php@v2`, `docker/setup-buildx-action@v3`, `docker/build-push-action@v6`, referenced by tag, not by commit SHA. Any action is allowed (`allowed_actions: all`).
- **Artifacts:** 161 `.dockerbuild` build records, automatically uploaded by `docker/build-push-action`. Each holds the BuildKit history record, the build log, an OpenTelemetry trace and SLSA provenance. Checked: no build arguments, no build secrets, no `.env` content. They do contain the Dockerfile steps, package versions and the repository URL, which are already public in the code. Anyone signed in to GitHub can download them while they last (90 days by default).
- **Run logs:** gitleaks found nothing in 625 log files from 135 runs. The key-pattern sweep found only the CI throwaway database password (`DB_PASSWORD` for the `postgres` service container, as in the workflow file). The `GITHUB_TOKEN` shows as `***` (masked) in all 751 places it is echoed. No environment dump, no `.env` content, no real phone number (one fake test number `01799000000` in test output).
- **Side note, not a security issue:** 37 runs (34 push, 3 pull_request, around 16 Oct) failed because GitHub would not start the jobs ("recent account payments have failed or your spending limit needs to be increased"). Public repositories get standard runners free, so this should not recur now, but the billing message is worth a look.
- **Branch protection:** `main` is **not protected** and there are **no rulesets**. Only Joy (admin) can push, so today the risk is a mistaken force-push or deletion of the frozen release candidate, by a person or by an agent, not an outsider.
- **Security features:** secret scanning, push protection, non-provider patterns, validity checks, Dependabot alerts and Dependabot security updates are all **disabled**. For a public repository, secret scanning and push protection are free.
- **Collaborators:** Joy only (admin). **Deploy keys:** one, `srv1808022 hq-deploy (read-only)`, the VPS pull key; read-only, so it cannot push. **Webhooks:** none. **Pages:** none. **Wiki:** off. **Forks:** 0 at the time of review. **Pull requests:** 35, all from Joy's own branches, all merged.

## 8. Tracked configuration

- `deploy/docker-compose.yml` and `deploy/staging/docker-compose.yml`: only the app is reachable, through Traefik on HTTPS with an HTTP-to-HTTPS redirect. PostgreSQL and Redis publish no ports and sit on an internal network. Passwords come from the untracked `deploy/.env`; no default password is baked in (`${DB_PASSWORD}`, `${REDIS_PASSWORD}`, `:?` guards in `backup.sh` and `restore.sh`).
- `deploy/content-render/docker-compose.content-render.yml`: joins the HQ network, publishes nothing.
- Deploy scripts (`deploy.sh`, `rollback.sh`, `verify-only.sh`, `backup.sh`, `restore.sh`, `hq-monitor.sh`, rehearsal scripts): no embedded credential; the deploy safety test asserts that no `KEY=value` from `deploy/.env` is ever printed. The rehearsal script generates random throwaway passwords at run time.
- Hard-coded hosts that help an attacker map the system: the VPS IP `200.97.162.40` (deploy/README.md, docs/01, docs/04, docs/31, docs/37, docs/95, a rehearsal guard test), host name `srv1808022.hstgr.cloud`, `hq.bordeguna.com`, `erp.bordeguna.com`, the n8n host, the shared cPanel host IP `15.235.188.158` and the shops' cPanel account and database names (docs/01, docs/04), the shop server IP `15.235.229.40` (docs/95), production paths (`/opt/bordeguna-hq`, `/root/backups/bordeguna-hq`), container names. deploy/README.md records that the VPS firewall allows only 22, 80 and 443 and that fail2ban is active. Accepted by Joy; listed so the exposure is known.
- Public HTTP surface named in the code: `/api/health` is unauthenticated by design and reports statuses only; shop webhooks are signature-verified; Meta webhooks return 404 while their switches are off.
- The docs describe open defects and the identity incident (docs/117 to docs/138), including that the HQ scheduler is stopped. This is operational detail an attacker could use for timing; it contains no secret and no customer data.

## 9. Required actions

- **Rotation: none required.** No real credential was found in any public surface.
- **Redaction and history rewrite: none required.** No real secret and no real personal data was found. For the record: had one been found, rotation would come first, because a public repository may already have been cloned, cached or indexed, and a rewrite does not undo that.
- **Keep it that way:** before every push, the same gitleaks run (`gitleaks git --log-opts=--all` on a fresh mirror) is a two-second check. Recommended as a CI step (section 10).

## 10. Hardening recommendations (Joy's decision; nothing was changed)

- Turn on GitHub **secret scanning** and **push protection** (Settings, Code security). Free for public repositories. Push protection blocks a commit carrying a known key format before it lands.
- Turn on **Dependabot alerts** and **Dependabot security updates**.
- **Protect `main`** with a ruleset: no force-push, no deletion, changes through a pull request, the `hq-ci` checks required. Optionally also protect the tag pattern `hq-rc-*` against deletion and moving.
- Set **Actions, Fork pull request workflows** to "Require approval for all outside collaborators" instead of first-time contributors only.
- Add `permissions: { contents: read }` at the top of `hq-ci.yml` so the read-only token does not depend on the repository default.
- Pin third-party actions to full commit SHAs, and limit allowed actions to GitHub-owned, verified creators and the named ones (`shivammathur/setup-php`, `docker/*`).
- Add a gitleaks job to `hq-ci.yml` (pinned version, checksum-verified) so every pull request is scanned.
- Turn off the automatic build-record artifact (`DOCKER_BUILD_RECORD_UPLOAD: false` on the build steps) or shorten artifact retention; the records add nothing for HQ and are public while they last.
- Keep the HQ login protected by two-factor (already enforced) since the login e-mail is public; consider a separate admin login address that is not the public commit e-mail.
- In future docs, write the VPS address and cPanel account names as placeholders ("the VPS", "the shop host") instead of literal values. Existing mentions stay; changing them now hides nothing.
- Do not commit production query output that carries values; keep the current rule of counts only.
