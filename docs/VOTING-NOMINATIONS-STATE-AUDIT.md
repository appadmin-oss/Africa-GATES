# Africa GATES — Voting & Nominations State Audit

> **Date:** 2026-07-26 · **Scope:** the award-cycle lifecycle end to end — how a cycle opens and closes, what gates each write path, and what the public/admin UI shows for each phase.
> **Method:** source read of every read and write path that keys on cycle phase (`gates_award_cycles.status` and the five date windows), plus the two schedulers, the cache layer, and every template that renders a phase. Every finding below cites `file:line`. **F4, F5 and F7 were additionally verified by execution** against the installed dependencies (transcripts in §9); the suite was confirmed green at the time of audit — **499 tests, 2494 assertions, OK** — which is itself the point of F3.
> **Nature of this document:** analysis only. **No behaviour was changed.** §7 is the remediation plan.
> **Companion docs:** [`ARCHITECTURE-AND-SCALING-REVIEW.md`](ARCHITECTURE-AND-SCALING-REVIEW.md), [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md), [`CRON_SETUP.md`](CRON_SETUP.md).

---

## 1. Executive summary

The reported symptoms — **voting and nominations never close**, **inconsistent UI**, **no proper UX state** — are all real, and they trace back to **one architectural decision** plus a set of defects that follow from it.

**The root cause: the phase of a cycle is a stored column, not a computed value.**

Every gate in the system — the vote transaction, points redemption, bonus votes, paid-vote checkout, nomination submission, and every template — asks the same question: *is `gates_award_cycles.status` equal to `'voting'` / `'nominations'`?* **Nothing anywhere compares the current time to `voting_close` or `nominations_close`.** The five date windows the admin fills in are inert data; the only thing that ever acts on them is a background job that rewrites `status`.

So closing is not a rule — it is an *event* that a cron job has to deliver. If that job does not run, **the published close date passes and voting stays open forever**, exactly as reported. And the job's delivery is fragile in three independent ways:

1. **The documented lifecycle engine is not the one that is scheduled.** `bin/console cycles:advance` (forward-only, one phase at a time, writes the audit ledger, promotes winners through the judge-quorum path) appears in the README and in its own tests but **is not scheduled anywhere in `CRON_SETUP.md`**. The engine that *is* scheduled — `Maintenance::advanceCycles()` — is a second, weaker implementation with **no forward-only guard, no ledger write, and no winner promotion at all**.
2. **The no-shell-cron fallback is off by default.** `webcron_auto` (`Maintenance::autoEnabled()`) is opt-in, so a host without a real crontab advances nothing, ever.
3. **Even when a phase does flip, the UI keeps serving the old one.** `vote:hub`, `api:awards`, `api:nom:*` and `award:prog:*` are never invalidated by any cycle write — so /vote can advertise "Voting open" for 10 minutes and /awards/{slug} for 30 minutes after the flip. From the admin's chair the change looks like it did not take.

On top of that, the **voting phase can be skipped permanently** for any cycle that has a shortlisting gap between `nominations_close` and `voting_open` (F4) — the normal design — and the **`results` phase never crowns anyone** in the scheduled path, because winner promotion lives only in the unscheduled engine (F2).

The UI complaints are not cosmetic either. The nominate wizard **silently offers closed programmes** and only rejects the submission after four steps (F7); `/awards/{slug}` **never renders a live CTA in any phase** because of a data-contract mismatch (F8); the /vote "Your ballot" tracker reads a `localStorage` key **nothing ever writes** (F9); and paid-vote checkout failures — including "voting closed" — bounce the supporter back to an **unchanged page with no message** (F10).

**Severity roll-up: 4 P0, 4 P1, 4 P2, 3 P3.** The single highest-leverage change is to make phase a **computed function of the date windows**, evaluated at request time by both read and write paths, with the cron demoted from *source of truth* to *materialiser* (for emails, ledger entries and reporting). That alone fixes F1 and makes F2–F5 non-catastrophic.

### Findings at a glance

| ID | Severity | Finding |
|----|----------|---------|
| **F1** | **P0** | No request-time date gate anywhere — closing depends entirely on a cron job rewriting `status` |
| **F2** | **P0** | Two divergent lifecycle engines; the scheduled one has no forward-only guard, no ledger, and **never promotes winners** |
| **F3** | **P0** | The test suite covers the *unscheduled* engine — every lifecycle guarantee is asserted about code that does not run |
| **F4** | **P0** | `statusFor()` **permanently skips the voting phase** for any cycle with a shortlisting gap |
| **F5** | **P1** | Cycle changes do not invalidate `vote:hub`, `api:awards`, `api:nom:*` or `award:prog:*` — the UI serves a stale phase |
| **F6** | **P1** | The "current cycle" is resolved **four different ways**; the admin can edit a different cycle than the site is running |
| **F7** | **P1** | The nominate wizard falls back to **all** programmes when none are open, then rejects at submit |
| **F8** | **P1** | `/awards/{slug}` reads a key the service never returns — no phase CTA ever renders, in any phase |
| **F9** | **P2** | /vote "Your ballot" tracker reads a `localStorage` key nothing writes — permanently 0 of N |
| **F10** | **P2** | Paid-vote failure reasons (`?paid=closed`, …) are produced but rendered nowhere |
| **F11** | **P2** | Countdown copy is date-driven while open/closed is status-driven — the page contradicts itself when they disagree |
| **F12** | **P2** | Admin cycle editor: unselectable options, no computed phase, no date-window validation, no timezone hint |
| **F13** | **P2** | `PaidVoteService::mint()` has no phase check — a payment confirmed after close still mints votes |
| **F14** | **P3** | Paid-vote checkout accepts non-`pending` nominees (rejected/withdrawn) and merged-away duplicates |
| **F15** | **P3** | `castVote` reads `voting_close` and uses it only for display, never as a gate |
| **F16** | **P3** | Assorted cross-surface inconsistencies (API year default, deep links, `voting_open` name/type collision, 302-vs-404) |

---

## 2. How the lifecycle is *supposed* to work

Per `README.md` §"Award Cycle Management" and the docblocks in `CycleService` / `CycleAdvanceCommand`:

```
upcoming → nominations → voting → judging → results → archived
```

The admin sets five date windows in **Programmes → (a programme) → Cycle** (`nominations_open`, `nominations_close`, `voting_open`, `voting_close`, `results_date`) and the platform is meant to run the lifecycle itself:

- an hourly job moves each cycle **forward one phase at a time** as those dates pass,
- it writes a tamper-evident row to `gates_cycle_transitions`,
- and on entry to `results` it promotes winners/runners-up **by CPI rank, subject to the judge quorum**.

The admin editor is documented as enforcing the same rules — forward-only, one phase at a time, `results` never settable by hand.

**That description matches `CycleAdvanceCommand` exactly. It does not describe the code that actually runs.** See F2.

---

## 3. P0 findings — why voting and nominations never close

### F1 — There is no request-time date gate anywhere in the system

Every gate in every path keys on the stored `status` column and nothing else:

| Path | Location | Gate |
|------|----------|------|
| Organic OTP vote | `src/Services/VoteService.php:89` | `$cycle->status !== 'voting'` |
| Points redemption | `src/Services/PointsService.php:144` | `$cycle->status !== 'voting'` |
| Donation bonus votes | `src/Services/BonusVoteService.php:75` | `$cycle->status !== 'voting'` |
| Paid-vote checkout | `src/Controllers/PaidVoteController.php:172` | `(string)$status === 'voting'` |
| Nomination submit | `src/Services/AwardService.php:95` | `whereIn('status', ['nominations'])` |
| Nominee ballot render | `src/Controllers/VoteController.php:184` | `$nom->cycle_status === 'voting'` |
| Programme page render | `src/Controllers/VoteController.php:95` | `($p['cycle']['status'] ?? null) === 'voting'` |
| Nominate form render | `src/Controllers/NominationController.php:21` | `in_array($p['cycle_status'], ['nominations'])` |

**`voting_close` is never compared to `now()` on any request path.** The single place `VoteService` reads it (`src/Services/VoteService.php:88`) uses it only to compute the `days_left` number returned to the browser (`:130`) — see F15.

**Consequence.** The close date the admin published, the close date shown on /vote ("Voting closes 12 Jun"), and the close date emailed to voters are all **advisory text**. The actual close is whenever a background job next rewrites `status`. If that job never runs — no crontab entry, or `webcron_auto` left at its default `off` (`src/Support/Maintenance.php:137,152`) — **voting and nominations remain open indefinitely past their published dates**. This is the reported symptom, and it is a design property, not a crash.

**Why this is P0 beyond the UX.** Votes cast after the published close are *accepted and counted*, and they feed the CPI community signal (`organic_vote_count`, `src/Services/VoteService.php:115-118`) that decides winners. On a platform whose stated value proposition is tamper-evident, hash-chained standings, "the close date was not enforced" is an integrity defect, not an inconvenience.

### F2 — Two divergent lifecycle engines; the scheduled one is the weaker one

There are **two independent implementations** of cycle advancement:

**Engine A — `CycleAdvanceCommand` (`src/Console/Commands/CycleAdvanceCommand.php`)**
- forward-only: refuses to regress a cycle (`:83-86`)
- one phase per run, so `voting` can never be skipped (`:91`)
- writes `gates_cycle_transitions` (`:97`, `:184-199`)
- on entry to `results`, runs `promoteWinners()` — CPI-ranked, judge-quorum-gated, with public activity rows and winner emails (`:101-103`, `:127-181`)
- busts both `awards:%` and `%leaderboard%` (`:109-110`)

**Engine B — `Maintenance::advanceCycles()` (`src/Support/Maintenance.php:230-258`)**
- jumps **straight to the computed target** — no forward-only guard, no one-phase-at-a-time (`:239-241`)
- **no `gates_cycle_transitions` write**
- **no `promoteWinners()` call — winners are never promoted**
- busts only `awards:%` (`:253`)
- emits a `cycle.status_changed` webhook that Engine A does not (`:243-249`)

**Engine B is the one that runs.** `cron/maintenance.php` → `Maintenance::run('auto')` → `advanceCycles()` on every tick (`src/Support/Maintenance.php:62`), and `CRON_SETUP.md` schedules `cron/maintenance.php` every 15 minutes. **`cycles:advance` is not scheduled anywhere** — `grep -rn "cycles:advance"` returns only its own source, its own docblock, and a README sentence.

**Consequences, all live:**

1. **The `results` phase crowns nobody.** A cycle reaches `results` and no nominee is ever set to `winner`/`runner_up`; no `gates_activity` `kind='winner'` row is written; no congratulations email is sent. The leaderboard's `awards_given` count (`src/Controllers/ApiController.php:295`) stays at zero. The entire quorum-checked promotion path in `CycleAdvanceCommand::promoteWinners()` is dead code in production.
2. **The transitions ledger is empty for automatic changes.** `gates_cycle_transitions` receives rows only from the admin editor (`src/Admin/Controllers/ProgrammesController.php:146-157`). The "complete history (auto + manual)" the code comments promise does not exist.
3. **A cycle can silently regress.** Because Engine B has no forward-only guard, a mistyped `results_date` moves a *published* cycle back out of `results`, un-announcing results, with no ledger entry recording it.
4. **The README describes Engine A.** Anyone operating this platform from the documentation believes they have forward-only, one-phase-at-a-time, quorum-checked promotion. They have none of it.

### F3 — The test suite covers the engine that does not run

- `tests/Unit/CycleTransitionTest.php` — `test_advances_one_phase_and_never_skips_voting`, `test_backward_transition_is_skipped`, `test_forward_transition_is_applied_and_logged`
- `tests/Unit/CycleAdvanceWinnersTest.php` — `test_winner_is_chosen_by_cpi_not_raw_votes`, `test_tie_breaks_deterministically_by_lower_id`

**All five drive `CycleAdvanceCommand` (Engine A).** `tests/Unit/MaintenanceTest.php` has four tests — cache pruning, digest, unknown-task handling, tick gating — and **never calls `advanceCycles()`**.

So the suite asserts, in green, three guarantees (never skips voting · never regresses · promotes winners by CPI under quorum) that **the production code path does not provide**. This is why the defect survived: the tests are not wrong about Engine A, they are simply pointed at the wrong engine.

### F4 — `statusFor()` permanently skips the voting phase when there is a shortlisting gap

`src/Console/Commands/CycleAdvanceCommand.php:52-58`:

```php
$status = 'upcoming';
if ($nomOpen  && $now->gte($nomOpen))   $status = 'nominations';
if ($nomClose && $now->gt($nomClose))   $status = 'judging';   // shortlisting gap
if ($voteOpen && $now->gte($voteOpen))  $status = 'voting';
if ($voteClose&& $now->gt($voteClose))  $status = 'judging';   // final judging
if ($results  && $now->gte($results))   $status = 'results';
```

Line 54 uses **`judging`** to represent the shortlisting gap between nominations closing and voting opening — the same value line 56 uses for *post-voting* judging. The two are indistinguishable, and `judging` sorts **after** `voting` in the ordinal (`:28`). For the normal configuration `nominations_close < voting_open`:

| Time | `statusFor()` target | Engine A does | Result |
|------|---------------------|---------------|--------|
| after `nominations_close` | `judging` (ord 3) | current `nominations` (1) → steps to **`voting`** (2) | voting opens **early**, before `voting_open` |
| next tick | `judging` | `voting` (2) → `judging` (3) | voting closes again after one tick (~15–60 min) |
| at `voting_open` | **`voting`** (2) | current is `judging` (3) → **backward, refused** (`:83-86`) | **voting never opens** |
| at `voting_close` | `judging` | no change | still closed |
| at `results_date` | `results` | advances | results published with no votes |

**A cycle designed with a shortlisting gap gets a spurious ~15–60 minute voting window at the wrong time and then never opens for voting at all.** The "never skips voting" test passes because it only checks that the *ordinal* is not jumped, not that the *voting window* is honoured.

Engine B (the scheduled one) papers over the final row — with no forward-only guard it will regress `judging → voting` at `voting_open` — but it does so by regressing freely, which is F2's defect. **Neither engine implements the intended semantics.** Under Engine B the cycle also flips `nominations → judging → voting → judging`, and each flip fires a `cycle.status_changed` webhook, so downstream consumers see a phase sequence that never happened.

---

## 4. P1 findings — why the UI is inconsistent

### F5 — Cycle changes do not invalidate the caches the public pages read

`CacheService` is a DB table with per-key TTLs and a `tags` column (`src/Services/CacheService.php`). Phase-derived keys and their invalidation:

| Key | TTL | Set at | Invalidated by a cycle change? |
|-----|-----|--------|-------------------------------|
| `awards:active` | 1800s | `NominationController.php:20,38` | ✅ `awards:%` and admin `forget()` |
| `awards:index` | 1800s | `AwardsController.php:15` | ✅ `awards:%` |
| **`vote:hub`** | 600s | `VoteController.php:20` | ❌ **never** |
| **`api:awards`** | 1800s | `ApiController.php:58` | ❌ **never** |
| **`api:nom:{prog}:{cat}:{yr}`** | 300s | `ApiController.php:55` | ❌ **never** |
| **`award:prog:{slug}`** | 1800s | `AwardsController.php:19` | ❌ **never** (`awards:%` does not match `award:prog:…` — no `s`) |

The three invalidation sites are all pattern- or key-based, and all three miss:
- `Maintenance::advanceCycles()` → `where('cache_key','like','awards:%')` (`src/Support/Maintenance.php:253`)
- `CycleAdvanceCommand` → `awards:%` + `%leaderboard%` (`:109-110`)
- admin `bustAwardsCache()` → `forget('awards:active')` **only** (`src/Admin/Controllers/ProgrammesController.php:23`)

**Consequence.** After voting closes — by cron or by hand — **/vote keeps advertising "Voting open" and rendering "Vote →" buttons for up to 10 minutes**, `/awards/{slug}` for up to 30 minutes, and the public API for up to 30 minutes. Clicking through lands on a nominee page that (correctly, uncached) says voting is closed. That is the "inconsistent UI" report, reproducible on demand. Note the `tags` mechanism already exists and is used elsewhere (`['leaderboard']`, `['registry']`) — the award keys simply never pass a tag.

### F6 — The "current cycle" is resolved four different ways

| Consumer | Selector | Location |
|----------|----------|----------|
| Public pages (hub, programme, nominate) | status-priority (`in-flight` first), then `year DESC`, then `id DESC` | `src/Services/AwardService.php:17-21`, `:38-40` |
| Admin cycle editor (read) | `orderByDesc('year')` | `src/Admin/Controllers/ProgrammesController.php:92` |
| Admin category editor | `orderByDesc('year')` | `:168` |
| Admin cycle save | `where('year', <year from the form>)` | `:109` |
| **Nomination write** | `where('year', date('Y'))` **+** status | `src/Services/AwardService.php:93-95` |

The public selector is deliberately year-agnostic — `tests/Unit/ActiveCycleSelectionTest.php::test_active_cycle_shows_even_when_tagged_a_different_year` exists specifically to keep an in-flight cycle visible across the New Year boundary or when seeded ahead. **The nomination write path contradicts that test.**

Three concrete failures:

1. **Advertised-then-rejected nominations.** A cycle in `nominations` whose `year` is not the current calendar year is listed as open on /nominate (`NominationController.php:21`) and then **rejected at submit** with "Nominations are not currently open for this programme" (`AwardService.php:97`). The user cannot tell what they did wrong, and neither can the operator.
2. **The admin edits a different cycle than the site runs.** With a 2027 cycle seeded, `orderByDesc('year')` puts the admin on 2027 while the public site (status-priority) runs the in-flight 2026 cycle. The admin flips the status, sees "Cycle saved.", and **nothing changes on the site** — an exact match for "the logic is completely faulty".
3. **Editing the year silently creates an empty cycle.** `cycleSave` matches on the *submitted* year (`:109`). Change the year field and `$cycle` is `null`, so the code takes the `insertGetId` branch (`:136-139`) — a brand-new cycle with **no categories and no nominees**. Because `$from === null`, `CycleService::manualTransitionError()` waves any target status through (`src/Services/CycleService.php:45-50`), including starting straight at `judging`. There is no confirmation and no warning.

### F7 — The nominate wizard offers closed programmes, then rejects at submit

`templates/pages/nominate.twig:5`:

```twig
{% set awards = programmes|default(all_programmes|default(awards_data|default([]))) %}
```

`programmes` is the **open-for-nominations** list; `all_programmes` is **every active programme in any phase** (`src/Controllers/NominationController.php:29`). Twig's `default` filter falls back when the value is *empty*, not only when it is undefined — and an empty array is empty. So **when nothing is open, the wizard's programme picker silently lists every programme**, including `upcoming`, `voting`, `judging` and `results` cycles.

The user picks one, completes four steps (nominee identity + photo, programme + category, a ≥60-character story with optional AI polish and up to three reference links, their own identity), submits — and `AwardService::submitNomination()` throws "Nominations are not currently open for this programme" (`:97`), which re-renders the wizard with a red banner (`NominationController.php:40,76`).

The intended empty state — `"No programmes are open for nominations right now."` (`nominate.twig:223`) — is inside a `{% for %}…{% else %}` over `awards`, so it is **unreachable whenever at least one active programme exists in the database**.

Compounding it: **there is no page-level closed state at all.** The hero ("Nominate — put someone forward"), the stepper, and the wizard render identically whether nominations are open, closed, or years away. Nothing tells the visitor *when* nominations open, and nothing offers the alternative action (watch the leaderboard, subscribe).

### F8 — `/awards/{slug}` never renders a phase CTA, in any phase

`templates/pages/awards/programme.twig:5`:

```twig
{% set status = a.cycle_status|default('upcoming') %}
```

But `AwardService::getProgrammeBySlug()` returns the cycle as a **nested array under `cycle`** and never sets a `cycle_status` key (`src/Services/AwardService.php:42`). (`cycle_status` is a key of `getActiveProgrammesWithStatus()` — a *different* method, `:25`.)

`status` is therefore **always `'upcoming'`** on every programme page. Consequences:
- the hero "Cast a vote" CTA (`:14-15`) and "Submit a nomination" CTA (`:16-17`) **never render, in any phase** — a visitor on the programme page for a live voting cycle is offered only "All programmes";
- the per-category "Vote" buttons (`:38-40`) never render either;
- the eyebrow hardcodes `"Programme · 2026 Cycle"` (`:10`) regardless of the actual cycle year.

The page is a dead end for the entire lifecycle.

---

## 5. P2 findings — dead and unreachable UX state

### F9 — The /vote ballot tracker can never be non-zero

`templates/pages/vote.twig:250-256` reads, for each live programme id:

```js
if (localStorage.getItem('afg_voted_prog_' + id)) this.voted[id] = true;
```

`grep -rn "afg_voted_prog"` across the whole repository returns **exactly this one line**. Nothing — not the nominee ballot's success step (`vote-nominee.twig:349-361`), not `afg-features.js`, not the paid-vote success page — ever writes the key.

So the right-rail card always reads **"0 of N live programmes voted"** with an empty progress bar, and the CTA always says "Start voting" (`:223`), even for a voter who has just voted. The one piece of genuine per-user state on the hub is inert.

### F10 — Paid-vote failure reasons are produced but rendered nowhere

`src/Controllers/PaidVoteController.php:55` builds a bail redirect that appends a reason chip:

```php
$bail = fn(string $why) => $this->redirect($res, $backUrl . '…?paid=' . urlencode($why));
```

Eight distinct reasons are emitted: `off`, `rate`, `nominee`, `closed`, `unavailable`, `email`, `error`, `start` (`:57-95`). **No template reads a `paid` query parameter** — `grep -rn "paid=" templates/` returns nothing.

A supporter who submits the buy-votes form and is refused **because voting has closed** is redirected back to a ballot page that looks exactly as it did before, with their form values gone and no message. Same for a rate-limited supporter, a bad email, a failed gateway init, and a disabled provider. The controller carefully computes eight states; the UI shows one (silence).

### F11 — Countdown copy contradicts the open/closed decision

The two are derived from different sources:
- **open/closed** ← `cycle_status` (`VoteController.php:26`, `:95`, `:184`)
- **countdown / close copy** ← `voting_close` (`VoteController.php:29-34`; `vote.twig:119`, `:228-231`)

When they disagree — which is precisely the reported failure mode — the hub renders, simultaneously:

- badge: **"Voting open · 2026 cycle"** (status says voting)
- hero stat: **"0 Days left"** (`max(0, …)` clamps the negative remainder, `VoteController.php:34`)
- right rail: **"Closing soon — Voting closes 12 Jun"** (a date now in the past)
- cards: green **"Voting open"** chips and live **"Vote →"** buttons

There is no "closed", "closing today", or "closes in N hours" state, and no `aria-live` region — the countdown is rendered once at page load and never updates.

### F12 — The admin cycle editor cannot express or validate the lifecycle

`templates/admin/programmes/cycle.twig` + `src/Admin/Controllers/ProgrammesController.php:104-162`:

- **Unselectable options.** The status `<select>` offers all six values including `results` (`cycle.twig:17`), but `CycleService::manualTransitionError()` refuses `results` **unconditionally** (`src/Services/CycleService.php:41-43`). The admin picks it, round-trips, and gets a red flash. `archived` is likewise refused for a new cycle (`:46-48`). Options that can never be chosen should not be offered.
- **No computed phase shown.** The editor displays the *stored* status and the five dates but never "given these dates, the platform considers this cycle **voting**, closing in 3 days" — which is the single fact the admin needs to trust the automation.
- **No date-window validation.** Nothing rejects `voting_close < voting_open`, `nominations_close < nominations_open`, or `results_date` before `voting_close`. In particular, nothing warns about the `nominations_close < voting_open` ordering that triggers F4's permanent voting skip — the configuration the UI most encourages.
- **No transition history.** `gates_cycle_transitions` is written (by the admin path) but never displayed, so the admin cannot see what changed the phase or when.
- **Timezone is unstated.** The five fields are `datetime-local` (`:22-29`) and the values are stored verbatim (`ProgrammesController.php:115-119`), then parsed with `Carbon::parse()` in server time (`CycleAdvanceCommand.php:45`). The admin's browser timezone and the server's are silently assumed identical; nothing on the form says which timezone the dates mean. For a continental platform spanning UTC-1 to UTC+4 this is a real off-by-hours risk on close night.

### F13 — `PaidVoteService::mint()` has no phase check

`PaidVoteController::start()` checks that voting is open before taking money (`:62`). **`PaidVoteService::mint()` does not** (`src/Services/PaidVoteService.php:95-139`) — it validates the order (`tier`, `status`, `qty`, nominee exists) and mints.

`mint()` is reachable from **two** post-checkout paths: the browser callback (`PaidVoteController.php:114`) and the gateway webhook. Both can land arbitrarily long after `start()` — a supporter who leaves the gateway tab open, or a webhook retried after an outage. So a payment initiated while voting was open, but **confirmed after voting closed**, still inserts a weighted `gates_votes` row and increments the nominee's public `vote_count` (`:115-129`) — a vote recorded after the close, on the money path, with no phase check anywhere in it.

---

## 6. P3 findings — cross-surface inconsistencies

### F14 — Paid-vote checkout accepts nominees no other path would

`src/Controllers/PaidVoteController.php:61`:

```php
if (!$nominee || (string)$nominee->status === 'pending')  return $bail('nominee');
```

A **denylist of one**. Every other vote path uses an allowlist plus a merge check — `where('status','approved')` + `MergeService::notMerged(…)` (`VoteService.php:76`, `PointsService.php:138`, `BonusVoteService.php:64`). So paid checkout accepts a `rejected` or withdrawn nominee, and accepts a **merged-away duplicate** (`merged_into` is never consulted), taking real money for votes on a nominee that the public pages no longer show and whose tally is no longer read.

### F15 — `castVote` reads the close date and uses it only for display

`src/Services/VoteService.php:85-92` selects `cy.status, cy.voting_close` and gates on `status` alone. `voting_close` is then used at `:129-132` purely to compute the `days_left` figure returned to the browser. When the close date has passed but `status` is stale, the vote **succeeds** and the response cheerfully reports `days_left: 0`. The data needed to refuse the vote is already in hand, on the same row, in the same transaction.

### F16 — Assorted inconsistencies

- **Public API returns nothing for live cycles.** `ApiController::nominees()` defaults `year` to `date('Y')` (`:53`) and `AwardService::getNominees()` hard-matches that year (`:47`). For exactly the off-calendar-year cycles that `ActiveCycleSelectionTest` protects on the site, `/api/nominees` returns `[]`.
- **Three different vote deep-link shapes.** /awards cards → `/vote` (`awards/index.twig:113`); programme categories → `/vote#{{ c.slug }}` (`awards/programme.twig:39`) — an anchor **no page defines**; hub cards → `/vote/{slug}` (`vote.twig:193`). Only the third lands anywhere useful.
- **`voting_open` is both a date and a boolean.** `AwardService::getActiveProgrammesWithStatus()` returns the **datetime** under `voting_open` (`:27`), while controllers pass a **boolean** named `voting_open` to templates (`VoteController.php:95`, `:184`). Same name, two types, both reachable in template scope on different pages — a latent trap for anyone editing these templates.
- **Same mistake, two outcomes.** An unknown programme slug 302s to the hub on /vote ("never a 404", `VoteController.php:73`) and throws a 404 on /awards/{slug} (`AwardsController.php:20`).
- **Late nominations are simply accepted.** `submitNomination` ignores `nominations_close` for the same reason as F1, so once `status` is stale the write path takes nominations during what should be voting or judging.
- **The nominee breadcrumb skips the programme.** `vote-nominee.twig:140` points the category crumb at `/vote` rather than `/vote/{programme-slug}`, so there is no way back to the category listing.

---

## 7. Remediation plan

Ordered by leverage. Items 1–3 are the fix for "voting does not close"; 4–6 are the fix for "inconsistent UI / no proper UX state".

### 1. Make phase a computed value — one policy object, used by reads *and* writes  *(fixes F1, F15; de-risks F2–F5)*

Introduce a single `CyclePhase` policy:

```php
CyclePhase::of(object|array $cycle, ?Carbon $now = null): Phase
// → phase: 'upcoming'|'nominations'|'shortlisting'|'voting'|'judging'|'results'|'archived'
//   plus: opens_at, closes_at, seconds_left, is_voting_open, is_nominations_open
```

Rules: derive the phase from the **date windows** when they are set; fall back to the stored `status` only for a cycle with no windows (and for `archived`, which stays a deliberate manual state). Then:

- replace all eight `status === 'voting'` / `'nominations'` gates in §F1's table with `CyclePhase::of($cycle)->is_voting_open` / `->is_nominations_open`;
- `VoteService::castVote()` refuses with `VOTING_CLOSED` when `now > voting_close`, inside the existing transaction (the row is already loaded — F15);
- `AwardService::submitNomination()` refuses past `nominations_close`.

**The cron is then a materialiser, not the source of truth.** It still rewrites `status` (so reports, admin lists and webhooks stay accurate) and still sends the phase emails — but if it stops, **voting still closes on time**, because closing became a rule.

### 2. Delete one lifecycle engine, and add the missing phase  *(fixes F2, F4)*

- Give `CycleAdvanceCommand` and `Maintenance::advanceCycles()` **one** shared implementation — move Engine A's policy (forward-only · one phase per run · ledger write · `promoteWinners()` · both cache busts) into a service both call. Keep Engine B's `cycle.status_changed` webhook in the shared path.
- Add an explicit **`shortlisting`** phase between `nominations` and `voting` so line 54's gap no longer collides with post-voting `judging`. Ordinal: `upcoming(0) → nominations(1) → shortlisting(2) → voting(3) → judging(4) → results(5) → archived(6)`. This makes forward-only and "never skip voting" simultaneously satisfiable.
- Schedule the survivor in `CRON_SETUP.md`, and state plainly that `webcron_auto` is the fallback for hosts with no crontab.

### 3. Test the path that runs  *(fixes F3)*

- Re-point `CycleTransitionTest` and `CycleAdvanceWinnersTest` at the shared service (or duplicate them for `Maintenance::advanceCycles()`), so the "never skips voting / never regresses / promotes winners under quorum" guarantees cover production.
- Add a **regression test for F4**: a cycle with `nominations_close = T`, `voting_open = T+7d`, ticked hourly across the whole window, must be votable **only** between `voting_open` and `voting_close`.
- Add a **cron-independent close test**: freeze time past `voting_close` with `status` left at `'voting'`, and assert `castVote`, `redeemForVote`, `BonusVoteService`, `PaidVoteService::mint()` and paid `start` all refuse, and that /vote renders closed.

### 4. Fix cache invalidation properly  *(fixes F5)*

Tag every award/cycle-derived key `['awards']` at the `remember()` call — `vote:hub`, `api:awards`, `api:nom:*`, `award:prog:*`, `awards:active`, `awards:index` — and replace all three ad-hoc invalidation sites with a single `forgetByTag('awards')`. Drop the `LIKE 'awards:%'` patterns (they silently miss `award:prog:*`). Once phase is computed (item 1), also **exclude the phase from what gets cached**, or cap the TTL at the countdown's resolution, so a cached payload can never advertise a phase that has ended.

### 5. One current-cycle resolver  *(fixes F6)*

Extract `AwardService::currentCycle(int $programmeId): ?object` — the status-priority selector already used by the public pages — and use it in the admin editor read paths, the category editor, and `submitNomination`. In `cycleSave`, resolve the target cycle by **id** (a hidden field) rather than by the submitted year, and make creating a new cycle an explicit, confirmed action rather than a side effect of editing a number.

### 6. One phase view-model, rendered consistently  *(fixes F7–F12, F16)*

Expose the `Phase` object (item 1) to every template that renders lifecycle state — /vote, /vote/{slug}, /vote/{slug}/{nominee}, /nominate, /awards, /awards/{slug} — and give each surface a real state for **every** phase:

- **/nominate:** drop the `|default(all_programmes)` fallback (`nominate.twig:5`) so the closed state is reachable; add a page-level banner — *"Nominations for {programme} are closed. They open on {date}."* — with the alternative action, and keep the wizard out of the DOM when nothing is open.
- **/awards/{slug}:** have `getProgrammeBySlug()` return `cycle_status` (or read `cycle.status` in the template) so the hero and category CTAs work; replace the hardcoded "2026 Cycle".
- **/vote:** derive the badge, `days_left` and closing copy from **one** source so they cannot contradict each other; add "closes in N hours" / "closed" states and an `aria-live` countdown; either write `afg_voted_prog_<id>` on a successful vote (nominee ballot success step, points redemption, paid success) or remove the tracker.
- **Paid voting:** render the eight `?paid=` reasons as a message on the ballot; add the phase check to `mint()`; switch `start()` to the `approved` + `notMerged` allowlist used everywhere else.
- **Admin cycle editor:** show the computed phase and the next transition with its date; offer only selectable statuses; validate window ordering (and warn on the F4 configuration); show recent `gates_cycle_transitions` rows; label the timezone on the five datetime fields.
- **Deep links:** point every "Vote" CTA at `/vote/{programme-slug}` (or the category anchor, if one is added), and fix the nominee breadcrumb to link its programme.

---

## 8. What is genuinely solid

For balance — the parts of this subsystem that are well built and should not be touched while fixing the above:

- **The vote transaction itself.** `VoteService::castVote()` is careful work: row-level lock on the OTP token, per-token attempt cap, the OTP consumed **only** on success so a transient failure never burns the user's code, OTP bound to the exact nominee and award (blocking request-for-A/vote-for-B), one-vote-per-(email, category) enforced in code **and** by a DB unique, and voter-scoped idempotent replay.
- **The money/CPI separation.** Paid and bonus votes move the public `vote_count` only; `organic_vote_count` — the CPI community signal — is never touched by money (`PaidVoteService.php:127-129`, and the `max_paid_weight_pct` cap in `PointsService`/`BonusVoteService`).
- **Server-authoritative pricing.** `PaidVoteService::price()` recomputes the charge server-side and takes the cheaper of per-vote and bundle rates; the callback re-verifies the amount before confirming.
- **Winner selection policy.** `promoteWinners()` ranks by full CPI (not raw votes), enforces a judge quorum, excludes under-quorum nominees from the ranking rather than scoring them zero, breaks ties deterministically, and is idempotent. It is good code — it simply never runs (F2).
- **Judge-phase gating** is the one place the phase check is applied consistently and in both directions: scoring is locked before *and* after `judging` (`JudgeService.php:160`, `:219`).
- **Schema-drift tolerance.** `submitNomination()` checks each extended column against the live table rather than probing one and assuming the rest — the right call for a platform that has been migrated in stages.

---

## 9. Verification transcripts

Three findings were confirmed by execution rather than by reading alone.

**F4 — voting is skipped.** Driving the real `CycleAdvanceCommand::statusFor()` plus the command's own forward-only, one-step rule over a cycle with `nominations_close = 2026-07-01`, `voting_open = 2026-07-15`, `voting_close = 2026-08-15`, `results_date = 2026-09-01`, starting from `status = 'nominations'`:

```
2026-07-02  target=judging     status=voting       -> voting        ← opens 13 days EARLY
2026-07-03  target=judging     status=judging      -> judging       ← closes again after one tick
2026-07-16  target=voting      status=judging      REFUSED (backward)
2026-07-20  target=voting      status=judging      REFUSED (backward)
2026-08-01  target=voting      status=judging      REFUSED (backward)
2026-08-20  target=judging     status=judging      no change
2026-09-02  target=results     status=results      -> results       ← results with no votes
```

Voting was open for one tick at the wrong time and **never once during the real 07-15 → 08-15 window**.

**F5 — the cache pattern misses the pages that matter.** Against `LIKE 'awards:%'`:

```
awards:active          matched: yes
awards:index           matched: yes
vote:hub               matched: NO
api:awards             matched: NO
api:nom:1:0:2026       matched: NO
award:prog:foo         matched: NO
```

**F7 — Twig's `default` fires on an empty array.** Rendering `{% set awards = programmes|default(all_programmes) %}{{ awards|length }}` with `strict_variables` **on**:

```
programmes = []    → 3     ← falls back to all_programmes
programmes = [9]   → 1
```

So an empty open-programmes list silently becomes the full programme list.

## 10. Reproduction notes

For anyone verifying the remaining findings against a live install:

1. **F1 (never closes).** Set a cycle's `voting_close` to yesterday, leave `status = 'voting'`, do not run the cron. /vote still shows "Voting open"; a full OTP vote succeeds and returns `days_left: 0`.
2. **F2 (no winners).** Set `results_date` to the past and run `php cron/maintenance.php cycles`. `status` becomes `results`; `SELECT * FROM gates_nominees WHERE status IN ('winner','runner_up')` is empty and `gates_cycle_transitions` has no new row. Now run `php bin/console cycles:advance` against the same data — winners appear.
3. **F4 (voting skipped).** Cycle with `nominations_open = -30d`, `nominations_close = -14d`, `voting_open = -7d`, `voting_close = +7d`, `status = 'nominations'`. Run `bin/console cycles:advance` repeatedly: `nominations → voting → judging`, then it stops. It never returns to `voting` despite being inside the voting window.
4. **F5 (stale UI).** Load /vote (populates `vote:hub`), then flip the cycle out of `voting` in the admin. /vote keeps showing "Voting open" and live "Vote →" buttons; the nominee page it links to says voting is closed.
5. **F7 (closed programmes offered).** Set every cycle to `upcoming`. /nominate still lists every programme in step 2; completing the wizard yields "Nominations are not currently open for this programme."
6. **F8 (no CTA).** With a cycle in `voting`, load `/awards/{slug}`: no "Cast a vote" button, no per-category "Vote" buttons.
7. **F9 (dead tracker).** Cast a real vote, return to /vote: still "0 of N live programmes voted".
8. **F10 (silent failure).** Enable paid voting, set the cycle to `judging`, submit the buy-votes form: redirected back to an unchanged ballot with `?paid=closed` in the URL and no message on the page.

## 11. Sizing the historic damage — `bin/console cycles:audit`

Everything in §7 fixes the **future**: the phase is computed, so voting closes on
time, and `BallotGuard` refuses writes outside the window. None of it touches the
rows written across the years when nothing closed. Those rows are the reason
strict enforcement cannot simply be switched on and declared done, and three of
them carry money or a published result.

`PhaseAuditService` answers exactly those questions and writes nothing:

```
bin/console cycles:audit            # human report
bin/console cycles:audit --json     # for a ticket or a spreadsheet
bin/console cycles:audit --strict   # non-zero exit if anything was found
```

| Section | The question it answers | Why it needs a human |
| --- | --- | --- |
| **Clock** | Does the DB's `CURRENT_TIMESTAMP` agree with PHP's `now`? | A whole-hour skew means the findings below are timezone artefacts, not offences. This is the unresolved `DATETIME`-vs-`TIMESTAMP` question, made visible. **Read it first.** |
| **Cycles** | Stored `status` vs computed phase, for *every* cycle | `BEHIND` is the bug (the engine never caught up). `AHEAD` is legitimate (an operator advanced it by hand). Conflating them would have someone "fix" their own deliberate action. |
| **Undeclared boundaries** | Which cycles could not be audited at all | With no `voting_close` there is no instant at which a vote became late. Reported separately so *"we checked and found nothing"* stays distinct from *"we could not check"*. |
| **Votes after close / before open** | How many ballots landed outside the window, per cycle and vote type, with weight | Count = how many offences. Weight = how far the standings actually moved; one paid row can carry hundreds. |
| **Late nominations** | How many were taken after nominations closed, **broken down by status** | 40 pending rows and 40 approved finalists are entirely different decisions. |
| **Paid orders never minted** | Who paid and got nothing, in naira | `mint()`'s new phase gate refuses rather than minting into a closed cycle, leaving `votes_used = 0` — this turns that signal into a refund bill. Each row is tagged `re-mint` (window open again, delivery still possible), `refund` (`payments:clawback <id> --commit`), or `investigate` (no live target). |
| **Paid votes minted late** | Money kept *and* a closed public tally moved | The worse case, and the one with no clean remedy: voiding changes a published standing; keeping it means a closed result was bought after the fact. Sized so the choice is deliberate. |
| **Finished categories with no winner** | The historic `results` backlog | Winner promotion only happens when the materialiser *claims* the results transition; for these it never did. Note `CycleMaterialiser::ANNOUNCE_GRACE_DAYS` — past the grace window these are promoted **silently**, so a years-old cycle is corrected without emailing anyone about a competition that ended long ago. |

Two deliberate design choices worth knowing before reading the output:

- **Windows are judged half-open, `[open, close)`** — a vote *at* the closing
  instant is late, matching how `CyclePolicy` decides the phase. If the audit
  used `<=` it would report a different population than the guard refuses, and
  the two would disagree forever.
- **Only *declared* boundaries are used.** A cycle with no `voting_close` is
  reported as unjudgeable rather than assigned an inferred window. Inventing a
  deadline inside an audit would manufacture offences no operator ever announced.

The three decisions that remain genuinely the operator's — whether to void or
accept the out-of-window ballots, whether to refund, and whether to crown the
backlog — are unchanged by this. What changes is that they can now be made
against numbers instead of guesses.

## 12. The paid-vote receipt — a state the fix for F13 created

Closing **F13** (`PaidVoteService::mint()` had no phase check) created a state that
did not exist before and which nothing rendered: an order that is **paid but not
minted**. `mint()` now refuses to push weighted votes into a closed cycle and
leaves `votes_used = 0` deliberately, as the queryable "refund owed" signal.

`/vote/paid/success` branched on whether the *donation* was confirmed, so that
buyer was shown the celebration copy — "your votes are already in the public
tally", confetti, "a receipt is on its way". Taking money, adding nothing, and
saying thank you is a worse failure than either F10 or F13 on its own, and it was
introduced by fixing F13 rather than found during the original audit.

The receipt now has three states, keyed on whether the **votes minted**, not
whether the money arrived:

| State | Condition | What the buyer is told |
| --- | --- | --- |
| Minted | confirmed, `votes_used > 0` | Counted, in the tally, receipt coming. Marks the per-device ballot tracker. |
| **Paid, not minted** | confirmed, `votes_used = 0` | Voting had closed; **nothing was added**; the order is refundable; here is your reference and a route to claim it. No confetti, no tracker write. |
| Unconfirmed | no confirmed row | The gateway has not landed yet — try again shortly. Explicitly *not* a refund case. |

The operator sees the same population in `bin/console cycles:audit` under "paid-vote
orders confirmed but never minted", tagged `refund` or `re-mint`, so both sides of
that conversation are looking at the same fact rather than the buyer discovering it
first.

This also completes **F9** (the dead ballot tracker). The OTP and points paths were
given the `localStorage` write; the paid path was not, so buying votes still left
`/vote` reading "0 of N programmes voted". The write now happens on the paid
receipt too — **gated on `minted`**, because recording a vote that was refused
would be the same untruth in a different place.

### 11.1 Running it without deploying — `database/audits/phase-audit.sql`

`bin/console cycles:audit` is the real tool. `database/audits/phase-audit.sql` is
the same questions as plain, portable SQL, for the case where you want the numbers
**before** deploying the branch that carries the command: it needs nothing but a
SQL client and a read-only connection, so it can be pointed at a replica today.

```sh
mysql -h <host> -u <read-only-user> -p <database> < database/audits/phase-audit.sql
```

Strictly read-only — every statement is a `SELECT`, and a test asserts that
(`PhaseAuditSqlParityTest::test_the_sql_file_writes_nothing`, which scans the
comment-stripped body for write verbs *and* re-counts every table afterwards).

**It deliberately does not decide a cycle's phase.** That logic lives in
`CyclePolicy::phaseFor()` and must have exactly one implementation — the point of
this whole restructure was that the phase stopped being computed in several places
that quietly disagreed, and re-deriving it in `CASE` expressions inside a file
nobody would remember to update would recreate that defect. So the SQL covers the
sections that are pure data questions; the stored-vs-computed drift table and the
`re-mint`/`refund`/`investigate` tagging are console-only, and the file says so.

Because a second implementation is a second thing to drift, **the two are pinned to
each other**: `PhaseAuditSqlParityTest` seeds one fixture and asserts they agree on
late/early ballot counts *and weight*, late nominations status-by-status, the exact
refund total in naira, the same paid-vote row ids, the same uncrowned category ids,
and the same undated cycles — plus that both report **nothing** on a clean database,
since a pair of tools that only match when there is damage would be worthless as an
all-clear.

Two portability traps the parity test also guards, both of which would have failed
silently on production rather than loudly:

- **`||`** is string concatenation in SQLite and boolean `OR` in MySQL, so a
  concatenated column returns `0` on MySQL instead of erroring. The "which boundary
  is missing" output is therefore two plain columns, not one joined string.
- **Backticks** are MySQL-only quoting and would break the SQLite parity run that
  is the only pre-flight this file gets.

## 13. `DATETIME` vs `TIMESTAMP` — answered, measured on MySQL 8.0

This was flagged as an open decision four times without evidence. With a real MySQL
8.0.46 instance (strict mode, `ONLY_FULL_GROUP_BY`) it is now measured, and it was
**a live bug in the audit itself**, not a stylistic question.

### The finding

The two sides of every comparison are stored in different types:

| Side | Columns | Type |
| --- | --- | --- |
| **Boundaries** | `gates_award_cycles.voting_open`, `voting_close`, `nominations_open`, `nominations_close`, `results_date`, `next_boundary_at`, `gates_cycle_transitions.boundary_at`/`observed_at` | **`DATETIME`** |
| **Events** | `gates_votes.voted_at`, `gates_nominations.created_at`, `gates_donations.created_at`/`refunded_at`, `gates_jobs.*`, `gates_ai_calls.created_at`, `gates_ai_decisions.created_at`, `gates_phase_drift.created_at` | **`TIMESTAMP`** |

A `DATETIME` has no timezone semantics — MySQL returns it exactly as written. A
`TIMESTAMP` is stored as UTC and **converted into the session timezone on read**.
So `voted_at >= voting_close` — which is *every finding in the audit* — silently
shifts by the session's UTC offset.

### The measurement

A cycle closing at `2023-06-01 12:00:00`, and one vote at `11:30:00` — thirty
minutes **before** the deadline:

| session `time_zone` | `voted_at` reads | `voting_close` reads | reported late? |
| --- | --- | --- | --- |
| `+00:00` | `11:30` | `12:00` | no — correct |
| **`+01:00` (WAT)** | `12:30` | `12:00` | **yes — wrong** |
| `-05:00` | `06:30` | `12:00` | no |

And on the full fixture, with the server default set to `+01:00`:

```
without session alignment:  late_votes = 4,  weight = 93
with    session alignment:  late_votes = 3,  weight = 92
```

**One legitimate ballot falsely condemned**, from configuration alone. WAT (UTC+1)
is the natural setting for a Nigerian deployment, so this is the likely default
rather than an exotic edge case — and it means every vote in the final hour of
every cycle would have been reported as late. Under a negative offset the error
inverts and genuinely late votes are **hidden**.

### What was changed

`PhaseAuditService::alignSession()` now runs **before any query** and sets the
session to PHP's current UTC offset. Aligning to *PHP* rather than forcing UTC is
deliberate: the `DATETIME` boundaries were written by PHP in whatever frame
`Clock::boot()` pinned (`APP_TIMEZONE`, UTC by default), so matching that frame
makes both sides comparable whichever the operator chose. Forcing UTC would be
correct only for the default and would silently break a deployment that set
`APP_TIMEZONE=Africa/Lagos`.

If the connection cannot `SET time_zone` — a locked-down replica — the report says
so and the command prints an **error**, because numbers quietly off by an hour are
worse than a refusal. The Clock section now reports `session_aligned`,
`session_offset` and the session it found (`session_was`), so a non-UTC server
config is surfaced rather than merely worked around. `phase-audit.sql` carries the
same `SET time_zone` as its first statement, flagged as its one MySQL-only line
(SQLite rejects `SET` outright — verified, not assumed).

### The decision this leaves you

The audit is now correct on either convention, so nothing is blocked. But the
underlying inconsistency is still real and still worth settling, because it affects
anything else that compares these columns in SQL:

- **Decided and implemented: leave the types alone, pin the session.** `TIMESTAMP`
  for events is *right* — an event happened at an absolute instant. `DATETIME` for
  declared boundaries is also right: "voting closes 1 June at noon" is a wall-clock
  statement an operator made, not an instant in UTC. The bug was never the types;
  it was comparing them in an unpinned session.

  `config/database.php` now carries `'timezone' => Clock::databaseTimezone()`, which
  the MySQL connector applies as `SET time_zone=…` on **every connect** — so web,
  console, cron and all 62 consumers of that config (including every standalone
  migration) land in the same frame without any of them having to remember. It
  derives from PHP's current offset rather than hard-coding UTC, so an operator who
  sets `APP_TIMEZONE=Africa/Lagos` gets `+01:00` on both sides instead of a silent
  one-hour shift. `DB_TIMEZONE` overrides it for a zone name (only if MySQL's
  timezone tables are loaded) or to match a database written in another frame.

  Verified against MySQL 8.0.46 with the **server global default set to `+01:00`**:
  the session came up `+00:00`, and the late-vote count stayed at the correct `3`
  rather than the WAT-inflated `4`. A numeric offset is used rather than a zone name
  because `SET time_zone = 'Africa/Lagos'` requires `mysql_tzinfo_to_sql` to have
  been run — usually absent on shared hosting, and the failure mode is every request
  erroring. The DST caveat is documented on `Clock::databaseTimezone()`: the offset
  is resolved once per process, which is moot for UTC and WAT (neither observes DST)
  and is a further reason to prefer UTC storage.

  `PhaseAuditService::alignSession()` remains as the audit's backstop — it covers a
  hand-assembled connection or a replica whose config was never updated, and it
  still *reports* `session_was`, which is how an operator discovers their server
  default is not UTC rather than having it corrected silently underneath them.
- The alternative — migrating six boundary columns to `TIMESTAMP` — would convert
  every existing value using whatever session the migration happened to run in,
  which is the same trap with permanent consequences.

### Also verified on real MySQL while there

Every migration on this branch had only ever run against SQLite. All 58 steps
applied cleanly to MySQL 8.0 and are idempotent on re-run, and the MySQL-only
halves are confirmed present:

- `gates_award_cycles.status` → `enum('upcoming','nominations','shortlisting','voting','judging','results','archived')` — the `MODIFY ENUM` worked, so `shortlisting` round-trips
- `uq_cyctrans_phase` **UNIQUE** on `(cycle_id, to_status)` — the exactly-once phase claim holds on MySQL, not just SQLite
- `uq_jobs_dedupe` UNIQUE on `gates_jobs.dedupe_key`; `idx_cycles_next_boundary` on `next_boundary_at`
- `gates_ai_calls`, `gates_ai_decisions`, `gates_phase_drift` all created

`cycles:audit` and `phase-audit.sql` both run under `ONLY_FULL_GROUP_BY` without
complaint and return **identical numbers** to the SQLite run — three
implementations (PHP/SQLite, PHP/MySQL, SQL/MySQL) agreeing on the same fixture.

## 14. Running the suite against the canonical schema

Until now the 721-test suite had **only ever run against in-memory SQLite**. That
is what makes it runnable anywhere with no server and no `.env`, and it should stay
the default — but SQLite is not the production database, and §13 is the proof that
testing only against it hides real defects. SQLite has no session timezone and
stores datetimes as text, so it could not possibly have caught the hour-shifted
deadline comparisons.

```sh
TEST_DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_NAME=africa_gates_test \
DB_USER=… DB_PASS=… vendor/bin/phpunit
```

The MySQL harness loads `schema.sql` + `admin-schema.sql` + `community-schema.sql`
**and then runs the dated migrations**, so the schema under test matches a migrated
production database rather than the base files alone. Current state, against MySQL
8.0.46 with strict mode and `ONLY_FULL_GROUP_BY`:

```
MySQL:   721 tests, 4899 assertions, 11 skipped, 0 failures   (~21s)
SQLite:  721 tests, 4921 assertions,  0 skipped, 0 failures   (~9s)
```

The 11 skips are `ShopPricingTest` and `PaymentReconcileTest`, which build their own
tables inline with SQLite DDL because `gates_products` is not in the base schema
files. They test pricing arithmetic and reconciliation branching — PHP logic, not
SQL semantics — so `skipOnMysql()` states the reason rather than leaving eleven red
tests that train everyone to ignore the result.

### Harness notes worth knowing before you run it

Three things were wrong on the first attempt, each of which produced a large,
misleading failure count:

1. **`tests/bootstrap.php` pinned `DB_DRIVER=sqlite` unconditionally.** Anything
   building its own connection from `config/database.php` — `MigrationRunner` and
   every standalone migration do — opened a SQLite file and replaced the global
   Capsule the harness had just configured. The run "passed against MySQL" while
   testing SQLite. `DB_DRIVER` now follows `TEST_DB_DRIVER`.
2. **Per-test isolation is a transaction rollback, not `TRUNCATE`.** Truncating ~70
   tables per test is ~50,000 `TRUNCATE`s, and InnoDB rebuilds a tablespace for each
   one — unusably slow. But a test issuing DDL causes an *implicit commit* in MySQL,
   which Laravel's `transactionLevel()` cannot see, so its rows survive the
   rollback and the next test dies on a duplicate primary key. The rollback is
   therefore followed by a canary `COUNT` on a table that should be empty; only if
   that finds rows does the expensive full cleanup run.
3. **Connections must be closed per test.** `setUp` builds a fresh Capsule, so
   without an explicit `disconnect()` the run hits MySQL's `max_connections` (151)
   partway through and every remaining test errors for a reason unrelated to what
   it was asserting.

### What the parity run found

- **`TIMESTAMP` caps at `2038-01-19 03:14:07`** and MySQL *rejects* anything beyond
  it, while SQLite stores the text happily. A cache fixture used `2999-01-01` to
  mean "comfortably unexpired". No production code writes a far-future date to any
  `TIMESTAMP` column — checked — so this was a fixture artefact, but the ceiling is
  real and worth knowing before someone reaches for a sentinel date.
- **Seeded settings differ between harnesses.** The MySQL harness runs migrations,
  one of which seeds `ai_enabled = 1`; the SQLite harness loads schema files only.
  A test that plain-`insert`ed that key collided on one driver and not the other.
  Fixed with `updateOrInsert` — the better habit regardless: assert the value you
  need, don't assume the row is absent.
- **Migrations using `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS`, which
  MySQL does not support.** ✅ **Fixed — see §15.**


## 15. The index catch-up migrations — fixed

51 index statements across 27 migration files used `CREATE INDEX IF NOT EXISTS` or
`DROP INDEX IF EXISTS`. That is SQLite and PostgreSQL syntax; **MySQL answers 1064**.
Every one was wrapped in `try/catch`, so it printed `! index skipped` and the
migration reported success.

**The real scope was 4 statements in 4 files, not 51 in 27** — measured by running
the migrations against MySQL 8.0.46 and collecting the warnings, rather than by
grepping. The other 47 sit inside `if ($sqlite)` branches or files that already have
a correct MySQL path, where the syntax is legitimate: they only execute on SQLite,
and MySQL gets the same index from `schema.sql`'s inline `KEY`.

| File | Statement | Consequence on MySQL |
| --- | --- | --- |
| `2026_06_14_add_vote_device_hash.php` | `idx_votes_device` | never created; comment claimed the syntax "works on both SQLite and MySQL 8" |
| `2026_06_14_vote_idempotency.php` | `idx_votes_idem` | never created |
| `2026_06_15_vote_weighting.php` | `idx_votes_donation` | never created — **and not declared in `schema.sql`, so missing on every MySQL install including fresh ones** |
| `2026_06_15_idempotency_unique.php` | `DROP INDEX IF EXISTS` + `CREATE UNIQUE INDEX IF NOT EXISTS` | **both halves broken, in different ways** |

That last row is the worst. `DROP INDEX name` is SQLite-only — MySQL requires
`DROP INDEX name ON table` — and both statements sat in one `try/catch`, so the
first failure skipped the second. The index was neither dropped nor recreated as
UNIQUE: it stayed non-unique, silently, behind a printed warning.

On a **fresh** database most of this was masked, because `schema.sql` declares the
same indexes inline. The damage is on an **old** database — the only reason a
catch-up migration exists. A deployment predating a given index ran the catch-up,
watched it fail into a warning, and is still missing that index.

### The fix

`src/Support/SchemaIndex.php` — `ensure()`, `drop()`, `makeUnique()`. Existence is
**checked against the catalogue** (`information_schema.statistics` on MySQL,
`sqlite_master` on SQLite) rather than inferred from a caught exception, so "already
present" and "failed for a real reason" stay distinguishable. That distinction is
the whole lesson: a `try/catch` around DDL is exactly what let this hide.

- `drop()` takes the table as a **required** parameter, so the MySQL-invalid form
  cannot be written by accident.
- A pre-existing index with the same name but different columns is left alone and
  reported, not silently rebuilt — that would be a destructive guess about someone
  else's intent, and on a large table an unannounced rebuild.
- A UNIQUE index over data that already violates it still reports `!`, because that
  is the one case needing a human: duplicates must be resolved first.
- Identifiers are validated against `^[A-Za-z_][A-Za-z0-9_]*$` and rejected rather
  than escaped.

### Verified

The scenario that was actually broken — a database predating the indexes:

```
simulated old MySQL DB (idx_votes_device and uq_votes_idem dropped)
  before:  fk_vote_cat idx_nominee idx_voted_at PRIMARY uq_one_vote
  after:   … idx_votes_device idx_votes_donation … uq_votes_idem
  warnings: 0
```

Both restored, plus `idx_votes_donation` created for the first time on MySQL.
Re-running the four migrations reports `= already present` with zero warnings, and a
fresh SQLite migrate is unchanged at zero warnings. `SchemaIndexTest` covers the
helper and adds a regression test asserting **no unguarded migration uses the
MySQL-invalid syntax again** — scanning comment-stripped code, since the fixed files
now describe the trap in prose.

### One thing this cost, worth recording

`SchemaIndexTest` creates indexes, and **DDL is not transactional in MySQL**, so the
harness's rollback could not undo them. A leaked `idx_test_uniq` (UNIQUE on
`idempotency_key` alone) then broke nine `VoteServiceTest` assertions in an unrelated
file. Two fixes: the test drops what it creates, and the harness's leak canary now
counts rows across five tables rather than one — the original single-table check
missed vote rows committed by that same implicit commit.


### 15.1 The fix that would not have reached production

Correcting those four migration files fixes **fresh installs only.** `MigrationRunner`
records applied files in `gates_migrations` and never re-runs one, and on every
existing deployment all four are already recorded — they *completed*, having printed
a warning instead of throwing. So the corrected files would never execute there.

The convention is forward-only, so the repair arrives as a new ledger entry:
`2026_07_28_vote_index_repair.php`. Verified against a production-like database with
the four already ledgered and the indexes stripped — all three restored, zero
warnings.

`idx_votes_donation` was also added to **both** base schema files, since it was
declared in neither and is read by every paid-vote clawback.

**But a migration runs exactly once, and that is wrong for the UNIQUE one.** The
per-voter idempotency constraint legitimately cannot be created while duplicate rows
exist. Left as a migration alone the sequence would be: deploy → "1 duplicate group,
resolve it" → operator resolves it → **the constraint is never created, because the
migration is marked done.** That is the same silent gap this whole repair exists to
close.

So the logic is a service with two doors, one implementation:

```sh
bin/console db:repair-indexes      # idempotent, re-runnable, exits non-zero if still missing
```

Verified end to end: blocked run reports the count, the consequence ("a retried vote
can be counted twice"), the query to find the duplicates and the command to retry,
and exits `1` — while still creating the two plain indexes, because a half-repaired
schema beats aborting over an unrelated data problem. After the duplicates are
resolved, re-running creates the constraint and exits `0`.

One thing it deliberately does **not** do: drop a redundant leftover `idx_votes_idem`
(over `idempotency_key` alone, from the pre-per-voter design). It is redundant once
`uq_votes_idem` exists and costs write throughput, but dropping an index this code did
not create would be a destructive guess about someone else's intent — so it prints the
`DROP` for a human to run.


### 15.2 Making it impossible to miss

Every defect in this repair shared one shape: **something failed, printed a warning,
and nobody ever read it.** `CREATE INDEX IF NOT EXISTS` returned a 1064, the
try/catch turned it into a line of deploy output, and the index was missing for
months. A fix that only reports at deploy time repeats exactly that mistake — the
operator who most needs to know is the one who was not watching the deploy log.

So `VoteIndexRepair::warnings()` is a **read-only** check (no DDL, safe on a request
path) surfaced in two places operators actually look:

- **Admin operational state** — alongside `phase_divergences`, with a note in the
  assistant's briefing that a `critical` schema warning means *a guarantee the
  platform advertises is currently absent* and outranks any queue length.
- **The maintenance run**, every 15 minutes, logged to `gates_cron_log` in the same
  `!` form as the phase divergences:

```
! SCHEMA CRITICAL: gates_votes has no per-voter idempotency constraint,
  so a retried vote can be counted twice.  fix: bin/console db:repair-indexes
```

Severity is split deliberately. A missing `idx_votes_donation` makes clawbacks slow;
a missing uniqueness constraint means votes can double-count. Reporting both as
"warning" would lose the one that matters. And the message states the **consequence
in the operator's terms** rather than the index name — "a retried vote can be counted
twice" is actionable to someone who has never heard of `uq_votes_idem`. When
duplicates are what is blocking the fix it says so, so the operator knows the job
needs data work before a command.

A test asserts the check never writes: reporting a missing index must not quietly
create one, since it now runs on an admin page load.
