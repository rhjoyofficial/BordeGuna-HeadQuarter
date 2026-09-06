# 39 - Phase 1, Step 0: Verified Production State (6 September 2026)

Step 0 of the order of operations in `docs/38`, and **nothing beyond it**. Read-only inspection only: SSH reads on the VPS (`git rev-parse`, `swapon`, `free`, `docker ps`, one read-only `tinker` count) and plain HTTP GETs against the two live shops. No DNS change, no merge, no deploy, no token, no webhook subscriber, no credential, no checkout change, no Bionic merge, no Phase 2, no agents. **Step 1 has not been started and will not start without a separate instruction.**

Joy's decisions of 6 Sep are recorded in section J and are now the standing answers.

---

## A. ERP production HEAD

**VERIFIED DIRECTLY.**

| Fact | Value |
|---|---|
| Production HEAD | **`be052d0c0f850a4e0a7f4c9121e4482bf4171203`** (`be052d0`) |
| Commit | `2026-09-05 17:44:49 +0600` — "Other income: the document 4900 never had" |
| Checkout path | `/opt/bordeguna-erp`, detached HEAD, as designed |
| Working tree | **No modified tracked files.** Only untracked noise: `.env.bak-20260829`, `.env.bak-20260830-readonly`, `.env.bak.1785315518`, `backups/`, `deploy/.env.bak-20260829` |
| Local `master` | `be052d0` — **identical** |
| Branch awaiting merge | `feature/hq-api` = `3fd4be0`, whose merge-base is exactly this commit, so it fast-forwards |

**Conclusion: the ERP is exactly where `docs/38` assumed.** Nothing has drifted, and the ERP merge target is unambiguous.

## B. LaLaDia production HEAD

**VERIFIED BY BEHAVIOUR, not by reading the server's git.** There is no shell on the Nikolahost account from this machine (browser Terminal only), so production was identified by probing four markers, each unique to one of the four commits in question. All four are present.

| Probe | Result | Proves |
|---|---|---|
| `GET /js/landing-checkout.js` | **39,107 bytes**, byte-for-byte the size of `main`'s copy (`a6b4ffdc`'s was 30,672) | `66def463` is live |
| `GET /` — `application/ld+json` blocks | **2 present**; `<link rel="canonical" href="https://laladia.com">` present; `/sitemap.xml` serves a full dynamic URL set (pages, categories, products) | `c8c01b2e` is live |
| `GET /product-page/hilsa-fish-pickle` — the QA section's own wrapper class (`border-t border-[#D7CFBE] bg-[#FBF8F1] py-12 md:py-16`, which appears **0** times before the commit and **1** time after) | **1 occurrence**, and the four certification images (`iso.png`, `halal.png`, `haccp.png`, `gmp.png`) render | `5f3aa7f9` is live |
| `GET /product-category/dry-fish` (route added by the 404-recovery commit) | **301 → `https://laladia.com/category/dry-fish`**; and `https://www.laladia.com/` → **301 → apex**, the `CanonicalHost` middleware added in the same commit | `5e955a2c` is live |

**Conclusion: laladia.com is running `5e955a2c`, which is `main`.** Confidence is high — four independent markers, one of them a byte-exact static asset match.

⚠ **The one residual uncertainty, and it is Joy's to close in ten seconds.** Behaviour proves the code is *at least* `5e955a2c`; it cannot prove the server carries nothing extra. The definitive read is one read-only command in the cPanel Terminal:

```
cd ~/laladia-src && git log --oneline -1        # expect 5e955a2c
```

## C. Bionic production HEAD

**VERIFIED BY BEHAVIOUR**, same method, same limitation.

| Probe | Result | Proves |
|---|---|---|
| `GET /product-category/test` | **301 → `https://bionic.garden/category/test`** | the 404-recovery work (LaLaDia `5e955a2c`, merged into Bionic as `b30b4b7c`) is live |
| `https://www.bionic.garden/` | **301 → apex** | `CanonicalHost` is live |
| `GET /` — `application/ld+json` | **2 blocks present** | the SEO foundation (`c8c01b2e`) is merged and live |
| `GET /robots.txt` | `Sitemap: **https://bionic.garden/sitemap.xml**` | the robots **route** is live, not the forked static file that used to advertise laladia.com's sitemap — the exact bug that route was written to fix |
| Build asset | `app-wt8veaL2.js`, the same fingerprint LaLaDia serves | shared build lineage, consistent with the merge |

**Conclusion: bionic.garden is running `b30b4b7c` (its `relaunch` HEAD) or a commit containing it.** Since local `relaunch` is exactly `b30b4b7c`, production and the local branch agree.

⚠ **Bionic's docroot IS its git repository** (`~/bionic-garden-app`), so unlike LaLaDia it *can* carry server-side commits or dirty files that exist nowhere else — its `public/assets/ads/*.jpg` already do. Definitive read, again read-only:

```
cd ~/bionic-garden-app && git log --oneline -1 && git status --short | head
```

## D. LaLaDia blast-radius result — B.6 is answered

**The SEO/canonical-host/404-recovery work and the hilsa landing QA section are ALREADY LIVE.** `docs/38` section B.6 offered options (a) and (b); the evidence selects **(a)**.

**Therefore, if `feature/hq-phase1-api` is merged into `main` and `main` is released, the blast radius is exactly one commit:**

| | |
|---|---|
| Commits shipped | **1** — `4865c109` "HQ Phase 1: dedupable webhook envelope, inventory.low, assistant reads, order provenance" |
| Files changed | 12 (+501 / -6) |
| Migrations | 1 — `orders.conversation_ref` (nullable, indexed), `product_variants.low_stock_alerted_at` (nullable) |
| New public surface | 3 read-only endpoints, all inside the existing `chatbot.key` + `throttle:30,1` group |
| Behaviour change visible to a real customer | **None**, with one narrow exception: a checkout request sending `source` with any value other than `assistant` now returns 422 where it was previously ignored. No storefront code sends `source` |
| Pricing / stock refusal / coupons / OTP / courier | untouched |
| Existing webhook subscribers affected | none — the envelope only gains fields, and the signature method is unchanged (subscriber count still to be confirmed, section F) |

This is the smallest blast radius the release could have had, and it removes the reason to split the release.

## E. LaLaDia deployed-vs-undeployed commit comparison

`main` = `5e955a2c`. Everything below it is live.

| Commit | Subject | Live? | Evidence |
|---|---|---|---|
| `5e955a2c` | Recover the 404s and stop serving the shop at two addresses | **LIVE** | `/product-category/{slug}` 301s; `www` 301s to apex |
| `5f3aa7f9` | Hilsa landing: the Quality Assurance section it always had the data for | **LIVE** | QA wrapper class present once; four certification badges render |
| `c8c01b2e` | SEO/AIO foundation: reachable pages, honest canonicals, real structured data | **LIVE** | 2 JSON-LD blocks, canonical tag, dynamic sitemap, robots route |
| `66def463` | Landing checkout: mid-funnel tracking events + real payment choice | **LIVE** | `landing-checkout.js` is 39,107 bytes, `main`'s exact size |
| `a6b4ffdc` | Hilsa landing: his serving panel as the third image | **LIVE** (ancestor) | previously recorded as the live commit |
| — | | | |
| `4865c109` | **HQ Phase 1** (`feature/hq-phase1-api`) | **NOT LIVE, NOT MERGED** | this is the only thing a release would add |

⚠ **`docs/01` and `docs/36` are corrected by this.** They recorded live `a6b4ffdc` with local `main` at `66def463` and "ahead of production". Both halves are now stale: `main` has moved to `5e955a2c`, and production has caught up to it.

## F. Current webhook subscriber state

**NOT VERIFIABLE FROM HERE — REQUIRES JOY.** The subscriber list lives in each shop's own `webhooks` table, reachable only through the shop admin panel or the cPanel Terminal/phpMyAdmin, neither of which this machine can reach. I will not guess a number I cannot read.

**What I can verify, and did — the HQ side is definitively untouched:**

```
events = 0 · integration_credentials = 0 · facts_orders = 0        (read-only count on hq-app, 6 Sep)
```

So **nothing has ever been delivered to HQ**, and HQ holds no shop or ERP credential. That is consistent with "zero subscribers", and it also means no subscriber anywhere is currently pointed at `hq.bordeguna.com`. It does **not** prove the shops have no subscriber pointing somewhere else.

**The exact read-only checks for Joy** (either one is enough, per shop):

```
# cPanel Terminal, LaLaDia
cd ~/laladia.com && php artisan tinker --execute="foreach (App\Domains\Webhook\Models\Webhook::all() as \$w) { echo \$w->id.' '.\$w->event.' '.\$w->url.' active='.(int)\$w->is_active.PHP_EOL; }"

# or phpMyAdmin
SELECT id, event, url, is_active FROM webhooks;

# and the same in ~/bionic-garden-app for sangramn_bgarden
```

**Why this matters even though the envelope change is additive:** if a subscriber *does* exist and is not ours, it starts receiving four extra JSON fields and four extra headers. Nothing should break — `event` and `data` are unchanged and the HMAC still covers the exact bytes — but "should" is not "did", and one query settles it. This stays on the pre-deploy checklist (`docs/38` risk J-9) and is **not** a blocker for the ERP step.

## G. Current VPS swap state — the previous documentation was stale

**VERIFIED LIVE, and `docs/01`'s "no swap" statement was wrong as of today. It has been corrected in place.**

```
swapon --show     NAME       TYPE  SIZE  USED  PRIO
                  /swapfile  file  4G    768K  -2
sysctl            vm.swappiness = 10
free -h           Mem: 7.8Gi total · 3.1Gi used · 4.6Gi available
                  Swap: 4.0Gi total · 768Ki used · 4.0Gi free
df -h /           96G total · 27G used · 70G free (28%)
```

| Reading | `docs/01` said (5 Sep) | Live (6 Sep) | Action taken |
|---|---|---|---|
| Swap | "**no swap**" and a security finding about OOM risk | **4 GB swapfile, active, swappiness 10, 768 KB used** | `docs/01` table row and section 9 item 4 corrected; the OOM finding marked RESOLVED |
| Phase 0 checklist "4 GB swap file … verified with `swapon --show`" | unticked in `docs/34` | done and re-verified | `docs/34` item ticked with today's evidence |
| Disk | 20 GB used | **27 GB used** (HQ stack, its Postgres, and the accumulated pre-deploy dumps) | `docs/01` updated |
| RAM in use | 2.7 GB used / 5.1 GB available | **3.1 GB / 4.6 GB** with HQ running | `docs/01` updated |

**Reading of it:** the host is healthier than the audit implied — swap exists, is configured conservatively, and is essentially untouched, which says the box is not under memory pressure today. HQ's seven containers cost roughly 0.4 GB of resident memory. The capacity monitoring in doc 27 is still worth having before anything else lands here, but the "OOM is the realistic failure mode" warning no longer describes this machine.

**For completeness, HQ's own production state, read at the same time:** HEAD `6bb9f06`, all seven containers up and healthy (`web`, `app`, `horizon`, `scheduler`, `db`, `redis`, `backup`), matching the 5 Sep release record.

## H. Recommended exact next action

**One action, and it is Joy's to authorise: Step 1 — the ERP, alone.**

Nothing in Step 0 changed the plan; it removed a decision (B.6) and confirmed the ground. The ERP remains the right first move because it is the highest-risk system and deserves an empty stage: no shop release in flight, no credential to juggle, one migration pair, and a rollback target (`be052d0`) now confirmed rather than assumed.

```
1. Full ERP suite locally + npx tsc --noEmit                      (I can run; ~3,096 expected)
2. Backup on the VPS, size and "Dump completed" tail verified     (docs/38 F.1)
3. git merge --ff-only feature/hq-api  →  push                    (on your word)
4. Deploy: fetch, detach to origin/master, build, up -d, migrate --force   (exactly 2 migrations)
5. Verify: docs/38 H.1, including opening one ticket on the CRM screen
6. Watch for one working day before anything else moves
```

**Two read-only confirmations only you can make, and they are cheap** — they are not blockers for the ERP step, but both should be done before the *LaLaDia* step:

1. `cd ~/laladia-src && git log --oneline -1` (expect `5e955a2c`) and the same in `~/bionic-garden-app` (expect `b30b4b7c`).
2. The `webhooks` table query in section F, on both shops.

**Not recommended:** starting the LaLaDia merge in the same sitting. The blast radius is now one commit, which makes it a small release — but it is still a live storefront, and a shop release deserves its own attention rather than the tail end of an ERP day.

## I. Remaining blockers

| # | Item | Status | Blocks |
|---|---|---|---|
| 1 | Shop webhook subscriber count on both shops | **REQUIRES JOY** (read-only query, section F) | The LaLaDia deploy's pre-flight; not the ERP step |
| 2 | Definitive git HEAD on both shop servers | **REQUIRES JOY** (read-only, section H) | Nothing hard — behaviour already answered B.6; this is belt and braces before the shop release |
| 3 | ERP `hq` user, tokens, and their exact abilities | **REQUIRES JOY**, after the ERP deploy | HQ actually reading the ERP |
| 4 | Shop `hq` credentials and webhook subscriber rows | **REQUIRES JOY**, after the LaLaDia deploy | HQ receiving orders |
| 5 | `inventory.low` re-arm statement after subscribing | **REQUIRES JOY** (`docs/38` section E) | HQ hearing about already-low variants |
| 6 | Econic / WooCommerce adapter | **BLOCKED** — no cPanel account, no REST keys, Q6b undecided | Nothing on the Phase 1 critical path |
| 7 | Anything Meta beyond development mode | **BLOCKED** — the boss's official Meta account does not exist yet (Q12b) | Phase 3 channels, Phase 5/6 scopes |
| 8 | Phase 2 (Command Center) | **NOT STARTED, by instruction** | — |

**No blocker stands in front of Step 1.**

## J. Decisions recorded 6 Sep 2026 (Joy)

**P-1 — CONFIRMED, Option B.** The checkout stock refusal stays exactly as it is; `CheckoutPricingService` is not modified; **no separate checkout release is created**. The rule HQ and the customer AI will follow, in his words:

1. **Product status determines whether the product is offered.**
2. **Checkout confirms whether the exact quantity can be fulfilled.**
3. **When live availability reports `stock_insufficient`, the AI must communicate that the exact quantity is confirmed at checkout.**
4. **Do not promise unconditional availability based only on product status.**

This is now the specification for HQ's `commerce.availability_rule` and for the wording the Phase 3 customer agent uses. It needs no shop code: `GET /assistant/products/{slug}/availability` already returns `orderable`, the `reason` (`ok` / `stock_insufficient` / `status:*` / `product_inactive` / `variant_inactive`) and `available_stock`, which is exactly the input those four rules need. **Nothing was implemented today** — this is recorded, not built.

**B.6 — resolved by evidence, not by choice.** Option (a): the unrelated work is already live, so the HQ release carries one commit (section D).

---

**Stop point.** Step 0 is complete and this document is the deliverable. Step 1 has not begun. The next action is yours: authorise Step 1 (the ERP, alone), and optionally run the two read-only confirmations in section H.
