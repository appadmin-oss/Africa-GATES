# Africa GATES — notes for anyone (or anything) working in this repo

Start with `docs/CODEBASE-INDEX.md`. This file is only the handful of facts that have each
caused a real, shipped bug — the ones worth knowing *before* you write the first line
rather than after the review.

## The stack, and the two shapes of it

PHP 8.4 · Slim 4 · Twig 3 · Eloquent's Capsule used as a **query builder only** (no models,
no ORM). Tables are `gates_*`.

**Production is MySQL; dev and the test harness are SQLite.** This divergence is the single
most productive source of bugs in this codebase, because SQLite forgives what MySQL
enforces:

- SQLite ignores integer widths and `ENUM`. A value that fits in dev fails in production —
  `TINYINT UNSIGNED` caps at **255**, which has bitten a `sort_order` already.
- **And `INSERT IGNORE` does not refuse an oversized id, it CLAMPS it.** Thirty test files
  seeded `gates_award_programmes` with ids like `9800`; on MySQL every one of them became
  **255**, so they all resolved to the same programme. A nominee's submission pointed at
  255 while their questionnaire config had been saved for 9800, nothing matched, and
  `styleFor()` quietly served the guided form to somebody the programme had configured for
  an interview. Twelve tests asserted an interview screen and got a questionnaire.
- **A value outside an `ENUM` is `Data truncated`, not an error you will notice.** Two
  shipped: `JudgeSchedule` filtered the schedule screen on a status `'scheduled'` that
  `gates_interviews.status` has never allowed — so it matched nothing on production, while
  `'draft'` ("created, nobody told yet") was missing from the same list and never appeared
  on the one screen whose job is listing sittings. Both lists claimed the same thing in the
  same words; only `InterviewService::PENDING` was right. One resolver, never two.
- **Correcting a constraint needs a repair migration, not a corrected definition.** A
  migration that rebuilds its table only when the table is *empty* leaves the old
  constraint on every database that has rows, permanently. `gates_event_invites.audience`
  shipped as `ENUM('principal','child','judge')` and was corrected to
  `ENUM('nominee','judge')` one commit later; production kept the first. `'judge'` is in
  both sets and `'nominee'` is in neither, so "Build the list" minted judges and only
  judges — while dev, whose table was built fresh from the corrected definition, was
  correct and green. See `2026_11_06_invite_audience_widen.php`.
- MySQL normalises a `T`-separated datetime when it lands in a `TIMESTAMP` column. SQLite
  stores the string verbatim, so `2026-01-01T09:00` compares wrong and a comparison that
  passes every test silently rejects real input.
- **SQLite does not enforce a foreign key here at all, and the harness turns them off.**
  `gates_audit_log.admin_id` has an FK to `gates_admins`, and 71 call sites write
  `(int) ($_SESSION['admin_id'] ?? 0)`. There is no admin 0, so MySQL refused every row
  written without a live session — cron, the console, an expired session — and
  `AuditService::record()`'s catch swallowed it. The audit log was failing at the one
  moment it matters most, green in the suite the whole time. Normalise a sentinel where it
  is written, not at 71 call sites, and pin it on the **stored value** so the assertion
  survives `PRAGMA foreign_keys = OFF`.
- **And one trap runs the other way, which is worse.** `LIKE` needs its wildcards escaped;
  MySQL's default escape is a backslash and **SQLite has none at all**. So
  `LIKE 'stand\_call.%'` matches on production and returns **zero** rows in dev and in the
  suite — the failure looks like the feature simply not working, and somebody "fixes" a
  filter that was never broken where it runs. Spell the clause out, and not with a
  backslash: `ESCAPE '\\'` is one character to MySQL and two to SQLite, `ESCAPE '\'` is an
  unterminated literal to MySQL. `!` is safe in both. See `AuditService::like()`.
- Anything with a `NOT NULL` column and no default will pass in a test that omits it only
  if you got lucky; check the schema, not the fixture.

Both schemas live in `database/`: `admin-schema.sql` / `community-schema.sql` and their
`sqlite-*` counterparts. **Migrations run in filename order, not date order.**

## Twig: the trap that shipped twice

A `{% set %}` inside a `{% block %}` is invisible to every other block, and renders as
`null` **silently** — no error, no warning, just an empty attribute or a dead nav.

This took out the whole account-page navigation once and a vote page's share link a second
time. Hoist anything used by more than one block to template scope.
`tests/Unit/TwigBlockScopeTest.php` scans every template for it now.

## The admin CSP has no `'unsafe-inline'`

So no `onclick=`, no inline `<script>` without a nonce. The convention is
`data-ag-do="..."` with a delegated listener in `public/assets/js/admin.js`; `data-confirm`
on a form routes it through `agConfirm`.

## A header can switch a feature off in a way nothing on the page can see

`Permissions-Policy: camera=()` denied the camera on **every page of the site**, so the
door's ticket scanner had never worked in production on any device since it shipped.
`getUserMedia` was rejected by the browser before a line of the page's own code ran, and
the page's catch wrote "Camera unavailable — type the code" — indistinguishable from a
refused prompt or a broken lens. Nothing anywhere pointed at a header.

Two things make this worth a section of its own. **A test asserted `camera=()` by name**, in
a list of things that ought to be denied, so the bug was not merely unnoticed — it was
enforced. And the header is set in **two** places: `SecurityHeadersMiddleware::SHARED` and
`public/.htaccess`, where Apache's `Header always set` REPLACES rather than conflicts, so a
divergence never shows up as an error. `SecurityHeadersTest` compares them.

`media-src` is the same shape of trap: it is `'self'` plus two video hosts, with no `data:`
and no `blob:`, so audio returned as a data URI is blocked with nothing an operator can see.

## A `<form>` inside a `<form>` is silently deleted

The HTML parser **ignores** a `<form>` start tag while a form is already open — not nested,
not errored: dropped. Its children survive and are adopted by the outer form, so the button
renders, is styled, is enabled, and posts to the **outer** action. No console warning, no
validator, and the server sees a valid request to a route that exists.

Three shipped: Settings' "Check the sync" saved the page instead of probing Google, a
category's "Delete" ran the update, and the questionnaire's "copy the outcomes in" posted an
empty list to the route that stores outcome lists. Use `formaction` on the submit button.
`tests/Unit/NestedFormTest.php` scans every template now.

The JS half matters too: `form.submit()` drops the pressed button's `formaction`, so a
confirmed delete sharing a form with a save would run the save. Both `data-confirm` handlers
use `requestSubmit(submitter)`.

## Anything operational must be settable from `/admin/settings`

There is no shell on production, so a credential read only from `.env` is a credential that
cannot be set. `GAS_URL` and `GAS_SECRET` were exactly that: the whole Google Calendar and
Meet integration was dead while every screen explained itself correctly and told the
operator to edit a file they cannot open.

The pattern is `gates_settings` first, `.env` as the fallback, resolved by one static per
service — `AiService::boot()`, `GoogleMeetService::gasUrl()`. One resolver per value, never
two: `GoogleSheetsService` shares the calendar's, because two readers of one setting is how
the halves of an integration come to disagree about whether it is configured.

## Running the tests

```bash
./vendor/bin/phpunit
```

**Remove a local `.env` first, and clear `var/data/` of run state.** A dev `.env` carrying
`OPENAI_API_KEY` leaks into the suite and breaks ~14 questionnaire/interview tests that
assert the no-provider path. Running the dev server also leaves
`var/data/.gates-maintenance.lock` and `.maintenance_tick` behind, and those make
`MaintenanceTest` fail **in the full suite only** — it passes in isolation, so the failure
looks like whatever ran before it.

```bash
mv .env /tmp/env.bak; rm -f var/data/.gates-maintenance.lock var/data/.maintenance_tick
./vendor/bin/phpunit --no-coverage
```

Both traps name code you did not touch, which is what makes them expensive.

The harness builds an in-memory SQLite database from the three schema files and then runs
every dated migration, with `PRAGMA foreign_keys = OFF` so unit seeds can stay minimal.

### The MySQL parity run, which is the one that finds things

```bash
TEST_DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_NAME=africa_gates_test \
  DB_USER=… DB_PASS=… ./vendor/bin/phpunit --no-coverage
```

Real ENUMs, real integer widths, strict mode, `ONLY_FULL_GROUP_BY`. Everything in the
MySQL/SQLite list at the top of this file is invisible without it.

**Read the count, not the exit code.** Piping to `tail` or `grep` gives you the pipe's
status, not PHPUnit's, and a run with two hundred errors exits 0 through a pipe.

Three things used to make its output unreadable, and all three are fixed — but they are
worth knowing, because each turned ONE fault into hundreds and none of the hundreds was
about the test reporting it:

- **DDL implicitly COMMITs**, so a test that inserts and then issues DDL has already made
  its rows permanent when the rollback runs. `TestCase` plants a marker inside the
  transaction and purges when it survives. It used to count six named tables instead, and
  that list only ever grew — an enumeration of past failures is never a fix for the next
  one.
- **`information_schema` caches `AUTO_INCREMENT` for a day** (`information_schema_stats_expiry`).
  The narrow-counter rewind read it, saw a value from the start of the run, and skipped
  every time while the real counter stuck at 255. The session now sets the expiry to 0.
- **`ALTER TABLE … AUTO_INCREMENT = 1` is clamped UP to `max(id)+1` when rows exist**, so a
  reset on a non-empty table silently does nothing. Empty it first.

`tests/Feature` was outside the `testsuite` element and had never run — ninety-two tests
that read as coverage in a directory listing and were not. Both directories are in now.

## Scheduled work has no shell

There is **no SSH on production**. Maintenance runs through one orchestrator
(`src/Support/Maintenance.php`) behind two doors: `cron/maintenance.php` (needs a shell)
and the token-gated `/__cron/run` (does not). A Cloudflare Worker drives the latter —
`deploy/cloudflare/`, guide in `docs/CLOUDFLARE-CRON-WORKER.md`.

**`/__cron/run` returns `200` with `ok:false` on a partial run.** Deliberately: it used to
return 500, and webcron services reacted by disabling the job, which stopped the tasks that
were still working. Anything monitoring it must parse the body. `Maintenance::TASK_FAILED`
is `-1`; `0` means "ran, nothing to do".

Full account in `docs/CODEBASE-INDEX.md` §16.

## Things that must stay true

- **A criterion, code, or record that has been used is retired, never deleted.** Ballots,
  receipts and published results point at rows by id; deleting one changes history that has
  already been published. See `src/Services/JudgeRubric.php` for the worked example.
- **The sandbox must never reach the public.** `DemoSeeder` creates real rows with real
  flags, because the sandbox exists to be walked through for real. Every public reader has
  to exclude them — `JudgeService::realJudges()` is the pattern.
- **Anything a partner or nominee typed is untrusted in JSON-LD.** `layout/gates.twig`
  renders it with `JSON_UNESCAPED_SLASHES`, so `</script>` in a campaign title closes the
  script element. Everything in `src/Support/Schema.php` goes through `text()`.
- **No secrets, no model identifiers, and no operator email addresses in commits.**
- **And its mirror image: a record everything writes that nothing can ask.** `gates_audit_log`
  has 124 writers and passed every sweep — §17's (is anything reading it?) and §19's (does
  prose promise it?) both. It still could not answer a single question anybody brings to an
  audit log, because its two readers were the dashboard's last **twelve** rows and a generic
  table dump rendering the admin as `7` and the target as `412`. `ip_hash` ends in `_hash`,
  so `DataRegistry::isHidden()` stripped it from the detail page and the CSV export alike —
  never rendered anywhere since the table shipped. The question that catches this shape is
  not *is anything reading it* but **can the only reader answer the question the data was
  collected for?** `docs/CODEBASE-INDEX.md` §23.
- **A declared field with no reader is the most expensive bug available here.** Six have
  shipped: `AiCapability::$model` (read into the log, never onto the wire),
  `AiCapability::$timeout` (nothing at all — every summary ran on a 6s default and the
  status page read "0% answering" for weeks), `TicketLinkService::prune()` (no caller, so
  every dead link was permanent), `gates_ai_calls.error` and the `failed` rows in
  `gates_judge_orientation` (both written since day one, both unrendered), and
  `gates_status_log.components_json` (stored every 15 minutes for the life of the log, so the
  status page could say "something broke on the 14th" and not which thing). With no shell on
  production the symptom always looks like something else. **Grep for a reader before you
  believe a declaration.** Full account in `docs/CODEBASE-INDEX.md` §17.
- **A whole-schema sweep found three more, and they are the worst kind.** Each was a
  behaviour the documentation already *promised*: `gates_interviews.bot_disclosed_at` (the
  index described a consent stamp nothing wrote), `gates_nominee_submissions.skipped_json`
  (its own migration named the harm — a panel reading a decline as "not answered"), and
  `gates_nominee_submissions.reminded_at`, where the missing reader was the reason a warning
  did not exist at all: a nominee was **removed from an award** by an unattended 06:00 rule,
  having heard from us exactly once, months earlier. The distinguishing question for the
  next sweep: **is there prose somewhere promising what this column does?** A column nothing
  has claimed for is a vestige; one the docs, a migration comment or a screen has already
  promised is a lie with a schema behind it. §19 lists all three, plus the four that really
  are vestiges, so nobody re-derives them.
- **The same sweep over public methods found the worst instance of either.** `votes:recover` mints
  votes for people whose vote code this platform failed to deliver, and it was reachable only from a
  console command on a host with **no SSH** — while its own two-person rule required an admin panel
  nobody had written, so `apply()`, which refuses anything not `approved`, could never fire by any
  route. And `disclosureFor()`, the "public disclosure of every applied batch" its doctrine calls the
  strongest control, had no caller: so opening a route in without publishing its use would have been
  strictly worse than leaving the mechanism dead. Both halves shipped together, and must stay
  together. The method sweep's question is sharper than the column sweep's: **what does this method's
  own docblock claim about the running system?** One with no caller is that claim being false.
  `docs/CODEBASE-INDEX.md` §20.
- **And its sibling: a mechanism with no route in.** The Chrome extension's install note
  named a folder nothing served and no shell could fetch; the extension had also hardcoded
  one hostname into `host_permissions`, which no popup setting can override. Its content
  script returned on line one for anybody who reached a Meet call by clicking it rather than
  opening its URL. Each part was complete and correct in isolation. `docs/CODEBASE-INDEX.md`
  §18.

## Two things about the events page's tier list

**A tier's colour is a slot, never a hex.** `EventTierPalette` resolves it from the event's
own `ticket_accent` on every read, so changing the accent moves the whole ladder — including
the selection light on the registration card and the dot on the printed ticket, which read
the same value for that reason. Do not add a *hex* picker, and do not invent a palette for
a new surface: `EventTierTone::hues()` is the one resolver.

It returns **two** values and the distinction matters. `hue` is `fill` — the identity, the
swatch the organiser picked, what the light and the dot are painted with. `edge` is the
darker variant, and it is only for things that owe 3:1 against white (WCAG 1.4.11): a
border, a ring, a hairline that holds. Painting the light with `edge` shows the organiser a
colour they did not choose; drawing a border with a pale `fill` shows them nothing at all.

**The same split runs through `EventFlierTheme`, inverted.** The flier's whole palette is
derived from that one accent too — nothing stored, nothing picked, a style is a key — and it
publishes `accent` at 3:1 for the rule and the chip fill and `accent_text` at 4.5:1 for the
kicker and the invitation. One value for both made a gold event's flier come out olive.

And the reason to trust any of it: `EventFlierThemeTest` **samples the accent space** — the hue
wheel at two saturations and three lightnesses, plus the greys and the primaries — and asserts
the contrast floors rather than the colours. Every fault it has caught was on an accent nobody
would have written down: `bold` collapsing the name, the title and the date to one white on a
mid-lightness saturated hue, and pure `#0000ff` landing in the light band because HSL lightness
says 0.50 and the eye does not. Do not add a colour path here without extending that sweep.

**Tiers are ordered by `sort_order`, not by price.** So `loop.last` is whichever row the
organiser dragged to the bottom, and rank is a price question answered in `EventTierTone`.
A design handoff asked for `loop.index0` here; it would have made the cheapest tier sweep
hardest for any organiser who puts their premium row first, and nothing about that failure
is visible from the template.

## Generated images are GD, server-side, and share one set of hands

There is no headless browser on this host and there cannot be. Every generated graphic —
the nominee share card, the ticket, the event flier — is rasterised by GD from the faces in
`resources/fonts/`, and the primitives live in **one** place: `FlierRaster`. Text with
letter-spacing, wrapping measured against the real face, gradients, cover-crop, photo
loading. Do not write a second `cover()`: two renderers with their own crop maths is how one
graphic centres a face and another cuts the chin off, and neither looks wrong on its own.

Two GD traps that have each cost a render pass here: `imagefilledrectangle()` is **inclusive**
of both corners, so filling to `y + $h` draws one pixel more than the geometry you measured
with; and `imagettftext()` takes a **baseline** while every box you draw grows downward from
its origin, which is how a chip came to sit on top of a name.

## `Support\Qr` has two entry points and they are not interchangeable

`encode()` is for a **ticket code**: version 1, alphanumeric, 16 characters, and it folds
case because a code read off a screen may be typed either way. `encodeBytes()` is for a
**URL**: byte mode, versions 2–6, case preserved, 74 bytes. Uppercasing a URL path produces
a code that scans perfectly and goes nowhere.

`Qr::SIZE` is only true of version 1 — read `count($matrix)` for anything from
`encodeBytes()`. Vectors are verified by **decoding** (`tests/Support/qr-bytes-vectors.py`),
never by diffing another encoder: the pad region after the terminator is not uniquely
determined, so two correct encoders disagree byte-for-byte.

The 4-module quiet zone is **the specification**, and it is the reason to keep it. Do not
trim it for a tidier inset.

Do not try to justify it by simulating recompression either — that was tried here and the
harness cannot resolve it. `tests/Support/qr-recompression-check.py` decodes a rendered
symbol after downscale-and-JPEG, and shifting the plate by **one pixel** flips pass to fail,
with the 2-module zone sometimes surviving where the 4-module one does not. It measures
alignment artefacts of the resampler against OpenCV's detector, not robustness. It is a
smoke check that a symbol decodes at all; the threshold question needs a camera.

## House style

Comments explain *why*, and name the failure the code exists to prevent — this codebase is
maintained by people who were not in the room. Match the density of the file you are in.

Design system: paper ground `#f0f2f2`, hairline rules, mono micro-labels, one gold accent
`#f3b416`, emerald `#237b22` reserved for action.
