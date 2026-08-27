# Africa GATES — Structure & Flaw Audit

> **Date:** 2026-08-27 · **Scope:** analysis only, no behaviour changed.
> **Method:** every number below was measured against the working tree at `35e2e97`. Nothing is
> carried over from the 2026-08-08 audit — where that document's method could not be reproduced
> (see §7 on error handling) this one says so rather than quoting a figure it cannot stand behind.
> **Companion docs:** [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md) (the map),
> [`CODEBASE-AUDIT-2026-08-08.md`](CODEBASE-AUDIT-2026-08-08.md) (the previous pass).

---

## 1. Summary

**The core is healthy, and measurably so.** The suite is green on a clean install with no `.env`:
**4,881 tests, 30,464 assertions, 0 failures, 2m32s.** All 966 tracked PHP files parse. All 496
route-handler registrations resolve to a real method on a real class. Every template referenced
from PHP or from another template exists. MySQL and SQLite are at exact **76/76** table parity
across all three schema pairs. Every one of the six "declared with no reader" bugs recorded in
`CODEBASE-INDEX.md` §17 now has a verified reader — those fixes landed.

The tree has roughly doubled since the last audit (245 → 404 source files, 135 → 201 templates,
220 → 403 test files). The test culture scaled with it, which is why the mechanical checks come
back clean.

**What is wrong is concentrated in one theme, and it is the theme this codebase already knows it
has:** a mechanism that is complete, correct, and connected to nothing. Four of the findings below
are that shape, and two of them are the *documented* failure repeating in a new place:

1. **The sandbox reaches the public** (§3.1). `VoteController` is a public reader that does not
   filter `is_active`, so demo nominees have live, votable pages.
2. **SMTP credentials and Cloudinary cannot be set without a shell** (§3.2) — the `GAS_URL`
   failure, twice over, on screens that explain the rule while breaking it.
3. **`gates_events` is written and read by nothing** (§3.3).
4. **The canonical datetime round-trip is dead code** and has been reimplemented twice (§3.4).

Nothing found is a remotely exploitable security defect. CSP, CSRF, the setup-token gate and the
upload path all hold up under re-check (§6).

---

## 2. Structure, measured

| Area | Files | Lines |
|---|---:|---:|
| `src/` (PHP) | 404 | 137,150 |
| `templates/` (Twig) | 201 | 54,941 |
| `tests/` | 403 | 100,718 |
| `public/assets/` (css + js) | — | 23,779 |
| `database/migrations/` | 147 | — |
| `docs/` | — | 10,888 |

```
src/routes.php     3,716 lines · 496 handler registrations (459 unique class:method)
src/
  Services/    211    Controllers/  43
  Admin/        65    Judge/         5
  Support/      45    Console/      27
  Middleware/    6
database/  76 tables · MySQL 52+9+15, SQLite 52+9+15 — exact parity
```

### Mechanical health — all green

| Check | Result |
|---|---|
| `php -l` across `src public config cron bin database tests` | 966 files, **0 parse errors** |
| Route handlers resolve to a real method | **496/496** |
| Templates referenced from PHP exist | **all** |
| Twig `extends`/`include`/`embed`/`import` targets exist | **0 missing** |
| MySQL ↔ SQLite table parity | **76/76**, no drift either way |
| `./vendor/bin/phpunit --no-coverage` | **4,881 tests, 30,464 assertions, OK** |

The guard tests named in `CLAUDE.md` are all present and passing: `NestedFormTest`,
`TwigBlockScopeTest`, `RouteHandlerExistsTest`, `TemplateSyntaxTest`, `SchemaTest`, `CspTest`.
Naive re-implementations of those scans produce false positives that the real tests correctly
reject; the tests are the authority and they are green.

---

## 3. Findings

### 3.1 The sandbox reaches the public — `VoteController` has no `is_active` filter

**Severity: high.** Violates a stated invariant: *"The sandbox must never reach the public."*

> **Resolved after this audit was written.** Both handlers now carry `->where('p.is_active', 1)`,
> and `PublicSurfaceSandboxTest` gained two guards that fail without it — the ballot returned `200`
> and `/vote/{id}` redirected to `/vote/demo-sandbox/1-demo-kigali-signal` with the filter removed.
> The rest of this section is left as written, because it is the record of what was found. Note the
> one consequence beyond the sandbox: "Active" is an operator checkbox, so deactivating a real
> programme now takes its nominee pages down too — consistent with the hub, the programme page and
> the sitemap, which already dropped them.

`DemoSeeder` contains the sandbox by construction rather than by flag, and says so:

> `is_active = 0` is the whole public-invisibility mechanism. Both public readers already
> require 1, so nothing new has to know about the demo.

There are no longer two public readers. `is_active` appears **nowhere in
`src/Controllers/VoteController.php`**, and two of its handlers join
`gates_award_programmes` without it:

- `nomineeBallot()` (`VoteController.php:356`, reached from `:271`) — the public per-nominee
  vote page with the OTP ballot inline.
- `nomineeRedirect()` (`VoteController.php:286`, reached from `:125`) — the legacy `/vote/{id}`
  bounce, which resolves *any* nominee id to its canonical URL.

Both filter only `whereIn('n.status', PUBLIC_STATUSES)` — `['approved','winner','runner_up']`.
`DemoSeeder` writes its nominees at `status = 'approved'` (`DemoSeeder.php:175`) inside a cycle at
`status = 'voting'` (`:122`). Every condition the ballot checks, a demo nominee satisfies.

So `/vote/demo-sandbox/{id}-{name}` renders a live, votable page for sandbox data. The programme
slug is a public constant (`DemoSeeder::PROGRAMME_SLUG`) and ids are enumerable through the legacy
redirect.

**Scope, precisely.** The listings are safe — `/vote` goes through `AwardService`, which does
filter `is_active`, and `SitemapService` filters it too, so the sandbox is neither browsable nor
indexed. The exposure is direct-URL only. That is a real limit on the blast radius and not a
reason to leave it: the page accepts votes, so a demo nominee can accumulate real ballots and a
real voter's OTP can be spent against a row that exists to be deleted.

**The wider pattern.** Of 81 sites that touch `gates_award_programmes`, 54 have no `is_active` in
the surrounding query. Most are admin-side and correct — the operator *should* see the sandbox.

> **All eight public-side candidates have since been reviewed.** Three were real and are fixed;
> five were not leaks. The review also turned up `DemoSeeder::notSandbox()`, a NULL-safe helper
> that already existed for readers which reach the programme through a LEFT JOIN or not at all —
> which is why `ActivityFeedService` was a false positive in this scan.
>
> | Site | Verdict |
> |---|---|
> | `VoteMessageController::nomineeFor()` | **Leak — fixed.** The messages and supporters pages gated on nominee status alone, so closing the ballot only moved the sandbox to a neighbouring URL. |
> | `FlierService::forNominee()` | **Leak — fixed.** The og:image every share link renders. All four flier surfaces (`page`, `svg`, `png`, `card`) route through this one resolver. |
> | `NomineeBroadcast::cycles()` | **Leak — fixed**, and outward rather than inward: the rehearsal is seeded `voting` with `voting_close` 20 days out, exactly the shape this selects, so the broadcast mailed `@demo.invalid` — a non-resolving domain, so every send is a hard bounce against the sending domain's reputation. Gated with `notSandbox()` rather than `is_active`, because the LEFT JOIN makes a bare comparison NULL-unsafe. |
> | `ActivityFeedService:524` | Not a leak — already uses `DemoSeeder::notSandbox()`. |
> | `PulseFeedService::channelNames()` | Not a leak — a lookup map that only names programmes which have posts, and `DemoSeeder` creates none. |
> | `VoteMessageController::ballotPath()` | Not a leak — a URL builder reached only for a nominee `nomineeFor()` has already gated. |
> | `Support/NomineeUrl::path()` | Not a leak — called from the paid-vote flow, checkout receipts and support context, never as a public lookup on an arbitrary id. |
> | `GatedFormService::subjectTermsUrl()` | Not a leak — returns `/terms/{slug}`, reached through a single-use token that is itself the credential. |
> | `SupporterHonours::nominee()` | Not a leak — a mail path scoped to one nominee's own supporters, and LEFT-joined, so `is_active` would be the NULL-unsafe form anyway. |

---

### 3.2 Two operational credentials are `.env`-only — the `GAS_URL` failure, repeated

**Severity: high.** Violates a stated rule: *"Anything operational must be settable from
`/admin/settings`."*

The rule exists because `GAS_URL`/`GAS_SECRET` were readable only from `.env`, there is no SSH on
production, and so the whole Google integration was dead while every screen explained itself
correctly and told the operator to edit a file they cannot open. That lesson is written out at
length in `templates/admin/settings.twig:757–776`. Two integrations on the same site still work
the old way.

**SMTP credentials.** `CheckoutMailer::boot()` builds exactly the right resolver —

```php
$pick = static fn (string $key, string $env, string $dft): string =>
    trim((string) ($settings[$key] ?? '')) ?: (string) Env::get($env, $dft);
```

— and then applies it only to the cosmetic fields. The four values that actually authenticate the
connection do not use it (`CheckoutMailer.php:137–140`):

```php
'host'         => Env::get('SMTP_HOST', 'smtp-relay.brevo.com'),
'port'         => Env::int('SMTP_PORT', 587),
'username'     => Env::get('SMTP_USER', ''),
'password'     => Env::get('SMTP_PASS', ''),
'from_address' => $pick('mail_from_address', 'MAIL_FROM_ADDRESS', '…'),   // settings-aware
'from_name'    => $pick('mail_from_name',    'MAIL_FROM_NAME',    '…'),   // settings-aware
```

`/admin/settings` offers `mail_from_address`, `mail_from_name`, `mail_reply_to`, `contact_email`,
`support_email`, `admin_alert_email` — and no host, user or password field at all. The same card
reports the consequence back to the operator (`settings.twig:178`):

> SMTP not set — mail is written to `var/logs/outgoing-mail.log`.

An operator who pastes credentials into the admin gets the from-name applied and the login
ignored. Email is not a peripheral here: OTP codes gate voting.

**A second reader of the same setting.** `CycleAnnouncer::email()` (`:145`) builds its own
`OtpService` transport from `Env::get` only, with no `gates_settings` lookup whatsoever — so the
two mailers disagree about where configuration comes from. `CLAUDE.md` names this precisely: *"One
resolver per value, never two: two readers of one setting is how the halves of an integration come
to disagree about whether it is configured."*

**Cloudinary.** `CloudinaryService` never touches `gates_settings` — `CLOUDINARY_CLOUD_NAME`,
`_API_KEY`, `_API_SECRET`, `_URL` and `_FOLDER` are all `Env::get`. `MediaController` renders a
panel that reports `configured: true|false`, and the template's remedy is
(`templates/admin/media/index.twig:72`):

> add `CLOUDINARY_URL=cloudinary://key:secret@cloud` to `.env`

That is the `GAS_URL` sentence, verbatim in shape: a screen that diagnoses correctly and prescribes
something the operator cannot do.

The fix in both cases is the documented one — one static resolver per service, `gates_settings`
first and `.env` as the fallback, plus the fields on the settings page.

---

### 3.3 `gates_events` is written on every major action and read by nothing

**Severity: medium.** The §17 trap class, in a fresh instance.

`EventService` is fully wired: registered in `config/container.php:350`, injected into
`ApiController` and `MilestoneService`. Four of its emitters fire in production — `voteCast`,
`milestoneReached`, `fraudFlagged`, `otpRequested` — so `gates_events` accumulates rows for the
life of the install.

Nothing ever reads them. The only reader in the codebase is `EventService::funnelReport()`, and
`funnelReport()` **has no caller**. The sole other query naming the table is
`MergeService.php:275`, which reassigns `subject_id` during a nominee merge — bookkeeping over data
no screen renders. This is `gates_status_log.components_json` again: written on a schedule for the
life of the log so a question could be answered, and never wired to the thing that asks it.

Five further emitters are declared and never called at all: `nominationReceived`, `nomineeApproved`,
`registrationCompleted`, `shareClicked`, and the generic `dispatch()` (only reached internally).
So the class's own docblock — *"Every major platform action should dispatch an event"* — describes
an intent the wiring only half keeps.

**And the enable guard does not guard.** The constructor:

```php
try {
    DB::getSchemaBuilder()->hasTable('gates_events');   // return value discarded
    $this->enabled = true;
} catch (\Throwable) {}
```

The comment above it reads *"Silently disable if the events table doesn't exist yet"*. It does not:
`hasTable()`'s boolean is thrown away and `$enabled` is set to `true` whenever the call does not
throw. The effect is currently masked because `dispatch()` and `funnelStep()` each wrap their
insert in their own silent `catch` — but the guard is a comment describing behaviour the code does
not have, and `funnelStep()`/`funnelReport()` both branch on it.

Note the contrast that makes this worth fixing rather than deleting: `gates_funnel_events`, written
by the *same* class, **is** read — by `Admin\Services\AnalyticsService:425–431` and
`VoteRecoveryService:612`, and surfaced in `templates/admin/analytics.twig`. The pattern works. One
table got its screen; the other did not.

---

### 3.4 The canonical datetime round-trip is dead, and reimplemented twice

**Severity: medium.** Touches the `T`-separator divergence documented in `CLAUDE.md`.

`Support/DisplayTime` exists to solve exactly one problem, and its docblocks state it clearly:

> an operator typing "23:59" into a cycle's `voting_close` means 23:59 in THEIR zone, and storing
> that string verbatim closes the vote an hour early in WAT.

**`DisplayTime::toStored()` has zero callers. `DisplayTime::forInput()` has zero callers.** Both
are unreferenced anywhere in production code, including from each other's documentation, which
still asserts the round-trip happens.

In their place, two reimplementations:

1. **`Admin\Controllers\EventsController`** carries a private `forInput()`/`fromInput()` pair
   (`:429`, `:437`) built on `Carbon`. They are symmetric, so nothing drifts — but `forInput()`
   formats `'Y-m-d\TH:i'`, dropping seconds. That is the precise 59-second-per-save drift that
   `DisplayTime::forInput()`'s comment says it includes seconds specifically to prevent.
2. **`templates/admin/programmes/cycle.twig`** does it inline in Twig, five times:
   `{{ cycle.voting_close|…|replace({' ': 'T'})|slice(0,16) }}`.

The save side does nothing at all. `ProgrammesController::cycleSave()` (`:160–163`) writes the raw
POST value straight through:

```php
'voting_open'  => $b['voting_open']  ?: null,
'voting_close' => $b['voting_close'] ?: null,
```

So `2026-01-01T09:00` — `T` separator, no seconds, no timezone conversion — lands in
`gates_award_cycles.voting_close`. In production that column is `DATETIME`, and MySQL normalises
the value on the way in, so **production is not currently broken by this**. In dev and in the test
harness the column is `TEXT` and SQLite stores the string verbatim, where `'2026-01-01T09:00'`
sorts *after* every space-separated timestamp of the same date, because `T` (0x54) > `' '` (0x20).
That is the divergence `CLAUDE.md` warns about, sitting one schema-type decision away from the
deadline column that decides whether a vote counted.

The timezone conversion `toStored()` was written to perform never happens anywhere. The cycle form
is at least honest about it — *"Entered and stored in {{ timezone }} — your browser's timezone is
not applied"* — but that is a note explaining an absence, not the behaviour the helper exists for.

Three implementations, one of them canonical and unused: this is the "one resolver per value"
rule again, in the time domain.

---

### 3.5 Forty-eight methods have no production caller

**Severity: medium in aggregate.** Measured by tokenising every `.php`, `.twig`, `.js`, `.json`,
`.sql`, `.md`, `.gs` and `.html` file in the tree and counting call, property-access and
string-callable references outside the declaration itself. String-dispatch forms (`':method'`) and
Twig `->prop` access are included, so route handlers and template calls do not show up as false
positives — the 496/496 route check in §2 confirms that.

Several are individually consequential, and each is a mechanism rather than a stray helper:

| Method | What is not happening |
|---|---|
| `GoogleMeetService::cancelEvent()` | A cancelled interview never cancels its calendar event, so the Meet link stays live and the invitees keep the slot. |
| `OtpService::sendNominationConfirmation()` | A nominator never receives the confirmation the method composes. |
| `TicketLinkService::revokeForTicket()` | Ticket links are never revoked. Same class as the documented `prune()` bug (§17) — the second no-caller found in it. |
| `ReferralService::clearSession()` | Referral attribution is never cleared from the session, so a source can outlive the journey that set it. |
| `MilestoneService::getForNominee()`, `nextMilestone()` | Milestones are computed and stored; neither reader is called. |
| `BallotGuard::isNominable()`, `stateForProgramme()` | Two judging guards that never run. |
| `Admin\Services\AuthService::hasRole()`, `currentAdmin()` | Role helpers unused — role checks are done another way, so there are two answers to one question. |
| `AttendeeBot::transcriptReady()` | Transcript readiness is never polled. |
| `EventService` × 5 | See §3.3. |

The remainder are lower-stakes but the same shape: `Support/Pdf::pageWidth()`, `pageHeight()`,
`hasFont()`; `AiResult::valueOr()`; `SupportAttachmentService::humanBytes()`;
`QuestionnaireChat::noteSource()`; `VendorCatalogue::forOrgs()`. A further 25 have test coverage but
no production caller — a test proving a mechanism works is not the same as the mechanism being
reachable, and `AiCapability::$timeout` is the standing proof of how expensive that gap gets.

The full list is reproducible with the scan described above; it is not reproduced here because the
value is in the nine rows in the table, not the tail.

---

### 3.6 The test suite makes live outbound calls to `api.openai.com`

**Severity: medium.** Not a product defect; a CI-integrity one.

Three tests — `QuestionnaireAdminRenderTest:44`, `InterviewPageTest:56`,
`QuestionnaireInterviewTest:82` — seed a `gates_settings` row with
`['value' => 'sk-test-not-a-real-key']`. `AiService` resolves it as a real credential and calls
`curl_exec` (`AiService.php:1226`). The run log carries OpenAI's own reply:

```
[AiService] chat() failed — openai/gpt-4o-mini → HTTP 401 {
  "error": { "message": "Incorrect API key provided: sk-test-**********-key. …
```

That response came back over the network, so the request left the machine. Three consequences: the
suite depends on outbound reachability and will behave differently on an isolated runner; it pays
connection latency and a retry budget on every run; and it sends traffic to a third party on every
CI build. The suite is green either way, which is what makes it easy to miss — the failure path
under test is reached whether the call is refused locally or refused in Virginia.

The fix is a transport seam or a sentinel key that `AiService` refuses before dialling, so the
no-provider path is exercised without egress.

---

## 4. Smaller findings

- **Four orphan templates.** `pages/privacy.twig` and `pages/terms.twig` are dead: legal documents
  are now database-backed and rendered through `LegalService` + `partials/article.twig` (see the
  `$legalRender` closure in `routes.php:2324`). `pages/registry/register-success.twig` and
  `partials/comments.twig` have no reference anywhere in the tree.
- **One un-versioned script tag.** `templates/admin/dashboard.twig:252` loads
  `<script src="/assets/vendor/chart.min.js">` with a literal path while every other script in the
  admin goes through `{{ asset(...) }}`. The file exists, so nothing is broken — but it is outside
  the cache-busting scheme, which is how a stale vendor bundle survives a deploy.
- **`SETUP_TOKEN` travels in the query string.** The gate itself is correct — `hash_equals`,
  minimum length, `404` when unset (`routes.php:88`) — but a query parameter lands in access logs,
  browser history and any outbound `Referer`. The token is bootstrap-only and the migrate page
  tells the operator to delete it afterwards, which is the mitigation; worth knowing it is the only
  one.
- **Admin-authored HTML renders through `|raw`.** `partials/article.twig` renders standfirsts,
  paragraphs, headings and list items unescaped. Its inputs are developer- and superadmin-authored
  (`LegalService`, `HelpCentre`, `CommunityVotingPhilosophy`), so this sits inside the admin trust
  boundary and is deliberate. Noted only so the boundary stays explicit: a superadmin can put
  markup on a public legal page.

---

## 5. Error handling and schema drift, measured

**Silent catches: 259 of 1,248.** 21% of catch blocks in `src/` are `catch (…) {}` with an empty
body — no log, no rethrow, no comment. The 2026-08-08 audit reported 222 empty against 92 that log
or rethrow; those two figures sum to 314, well short of the 1,248 catch blocks now present, so the
methods are not comparable and no trend is claimed here. What is true today: a fifth of all error
handling discards the error, on a system with no metrics, no tracing and no shell.

Some of that is deliberate and correct — `EventService::dispatch()` swallowing an insert failure is
the right call for a telemetry write that must never break a vote. The concern is that the
deliberate cases and the accidental ones are written identically, so neither `grep` nor review can
separate them. A one-line comment inside the brace, which several already carry, is the whole fix.

**Schema-drift probes: 179.** `OptionalColumn` / `hasColumn()` call sites, up from the 54 recorded
on 2026-08-08 against roughly half the source. The last audit called this a reasonable response to
hand-applied migrations on shared cPanel hosting, and that reasoning still holds — but the ratio is
now one probe for every 2.3 source files, and each one is a branch that the test suite must either
cover twice or leave half-covered.

**Persistence coupling.** 250 of 404 source files call `DB::` directly; 2,294 quoted `gates_*` table
literals across 138 distinct tables. Unchanged in character from the last audit and unchanged in
consequence: a column rename is a whole-tree search-and-replace with no compiler help.

---

## 6. Security posture — re-checked, holds

| Surface | Finding |
|---|---|
| **CSP** | Nonce-based. `'unsafe-inline'` is absent from `script-src`. Zero real inline handlers in 201 templates — the single `onclick=` match is inside a comment explaining why there are none. |
| **CSRF** | Exemptions are enumerated and each is justified in comment: OTP-gated API routes, the payment webhook, the live-interview endpoints, `/email/unsubscribe`. The one non-exact-match exemption is documented as carrying its credential in the path. |
| **Setup routes** | `hash_equals` against `SETUP_TOKEN`, minimum 12 chars, `404` (not `403`) when unset — invisible without the credential. |
| **Uploads** | MIME sniffed from bytes with `finfo`, restricted to JPEG/PNG/WebP/GIF/PDF, rasters re-encoded. `public/uploads/.htaccess` now ships (negated in `.gitignore`) and uses only universally-available directives after an earlier version 500'd a deployment. |
| **Secrets** | No `.env` tracked. No high-entropy literal assigned to a key/secret/password/token name anywhere in `src/`, `config/` or `public/`. |
| **JSON-LD** | `Support/Schema.php` routes 16 fields through `text()`; the layout renders with `JSON_UNESCAPED_SLASHES` as documented, so the escaping is load-bearing and present. |

---

## 7. What the previous audit claimed, re-checked

| 2026-08-08 claim | Status today |
|---|---|
| Suite green on clean install | **Holds** — 4,881 tests (was 2,103), 0 failures |
| Every route handler resolves | **Holds** — 496/496 (was 289) |
| Every template named in PHP exists | **Holds** |
| MySQL/SQLite table parity exact | **Holds** — 76/76 (was 75/75) |
| No parse errors | **Holds** — 966 files |
| No unreferenced service classes | **Holds** at class level; **fails at method level** — see §3.5 |
| Nothing found is a live security defect | **Holds** |
| The six §17 no-reader bugs | **All fixed** — `components_json` read at `SystemStatus:290`, `gates_ai_calls` at `:977` and `AiGateway:356,379`, `AiCapability::$model`/`$timeout` consumed on the wire |

The recent flier and ticket-tier work was spot-checked against the invariants in `CLAUDE.md` and is
clean: `fill` is used for backgrounds and `edge` only for borders (`events/form.twig:347`,
`events/detail.twig:940`, `events/ticket.twig:819`); `detail.twig:927` carries an explicit comment
that `loop.last` is not the dearest tier; and `FlierRaster` still holds the only `cover()` and the
only `imagecopyresampled()` in the tree.

---

## 8. Suggested order of work

1. **§3.1** — ~~add `->where('p.is_active', 1)` to both `VoteController` handlers, and walk the
   eight other public readers.~~ **Done.** Five guards now live in `PublicSurfaceSandboxTest`,
   each verified to fail with its fix removed.
2. **§3.2** — one static resolver per service (`CheckoutMailer::smtp()`, `CloudinaryService::config()`),
   `gates_settings` first and `.env` as the fallback; point `CycleAnnouncer` at the same resolver;
   add the fields to `/admin/settings` and replace the "add it to `.env`" copy in
   `admin/media/index.twig`.
3. **§3.4** — call `DisplayTime::toStored()` from `cycleSave()`, delete the two reimplementations,
   and cover the round-trip with a test that asserts the stored string has no `T` and keeps its
   seconds.
4. **§3.3** — either surface `funnelReport()` on the analytics page next to the funnel data that is
   already rendered, or drop `gates_events` and its emitters. Fix the discarded `hasTable()` either
   way.
5. **§3.6** — give `AiService` a sentinel it refuses before `curl_exec`, so the suite stops dialling out.
6. **§3.5** — triage the nine rows in the table; each is a decision to wire up or delete, not a fix.
