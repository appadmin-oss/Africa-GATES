# Africa GATES — Structure & Flaw Audit

> **Date:** 2026-09-16 · **Scope:** analysis only, no behaviour changed.
> **Measured against** the working tree at `8c2115b` on
> `claude/ai-assistance-judges-features-1ka4oz`.
> **Companion docs:** [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md) (the map),
> [`CODEBASE-AUDIT-2026-08-27.md`](CODEBASE-AUDIT-2026-08-27.md) (the previous pass).
> Nothing is carried over from that pass; every figure below was re-measured.

---

## 1. Summary

The suite is green on a clean install with no `.env`: **6,535 tests, 45,415 assertions,
0 failures, 2m38s**. `src/` is 470 files and 170,517 lines; 231 templates; 522 test files.
Every finding below therefore passed every sweep this repository already runs — which is the
only kind of finding an audit is for.

**Six faults are live. All six are one shape, and it is the shape `CODEBASE-INDEX.md` §17,
§19 and §20 already name:** something is written, computed or declared, and the reader
either does not exist or is asking a question the data cannot answer. Two of them put a
**structurally impossible number on an operator's screen**; one puts **retired arithmetic in
the handbook**; one leaves a **donor's monthly gift with no operator surface at all**.

| # | Finding | Severity |
|---|---|---|
| 3.1 | The funnel's "claimed" stage filters on a status `gates_nominee_claims` cannot hold — always 0 | **High** |
| 3.2 | The handbook states one basis, one scope and one judge scale as fact, and types the worked example | **High** |
| 3.3 | Recurring gifts have no operator surface; `activeFor()` has no caller and `failed` has no reader | **High** |
| 3.4 | The analytics moderation warning counts a comment status nothing writes — can never fire | Medium |
| 3.5 | ~150 template variables passed and never read, four of them paying for queries per view | Medium |
| 3.6 | Three smaller vestiges: `forTarget()`, `logo_path`, an unescaped `_` in a `DELETE … LIKE` | Low |

Each finding below was reproduced with a throwaway test before it was written down, per
`CLAUDE.md`'s rule that a sweep is only evidence once it has named the break.

---

## 2. What came back clean

Worth recording, so the next pass does not re-derive them:

- **`where($col, $arrayValue)`** — swept `src/` for the two-argument form with an array
  value. Nine candidates, all `$status` scalars. `MergeJournal::applyScope()` is still the
  one clause.
- **Settings keys with no reader** — all **133** form fields in `admin/settings.twig`
  resolve to a reader outside the template. No control on that screen does nothing.
- **Columns never mentioned in code** — three, of which two (`revoked_reason`,
  `verified_by`) are the documented vestiges in §19. The third is 3.6b below.
- **Public methods with no caller** — 2 of 2,739 (3.3 and 3.6a). §20's sweep has held.
- **Out-of-`ENUM` values, table-aware** — 62 `ENUM` columns swept against every literal
  compared or assigned to them in `src/`, `config/`, `cron/` and `bin/`. Two real hits
  (3.1, 3.4), one harmless dead alternative (`QuestionnaireInvites::pending()` includes
  `gates_jobs.status = 'running'`, which nothing writes; the `whereIn` still matches
  `pending`, so the count is right).

---

## 3. The findings

### 3.1 The nomination funnel's last stage counts a status the column has never allowed

`src/Admin/Services/AnalyticsService.php:398`

```php
$claimed = (int) DB::table('gates_nominee_claims')->where('status', 'approved')->count();
```

`gates_nominee_claims.status` is
`ENUM('pending','active','held','rejected','revoked')`
(`database/migrations/2026_08_12_nominee_claims.php:78`). There is no `approved`. A
successful claim is **`active`** — that is what `ClaimGuard::controlledBy()` (line 199) and
`ClaimController` line 298 both read, and `ClaimDispute` writes `held` beside it.

So `$claimed` is **zero on every deployment, for ever**. And it is not drawn as absent: the
code deliberately distinguishes `null` ("this deployment has not run the migration", stage
dropped) from `0` ("the real count"), so the stage renders as

> **Profile claimed by the nominee — 0 — 0%** · *The nominee proved who they are and took the page.*

at the foot of the funnel an operator reads to decide whether claiming works at all. On a
platform where nominees are claiming, that is a screen reporting the opposite of the truth,
in a sentence that sounds like a finding about people.

This is `JudgeSchedule`'s `'scheduled'` exactly (see `CLAUDE.md`): a status filter outside
its own `ENUM` is `0 rows` and not an error, on both drivers, so nothing anywhere reports it.

**Proof.** Insert one claim at `status = 'active'`; `count()` is 1 and
`where('status','approved')->count()` is 0.

**Fix.** `where('status', 'active')` — and the counted set is a product question worth
settling in the same edit, because `held` is a live claim under dispute and arguably belongs
in the funnel too. Whatever is chosen, it belongs in **one** resolver next to
`ClaimGuard`'s, not as a third literal.

---

### 3.2 The handbook states one basis, one scope and one judge scale as fact — and types the worked example

`templates/admin/handbook.twig:195–217` · `src/Admin/Controllers/HandbookController.php:65`

`HandbookController`'s docblock is four paragraphs on why this page's structural facts are
read from the code, naming the three times prose outlived its rule here — including the
settings screen that explained the default basis with a different basis's arithmetic, 157
points out. §5 of the page it renders then does both halves of that fault.

**a. The prose is unconditional.** `RuleEngine::effective()` is passed in whole, so
`rules.community_basis`, `rules.community_scope` and `rules.judge_scale` are all in scope.
The template names none of them. It states:

- *"Both are measured against the largest vote total anybody reached in the whole edition —
  not in their own category."* False under `community_scope = category`, and false under
  `community_basis = reach`, where the people term has its own denominator.
- *"The panel's average mark out of ten, straight. **No floor, no curve.**"* False under
  `judge_scale = curved`, which `RuleEngine` still supports and `CommunityBasisTest` still
  pins, because an announced standing has to stay reproducible.

`basis_ideal` **is** passed by the controller (line 65) and appears nowhere in the template
— a declared field with no reader, in the class written to prevent exactly this. It is the
tell: somebody intended the branch and it was never written.

Every other screen that explains a community half *does* branch.
`templates/admin/result-release.twig:180` gates its paragraph on
`categories[0].community_basis == 'ideal'` and prints `basis_from` when a cycle carries an
override; `templates/pages/results/show.twig:532/568` branches `ideal` against `reach`. The
handbook — the one document people are *told* to trust, and the only one a role without
Money access can read — is the single screen that does not.

**b. The worked example is typed.**

```twig
<b>Why a leader can score well under 450.</b>
… one backed by a thousand people scores 293, one backed by two scores 135.
```

Four lines above it, the weight **is** computed:
`{{ (rules.community_weight * 1000)|round }} points`. So a programme on
`community_weight = 0.30` renders **"Community vote — 300 points"** and then, immediately
below, **"Why a leader can score well under 450 … scores 293 … scores 135"** — the previous
ladder, on the same screen, four lines apart.

**c. Its guard passes over it.** `HandbookTest::test_the_numbers_are_read_from_the_rules_and_not_typed_in`
already asserts the stale-figure rule:

```php
$this->assertStringNotContainsString('450 points', $moved,
    'a stale figure beside a live one is worse than no figure');
```

The heading is `450.`, not `450 points`; 293 and 135 carry no unit at all. The assertion is
the right rule pinned to the wrong token, so the test enforces the guard and steps over the
breach — `SchemaIndexTest` excusing all three 1064s, and `SecurityHeadersTest` pinning
`camera=()` while the scanner was dead.

**Proof.** Rendered at `community_weight = 0.30`: the page contains `300 points`, `under 450`,
`scores 293` and `scores 135` together. Rendered at
`community_basis = relative, judge_scale = curved`: it still contains `No floor, no curve`
and `in the whole edition`.

**Fix.** Branch §5 on `rules.community_basis` / `community_scope` / `judge_scale` the way
`result-release.twig` does, and derive the example's three figures from
`rules.community_weight` rather than typing them — or drop the example under any non-default
basis, because a wrong worked example is worse than none: it is what somebody checks their
understanding against. Then re-point the guard at the **figures** (`450`, `293`, `135`) and
not at `450 points`, and watch it fail before trusting it.

---

### 3.3 A monthly gift has no operator surface, and its failure state has no reader

`src/Services/RecurringGiving.php:351`

```php
/** Everything still billing for one donor. For the admin view and for tests. */
public static function activeFor(string $email): array
```

There is **no admin view and no test**. Both halves of that sentence are false, which is
§20's distinguishing question — *what does this method's own docblock claim about the
running system?* — answered wrong in one line.

It is not an isolated dead method. `gates_donation_subscriptions` is read by **exactly one
file**, `RecurringGiving` itself. The whole of `src/Admin/` and `templates/admin/` contain
the word "subscription" once, in an unrelated webhook allow-list. So on a host with no SSH,
nobody can answer:

- who is giving monthly, and how much;
- whether a given donor's gift is still active (the commonest support question a recurring
  programme gets);
- whether this month's charges collected.

And the last of those is the sharp one. `collectionFailed()` (line 336) moves a subscription
to **`ST_FAILED`** when Paystack sends `invoice.payment_failed`. Nothing reads `failed`:
`activeFor()` explicitly excludes it, no screen lists it, and `PaymentController:499` sends
no mail. So a donor whose card expires stops giving, is never told, and appears on no screen
— the terminal state of a money flow with no reader at all. That is the same failure
`manageUrl()` had (§18: the stop button with no caller), one state further along, and the
same doctrine applies: *who is ever handed this?*

**Fix.** Either give `/admin/finance` a recurring panel that reads `activeFor()` and lists
`failed` separately, or delete `activeFor()` and correct the docblock. The two must ship
together — a list of active gifts that silently omits the failed ones would be worse than no
list, because it reads as "everyone is still giving". A dunning mail on `collectionFailed()`
is the other half and is a product decision, not an audit finding.

---

### 3.4 The analytics moderation warning can never fire

`src/Admin/Services/AnalyticsService.php:923` · `templates/admin/analytics.twig:699`

```php
$pending = … DB::table('gates_comments')->where('status', 'pending')->count();
```

`gates_comments.status` is `ENUM('approved','deleted','quarantined','rejected')`.
`CommunityService::postComment()` writes `approved` or `quarantined` and nothing else. The
template then guards on it:

```twig
{% if community.pending_moderation > 0 %}
  <div class="an-warn">{{ community.pending_moderation }} comment(s) waiting on moderation.</div>
```

so the warning is **unreachable**. Meanwhile `ModerationController::index()` builds the real
queue from `where('status', 'quarantined')` and counts it correctly. Two readers of one
fact, disagreeing structurally — the analytics screen says the backlog is empty while the
moderation screen shows it.

**Proof.** One comment at `status = 'quarantined'`: the queue's filter returns 1, the
analytics filter returns 0.

**Fix.** `where('status', 'quarantined')`. Better: have both read one resolver, since
`CLAUDE.md`'s rule is one resolver per question and this is the second reader that drifted.

---

### 3.5 Roughly 150 template variables are passed and never read

Swept every `render($res, '*.twig', [...])` in `src/` and `config/` for top-level keys that
appear nowhere in the target template or anything it extends, includes, embeds or imports.
**46 distinct keys across 150 render sites.** Most are harmless weight; four are not.

| Key | Sites | Note |
|---|---:|---|
| `has_hero` | 78 | Appears in **93** places in `src/` and **zero** templates |
| `current_section` | 26 | 29 in `src/`, zero in templates — the public nav switched to `_p` and nobody swept back |
| `basis_ideal` | 1 | §3.2 — the tell for a branch that was never written |
| 43 others | 1–2 each | `ship_rates` on both shop pages, `max_naira` on `/giving`, `audiences` on the invites screen, … |

**The four that cost something:**

- `LegacyController::event()` (`src/Controllers/LegacyController.php:22–23`) calls
  `listComments('legacy', …)` and `cheerCount('legacy', …)` on **every legacy event page
  view**. Neither is cached — the event row is, the comments are not — and
  `pages/legacy/event.twig` contains neither word. Two uncached queries per view for data
  nobody sees. `CommunityService::postComment()` accepts `legacy` as a target type, so a
  comment on a legacy event can be stored and will never be displayed by anything.
- `HomeController::index()` passes `ticker_profiles`, `legacy_events`, `active_opps` and
  `latest_posts` — four `cache->remember()` datasets, unread by `pages/home.twig` since the
  globe-band redesign. Cached, so the cost is a cache read and a deserialise per view on the
  busiest page rather than four queries, plus four queries per TTL expiry.

**Fix.** Delete the dead keys; delete the two `LegacyController` calls or render them. The
cheap durable guard is a sweep of this shape in `tests/Unit/` — it is thirty lines, it found
`basis_ideal`, and the Twig harness cannot find it on its own: `strict_variables` fails on
*reading* an undefined variable, never on *passing* an unused one.

---

### 3.6 Three smaller vestiges

**a. `AuditService::forTarget()`** (`src/Admin/Services/AuditService.php:150`) has no caller
anywhere. Its docblock explains that it queries every alias of a target type so an event's
history under `site_event` and under `event` come back together. That reasoning is sound and
already lives in `filtered()` (line 321), which `AuditController::target()` reaches through
`search()`. So this is duplicate code rather than a broken promise — but it is a second
definition of "one record's history", which is how the two come to disagree. Delete it.

**b. `gates_programme_sponsors.logo_path`** is declared in both driver branches of
`2027_01_21_programme_sponsors.php` and appears nowhere else in the tree:
`ProgrammeSponsor::save()` does not write it, nothing selects it, no template renders it.
A genuine vestige — a sponsor logo for a surface that was never built. It makes no false
statement, so it belongs in §19's vestige table rather than in a fix. (Its neighbour
`amount_naira` is fine: the migration says it is "for the finance screen" and it is in fact
drawn on `admin/programmes/sponsors.twig:114`. Worth correcting the migration's wording,
which names a screen that does not show it.)

**c. `ProviderBreaker::clearAll()`** (`src/Support/ProviderBreaker.php:143`):

```php
DB::table('gates_cache')->where('cache_key', 'LIKE', 'ai_breaker:%')->delete();
```

The `_` in `ai_breaker` is an unescaped single-character wildcard, so the pattern is
`ai?breaker:%`. Nothing in the tree writes a key that collides, so this over-matches
harmlessly today — but it is the documented `LIKE` trap inside a `DELETE`, which
`CLAUDE.md` calls the shape that costs most, and `Support\Like` exists precisely for it and
has only two callers. Use `Like::clause()`/`Like::esc()`.

---

## 4. Method

Sweeps were written for this pass and kept deliberately crude, because the expensive ones in
this repository have all been the clever ones that went quiet in the wrong place:

1. **No-caller sweep** — every `public function` in `src/` (2,739), against a token index of
   every `.php`, `.twig`, `.js`, `.json`, `.md`, `.gs`, `.html`, `.css`, `.sql` and `.py`
   file in the tree. A word-boundary token index rather than a call-shape regex, because
   handlers are registered as `Class::class.':method'` and a `->name(` sweep reports every
   controller action as dead (it did: 135 false findings on the first run).
2. **ENUM sweep** — `ENUM(...)` and SQLite `CHECK(... IN (...))` parsed per table from
   `database/`, then every literal compared or assigned to a column of *that table*. Keyed
   by table and not by bare column name: `status`, `kind`, `purpose`, `source` and `method`
   each exist on six or more tables, and a name-keyed sweep reports 60 findings of which
   two are real — the same trap that had `OneResolverPerSettingTest` inventing readers.
3. **Template-variable sweep** — top-level array keys only, by bracket-depth rather than
   regex: a nested `'schema' => ['mainEntity' => …]` otherwise reports `mainEntity` as an
   unused page variable.
4. Every finding reproduced in a throwaway `tests/Unit/` case, run, and the case deleted.
   The four assertions above all passed against the tree at `8c2115b`.

## 5. Not found

- No remotely exploitable defect. CSP, CSRF, the `ESCAPE` clauses in `AuditService` and
  `ActivityFeedService`, the sponsor-URL parser and the video-provider allow-list all hold
  under re-check.
- No `CREATE INDEX IF NOT EXISTS` outside a SQLite branch, no nested `<form>`, no
  `{% set %}` inside a `{% block %}`, no inline handler without a nonce. Those four sweeps
  live in the suite and are doing their job.
- **No MySQL parity run was possible** in this container — no MySQL and no MariaDB. Every
  ENUM finding above is therefore a *reading of the schema*, not an observed truncation, and
  the integer-width and strict-mode traps at the top of `CLAUDE.md` are invisible to this
  pass by construction. 3.1 and 3.4 are `0 rows` on both drivers, so they do not depend on
  it; anything about a *write* outside an `ENUM` would.
