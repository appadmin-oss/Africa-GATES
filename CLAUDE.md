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
- **And with milliseconds and a zone it is not normalised, it is REFUSED** — and that broke
  every recurring gift on production. Paystack sends `2026-10-04T09:00:00.000Z`; it went
  straight into `next_charge_at`, and into `confirmed_at`/`created_at`/`last_charge_at` on
  the donation row. Strict-mode MySQL answers `Incorrect datetime value`, the statement
  throws, and `PaymentController`'s webhook catches `Throwable` and still returns **200** —
  deliberately, so the gateway does not retry for three days. So `subscription.create`
  never activated anything (the row stayed `pending` and `email_token`, which is the
  donor's stop button, was never stored) and `charge.success` never minted the donation
  row: the second month's money arrived in the bank and nowhere else, which is the exact
  failure `RecurringGiving::chargeArrived()`'s own docblock says it exists to prevent. All
  of it green in the suite. One normaliser now — `RecurringGiving::stamp()` — and it
  returns **null** rather than throwing, because a date the gateway sent must never cost
  somebody the ability to stop giving us money.
- **A float written into a `TINYINT` is rounded on MySQL and stored verbatim on SQLite**,
  which makes a fixture assert on data the platform cannot hold. `gates_judge_criteria_scores.score`
  is `TINYINT`; `CommunityHalfDarkTest` wrote panel marks of `7.9` and `8.0`, so on SQLite
  two nominees were a tenth of a mark apart and on the production database they were
  IDENTICAL — while the test asserted the difference decided the award. A judge writes whole
  numbers, so with four equally weighted criteria the reachable averages are quarters. The
  helper now builds the mark from whole scorecards and fails loudly when asked for one they
  cannot produce.
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
- **And `CREATE INDEX IF NOT EXISTS` is SQLite syntax MySQL answers with a 1064** — a
  fault whose cost is nothing like one missing index. `MigrateCommand` aborts the run on a
  throw and does **not** record the file, so the migration re-runs on the next deploy, and
  by then the guard above the statement ("table already present", "column already added")
  is true: either the file is skipped for ever with its index never created, or — where
  nothing above it can become true — it throws again on every deploy and **every migration
  dated after it never applies**. Three shipped together, and each had already committed
  its `CREATE TABLE` before throwing: `gates_name_says` lost the UNIQUE key on `name_key`
  that is the whole point of the migration, `gates_donation_subscriptions` lost all five of
  its indexes including the UNIQUE on `manage_token` (the donor's stop button) and the
  webhook lookup on the hot path of every recurring charge, and
  `gates_vote_snapshots.idx_snap_cycle_kind` is read by a public result page on every view.
  `SchemaIndex::ensure()`/`drop()` exist for this; never write the raw form outside a
  branch only SQLite reaches.
  **The lesson is the guard, not the syntax.** `SchemaIndexTest` was already watching for
  exactly this and passed all three, because it asked *does this FILE mention the driver*
  (`str_contains($body, '$sqlite')`) rather than *is this STATEMENT in a driver branch* —
  and nearly every migration declares `$sqlite` to pick its column types, so the test
  excused the files most able to offend. It reads the tokens now, per statement, and knows
  the polarity: `if (!$sqlite) { CREATE INDEX IF NOT EXISTS … }` mentions the driver and is
  the offence in its purest form.
- Anything with a `NOT NULL` column and no default will pass in a test that omits it only
  if you got lucky; check the schema, not the fixture.
- **And one trap is the BUILDER rather than either driver, and it is silent on both.**
  `where($col, $value)` with two arguments means `where($col, '=', $value)`, so an array
  value is bound as a scalar and the driver reads its **first element** — no exception, no
  warning, a query that runs and under-selects. Verified: three rows, two of them named in
  the array, and the update touches one. It is the shape that costs most inside a
  destructive operation: the nominee merge scopes `gates_otp_tokens` to an allowlist of
  purposes, and three of the four reassign sites spelled the clause without a `whereIn`, so
  rows for every purpose after the first would have stayed pointed at a merged-away nominee
  while the journal — the record used to review and undo a merge — recorded only what
  moved, and `restore()` then reported a clean unmerge. `MergeJournal::applyScope()` is the
  one clause; never write a second.

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

**Then it happened twice more, off the same denied list, and one of those was pinned by name
too.** `autoplay=()` meant the door had never once greeted a guest aloud on any device — the
clips were rendered, the check returned the right key, `EventLifecycleTest` walked a guest
through and passed, and the browser refused `play()` before any of that reached a speaker.
`microphone=()` did the same to the two places a nominee may answer a question out loud
(`my-work.twig`, `my-work/interview.twig`), whose catch says "Your phone may be asking for
permission — allow it", sending that person to a prompt that was never going to appear.

Every one of these fails as a **rejected promise the page swallows on purpose**, and it is
right to swallow it: a door with no sound is a working door, and nothing about a greeting
may hold a queue. That is exactly why they last for months. No error, no console line, no
log, nothing to grep — the feature is simply not there, and the page's own fallback copy
describes some other cause.

So the question to ask of this header is never "is each denial correct?" but **"is any
capability this site's own code actually calls being denied?"** — and it is now asked on
every run, over the shipped templates and JS, by
`SecurityHeadersTest::test_no_capability_the_site_actually_uses_is_denied_by_the_header`.
A denial is only ever right for a capability nothing here reaches for. Note what that sweep
has to do that a naive one does not: `getUserMedia` is two features wearing one name, and
only the **constraint** says which — the door asks for `audio: false`, and a sweep reading
the call rather than the constraint has the door vouching for a header that mutes somebody
else. (`\s*` before a `(?!false)` backtracks to zero width and the lookahead then reads the
space, so the value is captured and compared, never excluded by a lookahead.)

`media-src` is the same shape of trap and had already caught the other half of the same
feature: it was `'self'` plus two video hosts with no `data:` and no `blob:`, so the
nominee's read-aloud — which plays its audio through `URL.createObjectURL` — was blocked a
second time, independently, by a policy line. `blob:` is allowed now; `data:` still is not.

**And a header is only ever half of an autoplay fault.** Both mobile browsers gate audible
playback on an element having been played inside a user gesture, and the door's greeting
fires from the scanner's decode loop, which is a timer. The player used to be built lazily
*inside* that callback, so the only element that ever existed had never seen a gesture and
never could. It is now built up front, unlocked muted on the steward's first touch of
anything, and a refusal that survives both reveals a one-tap control on the dock rather than
being dropped — because at a gate the person who can fix it is standing right there.
`DoorGreetingPlaysTest` holds all of it.

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

## The admin console's shape, and the two directions a nav can be wrong

The rail is **seven headings, and seven is a floor rather than a taste call**: there are
exactly seven `admin_sections` gates and a section can carry only one, so fewer sections
means moving a page to a different gate. That is an access change and **must never ride
along inside a navigation change** — `AdminNav` records `gate` per section and
`AdminNavTest` asserts it against the original mapping. Sections are uneven by design
(three items in Programmes, eleven in Content); one opens at a time, the tail is in the
in-page sub-nav and the `⌘K` palette, which is the documented hybrid resolution to NN/g's
finding that hidden navigation roughly halves discoverability.

**And a nav can be wrong in two directions.** `AdminNavTest`'s fourteen tests all read
NAV → ROUTES — every entry is a real route, no page twice, every item has a sprite icon.
Not one read ROUTES → NAV, which is the exact direction `AdminNav`'s own docblock says the
class was built to fix ("a new page could be built, routed and permissioned and still not
appear in the nav"). Moving the links into a class made the tree greppable; nothing checked
it was complete. So the fault it was built to prevent was still live, and had recurred:
`/admin/settings/providers` — "the page that asks every provider a real question" — was
routed, declared `admin_page: 'settings'` so the rail highlighted Settings while an
operator stood on it, and was linked from nowhere. Not the rail, not Settings, and so not
the palette either, which is generated from the rail.

`AdminIaTest` asks the other question: **is every admin page findable without knowing its
URL?** In the rail, or linked from a template. Its exclusions are KINDS with reasons —
authentication, `/new` create forms, downloads, fragments — never a list of pages nobody
linked. It also holds that the rail label and the page's own `topbar_title` AGREE (the rail
said "Revenue" over a page headed "Finance"), one casing convention (sentence case), and a
ceiling of twelve items per heading before a grouping becomes a list.

**A sub-page is linked from the page it belongs under, not added to the rail.**
`/admin/shop/codes` from shop orders, `/admin/nominees/duplicate-scan` from nominees. That
is what keeps the rail scannable, and it needs no new page key or sprite icon.

**Judging is spread across three gates and that is not a tidy-up.** `judges` and `rubric`
under `configuration`, `judging-audit` and `result-release` under `data`, `interviews` and
`questionnaires` under `moderation`. It reads as a fragmented workflow and it is
gate-driven: consolidating it moves pages between gates, which grants or removes access for
whole roles. Raise it as a product decision; never do it inside a navigation change.

### Admin documentation lives in the console, not in `docs/`

There is no SSH on production, so an administrator cannot open a Markdown file — `docs/` is
for whoever changes the code. The handbook is `/admin/handbook`, in the always-visible
section so every role can read it, because somebody whose permissions do not reach Money
still needs to know Money exists and why their rail is shorter than a colleague's.

**Its structural facts are LOOPED FROM THE CODE, and that rule is the whole design.** Areas
from `AdminNav::sections()`, roles from `Permissions::MATRIX`, weights and quorum from
`RuleEngine`, verification words from `RegistryCheck::STATES`, the grace window from
`ANNOUNCE_GRACE_DAYS`. This repo has paid four times for prose outliving the rule it
describes, and a handbook is that hazard with people actively told to trust it — the
settings screen was found explaining the default scoring basis with a *different* basis's
arithmetic, 157 points out, on the screen where an operator picks it.
`HandbookTest::test_the_numbers_are_read_from_the_rules_and_not_typed_in` renders the page
twice against different rulesets and requires the figures to move. **Anything typed there
that could have been computed is a bug waiting to happen**, and a second admin-facing
document anywhere else is a second thing to keep true: add to the handbook.

## A legal page that states a fact is a fact that will go stale

`/cookies` said in bold **"We set one cookie"** — there were three; `ag_region` and
`ag_currency` are written by `document.cookie` from the shop's selects and last a year — and
in bold **"We run no analytics"**, while `VisitTracker` recorded every arrival's source,
campaign, landing page, device and country by default. Both sentences were true the day they
were typed. That is §19's shape on a document people are asked to rely on.

Three things made it last, and each is a rule:

- **The evidence it cited was the wrong evidence.** Its docblock said "checkable against the
  code: see `session_set_cookie_params()`", so checking the named place *confirmed the false
  answer* — the second writer is a `data-cookie` attribute and one delegated listener.
- **A test asserted the false claim by name**, requiring the string `no analytics` to be
  PRESENT — `SecurityHeadersTest` pinning `camera=()` again. And its sibling forbade the bare
  string `google analytics`, which forbids the page from *denying* it: **a sweep that cannot
  tell a denial from an admission pushes a page towards saying nothing.**
- **The opt-out was `DNT`/`Sec-GPC` and nothing else.** Chrome and Safari both removed Do Not
  Track, so most visitors had no way to say no while the page promised they would be asked.

So the facts are generated: `Support\CookieRegistry` (derived — the session cookie's name and
life come from `session_name()` and `session_get_cookie_params()`), rendered by
`LegalDocument::cookiesHtml()` in the same place as the AI disclosure so `/cookies.txt`
carries it. `CookieRegistryTest` sweeps the shipped templates and JS and fails by name on a
cookie or storage key nobody declared — storage keys as PREFIXES, since several are per-item.

`Services\CookiePrefs` is the one resolver for "may we count this person", and the rule is one
sentence: **if anything said no, the answer is no** — a header beats a stored yes, which is
stricter than the GPC specification requires and is deliberate. `VisitTracker` lost its own
`optedOut()`. The posture is a setting (`visits_consent_mode`), because whether first-party
audience measurement needs consent is a legal position rather than a fact, and the generated
section states which one is running. **`consent` mode with nowhere to consent counts nobody
for ever**, so the mode and the notice shipped together.

Both controls are plain forms that post — a privacy control needing JavaScript is missing for
exactly the people likeliest to block it — the notice's two answers carry an identical class
string (asserted, because "accept is a button, decline is grey text" is the pattern the rules
exist to stop), and dismissing without answering is not offered. The prose is the promise; the
generated section is the fact. `2027_01_23_cookie_policy_repair.php` corrects production's
stored copy **only where `updated_by IS NULL`** — an operator's edits are theirs.
`docs/CODEBASE-INDEX.md` §24.

## A bare hex is not a colour: `fill`, `edge`, `ink`, `wash`

The house style used to name **one gold accent, `#f3b416`**. On the house paper it is
**1.65:1** — under the 3:1 a border owes and far under the 4.5:1 a word owes — and it was
being used as a hairline and as a mono micro-label. That is the whole reason the site read
monochrome: not too little colour, but **colour used as a LINE when it is only ever visible
as a FIELD**. Three of five tokens failed on paper (`--ag-gold` `#c9a24b` 2.13 was a SECOND
gold nothing mentioned; `--ag-green-light` 1.79; `--ag-pulse` 4.08 — a pass for a border and
a fail for a word, and it WAS a word on three public screens). Underneath: **642 distinct hex
values in the templates**, four golds among them, because people were hand-deriving this ramp
separately.

`Support\Accent` is the ramp. Same `fill`/`edge` split `EventTierTone` already draws; **never
invent a fifth name.** A `fill` owes NO floor and that is load-bearing — demanding 3:1 of it
forces the gold to a mustard nobody chose, which is how an accessibility pass fixes a palette
into blandness. The rule held instead is that a fill is never the only carrier of meaning.
`AccentTest` re-derives every floor, keeps each lifted value within 18° of its identity, and
caps a public template at **two roles** — rarity is not something a palette can hope for.

`Support\Contrast` is the one relative-luminance implementation; there were four, and the
fifth is where the wrong threshold lands. Each caller keeps its own threshold (`Swatch`'s
0.45, `EventTicketDesign`'s 0.36): a printed ticket's contrast decision must not move
underneath a consolidation.

**A colour sweep must know the GROUND, and four things stop it knowing.** Mine reported 36
findings on its first run and essentially all were correct code. BEM naming does not encode
containment (`.vn-ballot__k` is inside `.vn-ballot__top`, which is dark). A selector can be
declared twice with different grounds (`.jg-chip`). A CSS comment above a rule becomes part
of its selector and the guard then skips the rule — so a sweep goes quiet in exactly the
files somebody documented. And the hex alone decides nothing: `#e0245e` is 4.58 on white,
4.42 on `#fbfbfa`, 4.08 on paper. Ask only what CSS states: the block's own background, or a
descendant selector naming an ancestor that declares one.

**Three of WCAG 2.2's four newest criteria were unmet.** 2.4.11 (sticky nav and fixed tab bar
covering whatever the keyboard focused — `scroll-padding` on the scroll container, read from
the chrome's own tokens); 2.5.7 (the globe rotated by drag alone) together with 2.4.7 (a
far-side marker is `opacity:0` and still in the tab order, so focus landed on something
invisible — both fixed by focusing a marker turning the globe to it); 2.5.8 (44px under
`pointer: coarse` was the only floor, and the AA 24×24 is not conditional on the pointer).
`docs/CODEBASE-INDEX.md` §25.

## Anything operational must be settable from `/admin/settings`

There is no shell on production, so a credential read only from `.env` is a credential that
cannot be set. `GAS_URL` and `GAS_SECRET` were exactly that: the whole Google Calendar and
Meet integration was dead while every screen explained itself correctly and told the
operator to edit a file they cannot open.

The pattern is `gates_settings` first, `.env` as the fallback, resolved by one static per
service — `AiService::boot()`, `GoogleMeetService::gasUrl()`. One resolver per value, never
two: `GoogleSheetsService` shares the calendar's, because two readers of one setting is how
the halves of an integration come to disagree about whether it is configured.

Fifty-two hand-rolled reads across thirty-five files is fine — that IS one static per
service. **Two readers of one KEY is the fault, and the pair most likely to disagree is the
one that PUBLISHES the value and the one that ACTS on it.** `review_sla_hours` had three
readers: `config/container.php` cast it into a Twig global with no floor (printed on
`/nominate-success` as "usually within N hours" and on `/integrity` as "Acknowledge the
complaint within N hours"), `GuideService` cast it with no floor into the site-state block
the assistant may quote to the public, and `Maintenance` floored it at one before deciding
when the acknowledgement mail goes. The only reader that acted on the number was the only
one that guarded it. **A number a screen prints as a promise needs its floor at the read,
and the form must not offer the value that breaks the sentence** — this one was `min="0"`
with a `max(0, …)` writer, so nought was savable and reads like switching the promise off.
It switched nothing off; it promised two public pages' worth of instant review while the
mailer carried on at one. `NominationFeedbackService::slaHours()` is the resolver, and it
takes an already-loaded row so a caller holding the settings array queries nothing twice.
`OneResolverPerSettingTest` sweeps for the shape.

Two things that sweep had to learn, both of which had it lying. It must resolve a key
reached through a class **constant**, or it goes blind exactly when a fault is fixed — the
owner stops spelling the literal. And that constant map must be scoped **per file**: keyed
by bare name across the tree, the last `LAST_ERROR` parsed wins and `AzureVoice` is
reported as a second reader of ElevenLabs' key; `SETTING` does the same to `DoorVoice` and
`DisplayTime`. Two invented findings, both plausible enough to send somebody refactoring
correct code.

**And one probe, not one per service.** "Does this database have that column?" has to be
asked here — migrations are applied by an operator opening a URL and the admin layout
counts unapplied steps in the dozens. The four lines that ask it existed **four** times and
had drifted on the only thing with a cost: two memoised, `AnalyticsService` did not, and it
asks twenty-four per dashboard render, each fetching the table's whole column listing.
Twenty-four round trips per page, invisibly — the page draws and the figures are right.
`Support\SchemaHas` is it; it never memoises a `false` that came from a throw, because a
dropped connection recorded as "no such column" makes a merge skip a table, journal nothing
for it, and report success.

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

### What a new test owes, and three ways one lies to you

**Prove a new sweep FAILS before you trust it passing.** Every sweep in this file exists
because something shipped, and several of them passed over the thing they were written for
— `SchemaIndexTest` excused all three 1064s, `SecurityHeadersTest` asserted `camera=()` by
name while the door's scanner was dead, `scale_is_out`'s own test kept passing because the
fixture has one category. A sweep is only evidence once you have broken the code and
watched it name the break. Do that before committing it, and say so.

**A memo can only be tested by counting queries.** It has no other observable behaviour —
same answers, fewer questions — so "it returns true" passes on the unmemoised version it
replaced. `SchemaHasTest` counts, and so does `EditionScaleTest` for the scorer's per-cycle
memo. Assert the first call reaches the database too, or a broken probe that answers from
nowhere also passes. And reset the memo in `setUp()`: it is per PROCESS and the suite is
one process, so the first test to run otherwise seeds every later one's answers and the
counting proves nothing.

**A FIXTURE THAT WRITES A COUNTER AND NO ROWS IS SCORING A DIFFERENT RULE.** `vote_count`
is denormalised and `gates_votes` is the ledger, so a fixture that sets the first and not
the second puts its whole edition into the unmeasured path — the people term, 70% of the
community half, is not paid at all. Five suites were doing it while their docblocks
described the measured arithmetic, and they passed for years because the all-or-nothing
fallback happened to pay the leader the same 450 either way. `OverallWholeFieldTest`'s
documented figures (890 / 794 / 460) turned out to be exactly right and exactly not what
its fixture produced. Write one ballot row per vote — chunked, 500 at a time — unless the
imported shape IS the subject, and say which you meant.

**An enumeration of past failures is never a fix for the next one.** `TestCase` used to
purge six named tables and that list only grew; a sweep for the literal figures of a
retired worked example would fail on the paragraph legitimately documenting the retirement.
Assert the RULE (is any capability the site calls being denied?) rather than the instances.

Three things that made a sweep of my own lie, all of them about reading `src/routes.php`:

- **`$a` is the route-group proxy variable for the API and `/account` groups as well as
  `/admin`.** A sweep for `$a->get(` therefore reports `/api/v1/registry` as an unreachable
  admin page — thirteen false findings. Locate the group's span; do not assume it.
- **A route's path can appear three ways**, because routes are declared relative to their
  group: `/admin/legal` is `'/legal'`, `/admin/shop/orders` is `'/orders'` inside a
  `/shop` group, and `/admin/questionnaires/invitations` is a perfectly real
  `$s->get('/invitations', …)`. `AdminNavTest` has the three-form matcher; reuse it rather
  than write a fourth almost-right version, because two tests disagreeing about what counts
  as a registered route is worse than either being loose.
- **Resolving a template by scanning for an `admin_page` declaration finds the wrong one.**
  Several templates legitimately declare the same key so the rail highlights the right
  section from a sub-page — `scorecard.twig` declares `result-release`. Follow the route's
  own handler instead.
- **And past a certain point, stop parsing and ASK SLIM.** Even with the group span
  located, the API routes are a **closure mounted twice** (`/api/v1` and `/api`), so a
  parser found 563 routes where the router has 755 verb-path pairs — a hundred and
  ninety-two invisible, every versioned endpoint among them, each appearing under a bare
  prefix where it could collide with a public page of the same name. Boot the container and
  the route file the way `public/index.php` does and read `getRouteCollector()->getRoutes()`:
  no prefix arithmetic, and no declaration shape it can fail to understand.
  `RouteTableIntegrityTest` does that, and holds the two silent faults — a verb-and-pattern
  registered twice (the second handler is dead code that reads as live, and **its middleware
  never runs**, which is how a route is permissioned in the source and open in production)
  and a literal registered after a placeholder that matches it (`/results/late` served by
  `/results/{id}` answers **200 with the wrong page**, so it reads as a bug in the handler).
- **A constrained placeholder is not `[^/]+`.** `{id:[0-9]+}` matches digits only, and
  treating it loosely condemns `/admin/shop/codes` and nearly every admin sub-page here.
  Honour the constraint, group it (`(?:…)`, or an alternation swallows the tail), and
  decline an optional segment (`[/{page}]`) rather than approximate it. **And the test for
  that optional segment must ignore brackets INSIDE a placeholder** — a plain
  `str_contains($path, '[')` also matches the constraint, which had the sweep skipping
  almost the whole table while reporting a clean pass.

And one about the harness: **`csrf_token` is a Twig GLOBAL** (`config/container.php`), not
something a controller passes. A render test that builds its own `Environment` under
`strict_variables` breaks the moment the screen gains a form, for a reason unrelated to the
screen — 16 tests at once. Mirror the app's globals in the test; do not default the token in
the template, which posts an empty one and has the write rejected in production.

### The MySQL parity run, which is the one that finds things

```bash
TEST_DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_NAME=africa_gates_test \
  DB_USER=… DB_PASS=… ./vendor/bin/phpunit --no-coverage
```

Real ENUMs, real integer widths, strict mode, `ONLY_FULL_GROUP_BY`. Everything in the
MySQL/SQLite list at the top of this file is invisible without it.

**It has to be MySQL. MariaDB is not a stand-in, and it is the easy one to reach for**
(`apt install mariadb-server`, `mysqld` on the path, the same client, the same connection
string). MariaDB has supported `CREATE INDEX IF NOT EXISTS` since 10.1.4, so a full green
parity run on MariaDB says nothing whatever about the three migrations that were throwing a
1064 on production — it creates the indexes and reports success. Where only MariaDB is
available, run it and say which engine it was: it still catches the ENUMs, the integer
widths, the datetime formats and `ONLY_FULL_GROUP_BY`, and it is blind by construction to
anything MariaDB accepts that MySQL rejects.

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
- **Money buys the tally, never the reach.** The community half is 450 points and it is
  split: **70% (315) is how many verified PEOPLE backed a nominee**, 30% (135) is the total
  tally — bought, free and granted votes added together. Both are shares of the biggest
  figure in the whole **edition**, never of the nominee's own category — see the denominator
  bullet below, which is the half of this rule that decides the overall award. Two nominees
  on 2,000 votes each — 2,000 also being the edition's largest tally — one from a thousand
  supporters and one from two, score **293 and 135**.
  **That pair used to read "450 and 136" here and on the settings screen, and it was the
  wrong basis's answer.** 450/136 is what `reach` pays, where the 315 has its own
  denominator; under `ideal`, which is the rule, both terms divide by the largest tally, so
  a thousand supporters on the cycle's biggest tally take 293 of 450 and not the lot. The
  number was stale on the one screen an operator picks a basis from, under a paragraph
  calling it the default — §19's shape, on the arithmetic that decides an award. Recompute a
  worked example when a basis changes, or delete it; a wrong one is worse than none, because
  it is what somebody checks their understanding against.
  The 70% is counted by `VoterReach`, and the obvious implementation destroys it:
  `COUNT(DISTINCT voter_email_hash)` counts **rows**, and three of the five services that
  mint a vote write a *randomised synthetic* hash — `paidvote:<order>:<rand>`,
  `bonus:<order>:<rand>`, `points:<user>:<rand>`. Under a distinct count a supporter who
  splits ₦200,000 into a thousand ₦200 orders buys a thousand units of reach, which is the
  exact scheme the 70% exists to defeat. So a paid row resolves through `donation_id` to
  the buyer's own address, hashed with `VoteService::voterHash()` and **no prefix** so it
  collides with their organic vote; a grant is nobody; a fraud-flagged row is nobody.
  The 30% still counts every bought vote at full weight — this platform does not pretend
  otherwise, and the receipts would contradict it. `organic_vote_count` is still maintained
  and published beside the total; it decides nothing. It used to decide the whole half,
  which was structurally zero wherever `paid_voting_disable_free` is set, because
  `VoteService::castVote()` is the only path that increments it.
  **And where the ENUM has no room for a kind of vote, the HASH PREFIX is the only signal
  there is — so read it first.** `gates_votes.vote_type` is
  `ENUM('standard','bonus','paid')` with no `points` in it, so a member spending their own
  loyalty points is written as `bonus`, and only `points:<userId>:<rand>` distinguishes
  their choice from an operator's grant. `VoterReach::personKey()` tested
  `$type === 'bonus'` **before** the prefix, so its whole `points:` branch was unreachable
  for every row the platform has ever written and every redemption counted as nobody — 70%
  of the community half, denied to the one supporter who had paid for it out of a balance
  we credited them. Its own docblock said in as many words that a redemption is its member.
  The test that was meant to hold it wrote `vote_type = 'standard'` beside a `points:`
  hash: **a row no service here can produce**, which is the same shape of fixture as the
  7.9 panel mark, and it passes while the platform is wrong.
- **The community half is now `ideal`: BOTH counts against ONE yardstick.** The yardstick
  is the largest vote total any nominee in the *edition* reached, read as a number of
  people — in the perfect case those votes were one each from that many separate human
  beings. `315 × (unique voters ÷ ideal) + 135 × (total votes ÷ ideal)`. So a full 450
  means exactly one thing: as many separate supporters as the biggest total anybody
  managed, and nothing softer. `reach` divided the people term by the most PEOPLE anybody
  had, which **sags**: in an edition where nobody has broad support, the least narrow
  nominee still collected the whole 315 because the denominator fell to meet them.
  Where every vote in an edition IS one person one vote the two bases are arithmetically
  identical, so they differ exactly to the extent that votes are not.
  **The cost is a FIXED EXCHANGE RATE, and it is the whole of the cost.** One denominator
  cancels out of every comparison, so the half is proportional to `0.7 × people + 0.3 ×
  votes` — the ORDER never depends on the ideal, and one supporter is worth exactly
  `0.7/0.3 = 2.33` votes in every edition whatever the figures. On `reach` the same
  supporter is worth `(maxVotes ÷ maxPeople) × 2.33` — 23.8, then 233, then 4,667 as
  tallies grow — so buying past genuine support got *harder* there and does not here. On
  the suite's own fixture (A: 10 supporters/10 votes, B: 3 supporters) **twenty-five bought
  votes**, one donation, puts B above A. This was specified, and confirmed with these
  numbers in view; `PaidVoteCpiSeparationTest` asserts BOTH outcomes — the guarantee under
  `reach`, the inversion under `ideal` — so the trade-off is recorded rather than
  discovered later as a bug. The repair if it is ever seen on a real cycle is the highest
  ORGANIC tally as the ideal, which no purchase moves; that is a NEW basis, named and
  settable, never an edit to this one, because an announced standing must stay reproducible.
  **And the help centre had to change with it.** "What they cannot buy is the seventy per
  cent" was true while that seventy per cent divided by a count of people. The narrow claim
  survives (splitting one payment into a thousand buys nothing) and publishing only the
  narrow claim is the worse kind of true — a reader takes it to mean money cannot outrank
  supporters. `how-cpi-works` and `/integrity` state the rate now, and `EditionScaleTest`
  sweeps for the retired wording.
  **The measurement cliff is the surprising edge.** An edition with imported tallies and no
  ballot rows is in the all-or-nothing fallback and the tally takes the whole half. The
  FIRST countable row anywhere switches the people term on for every nominee at once,
  against a tally denominator — so a nominee on 80 of a 100-vote maximum goes from 360 to
  122 by gaining three counted supporters. `VoteRecoveryTest` found it by failing.  Not
  smoothed, because every smoothing available is a lie about a measurement; the rule stays
  "understate, and flag it", and the case nothing flags is the PARTIALLY measured one — three
  real rows behind an imported eighty-vote tally is scored as eighty votes from three people.
- **And where reach is unmeasurable, the 315 IS NOT PAID.** A cohort maximum of *zero*
  unique voters means the vote **rows** are missing while the tallies are not (an import
  from before this platform held rows, a fixture, a purged cycle). It used to mean the
  tally took the **whole** community half — so the leading tally collected 450 with any
  number of backers at all, including none. The argument was that a field capped at 135
  "still reads like one scored out of 450", which is an argument for SAYING SO, not for
  paying 315 points against a measurement nobody made. It also contradicts the rule as
  published: `315 × (unique ÷ highest total votes)` is zero when the unique voters are
  unknown, and there is no fallback clause. **It was one of the two mechanisms behind a
  live report of a nominee holding a full community half with fewer backers than the
  biggest tally in the edition.**
  The people term is simply unpaid now, which is what every other unmeasured quantity here
  does — an unfinished panel scores the judge half as absent rather than renormalising it
  away. The saying-so shipped with it: `cohort_max_unique == 0` is stated once per cycle on
  the release screen and on the public result page, and only once, because a caveat that
  reads as a finding about a person is an accusation.
  **And the measurement cliff went with it.** An edition with imported tallies and no rows
  used to sit on the fallback, so a nominee on 80 of a 100-vote maximum had 360 — and the
  FIRST countable row anywhere switched the people term on for everybody at once, dropping
  that nominee to 122 by gaining three supporters. `VoteRecoveryTest` found it by failing:
  a test asserting a recovered vote helps its nominee, failing because it did the opposite.
  It was documented as unsmoothable, and it was — as long as there was a cliff to fall off.
  The same three rows are now a rise (108 → 121.5). Nothing was smoothed; the
  discontinuity was an artefact of paying a term nothing had measured.
  `reach_unmeasured` is the *other* case and still fires only where the cycle maximum is
  above zero: it marks the nominee whose rows are missing while the REST of the edition has
  them, because that one silently loses 315 while every other figure on their line looks
  normal.
- **The judge half is the mark, and nothing else:** `550 × avg/10`. It was
  `((avg−5)/5)^1.5` — a floor at five and an exponent — which moved the number the judge
  wrote (8.0 paid 256 of 550, not 440), paid 5.0 and 4.0 identically, and could not be
  explained to a nominee who lost by it. The discrimination that curve was defending now
  happens in the community half, by counting people rather than by steepening a tally.
- **Both older forms survive as settings** (`community_basis` = `relative` | `absolute`,
  `judge_scale` = `curved`), per programme and per cycle through `RuleEngine`, so an
  announced standing stays reproducible to the digit. `CommunityBasisTest` pins that.
  **Reproducing one now takes three settings, not two** — the denominator's scope moved
  with them, and `community_scope` = `category` is what puts it back. Two of the three
  reproduce the *shape* of an announced cycle and not its numbers, which is the worst of
  the three outcomes: it looks like a reproduction. The scope applies to **every** basis
  that has a denominator, and it is not offered as an alternative rule — a per-category
  denominator is not a variant of the rule, it is the fault. `EditionScaleTest` holds it.
- **The CPI's denominator is the whole EDITION's field, and exactly one thing computes it.**
  `NomineeScoringService::editionScale()`, once per cycle, memoised. Both community terms —
  the 315 and the 135 — are shares of the largest figure held by any nominee in the *cycle*,
  not in the nominee's own category.
  **Per category it was cheating, and the word is the operator's.** Every category was
  normalised to its own leader, so *every* category's leader took the full 450: a 1,955-vote
  leader and an 89-vote leader, paid identically. Inside a category neither figure is wrong;
  `ResultRelease::overall()` then adds them into one column to rank the cycle, and the
  second is being paid for a field rather than for support.
  **The objection to the wider scale is real and is accepted, not answered.** A category
  with little public backing now contributes little community credit to anybody in it, so
  its nominees reach the overall standing on their 550 and not much else. The two rules
  cannot both hold; this is the one under which a share means the same thing wherever it is
  printed. `OverallWholeFieldTest` and `OverallWinnerTest` assert the numbers on both sides.
  **The scale is the cycle, never the programme's whole history** — a programme spans years,
  and a max across them would re-scale an announced standing whenever a later edition drew a
  bigger tally, and would rank two different electorates against each other.
  **And it is EVERY SCORED ENTRY IN THE EDITION — a shortlist does not narrow it.** This is
  the other half of the reported fault and the reverse of what shipped first. `cohortMax`
  was narrowed to each category's published shortlist, on the reasoning that a popular
  nominee left off a list should not decide what the finalists' votes were worth: three
  finalists on 500, 400 and 300 behind a 5,000-vote non-finalist came out four points apart
  on a thousand-point index, and the panel decided the final alone.
  **That reasoning was inherited from `relative`, and it inverts under an edition-wide
  `ideal`.** Narrowing to the shortlist reintroduces the exact fault the edition-wide scale
  exists to kill, one level down: every *shortlisted* leader collects a full 450 precisely
  as every *category* leader used to. A finalist on 500 votes from 500 supporters took the
  whole community half while a non-finalist in the same edition sat on 2,000 — every figure
  on the row internally consistent, and a full half beside a backer count plainly smaller
  than the biggest tally anybody could see. And it contradicted the rule as published,
  "Highest Total Votes in award programme edition", which is what a nominee is told their
  score means.
  The compression objection is real and is **accepted, not answered**: one denominator
  cancels out of every comparison under `ideal`, so the ORDER never depends on it — what
  changes is how much community credit an edition hands out in total, and an edition where
  one person holds most of the public support saying so is the honest answer.
  **Setting the scale is not being in the running.** Somebody off a shortlist still cannot
  win; that is decided separately. But the yardstick can now belong to a nominee who cannot
  win, which is settled (unlike the quorum case, where it can still MOVE) and invisible from
  any row — so `scale_not_in_running` says it, and is never merged with `scale_is_out`.
  The **quorum deliberately does not narrow it** either — below quorum is pending, not out,
  and dropping an unjudged nominee would move every published score the moment their panel
  finished, then hand it all back. A nominee who does not score at all sets nothing:
  `scoredIn()` is the one definition, so a withdrawn or merged-away entry contributes no
  maximum.
  **Resolve the cycle from the CATEGORY, not through a join.** `gates_award_cycles` is
  missing more often than it looks (an import, a fixture, a cycle deleted after release),
  and an inner join there silently collapses the scale back to the one category — the fault
  itself, reappearing with nothing on any screen to name it. `EditionScaleTest` pins it.
  **Drawing one category now reads a whole cycle**, so any loop over categories must share
  one `NomineeScoringService` — the memo lives on the instance, and a fresh one per row
  makes a public list quadratic in the size of its edition with nothing to see but a page
  that gets slower as the cycle grows. `PublicResults::index()`, `PulseFeedService` and
  `ResultRelease::forCycle()` all pass one through; `EditionScaleTest` counts the queries
  and sweeps `src/` for a loop that does not.
  **A screen's question about the scale-setter has to follow them.** `scale_is_out` warns
  that the denominator can still move because the nominee holding it is below quorum. It
  found them by scanning the drawn category's own rows — correct per category, and
  edition-wide a warning covering a strictly *smaller* set than the risk, which had just
  grown to the whole cycle. Its own test kept passing: the fixture has one category. The
  standing now travels on the setter, from `editionScale()`, and `null` (nobody asked —
  a programme may run with no quorum) is **not** `false`.
  **And the scale-setter is usually on another page.** Every screen that explained a
  community half used to find the denominator by scanning its own rows for whoever held it;
  edition-wide that scan finds nobody and reports the scale as unset beside percentages that
  plainly came from somewhere. `cohort_max_by` names them, with their category, from the one
  pass that computed the number. `ResultRelease` used to take its own `max()` for the same
  figure; the two agreed only while both meant "everybody who scored". `ResultReleaseTest`
  holds the identity rather than the value.
- **A retired rule outlives its code in the prose, and the help centre is where it hides.**
  Moving the denominator left four published promises of the *old* rule standing: an article
  titled "Why a small category is not a disadvantage" whose body said the half is
  "normalised inside each category" — linked from `/integrity`, quoted inside
  `how-cpi-works`, and what support pastes into a ticket; `how-cpi-works` itself asserting
  "Paid votes are excluded entirely" while `what-paid-votes-do`, in the same file, said they
  count exactly like a free vote; and the release screen's own lede describing "the largest
  **organic** vote count **in the category**" — wrong about both terms of the thing it was
  explaining, on the page an award is signed off from. **The slug is kept when the promise
  is retired** — it is a published URL somebody was sent — and the answer at the end of it is
  rewritten. `EditionScaleTest` sweeps every article for the retired phrasings, because the
  fault is not "this article is wrong", it is that prose outlives the rule it describes.
- **There is no category discount, and no screen may describe one.** `CpiService::depth()`
  once scaled a category's whole community weight by how deep that category's support was,
  and the release screen drew a "category discounted" label for it. It cannot be right under
  any basis now: the default never calls `depth()` (the full-credit mark decides nothing);
  `relative` passes the **edition's** maximum, so the factor is one constant applied
  identically to every category and changes no order anywhere; `absolute` passes the
  nominee's own tally. The category is not a scoring unit — the award is one, and categories
  are how it is organised. `EditionScaleTest` sweeps every template for the phrasing, and a
  comment explaining the removal must **describe** the old label rather than quote it, or it
  trips the sweep it is documenting.
- **A number that is now constant is not evidence any more.** The overall table printed
  `cohort_max` per row to show the comparison was uneven. Edition-wide it is the same figure
  down the page — the very fact that made it worth printing is what removed the need for it.
  Same shape as the caveat above it, which went on admitting a bias the change had removed.
- **A published result is the one that was ANNOUNCED, not the one today's rules give.**
  `PublicResults::category()` re-ran the whole calculation on every page view, so a released
  page showed current arithmetic under a nominee's name and `r.winner` named the RECOMPUTED
  top rather than the person crowned. One real released nominee went 693 → 885 across a week
  of scoring changes, with nothing edited and the hash chain intact — while the help centre
  promised "no quiet edit available", about an archive whose only readers were a console
  command and the maintenance sweep, on a host with no shell. `CycleMaterialiser` seals the
  drawn standing at promotion (`SnapshotService::captureRelease()`, idempotent per cycle) and
  `ReleasedStanding` lays it back over the page. It seals the **drawn result**, not the raw
  scorer: below quorum, off the shortlist and no support are all live facts that move after a
  release, so `in_running` and the rank are sealed too — otherwise a panel finishing a week
  late sweeps somebody into a published award. The new columns sit **outside the hash
  payload** (`cycleId|nomineeId|votes|cpi|at`), so every existing link still verifies; and it
  is `standing_rank`, never `rank`, which is a reserved word in MySQL 8 and bare-legal in
  SQLite. Where a cycle was released before sealing existed there is **no guess** — the page
  recomputes and says so. `ReleasedStandingTest` proves it by moving the rules between the
  seal and the read, which is the only way to tell a sealed figure from a recomputed one.
  **And the ORDER is part of the announcement, so it comes off the seal too.** `apply()`
  sealed `standing_rank`, read it out of the database, threw it away and re-sorted the
  sealed figures through `ResultRelease::order()` — reasoning that it is "the same
  comparator the award was decided with". It is, until somebody changes it, and a tiebreak
  is a rule exactly like the two the seal already protects: `order()` settles a dead heat
  on the tally and then on the nominee id, neither of which anybody announced. The column
  was written at every release and read by nothing, while its docblock claimed it was
  "carried for display and as the check that the two agree" — and it was neither. The
  sealed placings decide the list now; the comparator is the fallback for the case the old
  reasoning was actually about (a rank that failed to write), and the page then says
  "order reconstructed" rather than presenting it as the announcement. Same for
  `cohort_max_unique`: read out of the seal, never applied, so a sealed page asked
  **today's** rows whether to print a sealed number of supporters.
  **And the operator's screen has to hold BOTH figures, or the support call cannot be
  answered.** `/admin/result-release` draws live and must keep doing so — asking the
  promotion's own comparator is what makes it an audit of a release rather than a report
  about one — so once sealing shipped it stopped agreeing with the public page for a
  released cycle, with nothing anywhere to say why. Its own lede still promised "what is
  drawn here is what will be published", which is the §19 shape on the page an award is
  signed off from. So the person taking the call that begins *"my score has changed"* had
  the recomputed figure in front of them, the nominee had the sealed one, and no screen
  held the pair: the honest answer — the result has not changed, the method has, and yours
  is still the one you were given — was not available to the only person who needed it.
  `ReleasedStanding::divergence()` compares the drawn cycle against its seal and the screen
  states which it is showing. It compares the **index, the placing and whether they were in
  the running** and nothing else: a community half that reaches the same 693 by a different
  route has moved nothing anybody was told, and reporting it buries the rows that matter.
  A nominee entered after the announcement is counted apart and never called a discrepancy,
  or the panel shows a number beside "these have moved" on every cycle that has taken an
  entry since — which teaches an operator to stop reading it.
  **And a RESULTS DATE IS A PROMISE, NOT AN ANNOUNCEMENT.** `PublicResults` gated its pages
  on `status IN ('results','archived')` **or a `results_date` that has passed**, two lines
  under its own docblock saying "a judged-but-unreleased category is a decided award nobody
  has announced, and serving it publicly is announcing it". So a cycle still in `judging`
  three days past its date published a full standing with a named winner and an index — from
  a panel that was still open, with no seal, so the page also printed "Recomputed under
  current rules": the platform admitting on a result page that this was not the
  announcement. It named as winner somebody the promotion had not yet told. The gate is the
  status alone now, because that status is written by `CycleMaterialiser` in the same
  transaction that crowns and seals. The test asserting the old rule reasoned "the cron is
  moved by a scheduler on a host with no shell and it has been dead for weeks before" —
  right about the risk, wrong instrument, and answered since by the webcron tick, which
  engages itself when `CronHealth` shows the schedule has PROVABLY missed work. Publishing
  an unannounced result was a second fault covering for the first.
  **Gating alone then fails the other way, which is why the two shipped together.** The page
  a nominee's family refreshes on the results date would go from a wrong answer to NO
  answer, and this class already holds the rule ("silence is how a withheld award becomes a
  rumour"). `PublicResults::delayed()` states the delay: the date that was promised, that
  the award is not decided, and the operator's own note where one is written.
  **Derived, so it cannot be left up** — the condition is a past date plus a status that is
  not released, so it appears when a release slips and goes when the cycle is announced,
  which is the same moment the real result replaces it. A banner an operator has to remember
  to take down is a banner that is still up in March. And `/results/{id}` for a late award
  answers **200 with a holding page**, not 404: a result's URL is in front of people before
  the date (the congratulations mail, the Pulse, a forward), whoever follows one on the day
  is exactly the person owed the explanation, and a 404 there reads as the result having
  been taken down. `noindex`, because the real result takes that same URL.
  **No invented second date.** The last date this platform named is the one it did not keep.
  **AND A SEAL CLAIMS AN ANNOUNCEMENT, SO IT NEEDS ONE.** Entering `results` fired two side
  effects that never referred to each other: the staleness rule withheld every notification
  — correctly, a months-old result must not email congratulations now — and the seal
  recorded the standing "as announced" regardless. So a late cycle told nobody and froze its
  figures as the announcement in the same pass, with panels still unfinished. Nothing on any
  screen showed it: the published figures simply stopped moving while scoring carried on, so
  a judge completing a scorecard changed nothing anybody could see, and the symptom reported
  is "the score is not changing" — which names the scorer, the one part of it that was
  working. `captureRelease()` is gated on the announcement actually going out now, and
  `2027_01_12_unannounced_seal_repair.php` demotes the rows already written. It finds them
  from `gates_cycle_transitions.notify`, the platform's own contemporaneous record of
  whether it announced — evidence, not a heuristic — and DEMOTES rather than deletes,
  because `gates_vote_snapshots` is a hash chain and `capture_kind` is stored beside the
  hash rather than inside it. A cycle with no ledger row is left alone: absence of a record
  is not a record of absence.
  **Which made releasing an ACT rather than a date, because it had to.** The sweep never
  revisits a cycle — the ledger's UNIQUE (cycle_id, to_status) is its claim — so a
  withheld seal was a one-way door: honest, and permanent.
  `CycleMaterialiser::release()` is the way in, from `/admin/result-release`. It does NOT
  relax `CycleService::manualTransitionError()`, which still refuses a hand-set `results`:
  an operator never writes the status, they ask for a release, and the same quorum-checked
  promotion and the same seal run as on the scheduled path, on the same ledger, marked
  `notify = 1` because a person deliberately releasing IS the announcement. It refuses a
  cycle before judging (the phase is COMPUTED, not read off the status column — that column
  is a cache and this is an authorisation question), accepts one already in `results` (that
  is the repair), and seals once however many times it is pressed. It deliberately does not
  refuse an early release: a panel that finished early may publish, and making them wait for
  a date they set themselves enforces a promise nobody made to anybody.
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
  **And a column can be unread while a screen appears to be showing it**, which is the
  variant no sweep asked about. `gates_name_says.source` records whether a respelling came
  from a model, the offline rule, or a person. Three paths wrote it. `NameSays::all()` —
  the one method that selected it, whose docblock says "for the settings screen" — had no
  caller anywhere, and there was no delete-by-source path at all, so both halves of what
  its migration promised ("the admin screen can show where an answer came from", "a bad
  batch can be cleared without touching anything a person wrote") were false. Meanwhile
  the settings screen **did** show a source, and it was not this one:
  `DoorWelcome::nameSheet()` labelled every row it found in the table "worked out",
  derived from WHERE IT LOOKED rather than from what was stored — so a respelling a model
  invented and one derived from letters were presented as the same kind of answer, on the
  screen whose job is deciding which to trust. A column is read when the value a screen
  prints comes **out of it**, not when the screen prints something about the same subject.
  The clearing matters as much: a name is asked about **once, ever** (`remember()` keeps
  the first answer, because it may already have been read aloud), so a bad model run was
  permanent — forty names mispronounced at every door with no way to ask again. Forgetting
  is safe where editing would not be, since a name with no row is simply asked again and
  the offline rule answers until then; `hand` is refused, because nothing can ask a model
  to guess again at what somebody decided after hearing the clip.
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
  **And it happened again, over money.** A donor's link for stopping a monthly gift was
  complete on every side: `RecurringGiving::start()` mints `manage_token` at checkout with
  a comment saying it is minted "so it can travel in the receipt", `byToken()` resolves it
  (including for an already-stopped gift, deliberately), `/donate/giving/{token}` renders
  the page and its button, and `DonationController::giving()` explains at length why the
  cancellation is a link in a receipt rather than a login — "a donor who cannot easily stop
  is not a supporter, they are a dispute waiting for a quiet month". And `manageUrl()`, the
  one function that builds the link, **had no caller**: no receipt, no template, no page
  ever contained the URL, so the stop button was reachable only by somebody who could read
  the database. The distinguishing question is not "does this work?" — every piece did —
  but **who is ever handed this?** `RecurringGivingTest` now asserts the receipt's own body
  calls `stopLink()`, because a link builder with a passing test and no caller is precisely
  the state this shipped in.

## An organisation's own donation page

`OrgBrand` is what a partner organisation controls on `/gift/{slug}`: an accent, a logo, a
tagline, a story, and nine blocks — impact figures, a gift ladder, video, quotes, an FAQ,
the team, a short history, partners, links out. It shipped **dead on both sides** and that
is the thing to keep in mind before extending it: there was a validated writer, a route, an
uploader and an accent refused for failing contrast against white — and `css()` had no
caller, `DonationController` never mentioned the service, and the organisation's own
dashboard template contained the word "brand" zero times. No form in, no page out, while
the migration's docblock described it as shipped. §17 and §18 in one feature.

**It is ONE JSON document, and that is deliberate** — everything in it is read once per
page for one organisation already loaded by id or slug, and nothing filters or sorts on an
accent. So a new block needs no migration. It also means the column is the constraint:
`brand_json` is TEXT, **65,535 bytes on MySQL**, and the per-block caps count CHARACTERS.
Filled with four-byte characters the same caps allow ~132KB — twice what the column holds —
so an organisation writing in a non-Latin script can overflow it while typing nothing the
form called too long. Left to the database that is a throw in strict mode or a TRUNCATION
on a host that overrides `sql_mode`, and a truncated JSON document does not parse, so
`of()` falls back to the house defaults and their whole page silently reverts to unbranded.
`save()` refuses above `MAX_JSON_BYTES` with the size instead.

**The two-field blocks are a TABLE, not seven loops.** `OrgBrand::BLOCKS` drives one reader,
one writer, and the editor's form field names — which are DERIVED, so a field the writer
does not read is impossible rather than unlikely. That failure would be silent: a field
named `impact_figures` posts happily, is never read, and the organisation saves with no
error and finds the block empty.

**Every value is re-validated on the way OUT, not only in.** A document survives the code
that wrote it — an import, a restore, a retired provider — so reading a URL out of storage
and putting it in an `href` because "it was checked when it was saved" is trusting a past
version of the file.

**Embeds are provider-allowlisted and CLICK-TO-LOAD, and the second half is a legal
requirement rather than a performance choice.** An iframe present in the markup sends the
visitor's IP to YouTube or Vimeo and lets them set storage on page view, before the visitor
has done anything; under the GDPR joint-controller line and Nigeria's NDPA 2023 that needs
a lawful basis, and "the page contained a video" is not one. `-nocookie` narrows the cookie
question and does not touch the transmission. So nothing is fetched until somebody presses
play, the button names the provider before they do, and there is a plain link out for a
browser that refuses the frame. `OrgPageTest` asserts **zero iframes in the shipped HTML**,
not an intention.

An organisation pastes a URL and only the video ID is kept; `embedUrl()` builds ours from a
fixed per-provider prefix. Nothing they typed reaches an `src`. The host is PARSED, never
substring-matched — `str_contains($u, 'youtube.com')` is true of
`youtube.com.attacker.example`, which is how an allowlist stops being one. Adding a provider
is three edits and all three are required: `VIDEO_PROVIDERS`, `Csp::FRAME_HOSTS`, and
`public/.htaccess`. **On this host the static policy in that file is the one a browser
receives**, so an origin added only in PHP works nowhere while looking correct in the
source; `CspStaticFallbackTest` fails if the two diverge.

**And `gates_partner_orgs.contact_email` is a compliance contact, not a press office.** It
is the address given to verify a CAC registration. The public page publishes only the
website they typed into a field labelled as public.

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

## The homepage globe band, and three faults nothing on the page could show

The band arrived as a design handoff over **sixteen invented cities** — Lagos on 41,280
ballots "confirmed at the verification node nearest the voter, median 1.3 seconds",
Nairobi on 33,940, arcs drawn between them. This platform has no verification nodes and
records no per-ballot latency; there has never been a column for either. It is
`StatsService`'s original fault ("1,247 profiles", "24 categories", "seven editions") with
better typography, and worse, because those numbers arrived with an air of instrumentation.
`GlobeBand` drives it from the one geographic fact here — WHERE THE NOMINEES ARE — by the
same joins `NationsLive` uses, so the globe and the footer's "live in …" sentence cannot
disagree, and the sandbox is excluded by reaching only for active programmes rather than by
a filter somebody remembers. A marker's position is the **centroid of the country's own
polygon**, so nothing is typed and a marker cannot drift from its outline; the price is that
the join is by Natural Earth's own name (`CD` is "Dem. Rep. Congo" there), which matches or
silently does not, so `GlobeBandTest` pins every name against the shipped geometry file and
against the script's Africa set.

**`setPointerCapture` on a container EATS a child button's click.** The stage captured the
pointer on `pointerdown` to drive the drag-to-rotate, and the browser then dispatches the
following `click` to the **capturing element** — so every marker's own listener never ran.
Markers were unclickable with a mouse or a finger while `Enter` on a focused one opened its
card perfectly, which is the worst possible split: keyboard and screen-reader paths work, so
an accessibility pass says yes, and the interaction the design is built around is dead. No
throw, no console line — the card simply never appears, and the globe reads as decorative. A
press that starts on a marker starts no drag now; there is nothing to rotate by grabbing an
11px button.

**A HIGHLIGHT ALMOST EVERYTHING QUALIFIES FOR IS A BACKGROUND.** The reference design
carries four plain dots and two ringed markers, and the ring is what the eye lands on. The
first cut here re-mapped the ring onto "this nation has recorded any votes" — true of nearly
every nation the moment an award opens — so the band rendered five rings and one dot, the
hierarchy exactly inverted. Every marker was defensible and the picture was noise. The ring
means an award has been DECIDED there now, which is rare by nature, and `GlobeBandTest` pins
that rather than the look.

**And the handoff's PRODUCTION file is not always its design.** That script stroked all 54
African nations every frame (0.85px at 16% ink, 1.15px of green for any nation with
activity); the reference `Homepage.html` outlines exactly one country — the selected one,
while its card is open. The land dots ARE the drawing, and fifty-four outlines over them
turn a quiet map into a diagram competing with itself. The dots were identical to the
reference's all along (15,000 samples, `#8fa39b`, alpha 0.10–0.40 by longitude) and looked
sparse only because the outlines shouted. Render the reference beside the build before
trusting a "production-ready" folder.

**A z-index cannot climb out of a lower stacking context, and both rules read correctly
alone.** The country card sits inside `.reg__body` (`z-index:1`); the stat card is a sibling
at `z-index:3` that deliberately rises `-11vw` **into** the stage. So the two figures a
reader clicked a country *for* were underneath it: the card's header showed and its rows did
not. Nothing about that is visible from either declaration, and raising the card is not
available — the fix is where it is anchored (`bottom:max(7rem,12.5vw)`, clearing the rise at
every width the rule applies to).

**A prose sweep must read what a READER sees, not the file.** This repo already shipped the
lesson that a comment explaining a removal must *describe* the retired label rather than
quote it, or it trips the sweep documenting it — which is a rule about how to write comments,
enforced by making comments unwritable. `GlobeBandTest` strips `{# … #}` before sweeping
instead: a Twig comment reaches nobody, so it was never in scope, and the comment above the
change may now name exactly what it removed.

**And the band inherited a second "nations live".** `StatsService` counted distinct
`country_code` over approved **profiles**, while the footer, the meta description and the
JSON-LD all print `NationsLive::phrase()` — which counts nations with a nominee standing in a
live award and says in as many words why a registered profile is not the platform operating
in a country. The homepage could print "12 nations live" beside a footer reading "live in
Nigeria" on one page load, and the directory figure was both the larger one and the wrong
one. `StatsServiceTest` asserted the wrong definition by name.

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

Design system: paper ground `#f0f2f2`, hairline rules, mono micro-labels. **Colour comes
from `Support\Accent` and nowhere else** — four roles (`honour`, `action`, `live`,
`caution`), four values each (`fill`, `edge`, `ink`, `wash`). See the section above for why a
bare hex is not a colour here.
