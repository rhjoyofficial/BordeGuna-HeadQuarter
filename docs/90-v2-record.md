# 90 V2 - Stream V2 record: the operator's language preference, per person (26 September 2026)

Branch `ui/language-preference-per-person`, worktree `hq-wt/k` (its own `vendor` and `node_modules`), test
database `hq_test_k`. Built on `origin/main` at `43d8782` under `docs/90` A.6 and B.

The ruling, in one line: the language a person's HQ screens read in is the person's own preference, saved
on their profile and audited, with the browser's language only the first fallback and English the last;
never anything a model or provider can change.

## What was built

**1. One nullable column, `users.locale`** (migration `2026_09_26_100000_add_locale_to_users`, additive:
five characters, null for "no preference saved yet"). It is `fillable` on the user so the profile request
can write it, and nothing else does.

**2. The write path is the profile settings, as the ruling asks: a normal profile mutation.**
`PATCH settings/profile` (the route that already existed; no route was added) now accepts `locale` as
`en` or `bn` or null, and `name` and `email` are required only when they are sent, so the top-bar switch
can save the preference with `locale` alone. `ProfileController::update()` audits a change as the person
(`user.locale_changed`, actor human = the person, target the user, before and after the locale, the
reason "the person chose the language their HQ screens read in"); saving the same language again is not a
change and writes no audit row; updating the name or email leaves the preference alone.

**3. Shared to every page.** The user model is already shared whole as `auth.user`, so `auth.user.locale`
reaches every page with no middleware change (null when none is saved). The client type `User` names it.

**4. The hierarchy on the client, in `resources/js/lib/i18n.ts`.** `resolveLocale(saved, browserLanguage)`
answers, in this order and no other: the person's saved preference when one is saved; the browser's
language when it says Bangla; English. `initializeLocale(saved)` runs once at boot in `app.tsx`, from the
first page's shared `auth.user.locale`, and sets the page's `lang`. The language of the page view is held
in memory and changed only by the switch; **nothing durable lives in the browser any more**: the
`localStorage` key of stream V is gone, and a test refuses it back.

**5. The switch saves.** `LanguageToggle` applies the choice to the page at once (as before) and then
saves it as the person's preference through `router.patch(route('profile.update'), { locale })`, with
scroll and state preserved. Choosing the language already shown does nothing.

**6. No model can reach it.** No tool's input schema names a locale; no file under `app/Tools`,
`app/Agents`, `app/Ai` or `app/Gateway` touches the column; only the profile settings request writes it.
A test reads every registered tool's schema and every file in those four directories.

English digits in both languages, NFC and the brand spelling are unchanged from stream V, and the route-list
test of the posture page still pins every non-GET web route: none was added.

## Not built here, on purpose

- No "Language" field on the profile page itself: the top-bar switch on every page is the control, and
  it goes through the profile route; a field there is one input away if wanted.
- No server-side `Accept-Language` reading: the ruling names the browser language as the initial
  fallback, and the browser reports it to the client at boot, so no request header is parsed.
- No change to the agents, the gateway or the Support path (docs/90 B: those belong to X and Y).

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New tests: `tests/Feature/Settings/LanguagePreferenceTest.php` (8: the column and the null default;
  the save with `locale` alone and its audit row; the preference left alone by a name or email update and
  no audit for a non-change; a language HQ does not have refused; the shared prop null and saved; the
  hierarchy order, no `localStorage`, the boot from the shared prop; the switch saving through the profile
  route and no locale route; no tool naming or touching the preference). The stream V test that held the
  choice in the browser is rewritten for the saved preference. All watched failing before the code (5 of
  the 8 failed; 3 passed by construction: the migration file, the shared prop and the switch were written
  first).
- Mutations, each applied, the tests run, the file restored, **8 of 8 caught**: the audit row not
  written; any language accepted; the browser language read before the saved preference; the durable
  choice put back in the browser; the switch saving by its own request instead of the profile route; a
  tool naming the preference; the preference hidden from the shared props; the boot ignoring the saved
  preference.
- Full Pest suite on `hq_test_k`: **1,612 passed, 18,935 assertions, 0 failed (663 s; 1,604 on main plus the 8 new)**. `vendor/bin/pint --test`: passed. `npx tsc --noEmit`:
  clean. `npx vite build`: built. No em dash in `resources/js` (held by the existing test).

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream V2: the language preference per person | yes | not yet (branch pushed for review) | n/a (a screen setting, no tool) | n/a | n/a | n/a | n/a | no |

Nothing an agent could observe or use changed: no prompt, tool, policy, runtime path or agent row. One
additive migration.

## Open questions

1. Whether the profile page should also show the language as a field beside the name (one input).
2. Whether a person's saved preference should also drive the language of alerts sent to them (Telegram),
   which today are English; a separate decision, since alerts are not screens.
