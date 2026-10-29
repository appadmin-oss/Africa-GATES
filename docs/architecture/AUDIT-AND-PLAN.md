# Africa GATES: architecture audit and restructuring plan

*Draft for the owner's review, 16 Oct 2026; Africa GATES Events added 17 Oct 2026 and restructured 18 Oct 2026 (§9). Each phase waits for your approval.*

You asked for an audit of how the app is built, research, and a plan that covers every part
of the platform. That includes:

- awards whose nomination forms, rules and look are their own;
- volunteers with scoped access, tasks and verified results;
- events, now a new product of its own: Africa GATES Events (§9);
- a market where people buy, sell, highlight their own products and businesses, and promote
  them;
- an experience structure tailored to each person;
- an assistant that acts.

Every process is held to your five tests: repeatability, visibility, accountability,
verification and replication. The numbers come from counting the code itself.

---

## 1. The short version

**What is wrong.** Africa GATES works, but it is built as hundreds of separate features, not
as one system. Most features were added end to end in one go: a route, a controller, a
service, a table, a template and a test, made to work and then left. Nothing gathers the
pieces into areas such as Awards, Money or Events. And nothing models the work people do to
run the platform. So:

- **The same job is done in many places, slightly differently each time.** There are 5
  sign-in systems, at least 5 rate limiters, 3 mail transports, 28 hand-built HTTP clients
  and 14 private copies of "read a setting". Each copy is a place for a bug to live.
- **Most processes fail your five tests.** Work goes to shared pools with no owner and no
  deadline. Status lives in people's heads. Nothing is scoped below "the whole platform",
  so a second centre would mean doubling the administration. Verification is strong only
  where the platform records facts itself (door scans, scorecards, sealed results).
- **There is no place yet** for volunteers, for people buying and selling, for
  highlights people post themselves, or for promotions. Today's shop, vendors, stands and
  banners are four separate models with separate payments.
- **Every award runs on the same hard-coded nomination form and rules.**
- **The experience is a list of pages, not a structure.** There are four navigation
  sources and two design systems, and a person with three roles has three homes.
- **15 pages return a server error.** (Gee's habit of promising checks it never made was
  fixed on 16 Oct.)

**What I propose.** Keep one app and one deploy on the same host, and organise it in three
layers:

1. **A platform kernel**: one way to do each shared job (settings, mail, HTTP, rate
   limits, errors, who is signed in).
2. **An operating model that makes your five tests true by construction**:
   - a process registry, so every process is declared with its steps;
   - work items, each with an owner and a deadline;
   - graded evidence, so reported activity is never counted as a demonstrated result;
   - scopes (platform → country → state → centre → event), so a centre is a row and a
     lead rather than a new set of staff;
   - templates, so a process or an event can be cloned.
3. **Domains built on it**, each owning its data:
   - Awards, with configurable award profiles and dynamic forms;
   - Volunteers;
   - **Africa GATES Events**, a new product for events that honour people first: it runs the
    whole award behind a ceremony (nominations, voting, judging, mobilising nominees, the
    result released on stage) as well as selling and admitting the night. Ticketing and the
    door are free to anybody who hosts; the award bundle is the paid offer; each opening
    waits for a pilot. Sized to this host (§9);
   - one Market for selling, highlights and promotions;
   - Money, on one payment ledger;
   - the rest of today's site.

On top sits **one experience structure**. It has five spaces (Public, a single home per
person, a scoped Workspace for staff and volunteers, Field tools, and the Judge room), one
navigation registry, and one design system in two densities. Every surface is tailored by
who, where, interests and phase.

Each choice is checked against how YouTube, Meta, Google and OpenAI structure their products,
experience and operations (§13). We take the ideas that survive on one app and shared
hosting, and leave out the ones that only make sense at their size. Gee's research is in §12.

Everything moves one piece at a time, behind the live site, and every step ships on its own.

**What I need from you** is in §16: thirty-five decisions, eight of them now settled. The ones that shape everything are:

- what a **centre** is for Africa GATES (D11);
- who may **sell** (D13);
- whether nominees may be **promoted** (D15);
- whether existing awards keep today's form as version 1 (D1);
- the price of the award bundle and the fee on tickets (D30, D31).

## 2. Broken right now: fix before any restructuring (Phase 0)

These are faults, not style. They are independent of the restructure and should be fixed
first. I verified each one in the code.

| # | Fault | Evidence | Effect |
|---|---|---|---|
| 1 | **15 page templates are missing.** Their routes still render them. They were removed in the 3 Oct redesign (`docs/handoff/DESTROYED.md`) and wait for their rebuild. *Corrected 18 Oct:* `my-work`, `my-work/interview` and `donate-giving` have since been rebuilt; three were missing from this list. | `pages/{nominee-confirm, nominee-confirm-done, shop/order, honour, interview, nominee-claim, claim-dispute, form, gated-form, registry/register, stands/apply, stands/call, stands/offer, shop/alert-stopped, pulse/_cards}.twig` | 500 on each. **The first three matter most:** `/n/confirm/{token}` is where a nominee confirms their nomination, so consent cannot be recorded; `/shop/order/{ref}` is the buyer's order page their receipt links to. Rebuild those first. |
| 2 | Shop stylesheet and script are missing | `shop/index.twig:67`, `shop/item.twig:49`, `:244`; no `components/shop.css` or `js/shop.js` | The shop renders unstyled, and its buttons do nothing. |
| 3 | Admin reads a table that does not exist | `QuestionnairesController.php:162` reads `gates_questionnaire_submissions`; the real table is `gates_nominee_submissions` | That action always says "That submission no longer exists". |
| 4 | Payment reference has no index | `gates_donations.payment_ref`: 28 lookups, including the webhook and verify paths; no key | Every gateway callback scans the donations table, and it gets slower as giving grows. |
| 5 | Money writes with no transaction | RefundService (6 writes), PaymentReconciler (7), ShopOrderService (11), PaymentController (4), StandFee (4): 0 transactions in each | A failure part-way leaves money half-recorded. |
| 6 | A settings table that is written and never read | `gates_admin_settings` (`SettingsController.php:803-817`): three donation-vote keys | An operator changes those settings and nothing happens. |
| 7 | The Pulse "load more" pieces don't line up | `social.js:188` sends `part=items`; the controller checks `fragment=1` and renders the missing `pulse/_cards` | "Load more" fails. |
| 8 | Stand fees have no backstop | `StandFee::confirm` is called only from `StandOfferController:302`; the webhook, reconciler, GatewayLedger and RefundService never reference stands | A paid stand whose return trip fails stays unpaid, and finance never shows it. |
| 9 | Developer documents state the retired paid-vote rule | `PaidVoteService.php:20-22` and `CODEBASE-INDEX.md:261` say "never move rank" | Whoever changes scoring next reads the wrong rule. |
| 10 | **Fixed on 16 Oct:** Gee promised a check and never made it | The loop accepted any reply that was not a tool call as final, and the planner never looked up a signed-in member's own payments | "I will check your payments, hold on", three times, then a ticket. See §12. |
| 11 | **Fixed in the previous commit:** my 16 Oct change replaced `Support\Brand`'s logo helpers | `OtpService:849`, `ChallengeFlier:217` and `InviteLetter:116` call `Brand::logoUrl`/`LOGO_ON_TINT` | The email masthead, challenge flier and invite letter broke. The helpers are restored, alongside the name rule. |
| 12 | **Fixed 17 Oct:** a released award showed as "With the jury" | `CyclePolicy::phaseFor()` trusted only `archived` from the stored status, so an award released before its results date (or with none) still read as being judged | Every page drawing the phase, beside the published result. |
| 13 | **Fixed 17 Oct:** referral payouts promised an email when paid and never sent one | `ReferralPayout::request()`'s message; `markPaid()` sent nothing | Members were told to expect an email that never came. |
| 14 | **Fixed 18 Oct:** saved award dates out of order were never flagged | The editor refuses an inverted window, but rows saved before that rule (two seen locally, with results dated before voting closes) are shown as normal | A results date before voting close makes the site treat voting as closed. The edition screen now says so, using the save's own rule. |
| 15 | **Fixed 18 Oct:** "select the secret" fields did nothing | `data-ag-do="select-all"` on the webhook secret and the interview link had no handler | One tap was meant to select the value for copying. |
| 16 | **Open, found 18 Oct: nothing backs up the database.** | No backup or restore code anywhere in `src/`, `cron/` or `deploy/`, and no procedure in `docs/`; whatever the host keeps is unknown | The payment records, sealed results, audit log and wallet ledgers exist in one copy on one machine. See §5.5. |
| 17 | **Open, found 18 Oct: retention and erasure have never run.** | `PrivacyPurgeCommand` (retention) and `PrivacyEraseUserCommand` (a person's erasure request) are console commands; the host has no shell and nothing in `Maintenance` or the console calls them | Personal data past its retention window is kept indefinitely, and an erasure request cannot be carried out from any screen, both duties under the NDPA. Needs your retention windows first (D33). |

**The test suite is not green today.** The full run (7,085 tests) has 12 failures that
predate this audit. Each one is a real finding, not noise:

- Four are known backlogs (missing shop assets, colour budget, colour without a word,
  form error state).
- The receipt pages' "never celebrated" check matches the `ag-cel-` storage key the
  cookie notice now lists on every page. That is a guard defeated by a substring, not a
  celebration.
- The judges page shows a judge from another programme under a programme filter.
- Three aliases redirect to a route that does not exist.
- `social.js` still links the retired `/activity` page.
- A support near-miss-domain check fails.

A restructure on a red suite cannot tell its own breakage from what was already broken, so
Phase 0 ends green.

**Sweeps run on 18 Oct, each first shown to catch a planted fault:** every internal link,
form action and script call against the live route table (none dead); every POST form for
its CSRF token (all carry one or are deliberately exempt); every static call, constant and
`new` against the classes that exist (none broken); every template compiled with the app's
Twig setup (all compile); every `data-ag-do` action against a handler (#15). The template and
action checks are now tests (`TemplatesExistTest`), with the missing pages in #1 as a list
that may only shrink.

Phase 0 also includes **one sweep that would have caught #1**: boot the router, render
every public GET route, and fail on any template that does not exist. This repo already has
a sweep that boots the router (`PublicSurfaceSandboxTest`), so this is one more question
asked of the same walk.

---

## 3. The audit

### 3.1 Backend (`src/`, 206k lines, 36% of them comments)

- **No domain boundaries.** `Services/` is one flat folder of **294 files**. Only Mail,
  Newsletter and Ops have subfolders. Middleware lives in three places.
- **Layers are mixed in both directions.**
  - 63 of 116 controllers query the database directly; Admin/EventsController alone has 32
    queries.
  - 7 services build their own Twig environment to render HTML.
  - 19 "Support" classes query the database. `Support/Maintenance.php` is a 1,364-line cron
    orchestrator.
  - A service imports a controller (`ShopPricing` → `Admin\Controllers\ProductsController`).
- **`routes.php` is 4,777 lines with 768 routes.** Lines 89–1,800 are a token-gated
  setup tool written as inline closures, about 1,700 lines of HTML strings. One closure
  resets superadmin passwords with a different hashing constant from the console command
  that does the same job. The API routes are mounted twice (`/api/v1` and `/api`).
- **God classes.** Twenty-five files are over 1,100 lines:
  - SupportContext (2,274 lines);
  - HelpCentre (1,861), whose help articles are written as PHP strings;
  - AiService (1,827) and PaymentService (1,823);
  - AccountController (1,723), with 96 `$_SESSION` reads;
  - SettingsController (1,696), whose `save()` is one 700-line method;
  - JudgeService (1,265), which holds the entire judging domain in one class.
- **Dependencies are hidden.**
  - About 5,400 static calls and 81 `::boot()` service locators.
  - About 1,266 `$_SESSION` reads outside middleware.
  - `(int) ($_SESSION['admin_id'] ?? 0)` is typed out **177 times**.
  - The container is 976 lines, and 635 of them are one Twig factory.
- **Copies of the same job:**

  | Job | Copies |
  |---|---|
  | Sign-in | 5 systems (admin, member, organisation, judge, vendor), each with its own session keys |
  | Mail transport | 3 |
  | Rate limiters | at least 5 |
  | HTTP clients | 28 files with their own `curl_init` or Guzzle client |
  | Client IP | 31 direct `REMOTE_ADDR` reads and 4 hand-written `X-Forwarded-For` parsers, despite `Support/ClientIp` |
  | AI calls | at least 3 outside `AiGateway`, which describes itself as "the single door to every model call" |
  | Settings | 72 files read `gates_settings` directly, despite `SettingsService` |

- **Errors are swallowed.**
  - 1,754 of 1,776 catches catch `\Throwable`.
  - 1,262 catch it without a variable, so nothing can be logged.
  - About 800 have empty bodies.
  - CLAUDE.md describes four production outages that a catch like this hid for weeks.
- **Sandbox code sits in production namespaces.** There are 48 references to `DemoSeeder::` in
  25 live files. Containment is correct, but every reader has to remember it.

### 3.2 Data (171 tables)

- **Each schema is defined in two places.** There are 6 schema files: 3 for MySQL and 3
  SQLite twins. 57 of the base tables are also created by a migration, and 105 columns exist
  only in migrations. The twins disagree on constraints: 52 ENUMs in MySQL against 39 CHECKs
  in SQLite. CLAUDE.md records how much that divergence has already cost.
- **Migration dates are labels, not times.** 63 migrations are dated after today, and 23
  dates are shared by several files, so those run in alphabetical order.
  `MigrateCommand::runFileMigrations()` is dead code.
- **No typed settings registry.** At least 68 literal keys, 131 direct reads, 20 private
  helpers and a second settings table that nothing reads.
- **The domain model overlaps itself:**
  - A person is spread across six tables: nominees (one row per *category*), profiles,
    users, nominee_claims, judges/admins and org_users.
  - A nomination's categories are stored in two places.
  - A cycle's phase has two sources (the status and the dates) and a table that logs where
    they disagree.
  - Results are stored in three places.
  - "Events" names four different things: the analytics log, the calendar, the archive and
    funnel events.
  - Money is spread across seven stores.
- **Integrity:**
  - 34 foreign keys against 217 `*_id` columns.
  - `ON DELETE CASCADE` on votes means deleting a category deletes its ballots, which
    contradicts the rule "a record that has been used is retired, never deleted".
  - At least 25 places query inside a loop.
  - Five places write the vote counter. A recount tool exists because those writers drift.

### 3.3 Frontend (257 templates, 55 stylesheets, 44 scripts)

- **The public site and the console share almost nothing.**
  - `ag-btn` is used 0 times in admin, and `cn-*` is used 0 times on public pages.
  - Inside admin, `ad-btn` (350 uses) and `cn-btn` (15) are two button systems.
  - Admin templates hold 1,405 raw hex values. `ColourLiteralTest` exempts admin by
    directory.
- **Components exist, but pages build their own.**
  - `partials/ui.twig` has 16 macros and is imported by 3 pages; five of its macros have no
    caller at all.
  - The console kit is used by 3 of 101 admin pages.
  - The bottom action bar is built 4 times; one copy's own comment says "copied
    deliberately".
  - There are 22 page-specific empty states, at least 8 back-button classes, and three
    near-identical "alert stopped" pages each with its own CSS family.
- **Prefixes collide.** `.hm-` is used for both the public home page and the console home
  page. The same goes for `.sp-` (shop and support), `.st-` and `.ed-`.
- **Navigation is defined four times, and the four disagree.** The header has 12 links, the
  tab bar 4, the menu sheet 15 (built in PHP) and the footer 29. The footer has no Events
  or Shop link. The menu links to the unstyled shop.
- **The asset build is built but unused.** `AssetBundle` produces a minified 78KB bundle
  from the shell's 150KB of CSS, and no template links it. Every public page loads 10
  stylesheets and 10 scripts one by one. The vote page loads 16 and 14.
- **Inline styles:** there are 2,307 `style=""` attributes (1,911 in admin) and 78 inline
  `<style>` blocks.

### 3.4 Feature domains and flows

**Where the weight sits.** Events (26.7k lines) and interviews/questionnaires (18.9k)
together are larger than the core award path: nominations 13.4k, voting 8.7k and judging
8.6k. The side systems have outgrown the product they serve.

**Identity is four systems with no shared person.**

- Admin, member, organisation and judge each have their own table, sign-in and session
  keys.
- About 14 more token links act as credentials: door pass, my-work, interview, claim,
  ticket and others.
- Records are joined by **email** across tables (`VendorAccount.php:34`,
  `ProfilePage::owns()`, which has two ownership rules).
- An organisation applies through the member form (`/account/register?as=organisation`)
  and then signs in somewhere else (`/org/login`) with a separate password.
- "Register" means four different things in the code.

**Money has no single ledger.**

- Four payment tables: donations (where `tier` doubles as the stream type: donation,
  paid vote, legacy pay), shop orders (status `paid`, not `confirmed`), event
  registrations, and stand applications.
- Five credit and payout ledgers on top: points, referrals, organisation payouts,
  community returns and challenge prizes.
- One webhook handler branches to all of them.
- **Stand fees are outside every backstop.** `StandFee` is not referenced by the webhook,
  the reconciler, `GatewayLedger` or `RefundService`, so a stand payment whose browser
  callback never lands is never confirmed by anything.
- Refunds are split three ways: donations, tickets and shop.

**One nomination passes through five records.** It goes nomination → nominee (one row
*per category*) → profile → recognition → hall of fame, and "who won" is shown on six
surfaces. The result is stored as the nominee row's status.

- Nominee consent (`nominee_confirmed_at`) is enforced only for challenges.
- Core nomination submit calls `ChallengeService::attachNomination` directly
  (`AwardService.php:551`), so the award path depends on a side feature.

**One-off scenarios are written into core code:**

- `ChallengesController.php:190` names `celebrate-nigeria-2026`.
- `Support/ChallengeDemo` hardcodes "Alimosho Awards 2026".
- `PromoService` is commented as being for Alimosho pages only.
- `HolidayTheme:197` and `GreetingsAdminController:82` test `$slug === 'teachers'`.
- `StandPhotos` has a date typed into a constant.

These are what "features from ideas that flew in mid-production" look like in code: each
was right for its week and is now a rule nobody can see.

**Dead or half-retired paths:**

- The `/pay/init` price book (410'd, but the prices are still in the controller).
- `BonusVoteService::redeem()`, which has no caller.
- The donation-votes settings, written to a table nothing reads.
- 85 redirect routes, from three renames of the giving URL (`/donate` → `/gift` →
  `/giving`).

**Configuration has five on/off styles.** Settings are checked first with the environment
as the fallback, and the default differs by feature (`SmsService` is off by default,
`PlatformTip` on). Other styles are setting only, the dead table, RuleEngine layers, and 230
`is_active` uses. There are 143 fields on one settings page and 67 environment variables.

**The documentation contradicts the scoring rule.** `PaidVoteService.php:20-22` and
`CODEBASE-INDEX.md:261` still say purchased votes "never move rank". The rule in force,
recorded in CLAUDE.md and the help centre, counts every vote in the 30% tally term. The
public pages are right and the developer documents are wrong. That is exactly where the
next person will check their understanding, so it is a Phase 0 fix.

---

## 4. Why it got this way

This is not carelessness. Each of the patterns above was a reasonable choice for the
feature in hand:

1. **Features arrived as whole slices** ("we need stands", "we need challenges"), and each
   slice brought its own copy of the plumbing because nothing shared existed to reuse.
2. **Static helpers are the easiest thing to call** on a host with no build step and no
   shell. So the dependency graph lives in method bodies, where nothing can see or enforce
   it.
3. **The guard tests protect the house rules, not the boundaries.** The suite is very good
   at catching "a colour typed outside Accent". Nothing catches "Money reaches into Events'
   tables".
4. **Pages were destroyed with no rebuild queue that could fail.** The routes outlived their
   templates, and no test renders every route.
5. **Documentation describes intent, not structure.** CODEBASE-INDEX describes about 250
   routes and 4 middleware; there are 768 and 7.

The plan below targets these causes. The individual faults are the symptoms.

---

## 5. Target architecture

### 5.1 Shape: a modular monolith

One app, one deploy, one database, on the same shared host. Inside it, the code is grouped
by **domain**, and the boundaries between domains are explicit and enforced by a test. This
is the route Shopify took with its own monolith rather than splitting into services. Their
own retrospective has two lessons that apply here:

- folders alone are not enough; the boundary has to be **checked**;
- tidying public interfaces does not help while every component still depends on half the
  others.

So the order is: draw the boundaries, check them, then move the code.

```
src/
  Platform/            the kernel: no business rules, used by every domain
    Settings/          typed registry: every key declared once (type, default, floor, who may edit)
    Http/              one outbound client (timeouts, retries, logging, proxy)
    Mail/              one mailer facade over the existing transport and SendPolicy
    Ai/                AiGateway, the only route to a model
    Auth/              one Actor ("who is this, and as what") over five sign-in kinds
    RateLimit/         one limiter (claim/peek), replacing five
    Errors/            one Reporter: every catch names its error and reports it
    Clock/, Ids/, ClientIp/, Schema/ (SchemaHas, SchemaIndex), View/ (one Twig factory)
  Operations/          the operating model (§6): no business rules of its own
    Processes/         the registry: steps, roles, evidence, time limits, playbooks
    Work/              work items: owner, due, status, escalation, digests
    Evidence/          observed, witnessed, documented, reported
    Scopes/            platform → country → state → centre → programme/event; templates
  Domain/
    People/            one person, memberships, grants (role at a scope, with expiry)
    Awards/            programmes, cycles, categories, award profile, terms, details
    Forms/             the one form engine: nominations, applications, listings, surveys
    Nominations/       submissions, triage, aftercare, claims
    Judging/           judges, rubrics, scorecards, COI, questionnaires, interviews
    Voting/            ballots, OTP, paid and bonus votes, fraud, recovery
    Results/           scoring (CpiService, RuleEngine), release, seal, recognitions
    Volunteers/        applications, training, shifts, tasks, hours, levels, recognition
    Events/            calendar, tiers, tickets, run-sheet, door, invites, templates
    Market/            sellers, listings (product, service, stand, ticket, highlight), orders, reviews, disputes
    Promotions/        placements, campaigns, codes, delivery
    Money/             one payment ledger, giving, recurring, reconciliation, refunds, payouts
    Community/         pulse, threads, comments, moderation
    Assist/            Gee, the help desk, the staff assistant: one agent core, many tool sets
    Comms/             newsletter, campaigns, broadcasts, SMS
    Content/           help centre, legal, blog, pages
    Ops/               maintenance, status, cron, audit log, sandbox
  each domain:  Http/ (controllers, routes.php)  Application/ (services)  Data/ (queries)  views → templates/<domain>/
```

### 5.2 Rules, each enforced by a test

1. **A domain's tables are written only by that domain.** Others call its Application
   service. A sweep maps `DB::table('gates_…')` writes to a domain, the same way
   `OneResolverPerSettingTest` maps settings readers.
2. **Controllers do not query the database.** They parse the request, call one
   application service and render. A ratchet test starts at today's 63 controllers and may
   only go down.
3. **The kernel is the only place for its jobs.** There is one `curl_init`, one
   `$_SESSION['admin_id']` read and one `gates_settings` reader. Sweeps fail on a second copy.
4. **Every catch names its error and reports it.** A catch may still swallow on purpose
   (the webhook's 200 must stay), but it records what it swallowed. This is a ratchet from
   1,262 that may only go down.
5. **Money writes run in a transaction.** A sweep lists every multi-write method in
   Domain/Money and requires either `DB::transaction` or a documented single conditional
   update.
6. **Every routed template exists**, and every public route renders without a 500 (the
   Phase 0 sweep).
7. **One source per navigation surface.** The header, tab bar, menu sheet and footer are read
   from one public nav registry, as the console already does with `AdminNav`.

### 5.3 How a request flows after the change

`route (domain routes file) → middleware (Actor resolved once) → controller (thin) →
application service (rules and transaction) → data (queries owned by the domain) → view
(domain templates and shared components) → Reporter on any failure`

### 5.4 Every part of the platform has a home

The domain list above was drawn from the code's largest areas and missed a number of real,
working parts. Each one belongs somewhere, and moves with its domain:

| Part (where it lives today) | Domain |
|---|---|
| Challenges (`ChallengeService`, `/challenges`) | Awards, as a kind of programme with computed qualification |
| Recognitions, the legacy vault, hall of fame, supporter honours (`LegacyVault`, `SupporterHonours`) | Results |
| Leaderboard, Discover, Registry and profiles, the nations globe (`GlobeBand`, `NationsLive`) | a public **Discovery** read layer over Awards and Results, owning no writes |
| Vote recovery (`votes:recover`, its admin review), collusion scans (`CollusionService`) | Voting |
| Points, referrals, the member wallet, automatic withdrawals (`PointsService`, `ReferralService`, `MemberWallet`, `WalletAutoPay`) | Money |
| Partner organisations' giving pages and console (`PartnerOrg`, `OrgBrand`, `OrgPayout`, `/org`) | Money (giving), with the organisation as a scope (§6) |
| The judge console (`src/Judge`) | Judging |
| The door's voice and greetings (`DoorWelcome`, `DoorVoice`) | Events |
| Celebrations, seasonal themes, display and reading settings (`Celebration`, `HolidayTheme`, `DisplayReading`) | the experience layer (§11), not a domain |
| Passkeys and one-time codes (`passkeys`, `OtpAttempt`) | Platform/Auth |
| Media storage (`R2Service`, `CloudinaryService`) | Platform, as one storage facade |
| The public API and outgoing webhooks (`/api/v1`, `WebhookService`) | Platform, with each domain publishing its own events |
| The status page, `CronHealth`, `MailHealth` | Ops |
| The sandbox (`DemoSeeder`) | Ops, kept outside every public reader (`liveAwardOnly()`) |
| Newsletter and broadcasts (`NewsletterAudience`, `BroadcastLog`) | Comms |
| Search (`SearchLanding`) | Discovery |

### 5.5 Cross-cutting duties the plan had not covered

These belong to no one domain, and each needs an owner and a test like everything else.

- **Backups and restore (fault 16).** Nothing backs up the database today. On a host with no
  shell: a scheduled task writes an encrypted, compressed dump in batches (a few tables per
  tick, so no tick hits the time limit) to a second location in Nigeria (D22), keeps 30 daily
  and 12 monthly copies, and records each run in `CronHealth` so a missed backup raises the
  same alert as a missed tick. A **restore drill every quarter**, into a scratch database, is
  the only proof a backup works; its result is shown on the status page.
- **Retention and erasure (fault 17).** The rules exist in `PrivacyPurgeCommand` and
  `PrivacyEraseUserCommand` but can only be run from a shell. Retention runs on the daily tick
  once you set the windows (D33); erasure becomes a reviewed work item in the console,
  carried out by the same code, with the audit log recording what was removed.
- **Security.** Strong already and scattered: the content-security policy (`Csp`, with its
  static copy in `.htaccess` kept equal by a test), security headers, CSRF, rate limits,
  one-time-code attempt caps, passkeys and the audit log. The kernel (§5.1) gathers them; the
  existing tests stay as the guard. Add a yearly outside review before Events opens to
  outside organisers.
- **Accessibility and languages.** Display & reading settings, the WCAG 2.2 work (§25 of the
  codebase index) and the `|trans` layer exist. Every new screen meets the same rules, checked
  by the existing guards; Nigerian languages arrive as catalogues (§9.11).
- **Observability.** Structured logs, `CronHealth`, `MailHealth`, the status page and alert
  webhooks exist. Every domain reports through the one `Reporter` (§5.2 rule 4).
- **Migrations by URL.** Migrations are applied by opening a URL, and a repair migration is
  the only way to correct a constraint (the CLAUDE.md lessons). That stays the deploy path,
  and the MySQL parity run (`scripts/mysql-parity.sh`) runs before every migration ships.

---

## 6. The operating model: five tests every process must pass

You set five tests for every process. Today almost every process on the platform fails at
least two of them, because a process here is a screen and a habit, not a thing the system
knows about. The fix is to make each process **data the platform holds**, so that the five
tests are properties the code checks rather than promises people keep.

### 6.1 The five building blocks

| Test | Building block | What it is |
|---|---|---|
| **Repeatability** | **Process registry + playbooks** | Every process is declared once in code: its steps, the role that may do each step, the evidence each step needs and its time limit. The handbook page for the process is **generated** from that declaration, the way `/admin/handbook` already reads its figures from the rules. Each step screen guides the person through it. A new volunteer or staff member follows the screen, not the founder. |
| **Visibility** | **Status read from work items** | Every piece of work (a nomination to review, a refund, a stand application, a door shift, a volunteer's task) is a **work item** with a status. Every queue, count, dashboard and daily digest is a view over work items, so status is known without asking anyone. The console's "needs a person" board becomes one view among many. |
| **Accountability** | **Owner and deadline on every item** | A work item cannot exist without an owner and a due time. Unassigned work rolls up to the lead of its scope. Overdue work escalates one level, visibly. A sweep fails any process that can create work with no owner or deadline. |
| **Verification** | **Evidence, graded** | Completing a step attaches evidence, and the platform knows how much each kind is worth. In order of strength: **observed** (the platform recorded it itself: a QR scan, a confirmed payment, an approved nomination through someone's link); **witnessed** (a second person confirmed it); **documented** (a file or photo, reviewed); **reported** (the person says so). Each process states the minimum it accepts, so reported activity and demonstrated results are never added together. |
| **Replication** | **Scopes and templates** | Everything (roles, work, settings, events, markets) belongs to a **scope**: Platform → Country → State/Region → Centre → Programme or Event. Roles granted at a scope apply below it. Settings layer the way RuleEngine already layers programme and cycle. Processes, events and task sets are **templates** a centre clones. Opening centre 101 means one row and one named lead, not new staff, new pages or new configuration. |

The test for this section is one sweep. Every declared process must have:

- an owner role for every step;
- a time limit on every step;
- a minimum evidence level;
- a scope;
- a generated playbook.

A process that is not declared cannot create work items. So the only way to add a process
is to answer all five tests.

### 6.2 Today's processes against the five tests

This is a first reading from the code. Phase 0 replaces it with the registry's own report,
because once processes are declared the registry prints this table and keeps it true.

| Process | Repeatable | Visible | Owned + due | Verified | Replicable |
|---|---|---|---|---|---|
| Nomination review | partial: one screen, rules in code | partial: a count on Home | **no**: a shared pool; the SLA promise is printed, but no person owns an item | n/a | **no**: one global queue |
| Duplicate merge | yes: journal and undo | partial | **no** | yes: the journal | no |
| Claim approval | partial | partial | **no** | partial: OTP to a contact | no |
| Judging | yes: rubric, COI, orientation | yes: judging audit | partial: assigned per nominee; the deadline is the cycle date | **yes**: complete scorecards only | partial: per programme |
| Nominee questionnaire and interview | partial | partial | partial: reminders exist | partial | partial |
| Result release | yes: one act, sealed | yes | partial | **yes**: sealed and hash-chained | yes: per cycle |
| Support tickets | partial | yes: ticket list | **no**: "48 hours" is printed, not measured per owner | n/a | no |
| Refunds and payouts | partial: three different paths | partial | **no** | partial: gateway status | no |
| Stand applications | partial | partial | **no** | **no**: no payment backstop | no |
| Door check-in | **yes**: the scanner | yes | partial: passes per event, not per person | **yes**: observed scans | **yes**: per event |
| Shop fulfilment | partial | partial | **no** | partial | no |
| Moderation | partial | yes: a queue | **no** | n/a | no |
| Challenge qualification | yes: computed | yes | n/a | **yes**: computed from records | partial |
| Maintenance (cron) | yes | yes: CronHealth and alerts | yes: the system owns it | yes | yes |

The pattern is clear. The platform is already good at **verification** wherever it records
facts itself (scans, scorecards, seals). It is weak at **accountability** and
**replication** everywhere, because nothing assigns work to a person and nothing has a
scope below "the whole platform".

---

## 7. People, access and volunteers

### 7.1 One person, many roles

Today a person can be a member, a nominee, a donor, a judge, an organisation user and a
vendor, and each of those is a separate record with its own sign-in, joined by email. The
target is one **person** with many **memberships**:

- member, nominee, nominator, donor, voter, judge, organisation user, seller, volunteer,
  staff;
- sign-in methods (password, one-time code, passkey) belong to the person, not to the role;
- token links (my-work, tickets, door passes) keep working without an account, and attach
  to the person's home when they are signed in.

This is the largest data change in the plan, so it comes last (D6). Everything before it
works through a single **Actor** in the kernel that already answers "who is this, and as
what" across all five of today's sign-ins.

**Shipped 29 Oct 2026 (owner: "one account that can manage different roles"; then "roles
given, not joined", with the separate sign-in pages retired).** The person is the Africa GATES
account (`gates_users`) and `/account/login` is the only sign-in. A staff, judging or
organisation role is GIVEN by staff to an email address — the one typed when the admin, judge
or organisation user was created — and held by the account that has verified that address
(`Services\Account\Roles`). There is no roles page and nothing to join or link.

- **Held is read from the role's own row on every request**, so switching a role off, or giving
  it to another address, takes effect on the next page, not at the next sign-out.
- **The console, panel and organisation open on the way in** from the account that holds them
  (`Roles::derive` in each gate). A session proved only by a phone code gets an email code first.
- **Money and configuration ask again**: an emailed code before a console write in Finance or
  Configuration, and the organisation's own password before a payout.
- **The old pages are forwarding addresses**: `/admin/login`, `/admin/magic`, `/judge/login`,
  `/org/login`. Signing out anywhere signs the person out. `/__setup/admin` leaves the
  superadmin's account able to sign in by password.
- **A sign-in lands where the account last worked** (`gates_users.last_place`). The product
  switcher at the top of the rail moves between parts of the account (after ElevenLabs).

Not yet: passkeys and sign-in methods belonging to the person (they already do — the member
account's), a second factor for accounts holding staff roles, and the data change that merges
the role tables (D6).

### 7.2 Access: scoped, time-boxed, least privilege

- **A grant is a role at a scope**, for example "door steward at the Lagos gala",
  "nominee outreach for Kano centre", "moderator for the community" or "finance for the
  whole platform". This is the pattern multi-tenant systems use instead of global roles,
  because a global role cannot say "admin here, viewer there".
- **Grants can expire.** A volunteer's event access ends when the event does. The door's
  `EventScanPass` already works this way, opening and closing per event, and it becomes
  the general mechanism.
- **The console asks the same function everywhere.** `Permissions::canOpen()` already
  decides every page and every nav entry. It gains a scope: `canOpen(person, page, scope)`.
  So a volunteer sees the same console as staff, with only their scope's pages and rows.
- **Personal data is masked by default.** A volunteer working a queue sees "Ada N.,
  0803•••••12" unless the task needs more, and every unmasking is audited.

### 7.3 Volunteers

Volunteers are designed in as a domain of their own, built on work items, scoped access,
evidence and the form engine.

**Lifecycle.** Each step is a process in the registry, so each passes the five tests:

1. **Apply.** The application form is a dynamic form from the same engine as nominations,
   so each centre or event can ask its own questions.
2. **Screen.** References, plus an identity check only for roles that need one. Age is
   checked, with a guardian's consent recorded for minors.
3. **Train.** Short modules with a check at the end. Passing grants the first
   qualification.
4. **Activate.** A grant of a role at a scope. The code of conduct is accepted and
   versioned, like award terms.
5. **Work.** Shifts and tasks.
6. **Recognise.** Hours, badges, certificates and a standing per centre. A volunteer
   award can be run as an Africa GATES programme in its own right.
7. **Offboard.** Grants expire on their own, and access never outlives the reason for it.

**Tasks come from a library of templates.** Each template states the process it belongs to,
the qualification needed, the minimum evidence, the expected time and the points. Examples,
each with how the platform verifies it without trusting a report:

| Task | Verified by (strongest available) |
|---|---|
| Door steward or usher | Check-in and check-out by QR scan at the venue (observed hours) |
| Stand marshal | Stand check-ins recorded on their pass (observed) |
| Nominee outreach | Nominations that arrive through their link **and are approved** (observed outcome, not effort) |
| Voter or community drive | Sign-ups through their link that verify an email or phone (observed) |
| Data entry and digitising past editions | The reviewer's acceptance rate on a sample (witnessed) |
| Moderation helper | Agreement with a moderator's decisions on a sample (witnessed) |
| Photo, video, writing, translation | Pieces accepted and published (witnessed) |
| Anything else | Approval by the scope lead, with a reason (witnessed); a self-report alone never counts toward hours or level |

**Measurement and qualification.**

- Four numbers per volunteer:
  - verified hours;
  - verified outcomes;
  - quality (the acceptance rate of their work);
  - reliability (shifts kept against shifts taken).
- **Levels are computed from evidence, never set by hand without a reason on the audit
  log.**
  - Level 1 can take general tasks.
  - Level 2 can create records that publish after a spot check.
  - Level 3 is a lead: they assign work, approve hours and confirm witnessed evidence for
    others.
- The rates move with the record. As someone's accuracy rises, the share of their work that
  is spot-checked falls, and it rises again if accuracy drops.

**Data volunteers create** carries its provenance: who made it, under which task and grant.
It stays a draft until their level allows publishing, or until a reviewer accepts it. New
volunteers can practise in the sandbox, which already exists for exactly this.

**Their experience.**

- Volunteering is a panel in their own home (§11): today's shifts, open tasks, verified
  hours, their level and what the next one needs, and a downloadable certificate.
- Field work (door, stands, check-in) uses full-screen field tools built for a phone and
  a poor connection, as the door scanner already is.

---

## 8. Dynamic awards and nomination forms

### 8.1 What exists today

Per-award configuration already exists, but in scattered pieces:

| Piece | Where | Per award? |
|---|---|---|
| Words such as "nominee", "nominate", the "who" question and hints | `AwardWording` (`wording_json`), 7 keys | Yes, but `reason_question`, `evidence_hint` and `nominee_noun` are not shown on the live form |
| Details: eligibility, prize, notes, FAQ | `AwardDetails` (`details_json`), added 16 Oct | Yes |
| Terms | `gates_award_terms`, versioned | Yes |
| Scoring weights, basis, quorum | `RuleEngine`: code defaults → programme → cycle | Yes, and this is the pattern to copy |
| Judging rubric | `JudgeRubric` (`programme_id` nullable = house rubric) | Yes |
| Nominee questionnaire style (interview or guided form) | `QuestionnaireStyle::styleFor(programme)` | Yes |
| Questionnaire questions | `gates_programme_questions` (programme/cycle, kind, label, help) | Yes |
| **The nomination form itself** | Hard-coded in `nominate-award.twig` | **No** |
| **Nomination rules** (2–3 categories, 40–2,500 characters, evidence kinds, file types, nominee kinds) | `NominationRules` constants | **No** (only the category minimum adapts to how many categories there are) |
| **The award's look** | Cover image only | **No** |
| A general form builder | `FormService` and `gates_forms.schema_json`: 10 field types | Exists, but is used only for standalone forms, not for nominations |

So the platform already holds **three form engines**: the hard-coded nomination form, the
questionnaire questions and the general form builder. None of them can express what you
asked for. The plan is to merge them into one, not to add a fourth.

### 8.2 Target: the award profile

Every programme gets an **award profile**. It resolves the same way RuleEngine does: house
defaults, then the programme, then the cycle, with the last layer winning. So one edition
can differ from the last without editing history. The profile has five parts.

**1. The nomination form: a versioned definition built from a field library.**

- Fields come from one typed library:
  - the existing `FormService` types (text, email, phone, number, long text, select, radio,
    checkbox, date, file);
  - **domain fields** that know what they are: nominee (person or organisation), category
    picker with a reason per category, evidence (link or file), region (country → state
    → LGA), age range, relationship to nominee, consent and terms.
- Each field carries:
  - a key, label, help text and placeholder;
  - whether it is required;
  - limits (minimum/maximum length, file types and size);
  - **conditions** ("show only if the nominee is an organisation", "only for these
    categories");
  - its **section and step**, so one award can be a single page and another a three-step
    flow.
- **System fields are locked.** Nominee name, nominee kind, categories, nominator name and
  contact, and consent are present on every form. Duplicate detection, triage, the review
  desk, judging and the claim flow all rely on them. An operator can reword, re-help and
  reorder them, but not remove them. Everything else is free.
- **Versions are immutable once used.** A form has one *draft* and any number of
  *published* versions. Every nomination records the version it was submitted against, so
  the review desk always shows a nomination with the questions it actually answered. A
  version that has been used is retired, never deleted: the same rule as rubric criteria.
- **One interpreter, two doors.** The same definition drives:
  - server validation for the web form and the API (today's "one validator for both doors"
    rule, kept);
  - the browser's `required`, `minlength` and `pattern` attributes, generated from the same
    rule, so the browser and server cannot disagree (a fault CLAUDE.md describes);
  - the review desk, the CSV export, Sheets, the operator email and the AI triage brief.

  That last item closes the blank-reason bug fixed this week for good, because there is no
  longer a reader that knows only one field.
- **Storage.** Answers are kept as one JSON document on the nomination
  (`answers_json` + `form_version_id`), and fields that something filters or counts are also
  written to real columns (nominee kind, country, categories). This follows the `OrgBrand`
  precedent: read once per row, no migration for a new field. The column limit is checked on
  save, because `TEXT` is 64KB on MySQL.

**2. The nomination rules.**
Today's constants become profile settings with today's values as the house default:

- category minimum and maximum;
- reason length;
- which evidence kinds are required;
- accepted nominee kinds (people, organisations or both);
- whether self-nomination is allowed;
- an age requirement;
- a region scope (Nigeria-only or pan-African);
- the daily limit;
- the opening window.

Each setting comes from the Settings registry, so it has one reader, a floor and a form that
cannot save a value that breaks the sentence describing it.

**3. The look: chosen, never typed.**

- A theme is picked from a **closed set built on the house palette**: the programme's
  identity family (from `Accent`, as results pages already do), a cover style, and a layout
  preset (standard, gala, challenge or community).
- No per-award hex values and no custom CSS. Every award stays legible and measurable by
  `SlotFloorTest` and `ColourBudgetTest`, and a sponsor's brand cannot break the contrast
  rules. Logos and cover art are uploads.

**4. The words, details and terms** stay where they are, and the three wording keys that are
saved but unseen get rendered.

**5. Judging and voting:**

- The rubric and the questionnaire style are already per award.
- The profile adds per-award choices of what voting is: free, paid or both, and points
  allowed or not. These sit behind the existing RuleEngine settings, so announced results
  stay reproducible.

### 8.3 The admin side: one "Award setup" screen

Today an award is configured across Programmes, Settings, Rubric, Questionnaires and
Judges. The plan is one screen per award with tabs:

**Basics · Look · Nomination form · Rules · Categories · Judging · Voting · Details & terms ·
Publish**

The form tab is a builder. You add a field from the library, drag it into place and set its
conditions, with a live preview at phone and desktop width. Publishing creates a new
version; until then the live form is untouched.

*The tabs link to the existing judging pages; they do not move them.* Judging access is
split across three permission gates, and moving those pages would change what whole roles
can open (see §16, D5).

### 8.4 Moving existing awards across

1. The current hard-coded form is written as a definition in code: the **house form,
   version 1**.
2. Every existing programme points at it, and every existing nomination is stamped with it.
   Nothing changes for anyone.
3. The public form and the review desk are switched to render from the definition. A test
   requires the rendered form to be **field-for-field identical** to today's.
4. Only then does the builder open, one award at a time.

---

## 9. Africa GATES Events: a new product

*Added 17 Oct 2026 from the Events Chief Engineer Plan (v1.0, 17 Oct 2026), sized to the host
this platform actually runs on. **Restructured 18 Oct 2026** around one position (9.1), with
market research (9.2), a priced offer (9.4), the trust rules an open platform needs (9.5),
pilots that decide each opening (9.7) and a stage order that starts where Africa GATES can
win (9.8). The engineering sections (9.9–9.18) keep the plan's catalogue, sized to the host.*

The section is in three parts:

- **Part A, the product** (9.1–9.8): who it is for, what it sells, how it is kept honest, and
  the order it opens in.
- **Part B, how it is built** (9.9–9.18): the host, the domain, phones, the door, money,
  messages, safety, privacy, AI and targets.
- **Part C, what could go wrong** (9.19–9.20): risks, and the evidence that would change
  this plan.

### Part A. The product

### 9.1 The position: events that honour people

**Africa GATES Events is the platform for events that honour people first, and a general
events platform second.** Anybody who hosts an event gets it (D23, decided), and that stands.
What this section decides is the order: the product **leads** with events that carry an
award, and the general tools follow once pilots show organisers want them (D35, decided
18 Oct 2026).

**Why this, and not general ticketing first.**

- **On plain ticketing the market competes on fees and payouts**, and established players
  are already there (9.2). A new entrant cannot win on a fee it can only match.
- **On award events nobody does the whole job.** Ticketing apps sell the night. Paid-voting
  vendors run the vote. Nobody runs nominations, shortlist, voting, judging with quorum,
  sealed results, nominee mobilisation, the ceremony's run-sheet, results released on stage
  and the honoured guest greeted by name at the door, in one place. Africa GATES has most of
  that built (9.3).
- **Award events are common.** School prize-givings, community and church recognitions,
  industry and professional awards, alumni and association galas. Each one also brings
  nominees, and every nominee brings a community: an award event recruits its own audience.
- **It is where Africa GATES's name already carries weight**, and where its integrity rules
  (sealed results, "money buys the tally, never the reach") are a selling point rather than
  a cost.

**Who it is for, in order.**

1. **Africa GATES's own awards and centres** (D11): the first users, and the pilots.
2. **Outside award organisers**: schools, associations, professional bodies, community and
   faith groups, companies with staff awards.
3. **Any event organiser**, on the free core: ticketing and the door.

**What it is not, for now.** Not a concert promoter's platform competing on fees; not a
cashless or wallet provider (D29); not a venue marketplace; not a general project-management
tool. Each of those waits for evidence (9.20).

### 9.2 What the market research says

*Researched 18 Oct 2026. Third-party figures are reported, not verified with the companies;
confirm before quoting externally. Sources in §17.*

| Finding | What it means here |
|---|---|
| **Ticketing fees.** Tix Africa is reported to charge about 5% plus a small per-ticket fee on paid events, nothing on free events, and dropped its subscription plan (Tix Pro) in 2023. Syticks is reported at 3.5%. Eventbrite's published fees are dollar-priced, and Nigerian competitors report it does not pay out to Nigerian bank accounts. Tix Africa launched in the UK in 2026. | Fees are where the market competes, and a subscription for ticketing has already been tried and dropped. **Do not sell ticketing by subscription and do not lead on fee.** Price the free core at the market level (D31) and charge for the award bundle (9.4). |
| **Paid voting is an established business.** Nigerian vendors list contestants, set a fee per vote, take a service fee and remit the rest; winners are decided by a published mix of public votes and judges' scores. AFRIMA, the .ng Awards (NiRA) and the Nigerian Healthcare Excellence Awards all ran public voting in 2025. | The demand is proven. What those vendors do not have is the rest of the award: nominations, judging with quorum, a sealed and verifiable result, nominee mobilisation and the ceremony. That gap is the product. |
| **"Pay to be honoured" schemes exist.** In one reported case a person staged an awards event without authorisation, charged builders for awards, and was expelled from the professional body in May 2025. Promoters of non-existent events have sold tickets and vanished. | An open award platform will be tried by exactly these people, and the damage lands on the Africa GATES name. **Award integrity rules are part of the product** (9.5), not an afterthought. |
| **Safety rules after the December 2024 stampedes.** Lagos requires events expected to draw more than 250 people to register with the Lagos State Safety Commission and hold an Event Safety Permit; above 500, trained safety marshals must be on the ground. A noise permit is a precondition of the safety certificate. Reported requirements for large events include two marshals per 500 guests, ambulance cover and a medical retainer. | The capacity and permit tools (9.15) are worth more in Nigeria now than planning or AI features, and few competitors offer them. Rules are held as data per state, each with the date it was last checked. |
| **Data law.** The GAID 2025 sets tiers for data controllers "of major importance" by the number of people whose data they hold (reported: 2,500, 10,000 and 50,000), with annual compliance audit filings and fees reported from ₦100,000 to ₦1,000,000. | Africa GATES may already be in a tier from its awards alone; Events adds every organiser's guests. Legal advice (D27) before outside organisers come in. |
| **Settlement.** Paystack subaccounts support `auto`, `weekly`, `monthly` and `manual` schedules (the platform already accepts all four in `PartnerOrg`). Reports say the manual schedule may need Paystack to enable it on the account. | "New organisers are paid after the event" (9.5) depends on it. **Confirm with Paystack before E2**; 9.5 names the fallback. |
| **Surge protection.** Cloudflare's Waiting Room is only on the Business and Enterprise plans (a basic room on Business). | The free plan cannot queue an on-sale. Decide the upgrade before the first big one (9.9). |

### 9.3 What already exists, and what does not

The events code is already the largest domain here (26.7k lines), and much of the "Sell" and
"Admit" catalogue exists:

| Already built | Where |
|---|---|
| Tiers with their own capacity, hidden tiers by access code, multi-seat orders | `EventTicketService` |
| Discount codes, and one box that takes a discount or a referral code | `EventDiscount`, `EventCodeResolver` |
| Waitlist on a sold-out tier; "email me when tickets go on sale" (double opt-in) | `EventWaitlist`, `EventSaleAlert` |
| Ticket resend, rename, transfer and cancel, each behind a one-time code | `TicketSelfService` |
| Refunds shown before cancelling, then paid and retried | `EventRefundPolicy`, `RefundService` |
| Referral commission on tickets, paid out through the wallet | `ReferralService`, `ReferralPayout`, `WalletAutoPay` |
| A door scanner that keeps working offline: scans queue on the phone and sync with a client id, so a retry cannot count twice | `templates/pages/events/door.twig`, `DoorController`, `DoorOfflineTest` |
| Already-in detection, undo, door passes per event, revocation | `EventArrivals`, `EventScanPass` |
| A spoken greeting at the door, rendered hours ahead | `DoorWelcome`, `DoorVoice` |
| Rotating 30-second codes (for guest-of-honour passes only) | `InvitePass` |
| Agenda by day and track; invites to nominees and judges with letters and reminders | `EventAgenda`, `EventInvites` and family |
| An event linked to the award programmes it serves; nominee invite references that double as discount codes | `gates_event_programmes`, `EventInvites` |
| Stands: calls, types, floor plan, applications, offers, fees, waitlist | `StandApplication`, `StandFee`, `StandFloorPlan` and family |
| Ticket artwork, PDF tickets, a GD-drawn "I will be there" flier | `TicketArtwork`, `TicketPdf`, `EventFlier` |
| Payment reconciliation for tickets; signed, logged webhooks | `PaymentReconciler`, `GatewayEventLog` |
| SMS (Africa's Talking, Termii, Twilio) and WhatsApp (Meta Cloud API) | `SmsService` |
| The whole award: nominations, shortlist, paid and free voting, judging with quorum, sealed release | the awards domain, `CycleMaterialiser`, `ReleasedStanding` |
| Organisations with CAC checks, a verified settlement account and a Paystack subaccount on any schedule | `PartnerOrg` |

**What does not exist.**

- **Per-organiser settlement for tickets.** Ticket money today goes to one destination for
  the whole `events` stream (`PaymentDestination`), which is right while Africa GATES is the
  only organiser and wrong the day a second one sells. Each organiser's sales must split to
  their own subaccount before any outside organiser sells (stage E2).
- Everything in "Plan", "Operate" and "Learn": tasks, budgets, vendor commitments, safety and
  permits, run-of-show cues, staff shifts, incidents, a post-event report, event templates.
- Signed tickets, installments, per-message cost tracking, the one ledger (D8), a consent
  ledger.

### 9.4 The offer: a free core and the award bundle

**Organisers pay for what makes or saves them money on the night**, not for planning tools
they already do in WhatsApp and spreadsheets. So the paid offer is the award, and ticketing is
free to adopt.

| | Free core | Award bundle |
|---|---|---|
| **For** | Anybody hosting an event | Anybody running an award with a ceremony |
| **Includes** | Event page, ticketing with tiers and codes, waitlists and transfers, refunds, the door scanner (online and offline), the agenda, email, the capacity check, the state's permit checklist, the incident log and the safety evidence pack (safety is never paywalled, 9.15) | Everything in the free core, plus: nominations with the award's own form (§8), shortlist, free and paid voting, judging with quorum, sealed results and the public result page; the award's timeline as the event's plan; nominee mobilisation kits with costs shown (D32); the ceremony run-sheet per category; **results released at the cue**; honours seating and greeting; certificates and badges; SMS and WhatsApp with a messaging budget; the generated post-event report; own branding |
| **Platform takes** | A per-ticket fee on paid tickets only, at market level (D31). Free events are free. | A fee per **edition** (awards run per edition, not per month), or a share of paid-vote revenue for editions that use paid voting, whichever the organiser picks (D30). Ticket fees as in the free core. |
| **Limits** | A guest cap per event until the organiser is verified | Set per plan |

**Why per edition and not monthly.** An award organiser runs one edition a year. A monthly
plan charges them for eleven months they do not use, and the market already dropped
subscription ticketing (9.2).

**Why the share-of-votes option.** Small awards (a school, a church) cannot pay up front, and
the share is paid from money the award brings in. It must never change the scoring rule, and
it must be disclosed on the award's rules page beside the vote price.

**Every limit and price is a setting** resolved through one plan resolver, so the owner can
change them without code.

### 9.5 Trust: who may host, and protecting guests and nominees

Anybody can host (D23). An open platform will be used to sell tickets to events that never
happen and to run awards people pay to win. The protections ship **with** each opening (9.8),
never after the first incident.

**Every organiser.**

- **The organiser is an organisation scope** (§6.1): its own team, roles and events, isolated
  from every other organiser, starting from `PartnerOrg`.
- **Verification before money.** Drafting an event needs nothing. Selling needs a confirmed
  email and phone. Being paid needs a CAC or identity check and a settlement account in the
  organiser's own name, confirmed with the bank (the check `PartnerOrg` and the wallet's
  automatic payouts already make).
- **A new organiser's first event is reviewed** before it goes on sale: a work item with an
  owner and a time limit, so review cannot become a silent queue.
- **New organisers are paid after the event.** Their subaccount runs on the `manual`
  schedule, and settlement is released after the event plus a short window. The money waits
  at Paystack, not with Africa GATES, so this needs no licence to hold funds (D29). A clean
  record moves an organiser to the normal schedule.
  - **If Paystack will not enable `manual` on the account**, the fallback is a `weekly`
    schedule with a cap on a new organiser's sales before the event, so an event that never
    happens can only ever have taken a bounded amount. Do not route their money through the
    platform's own account instead: that is holding client funds.
- **Attendees can report an event.** A report is a work item with a deadline, and enough
  reports pause sales automatically until a person looks.
- **Sales fraud**: velocity limits per buyer and per card, and refund and chargeback watching
  (9.13).

**Award organisers, additionally.** An award on Africa GATES carries the Africa GATES name,
so it is held to the platform's own rules:

- **Nobody pays to be honoured** (D34). An award may sell tickets and votes; it may never
  charge a nominee to be nominated, shortlisted, to win, or to receive their award. A guest
  ticket for the nominee's family is fine; a fee for the honour is not.
- **Rules before voting.** The scoring rule, the vote price and what money can and cannot buy
  are published before voting opens, and frozen for the edition (`RuleEngine` already
  versions them per cycle).
- **Sealed results.** The result is the one announced and sealed (`CycleMaterialiser`,
  `ReleasedStanding`), on every award, not only Africa GATES's own.
- **Equal help** (D32): the platform's own mobilisation help is identical for every nominee
  in a category.
- **A named, verified award organiser**, shown on the award's page, so the public knows whose
  award it is.

**Support** for organisers is the help desk and Gee, with playbooks generated from the
process registry (§6).

### 9.6 Events that carry an award

An award's ceremony is an event, and the event plan carries **the whole award, not only the
night**. Today the link already exists: an event names the award programmes it serves
(`gates_event_programmes`); the published shortlist and the judging panel are invited as
guests of honour with letters, reminders and a rotating pass (`EventInvites`, `InvitePass`);
and each nominee's invite reference doubles as a discount code, so every ticket bought with
it credits the nominee who brought that guest.

**Planning the award inside the event.** When an event is linked to an award cycle, its
workspace gets the award's timeline as milestones, read from the cycle's own dates so the
two cannot disagree:

1. nominations open and close;
2. shortlist published;
3. voting opens and closes;
4. judging (scorecards, interviews, questionnaires) complete, with quorum;
5. results release, as an act at a moment the organiser chooses (already how
   `CycleMaterialiser::release()` works);
6. the ceremony.

Each milestone carries its work items, owners and due times: judges recruited and
oriented, categories confirmed, trophies and certificates ordered, presenters booked,
the winners' seating plan, the press list. An award running late shows on the event's plan
before it shows on the night.

**The ceremony itself.**

- **Run-of-show cues per category**, generated from the award's categories: presenter,
  nominees on screen, the winner's walk-up.
- **Release at the cue.** The organiser can tie the results release to the cue that
  announces them, so the public results page, the winners' emails and the screen in the hall
  change at the same moment. Until then the result is sealed and embargoed, as now.
- **Seats for honours.** Nominees, guests and judges get reserved seating from their
  invites, and the door greets them by name (`DoorWelcome`).

**Mobilising nominees.** A nominee who mobilises their community is the award's growth
engine, and the event plan runs that as a programme with stages:

| Stage | What nominees get | Measured by |
|---|---|---|
| Nominated / shortlisted | A claimed page, a share card and flier (exist), their vote link and referral link, a short guide | Pages claimed, profiles completed |
| Voting | A campaign kit per stage (posts, WhatsApp text, flier), a live view of their own supporter count, reminders | Supporters reached, counted as people the way the scoring rule counts them |
| Before the ceremony | RSVP, guest tickets through their reference, travel and access needs | RSVPs, guests brought |
| After | Certificates, photos, the winner's or finalist's badge, a feedback form | Feedback returned |

Each stage is a set of work items for the award's team ("these 12 nominees have not claimed
their page") and a message sequence with its cost shown (9.14). Volunteers can own a group of
nominees (§7.3).

**The rule mobilisation must keep.** The platform's own help is **identical for every
nominee in a category**: the same kit, the same messages, at the same times. Nominees can
mobilise as hard as they like; the platform must never help one more than another, or it
becomes a party to the result. This extends "money buys the tally, never the reach" and D15
(no paid promotion of a nominee during voting) to everything the platform does itself. It is
D32, and a test holds it.

### 9.7 Proving it: three pilots before opening

Nothing opens to outside organisers on a belief. Three pilots, each measured:

| Pilot | What it proves |
|---|---|
| **1. An Africa GATES ceremony** | The award bundle end to end on our own award: timeline as plan, mobilisation, release at the cue, honours seating, the door greeting. |
| **2. A school or community award run by its own people** | Somebody who did not design it can run it unassisted (the five tests' repeatability), on budget phones, with a small budget. |
| **3. One event with no award** | The control: whether the free core holds an organiser on its own, or whether the award is the whole reason anybody stays. |

**Measured in every pilot:**

- at the door: zero lost scans, zero false refusals, queue time at peak;
- for the organiser: hours spent compared with their last edition, what they still did in a
  spreadsheet or WhatsApp, and whether they would pay, and how much;
- for nominees: pages claimed, kits used, supporters reached as people;
- afterwards: whether the organiser books the next edition.

**The decision each pilot feeds** is written down before it starts (9.20), so a result
cannot be argued into the answer somebody wanted.

### 9.8 The order of work

Events builds on the operating model (§6), volunteers (§7.3) and the one ledger (D8), so it
follows them in §14. Inside Events, each stage opens only when the one before has passed its
exit test:

| Stage | What | Exit test |
|---|---|---|
| **E0. Ready** | The prerequisites, none of them features: backups and a tested restore (§2 fault 16); legal advice on the NDPA and GAID (D27); retention running (D33); Paystack's `manual` settlement confirmed on the account, or the fallback in 9.5 chosen; a load test that measures this host's ceiling for an on-sale (9.9). | Each of the five has evidence: a restore drill's result, the advice in writing, a retention run in the log, Paystack's answer, a load-test report. |
| **E1. Award events, in house** | For Africa GATES's own awards and its centres: the award's timeline as the event's plan; tasks and milestones as work items; budget lines; templates and cloning; ceremony cues per category; results released at the cue; honours seating; nominee mobilisation kits (D32); the capacity check and the state permit checklist; the audit trail complete for events. | Pilots 1 and 2 pass (9.7); a cloned award event runs at a second centre with nothing configured beyond venue, date and lead. |
| **E2. The award bundle, open** | Outside award organisers: organiser scopes, verification, first-event review, **per-organiser settlement** to their own subaccount (new organisers after the event, 9.5), the award integrity rules (D34), per-edition billing and the share-of-votes option (D30). | One outside award runs end to end, is paid after its event, and has no unresolved report. |
| **E3. The door and selling, hardened** | Signed tickets and the revocation list (D25), rotating codes for high-value tiers, gateway failover, virtual accounts, SMS ticket retrieval, installments. | A pilot with the network deliberately cut at the gate: zero lost scans, zero false refusals. |
| **E4. The free core, open to anybody** | Self-serve sign-up for any organiser; attendee reports that pause sales; sales velocity limits; staff and volunteer shifts verified by scans; the incident log; the safety evidence pack; a live view by polling. | Pilot 3 passes; thirty days open with every report closed inside its deadline and no organiser paid for an event that did not happen. |
| **E5. Learn, then grow on demand** | The generated post-event report (attendance from scans, money from the ledger, hours from shifts, feedback from the form engine); messaging cost per event; AI change-sets. General planning tools (vendors and RFQs, change requests, sponsors and talent) are built **only when organisers ask for a specific one**, recorded as requests. | The post-event report needs no manual figures. |

Cashless wallets, escrow, the device mesh, native apps, multi-region hosting and expansion
markets wait for the decisions and evidence named in 9.9 and 9.20.

**Where each area of the source plan's feature catalogue lands:**

| Area | Stage | Note |
|---|---|---|
| Award planning, mobilisation, ceremony, release at the cue | E1, opened in E2 | 9.6; the product's lead |
| Project manager (templates, scheduling, dependencies, critical path, approvals) | E1 (on work items), more in E5 | WhatsApp task updates and voice-note-to-task only on demand |
| Budget and finance (four-value lines, commitments, cost per attendee, live P&L) | E1, E5 | Live P&L reads the ledger; price benchmarks need data first |
| Ticketing (tiers, codes, waitlists, transfers) | built | Installments in E3; group and table products and face-value resale limits on demand |
| Guest management (lists, plus-ones, dietary and access needs, seating) | E1 (honours), E3 | Walk-in approval and a host's live arrival view reuse the door |
| Access control (rotating QR, zones, re-entry, overrides) | E3 | NFC and the mesh wait for a native scanner |
| Safety and compliance | E1 (capacity, permits), E4 (evidence pack, incidents) | 9.15; never paywalled |
| Operations (run-of-show, shifts, dispatch, vendor check-in) | E1 (cues), E4 | Radio and call-tree fallbacks are runbook items, not code |
| Cashless | deferred | D29 |
| Vendors and venues (RFQs, quotes, contracts, scorecards, venue database) | E5, on demand | The market (§10) supplies verified vendors |
| Sponsors and talent | E5, on demand | Pipelines on work items; riders and travel as checklists |
| Communications | E1–E5 | 9.14 |
| Insights | E5 | The generated report first; benchmarks once there are events to compare |
| AI agents | E5 | 9.17; one agent at a time, each evaluated before it ships |
| Accessibility and language | every stage | 9.11 |
| Platform (API, webhooks, white-label editions) | after E5 | `/api/v1` and webhooks grow; editions are scopes and settings (§6), never code forks |

### Part B. How it is built

### 9.9 Built for this host

**The source plan's ten principles stand**, each mapped to something already in this
document: offline-first where the door, the stage and the cash are (9.12); Android-first,
low-end-first (9.11); money is a ledger (D8); never lose a scan, a payment or a decision (the
door's queue, the webhook's 200, the reconciliation sweep and the seal already follow it);
modular monolith (§5); privacy and safety by design (9.15, 9.16); people approve consequential
AI actions (§12); operable by a small team (§6); cost-aware by default (9.14); partner-ready
(scopes and templates in §6, the API in 9.8).

**The host.** One shared cPanel host in Nigeria: PHP-FPM behind Apache, MySQL on the same
machine, no shell, no long-running process, no Redis, no websockets, a job queue in a
database table, and one scheduled tick every 15 minutes through a Cloudflare Worker.
Cloudflare sits in front. Each part of the source plan's architecture, against what can run
here:

| The plan says | What we do on this host | When to revisit |
|---|---|---|
| TypeScript backend, Go for the scan gateway | **Stay on PHP 8.4 / Slim / Twig** (D24). One language and one deploy. | Only if the team is rebuilt from scratch. |
| PostgreSQL with row-level security | **MySQL.** No row-level security, so every events table carries `scope_id` (§6), and a test sweeps every query on those tables for a scope clause. | If a managed Postgres becomes affordable. |
| Redis, a durable queue (SQS, NATS) | **`gates_jobs`** drained on the tick. Anything that cannot wait 15 minutes (a gate change, a payment confirmation) is sent **inline, best-effort, with a queued retry**, as mail and SMS already are. | When the job table is the bottleneck. |
| Separately deployed scan gateway, payments service, messaging dispatcher, AI pool, realtime service | **Modules inside the monolith** (`Domain/Events/...`), each owning its tables. The scan endpoint stays a small, fast controller. | If one module's load is shown, by measurement, to hurt the rest. |
| Websocket realtime for live mode | **Short polling**: an open screen asks a small JSON endpoint every 10–30 seconds, only while visible, as the wallet already does. | When a command-centre screen with many viewers exists. |
| Kubernetes, infrastructure as code | **The host as it is**, plus repeatable deploys and migrations applied by URL. | With a host change. |
| OpenTelemetry traces | **Structured logs** with event and scope on every line, `CronHealth`, the status page, and alerts by webhook. | When there is somewhere to send traces. |
| Native Kotlin scanner and field apps; iOS later | **A PWA first** (D26): the door already runs in the browser, works offline and uses the camera. | After two pilots show the PWA failing on budget phones. |
| Device-to-device mesh between scanners | **Deferred.** A browser cannot form a local mesh. Signed tickets (9.12) make each phone safe alone, and duplicates across gates are settled at sync. | With a native scanner. |
| Event sourcing | **The existing audit log**, made complete for events. Undo where the domain needs it. | Not needed as a pattern. |
| Change data capture into a warehouse | **Nightly summary tables** written by the tick, and CSV exports. | When reporting outgrows the live database. |
| Table partitioning | **Archive tables**, moved monthly by the tick. | With a managed database. |
| In-country primary with a secondary site | **The host is already in Nigeria** (D22). Nightly backups to a second Nigerian location; anything sent abroad is encrypted and recorded as a cross-border transfer. A restore drill each quarter. | A standby site when revenue pays for it. |
| A virtual waiting room for on-sales | **Inventory holds** with expiry, the rate limiter, and Cloudflare caching of the event page. Cloudflare's Waiting Room needs its Business plan (9.2). | See the ceiling below. |
| Cashless NFC/RFID wallets, agent top-up booths | **Deferred.** Hardware, and holding attendees' money needs a licence. The member wallet is not a cashless wallet. | After licensing advice (D29). |
| Escrow for vendor payments | **Settlement through gateway subaccounts**, with holds on the platform's own fees only. | After licensing advice (D29). |
| Squads, platform teams, an architecture group | **One small team.** Streams of work, not squads. Decisions as short records in `docs/`. | When there are people to staff squads. |

**The ceiling, decided in advance.** One shared host has a ceiling, and the first time it is
found must not be during an on-sale. Stage E0's load test measures how many concurrent buyers
the host takes before checkout slows past its target (9.18). Then:

- an event whose expected on-sale is **below half that figure** sells as normal;
- **above half**, the organiser is told before scheduling the on-sale, and either the sale is
  staggered by tier and time, or the owner moves to Cloudflare's Business plan for its
  Waiting Room (or a bigger host) before it opens.

The figure and the rule are settings, re-measured after any host change.

### 9.10 The domain: one event, many workspaces

Events is one domain inside the modular monolith (§5.1), built on the operating model (§6),
because almost everything in "Plan" and "Operate" is **work with an owner and a due time**.

- **An event is a scope** (§6.1): Platform → Country → State → Centre → Event, or an outside
  organiser's scope → Event. Roles at the event are grants at that scope, time-boxed to the
  event: organiser, co-host organisation, door, stewards, stand manager, programme and MC,
  media, safety lead, volunteers.
- **Tasks, milestones and approvals are work items** in the event's scope, each with an
  owner and a deadline. Dependencies are links between items, and the critical path is
  computed, not drawn.
- **The source plan's "Event Graph" becomes ordinary tables**, added module by module, never
  all at once:
  - **Plan:** budget lines (estimate, committed, actual, paid), then on demand vendor
    commitments, risks and issues, decisions, change requests, permits.
  - **Operate:** run-of-show cues (built on `EventAgenda`), staff and volunteer shifts
    (§7.3), incidents.
  - **Sell / Admit:** today's tables, kept.
  - **Learn:** the post-event report.
- **Templates.** An event is cloned with its tiers, roles, run-sheet, task set, budget lines
  and forms, and an award event with its award's categories and timeline. 100 centres can
  each host a local event without 100 setups.
- **The audit trail** records every state change with actor, before and after, and source.
  The safety evidence pack (9.15) and the post-event report are built from it.

### 9.11 Phones and data

- **One web app, installable (a PWA)**, targeting Android 10 and up on low-end phones, with a
  performance budget enforced in tests: page weight, script size and time to usable on a
  throttled connection.
- **A small device shelf**: three or four budget Android phones (a Tecno, an Infinix, an
  Itel, one older Samsung) for every release that touches the door or the planner.
- **Data saver.** Images are resized server-side already; add compressed JSON, sync of only
  what changed, and media deferred until on Wi-Fi.
- **Guests without smartphones.** Printable tickets exist. Add an SMS link to retrieve a
  ticket, and an SMS RSVP. USSD needs a short code and an aggregator contract, so it waits
  until an event needs it.
- **Languages.** The translation layer (`|trans`) exists. Catalogues for Nigerian Pidgin,
  Yoruba, Hausa and Igbo first, French and Swahili for expansion, each checked by a native
  speaker before it ships.

### 9.12 The door and offline tickets (the critical path)

**Today's rule, and why.** An offline door phone records scans and does **not** decide
(`DoorOfflineTest`). The reasons are written in that test:

- an eight-character code is brute-forceable against a list of hashes in minutes, so a
  list on a volunteer's phone is the whole event's set of valid codes;
- a list downloaded at 18:00 would confidently refuse a ticket sold at 19:30.

**Signed tickets answer both, but only if the signature is public-key.**

- Each ticket carries a compact payload (event, ticket, tier, zones, valid window) **signed
  with Ed25519** (`sodium_crypto_sign`; confirm the host enables PHP's sodium extension).
  The phone verifies it in the browser, with a small vendored library where the browser
  lacks Ed25519.
- The door phone holds only the **public** key, so nothing on it can mint or recover a
  ticket. HMAC, used elsewhere here, cannot be: the phone would need the secret.
- A ticket sold after the door's last sync still verifies, because the signature does not
  depend on any list.
- The phone downloads a small **revocation list** (cancelled, refunded, transferred tickets)
  when online. A ticket revoked after the last download is the one remaining gap, caught
  when the scan syncs.
- **Duplicates across gates**, offline, are settled at sync: the earliest scan wins, later
  ones are logged with the reason, and a supervisor can override with a note.

This changes a deliberate rule in the code. **Decided (owner, 17 Oct 2026): yes** (D25). It
ships in stage E3, after a pilot with the network deliberately cut at the gate; until then
the door works as it does today.

**Also:**

- **Rotating codes** on the ticket screen, as `InvitePass` does for guests of honour, so a
  screenshot stops working. **High-value tiers only**, checked online, because checking a
  rotating code offline needs a per-ticket secret on the door phone.
- **Clock safety.** The door records the phone's clock and the server's offset at every sync.
- **Power.** A low-power scan mode (camera off between scans, screen dimmed), the printable
  paper list per gate that exists today, and power banks in the event runbook.
- **Testing offline.** Network cut-offs, clock skew, duplicated and reordered syncs, and a
  phone killed mid-sync, in tests and then in the network-cut pilot.

### 9.13 Money for events

Everything goes through the **one payment ledger** (D8). Stand fees fall outside every
backstop today, which is the Phase 0 lesson.

- **Per-organiser settlement** (stage E2): each organiser's ticket and vote sales split to
  their own subaccount at payment time, replacing the single `events` destination
  (`PaymentDestination`) for anybody but Africa GATES. The platform's fee is the split's
  platform share. A new organiser's subaccount runs on `manual` (9.5).
- **Two gateways exist** (Paystack primary, Flutterwave). Add **failover**: if one gateway's
  start-payment call fails or its health check is red, offer the other.
- **Dynamic virtual accounts** (one per order, through Paystack) for exact bank-transfer
  matching: a gateway feature to enable, not something to build.
- **Installments for tickets**, reusing the recurring-payment code from giving
  (`RecurringGiving`). The ticket is issued when the last payment clears, and a missed
  payment releases the seat after a published grace period.
- **FX:** store the currency, the amount and the rate's source and time on every payment from
  the start, even while only naira is sold.
- **Fraud on sales:** velocity limits per buyer and per card, refund and chargeback watching,
  and the wallet's risk checks (`WalletRisk`) applied to any payout the platform itself makes.
- **PCI:** card data never reaches this server; the gateways' hosted pages ensure it.
- **VAT** (7.5%) on the platform's own fees, shown on the organiser's statement.

### 9.14 Messaging, with costs shown

`SmsService` and the mail transport exist. What Events adds:

- **A cost table** per channel and provider, held in settings, so the console shows
  **"this send will cost about ₦X"** before it goes, and records the cost of every message.
- **A per-event messaging budget**, checked before a send, as `SendPolicy` already checks
  rules before mail goes out.
- **Cheapest effective channel per person**, from their consent and preference: email first,
  then WhatsApp utility templates, then SMS.
- **WhatsApp templates labelled utility or marketing**, because they are priced differently
  (the source plan reports about USD 0.01 and 0.06 per delivered message in Nigeria; check
  Meta's current price list before relying on it).
- **One consent record per person per channel**, with when and where it was given, built on
  `Suppression` and the opt-out list. Quiet hours per event.
- **Critical messages** (gate change, cancellation) are sent inline with delivery receipts,
  not left to the 15-minute tick.

### 9.15 Safety and compliance

Safety is in the free core and never paywalled: an unsafe event on the platform is a risk to
people first and to the platform's name second.

- **A capacity and density calculator** per venue area, tied to the tier and event capacity
  limits that already exist, so tickets cannot be sold beyond the safe figure.
- **State rules as data.** Each state's thresholds and permits, with the authority, the lead
  time and the date the rule was last checked. Lagos first, from 9.2: registration with the
  Lagos State Safety Commission above 250 expected guests, an Event Safety Permit, a noise
  permit, and trained safety marshals above 500. When an event's capacity crosses a
  threshold, the matching permits become work items with an owner and a deadline.
- **Incident log** at the event: what, where, when, who responded, outcome.
- **An evidence pack for the authorities**, generated from the event's records: capacity,
  door counts by time (from scans), incidents, marshals and stewards on shift.
- **Emergency and medical plans** as checklists on the event template, including the
  ambulance and medical cover large events are asked for.

### 9.16 Privacy, and what not to build

- Nigeria's NDPA 2023 and the GAID 2025 apply: registration as a controller "of major
  importance" by tier, a Data Protection Officer, impact assessments, breach notification and
  annual compliance audit filings (9.2). **Legal advice first** (D27), which also settles
  what outside organisers agree to as controllers or processors of their guests' data.
- **Data classification** for event data: public, internal, personal, sensitive (bank
  details, health and access needs). Each class has its own handling and retention (D33).
- **Guest data on a need-to-know basis.** Planners see counts and segments; seeing an
  individual guest is logged. The door already cannot show the guest list.
- **Biometrics: do not build** (D28).

### 9.17 AI for events

Every AI call goes through the one gateway (§5.1, `AiGateway`) with a per-event spend cap. The
platform keeps working when AI is unavailable or over budget, falling back to templates and
rules. AI is stage E5, after the product works without it.

- **Agents propose, people approve** (§12). An agent writes a **change-set** (these tasks,
  this budget move) that a person approves; nothing is applied silently, and financial and
  contract changes never without approval.
- **Grounded in the event's own records**, not general knowledge.
- **Local reference data** (lead times, price ranges, vendor categories in Nigerian cities),
  each item with its source and the date it was last checked.
- **Evaluated before it ships**: past events replayed and the agent's suggestions scored, as
  Gee's scenario suite works today.
- **Customer data is not used to train shared models** without opt-in.

### 9.18 Targets that fit the host

The source plan's SLOs assume a multi-node cloud. On one shared host:

| Objective | Target here | The source plan's target |
|---|---|---|
| Door decision on a low-end phone | p95 under 300 ms, works offline (signed tickets) | same |
| A scan reaching the server after reconnecting | within 60 seconds | same |
| Checkout availability | 99.5% monthly | 99.95% |
| Checkout under load | p95 under 2 seconds up to the measured ceiling (9.9) | — |
| Payment confirmed to ticket delivered | p95 under 60 seconds by webhook; under 15 minutes by the reconciliation tick | p95 under 30 seconds |
| Critical messages | sent inline, within 60 seconds of the action | 99% within 60 seconds |
| Recovery point | 24 hours (nightly off-site backup), aiming for 1 hour if the host allows | 5 minutes |
| Recovery time | 4 hours, from a rehearsed restore | 1 hour |

**Event mode** applies on event days: no deploys from the day before, the cron and the status
page watched, and a named person on call.

### Part C. What could go wrong

### 9.19 Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Scope grows across too many modules | High | High | One position (9.1); stages that open only on an exit test (9.8); general tools built only on recorded demand |
| A fake event sells tickets on the open platform | High | High | Verification before selling and before payouts, first-event review, new organisers paid after the event at the gateway, reports that pause sales (9.5) |
| An award charges people to be honoured, under our name | Medium | High | D34, enforced at setup and in review; a named, verified organiser on every award's page |
| Mobilisation help favours one nominee | Low | High | Identical kits, messages and timing per category, held by a test (D32) |
| Offline door wrongly admits a duplicate or refuses a paying guest | Medium | High | Signed tickets, sync-time duplicate settlement, supervisor override, the network-cut pilot before any large event |
| The shared host saturates on a big on-sale | Medium | High | The measured ceiling and the rule in 9.9; caching, holds and rate limits |
| A gateway outage during an on-sale | Medium | High | Failover to the second gateway, inventory holds, the reconciliation tick |
| Paystack will not enable manual settlement | Medium | Medium | The weekly-with-a-cap fallback in 9.5; never the platform's own account |
| Organisers will not pay for the award bundle | Medium | High | Pilots measure it before E2 (9.7); the share-of-votes option for those who cannot pay up front |
| One person holds the knowledge | High | High | The process registry and generated playbooks (§6), runbooks per module |
| Data-law or safety rules change | Medium | Medium | Rules as data with review dates (9.15); legal review before opening |
| Messaging or AI costs overrun | Medium | Medium | Cost preview, per-event budgets, spend caps |

### 9.20 What would change this plan

Written before the pilots, so their results decide rather than persuade:

- **If pilot 3 (no award) keeps its organiser as well as the award pilots do**, the free core
  is a product on its own: bring stage E4 forward.
- **If organisers in pilots 1 and 2 would not pay for the award bundle** at any price that
  covers its running cost, keep the award features for Africa GATES's own awards and centres,
  and stop before E2.
- **If the PWA door fails on the device shelf or in the network-cut pilot**, build the native
  Android scanner (D26) before E3 opens to outside organisers.
- **If the load test puts the ceiling below the on-sales the pilots need**, the host decision
  in 9.9 comes before E2, not after.
- **If legal advice says outside organisers make Africa GATES a controller of their guests'
  data in a way the platform cannot meet**, open the award bundle only to organisers who sign
  the processing terms, and hold E4.

---

## 10. The market: buying, selling, highlights and promotions

**Today there are four separate models**, each with its own payments:

- the platform shop (products, variants, orders, codes);
- vendors (`VendorAccount`, vendor items);
- event stands;
- partner organisations' giving pages.

There is also a banner system (`PromoService`) commented as being for one community's
pages. People cannot sell, and they cannot highlight their own products or businesses.

**Target: one Market domain**, built on the person, the ledger, the form engine and the
work items.

- **Sellers.** Any person or business can sell after verification, which has three levels:
  1. confirmed email and phone;
  2. an identity or CAC registration check;
  3. a settlement account, which is a gateway subaccount. Split settlement already works for
     partner organisations (`PartnerOrg`).

  Each seller has a storefront page built from presets, the way `OrgBrand` already builds
  an organisation's page.
- **Listings: one model with kinds.**
  - Products, physical or digital.
  - Services.
  - Stands and spaces.
  - Event tickets.
  - ~~Highlights~~: retired before it shipped (owner, 27 Oct). There is no free promotion;
    the only free thing is the ads trial (below).

  Each kind, and each market category, has its own **listing form from the dynamic form
  engine**. So "what a listing asks for" is configurable exactly as nomination forms are.
- **Trust.**
  - A new seller's listings are reviewed (a work item with an owner and a time limit) until
    their record earns publish-then-spot-check, the same trust ladder as volunteers.
  - There is a prohibited-items policy, and people can report listings.
  - **Reviews are accepted only from verified buyers.**
  - Disputes are work items with a time limit.
  - A seller's money is held until delivery is confirmed or the window passes. Payouts
    already hold funds on an unknown status (`OrgPayout`), and this is that rule
    generalised.
- **Orders.** One cart across sellers, split settlement, a platform fee per listing kind
  (a setting, with one reader), **one payment ledger** (D8) and one refund path.
- **Promotions are ads, built the way Meta builds them** (owner, 27 Oct; `Services\Ads`).
  - **Campaign → ad set → ad.** The campaign has the objective (be seen, get visits, sell)
    and the advertiser's name; the ad set has the placements, countries, window, budget and
    the rate it was booked at; the ad is one standard format (square image, headline, a line
    of text, a button, an optional customer offer and code).
  - **Placements:** the vote page's list of awards (never a ballot, a nominee or a result),
    Discover, the events list and event pages. Pulse and the newsletter are next.
  - **A published rate card**, naira per 1,000 views, the same for everybody, set in the
    console and printed on `/advertise`. An auction can be switched on per placement later.
  - **Prepaid, no wallet.** Each campaign is one payment; what it does not spend goes back
    to where it came from. Nothing holds a balance.
  - **Free trial only:** one per account, 7 days or 1,000 views, whichever comes first,
    starting when a person approves it. Same review, label and reporting as a paid ad.
  - **Reviewed before going live**, by a person, with automatic flags beside the ad.
    **Always labelled "Sponsored"**, with who paid and "Why am I seeing this?".
  - **A view is counted by the browser**: half the ad on screen for a second, once per
    drawn ad (single-use token), so a replayed beacon buys nothing. Chosen by page and
    country only, never by what a visitor did, so the cookie choice changes nothing.
  - **Nominees may advertise what they do** under four rules: never about the award; any
    offer open to everyone and never tied to voting; off the vote page while their own award
    is open; the same price as everyone.
  - **Not yet:** paid checkout, which needs advertising money to have its own stream in
    CACENTRE finance before it can be taken (`CfisCatalogue`), and a VAT decision.
  - **Codes:** one promotion-code system replaces today's three (event codes, shop codes,
    `PromoCode`).
- **Promotions never touch awards' results.** The rule "money buys the tally, never the
  reach" stands. A promotion may advertise an award or an event; whether it may promote an
  individual nominee during voting is your decision (D15).
- **Discovery.** The market is a tab in Discover, with search, categories and a local view
  ("near you", by state and centre).
- **Messaging.** Buyers and sellers message each other through the platform, and contact
  details stay private.

**Order of work.** The ads trial first (no money moves: built 27 Oct), then paid campaigns
once finance has an advertising stream, then sellers and listings on the ledger. Each step is
usable on its own.

---

## 10b. Community Return: what participation gives back

**Status (18 Oct 2026): built, and ahead of this section.** What follows is the brief and
the plan as written on 16 Oct; since then the owner answered its questions and it shipped:

- **Models, chosen per edition and opt-in:** the original threshold model, the two-stage
  **milestone** model of the brief, and three ladder models (**flat**, **tiered**, and
  **custom**, which may be measured on the nominee's own total or the whole edition's and may
  be held locked until the milestone). `CommunityReturnService`, `CommunityReturnLadder`,
  `CommunityReturnMilestone`; `/admin/community-return`.
- **The milestone** is total eligible paid-vote revenue in the edition, confirmed by a person
  as an audited act, exactly as planned.
- **A nominee's own buying counts.** So the per-supporter cap applies only under the original
  threshold model; the milestone and ladder models have none. (This is the opposite of D20's
  recommendation, and it was the owner's call.)
- **The wallet** is now for every member, not only nominees: community return per claimed
  page, referral earnings and voting points as pockets, a full history with receipts,
  notifications and a live balance (`MemberWallet`, `/account/wallet`).
- **Withdrawals are automatic**, not a work item. A request that passes every check
  (`WalletRisk`: the money is valid and unflagged, the bank confirms the account is in the
  member's name, no shared account, no recent password reset or new bank details, an
  established account, and per-withdrawal, daily and platform limits) is sent by Paystack
  transfer at once (`WalletAutoPay`). Anything doubtful is held for a person with the reason
  written on it. The ledger moves only on the gateway's confirmation.
- **One banded withdrawal fee** for every withdrawal (`WalletFee`; ₦10 / ₦25 / ₦50 by
  default), set in Settings and frozen on each request.
- **The published rules** on `/integrity` are generated from the same settings, as planned.

What is still open is legal and tax advice on paying nominees (D21) and the milestone
target per edition, which operators set.

**The brief (owner, 16 Oct).**

- Participants build up a share of the paid-vote money attributed to them, in two stages:
  - **before the collective milestone**, a **10%** credit per eligible paid vote, held
    **locked** in a digital wallet;
  - **after the milestone is officially confirmed**, the locked credits become eligible for
    withdrawal, and every later eligible vote pays **50%**, from that point on only.
- Withdrawal needs:
  - milestone confirmation;
  - identity verification;
  - a payout account the participant owns;
  - valid transactions (not fraudulent, reversed or refunded);
  - compliance with the published rules;
  - a payout review.
- The rules must be published before the programme opens. They must cover the minimum
  withdrawal, fees, timelines, tax, appeals, and how unclaimed or disputed balances are
  handled.

**What exists today** (`CommunityReturnService`, `gates_community_returns`). There is an
audited ledger of accrual, reversal, adjustment, hold, release, forfeit and payout, all
traced to confirmed contributions, with a statement and a balance. But the model is
different from the brief:

- the threshold is **individual** (250 votes per nominee), not collective;
- **nothing** is earned before it;
- 50% is paid after it.

There is also a useful anti-gaming rule: each supporter counts toward the threshold only
up to 10% of it, so at least ten different people are needed.

**Target.** The ledger stays. These change:

1. **Two rates and one collective milestone**, per programme or edition through
   RuleEngine: the pre-milestone rate (10%), the post-milestone rate (50%), and the
   milestone itself. That means its measure (for example total eligible paid-vote revenue
   in the edition), its target, what counts toward it, and how refunds and fraud are
   subtracted.
2. **The milestone is an act, not a number crossing a line.** The system shows when the
   target is reached, and an authorised person **confirms** it with the figures, as a
   work item, audited. That confirmation is the moment the rate switches to 50% and locked
   credits become available. This mirrors how results are released rather than published
   on a date.
3. **The wallet shows each status separately:** pending (payment not confirmed), locked,
   available, under review, paid, plus reversed and forfeited. Each line traces to its vote
   and rate. A locked credit is never presented as cash.
4. **A withdrawal is a work item with the six checks.** Identity and account ownership
   reuse seller and person verification (§7, §10). Payout uses the gateway transfer path
   `OrgPayout` already uses, including its rule that an unknown gateway status never
   releases money.
5. **The published rules are generated from the settings**, as the handbook is. The
   percentages, milestone, minimum, fees and timelines on the public page are read from
   the same values that do the arithmetic, so they cannot drift apart.
6. **Notifications** when the milestone is reached and confirmed, when a balance becomes
   available, and when a withdrawal needs action or is paid.
7. **Scoring is untouched.** A Community Return credit is money, never votes, and never
   enters the results.

**Decisions this needs.**

| # | Question | My recommendation |
|---|---|---|
| D18 | Who is the "participant": the nominee only, or also the people who campaign for them (volunteers, ambassadors, referrers)? | **The nominee first.** Campaigners later, through volunteer tasks with verified outcomes (§7.3). |
| D19 | What is the milestone's measure and target for the first edition? | Total eligible paid-vote revenue in the edition, at a figure you set. |
| D20 | Does the individual 250-vote line and the 10% cap per supporter stay alongside the collective milestone? | **Keep the cap** as the rule for which votes are eligible (it stops one buyer manufacturing a return). **Drop the individual line**, which the collective milestone replaces. |
| D21 | Minimum withdrawal, fees, payout timeline, tax treatment, appeals, and unclaimed balances? | These must be set before the programme opens. Take legal and tax advice on revenue-sharing with nominees in Nigeria first. |

---

## 11. Experience architecture: the UI/UX structure

**The problem.** Today's experience is a list of pages, not a structure.

- There are four navigation sources and two design systems.
- Components are built page by page, and the same job is done by two screens.
- A person with three roles has three homes.
- Token links lead nowhere once the job is done.

The tailoring pieces already exist (the account hub, `MenuShortcuts`, `MemberInterests`,
`GeePersonal`, the display and low-data settings, the award wording), but each tailors one
corner.

### 11.1 Five spaces, each with a clear audience

| Space | For | What it holds |
|---|---|---|
| **Public** | Everyone | Discover, awards, events, the market, community, help |
| **My Africa GATES** | Each person, **one home** | Panels that appear by role: voting, my nominations and my work, giving, tickets, orders, selling, volunteering, my organisation. Today's `/account`, `/org` and the token pages land here when signed in. |
| **Workspace** | Staff and volunteers | The console, scoped by grant. A volunteer sees their scope's queues; a director sees everything. One console, not two. |
| **Field tools** | People working on site | Door, stand check-in, capture: full-screen, phone-first, tolerant of a poor connection |
| **Judge room** | Judges | Stays separate, as you have held it |

### 11.2 One information-architecture registry

- Every page declares:
  - its space;
  - its audience;
  - where it sits in navigation;
  - its gate.
- The header, tab bar, menu sheet, footer, console rail and command palette are all
  **generated** from that registry, as the console's rail already is from `AdminNav`. A
  page cannot be orphaned or offered to someone who cannot open it.
- Sweeps:
  - no two pages for one job;
  - every page has a way back to its space's home;
  - every token link has a signed-in destination.

### 11.3 One design system, two densities

- **Public is comfortable and the console is compact**, and they share components: button,
  card, chip, empty state, action bar, back control, and every form field (the field
  library from §8 *is* the form component set).
- Colour stays in `Accent` and type stays on the ladder.
- **Page-local components are ratcheted down**: today there are 22 empty states, 4 action
  bars and 8 back buttons.
- **Class prefixes are owned per domain**, so `.hm-` cannot mean two things again.

### 11.4 Tailored, not generic: four rules

Every surface is tailored on four axes, and each surface's spec states all four:

1. **Who:** the person's roles and memberships. They decide which panels, actions and
   wording appear.
2. **Where:** the award's profile look, the event's theme, the centre's locale and the
   language.
3. **What they care about:** their interests, follows, recent activity, and the
   low-data and display settings.
4. **When:** the phase. The same home shows "nominate" in nominations, "vote" in voting
   and "results" when a result is released, read from the cycle phase and never typed in.

Each surface also states **the next action for each audience**. Empty states are generated
with that next action, so no screen ever ends without something to do.

### 11.5 Journeys

Each audience gets one journey map, with one next action at every step:

- visitor, voter, paid voter, nominator, nominee;
- donor, attendee, buyer, seller;
- volunteer, organisation, staff.

The maps sit in `docs/` for whoever changes the code. The handbook carries the
operational half.

---

## 12. Gee: an assistant that acts

**What happened in your conversation.**

- Gee has a tool for the signed-in person's own payments (`my_transactions`,
  `SupportContext.php:393`), but offers it only when it recognises the visitor as a signed-in
  member.
- Its loop ends on the first reply that is not a tool call (`SupportAgentService.php`,
  around line 171).
- So "I will check your payments, please hold on" was accepted as a finished answer three
  times. No check was ever made, and the frustration ("Hurry", "you are not doing anything")
  led only to a ticket.
- Nothing detects a promised action that never happened. The suggestion chips came from the
  topic of money, not from what you asked, which is why a refund conversation offered "why
  the nominee with the most votes may not win".

**Fixed on 16 Oct** (commit "Gee: a promise to check is not an answer"):

- A reply that announces a check instead of making it is sent back once, with the tools
  still offered. A second promise is discarded and the tools' own results stand in for it.
- A signed-in member's own payments are looked up before the model is asked anything.
- A follow-up such as "Hurry" is planned from the conversation, not from the one word.
- Suggested articles follow the conversation.

Five tests hold these fixes, and each was checked by breaking the code and watching the
test fail. The rest of this section is the remaining work.

**What the research says about agents like Gee.** The best support agents converge on
the same design:

- **Instructions plus fixed controls.** Intercom's Fin runs "procedures" that combine
  natural-language instructions with deterministic steps and data connectors. Engineering
  decides which fields the agent may read, and sensitive values are injected rather than
  typed by the agent. For Gee, `SupportPlan` already plays the fixed part. It becomes a set
  of declared procedures, one per situation in the playbooks, each with its tools and its
  permitted fields.
- **Rate every tool by risk.** OpenAI's guide to building agents rates each tool low,
  medium or high by whether it only reads and whether it can be undone. It hands over to a
  person after repeated failure to understand, and for high-risk actions such as large
  refunds. ChatGPT's agent asks for confirmation before high-impact actions, lets the
  person take over for credentials, and refuses high-risk actions such as bank transfers.
  Gee's tiers below follow this.
- **Check the outcome, not the stop signal.** Agents should take ground truth from the
  environment at each step, and the harness should check results instead of trusting the
  model's own "done". That is exactly the fault fixed on 16 Oct, and it generalises: every
  answer about money is checked against the records the tools returned. The grounding
  check already does this for references and amounts.
- **Measure reliability, not luck.** Sierra's τ-bench grades a support agent by the
  database state after the conversation and by whether it followed policy. It reports
  pass^k, the chance that *all* of k attempts succeed. Early models passed fewer than half
  the tasks, and about a quarter when the same task was repeated eight times. Gee's
  scenario suite is graded the same way: each scenario runs several times, and passes only
  if every run reaches the right records and the right offer.

**Cloudflare's assistant did eight things right.** Each becomes a rule Gee is tested
against:

1. **Act, then answer.** A reply that announces an action instead of taking it ("I will
   check", "hold on", "let me") is refused by the loop. The model is told to call the tool
   or to say plainly that it cannot. The target for these replies in the scenario suite is
   zero.
2. **Show the work.** Each step is shown as it runs ("Checked your payments: 3 found, 1
   confirmed with no votes delivered"), and the facts are kept separate from the
   suggestions.
3. **Know who it is talking to.** Identity is passed explicitly. When Gee cannot see an
   account, it asks for the payment reference or offers sign-in instead of guessing.
4. **Diagnose with evidence, most likely cause first**, then give concrete next steps.
5. **Ask before acting, specifically, scaled to the risk:**
   - **reading** needs no permission;
   - **a reversible action** is offered as a button ("Re-check this payment with the
     gateway?");
   - **money or identity** is a specific proposal plus a person's review ("I can open a
     refund request for order AFG-…, ₦2,000, 10 votes not delivered. Shall I?"). Gee files
     the request; it never moves money itself.
6. **Hand over warmly**, with the diagnosis attached, and only when it cannot go further or
   the person asks. Frustration speeds things up; it never causes a repeat.
7. **Suggestions follow the intent** of the conversation, not the topic.
8. **Professional, specific, no filler.**

**Tools to add:**

- re-verify a payment with the gateway (the reconciler already does this);
- check whether a paid order's votes were delivered;
- open a refund request (a work item);
- order and ticket status;
- a volunteer's shifts;
- market order help;
- for staff, the status of any work item they own.

**How it is held.** A scenario suite of recorded conversations is replayed against Gee on
every change, for example "my payment didn't go through", signed in, with one failed and
one confirmed-but-undelivered order. The suite checks:

- that the right tool was called;
- that the diagnosis matches the records;
- that the right action was offered;
- that no reply promised an action without taking it.

---

## 13. How the large platforms do it, and what we take

These companies operate at a scale Africa GATES will not need to copy, so the question for
each practice is the same: what is the idea underneath, and does it survive on one app on
shared hosting? Where it does, it is already written into the sections above.

### 13.1 Structure: separate the place people use from the place people run

| Company | What they do | What we take | Where |
|---|---|---|---|
| **YouTube** | Viewers use the YouTube app; creators run their channel from **YouTube Studio**, a separate workspace for content, analytics and money. Monetisation is earned: a channel qualifies at a **measured threshold** (1,000 subscribers plus 4,000 valid watch hours in 12 months, or 10 million Shorts views in 90 days), and then passes a **policy review** before it is accepted. | The public space stays for the public, and running things happens in the Workspace and the person's home. **Qualification is a computed threshold followed by a human review**, never one or the other alone: volunteers' levels, sellers' trust and promotion eligibility all work this way. | §7.3, §10, §11.1 |
| **Meta** | **Business Suite** is one hub for a business across its pages, messages and ads, with roles (and advice to keep at least two admins). Ads are structured as **campaign → ad set → ad**: objective, then audience, budget and placement, then creative. Marketplace added a **free identity check** with a visible badge, and a separate seller app for people 18 or over in good standing. | One seller hub inside the person's home, not a separate product. Promotions use the same three levels: a campaign has the objective and budget, a placement set has where and when, and a creative has what is shown. Seller verification is visible as a badge whose meaning is stated, with an age and good-standing rule. **Two named leads per centre**, so no scope depends on one person. | §10, §7.2 |
| **Google** | **Material 3** builds screens from three canonical layouts (**list-detail**, **supporting pane**, **feed**), with navigation that changes from a bottom bar to a rail to a drawer as the window grows. | Every screen is one of the three. Console queues and the person's orders and tickets are list-detail. Discover, Pulse and the market are feeds. Award, event, nominee and product pages are supporting pane. A screen that is none of these needs a reason. | §11.3 |
| **OpenAI** | The agent asks for confirmation before high-impact actions, hands control to the person for credentials, and refuses high-risk actions. Tools are rated by risk. | Gee's risk tiers, and staff tools built the same way. | §12 |

### 13.2 Grouping: the user's mental model, tested before it ships

Information architecture practice is settled on two steps:

1. **Card sorting.** About 15 people per audience group the site's content in the way that
   makes sense to them, and the groups and names they choose show their mental model.
2. **Tree testing.** About 50 people find things in a text-only version of the proposed
   structure, which tests the labels and grouping without any visual design.

Card sorts can mislead if taken literally. People make a catch-all pile for leftovers that
nobody would click on as a real menu.

**What we take.** Phase 3's navigation registry is not designed at a desk. It begins with
an open card sort for each audience (voters, nominees, nominators, donors, attendees,
buyers, sellers, volunteers, staff), run with real people in Nigeria. The result is
validated by a tree test before any menu ships. There is no "More" bucket. Today's 12/4/15/29
link menus are replaced by whatever the tests show.

### 13.3 Measuring the experience

Google's **HEART** framework measures UX in five categories: happiness, engagement,
adoption, retention and task success. Each is tied to a goal through
**Goals → Signals → Metrics**, and only the categories that fit a surface are used.

**What we take.** Every surface's spec (§11.4) names its goal and its HEART signals.
Examples:

- **Nominate:** task success, meaning submitted and then approved, plus the drop-off by
  step.
- **Vote:** task success, meaning a code is received and a vote counted, plus time to vote.
- **Seller hub:** adoption, meaning the first listing within seven days, plus retention.
- **Volunteer home:** retention, meaning a second shift, plus reliability.
- **Gee:** task success, meaning resolved without a ticket, plus happiness from a one-tap
  rating.

The figures come from first-party events that honour the cookie choice. Engagement is left
out of staff tools, which people use because it is their job.

### 13.4 Management: reliability, goals and learning

| Practice | What it is | What we take |
|---|---|---|
| **SLOs and error budgets** (Google SRE) | A target for how reliable something must be. The shortfall allowed is a budget, and when the budget is spent, feature work slows for reliability. | Every process step's time limit (§6.1) is its SLO, measured from work items. Each centre and each process has a monthly budget of breaches. A spent budget shows on the dashboard and pauses new templates for that process until it recovers. |
| **Blameless postmortems** (Google SRE) | After an incident, the write-up finds the underlying cause without blaming a person. | Incidents (the status page and alerts already open them) get a postmortem form in the console: what happened, why, what changes, the owner, and a due date as work items. This repo's own CLAUDE.md is a postmortem log in prose, and this makes it a process. |
| **OKRs** (Google) | Objectives with measurable key results. **Committed** ones are expected to score 1.0, and a miss needs an explanation. **Aspirational** ones average 0.7. Mislabelling either causes problems. | Each centre and each programme sets OKRs per edition, read from the same work items and HEART metrics, marked committed or aspirational, and shown on the dashboard. Management sees status without asking anyone. |

### 13.5 What we will not copy

Some of what these companies do only makes sense at their size, so we leave it out:

- microservices;
- multiple data centres;
- machine-learned feeds;
- custom build systems;
- review boards for every change.

The ideas above survive without any of them. They are rules about structure, measurement
and ownership, and each is enforced here by a test or a work item rather than by
headcount.

---

## 14. The phased plan

Each phase ships on its own, keeps the site working, and has an exit test. None needs SSH
or a new host. The order follows the dependencies: each domain is built on what the phases
before it lay down.

| Phase | What | Depends on | Exit criteria |
|---|---|---|---|
| **0. Stop the bleeding** | **First, backups and a tested restore (§2 fault 16, §5.5)**, because every later phase changes data. Then the open faults in §2, rebuilding `nominee-confirm` and `shop/order` first: the 15 missing pages (starting with `/my-work`), shop CSS/JS, the wrong table name, the `payment_ref` index (repair migration), transactions on money writes, the dead settings table, the Pulse fragment, stand fees in the webhook and reconciler, and the two documents stating the retired rule. (Gee's promise-without-action was fixed on 16 Oct.) Add the "every route renders" sweep and turn the suite green. | — | Every public route renders. The suite and a MySQL parity run are green. Gee's scenario suite has zero unkept promises. |
| **1. Kernel and operating model** | Settings registry, Actor, Reporter, Http client, one rate limiter, one Twig factory. Then the operating model: process registry, work items, evidence, scopes and templates. Today's queues (nominations, claims, tickets, refunds, stands, moderation) move onto work items **behind their existing screens**. | 0 | Every queue is a view over work items, and every item has an owner and a due time. The §6.2 table is printed by the registry. |
| **2. Forms and award profiles** | The one form engine and the award profile (§8): definitions and versions, field library, interpreter, rules into the registry, look presets, the Award setup screen, house form v1. | 1 | Today's form renders identically from its definition. A second award with a different form passes end to end. |
| **3. Experience structure** | The IA registry, generated navigation, **My Africa GATES** as one home with role panels, the scoped Workspace, one design system in two densities, and `AssetBundle` served. | 1 | Every nav surface is generated. One home per person. The page-local component count is falling. |
| **4. Volunteers** | §7.3 on work items, grants, evidence and forms: apply, screen, train, activate, shifts and tasks, hours, levels, certificates. | 1, 2, 3 | A volunteer can be recruited, trained, given a scoped grant, work a shift verified by scans, and see verified hours, all without staff doing anything by hand except the screening decision. |
| **5. Africa GATES Events** | §9, award events first (§9.1), in six stages (§9.8), each opening only when the one before passes: E0 ready (backups, legal advice, retention, Paystack manual settlement, a load test of the host's ceiling); E1 award events in house (the award's timeline as the plan, mobilisation, ceremony cues, release at the cue, honours seating, capacity and permits); E2 the award bundle open to outside award organisers (verification, per-organiser settlement, integrity rules, per-edition billing); E3 the door and selling hardened (signed tickets, failover, virtual accounts, installments); E4 the free core open to anybody (reports, velocity limits, shifts, incidents, the evidence pack); E5 learn, then general tools on demand. Three pilots decide the openings (§9.7). Stands onto the ledger. | 0 (backups), 1, 4, and the ledger from 6 for E2 | Each stage's exit test in §9.8. The last: a post-event report with no manual figures. |
| **6. Money and the market** | One payment ledger first (D8), bringing the wallet's streams onto it (Community Return, the member wallet and automatic withdrawals are **already built**, §10b); then highlights (no money); then sellers, listings, orders, reviews and disputes; then promotions and one code system (§10). | 1, 2, 3 | Every payment stream writes to the one ledger. A verified seller lists, sells, is paid out after the hold, and a dispute resolves through a work item. |
| **7. Domains and data** | Continue moving code into `Domain/*`, thin the controllers, unify a person's identity (D6), one schema source, votes stop cascading on delete, scenario hardcodes move into data, dead paths removed. | all | The write-ownership and controller ratchets are at zero. Schema parity runs on every test run. |

Gee improves alongside every phase: each new domain brings its tools to the Assist core,
with scenarios in the suite.

---

## 15. How the work stays safe

- **No big bang.** This is the strangler-fig pattern: the new path is built beside the old,
  traffic moves, and only then is the old path deleted. Every phase is a set of normal,
  shippable commits.
- **MySQL parity for every data change.** Every migration runs on real MySQL
  (`scripts/mysql-parity.sh`) before it ships. Corrections are repair migrations, never
  edited definitions.
- **Your five tests are checked, not trusted.** The process registry refuses a process
  with a missing owner, deadline, evidence level or scope, so a new process cannot be added
  in a way that fails them.
- **Behaviour tests before moves.** Before a domain moves, its flows get HTTP-level tests
  (today only 6 files are feature tests). The move must leave them green.
- **No access change hidden inside a restructure.** Permissions are diffed by the existing
  `AdminNavTest` mapping on every move.
- **Ratchets, not rewrites.** Each "may only go down" count is recorded today, so progress
  is visible and backsliding fails the build.

---

## 16. Decisions for you

| # | Question | My recommendation |
|---|---|---|
| D1 | Should existing awards keep today's nomination form as their version 1? | **Yes**: nothing changes until an operator edits an award. |
| D2 | Should system fields (nominee, kind, categories, nominator contact, consent) be locked on every form? | **Yes**: duplicate checks, triage, judging and claims rely on them. |
| D3 | How far does an award's look go? A closed set of presets on the house palette, or free colours and fonts? | **Presets only.** Free colours break the contrast rules the site is held to and cannot be checked. |
| D4 | Can each **category** have its own extra questions (as Award Force allows), or only each award? | **Yes, as a field condition** ("only for these categories"). It is cheap once conditions exist. |
| D5 | Judging pages sit under three permission gates. May the Award setup screen *regroup* them? | Link to them, don't move them, unless you want to change role access deliberately. |
| D6 | Person identity is spread across six tables. Should Phase 7 unify them around one person record? | Yes, but it is the largest change in the plan, so it goes last and gets its own review. |
| D7 | Should the `/__setup` tool (about 1,700 lines of HTML inside `routes.php`) move into the console? | **Yes**: it resets superadmin passwords, which is a console job behind the console's audit log. |
| D8 | One payment ledger for every stream, with each stream's table kept for its detail? | **Yes**: today stand fees fall outside every backstop because each stream wired itself to the gateway separately. |
| D9 | Should nominee consent (`nominee_confirmed_at`) be required for every award, not only challenges? | **Your call**: it is a product rule. The award profile can make it a per-award setting. |
| D10 | Order of work after Phase 0? | **As in §14**: kernel and operating model first, because volunteers, events, the market and awards all stand on it. |
| D11 | What is a **centre** for Africa GATES: a state chapter, a school, a community hub, a partner organisation, or several of these? | The scope hierarchy, volunteer grants and local markets all hang on this. I suggest **Country → State → Centre**, where a centre is any local unit with a named lead. |
| D12 | Which volunteer roles need an identity check, and what is the minimum age (with a guardian's consent below it)? | An ID check for any role that sees personal data or handles money or a door. Minimum 16, with consent under 18. |
| D13 | Who may **sell** in the market? | **Invited and verified sellers first**, then open to anyone who passes verification once trust and dispute handling are proven. |
| D14 | Platform fee per listing kind: products, services, stands, tickets, promotions? | Your call. It is a setting per kind, so it can change without code. |
| D15 | May an individual **nominee** be promoted with paid placement during voting? | **Decided (27 Oct): no.** Paid promotion is for goods, services, events, awards and highlights, always labelled "Promoted". The owner also ruled out the site promoting every nominee itself: promotion happens off the site, through what every nominee and voter is equipped with (the share kit, "bring your people" links). |
| D16 | May Gee **file** a refund request itself after the person confirms? | **Yes**: it files a request, a person decides it, and Gee never moves money. |
| D17 | Will you fund a card sort and tree test with real users per audience before the navigation is rebuilt (§13.2)? | **Yes**: about 15 people per audience for the sort and 50 for the test. It is the cheapest way not to rebuild the navigation twice. |
| D18 | Community Return: who is the participant? | **Decided:** the nominee holding a claimed page earns it; every member has a wallet for referral earnings and points. |
| D19 | The milestone's measure? | **Decided:** total eligible paid-vote revenue in the edition, per-edition opt-in; operators set the target. |
| D20 | Keep the per-supporter cap? | **Decided:** a nominee's own buying counts, so the cap stays only in the original threshold model. |
| D21 | Withdrawal terms and tax? | **Partly decided:** automatic withdrawals with risk checks and a banded fee are built and settable. **Still open:** legal and tax advice on paying nominees in Nigeria before terms are published. |
| D22 | Where is the host physically, and must event data stay in Nigeria? | **Decided (17 Oct): the host is Nigerian.** Backups go to a second Nigerian location; anything sent abroad is encrypted and recorded as a cross-border transfer. |
| D23 | Who runs events on Africa GATES Events? | **Decided (17 Oct): anybody who hosts an event**, with a free core and a paid award bundle. Award organisers open in E2 and every organiser in E4 (§9.8), each with verification, first-event review and post-event settlement for new organisers (§9.5). |
| D24 | Stay on PHP and MySQL on this host for Events, rather than the plan's TypeScript, Postgres and Kubernetes? | **Yes.** One language, one deploy, one team. §9.9 lists when to revisit each part. |
| D25 | Should an offline door phone **decide** for signed tickets, replacing today's rule that it only records? | **Decided (17 Oct): yes**, with public-key (Ed25519) signatures only, shipping after a network-cut pilot. |
| D26 | A PWA first for the door and planner, and a native Android app only if pilots show the PWA failing on budget phones? | **Yes.** |
| D27 | Commission legal advice on the NDPA and GAID 2025 (registration, a DPO, impact assessments) before Events opens to outside organisers? | **Yes, in stage E0**, before any outside organiser comes in (§9.8). It also covers what organisers agree to as controllers or processors of their guests' data. |
| D28 | Biometrics (face check-in) at the door? | **No.** QR and printed tickets cover admission; face data carries the heaviest legal duties for no gain. |
| D29 | May the platform ever hold attendees' or organisers' money (cashless wallets, vendor escrow)? | **Not without licensing advice.** Until then, settlement goes straight to organisers through gateway subaccounts. |
| D30 | What is in the free core and the award bundle, and what does the bundle cost? | The split in §9.4. **Price the bundle per edition, not per month**, with a share of paid-vote revenue as the alternative for awards that cannot pay up front; set the figures after pilots 1 and 2 have measured what organisers would pay (§9.7). Every limit and price is a setting. |
| D31 | What does the platform take on tickets? | **A per-ticket fee on paid tickets only, at market level** (reported at 3.5–5% in Nigeria, §9.2), nothing on free events, the same on both offers. The market competes on this fee, so do not try to win on it (§9.1). A setting. |
| D32 | Must the platform's own mobilisation help be identical for every nominee in a category? | **Decided (27 Oct): yes.** Built as `ShareKit` (the same messages, QR code and formats for everyone, held by `ShareKitTest`) and `VoteRecruit` (every voter's own link, counted). |
| D33 | Retention windows: how long is each kind of personal data kept (nominations not taken forward, support tickets, OTPs, visit logs, interview recordings)? | Set them with the legal advice in D27. Once set, retention runs on the daily tick and erasure requests become a console task (§5.5). |
| D34 | May an award hosted on Africa GATES charge a nominee to be nominated, shortlisted, to win or to receive their award? | **No** (§9.5). Tickets and votes may be sold; the honour may not. "Pay to be honoured" schemes exist and would carry the Africa GATES name. |
| D35 | Lead Africa GATES Events with award events, and open the free core to every organiser only after pilots? | **Decided (18 Oct): yes.** Events that honour people first, general events second (§9.1); three pilots decide each opening (§9.7, §9.20). |

---

## 17. Research notes

- **Africa GATES Events (§9).** The figures in §9 come from the Events Chief Engineer Plan
  (17 Oct 2026) and the sources it cites; they are reported, not re-verified here, and should
  be checked before anyone relies on them externally. Device share and Android versions: a
  KPMG/Orange study via arise.tv, and StatCounter for Nigeria. Hosting: reporting on the AWS
  Lagos Local Zone, Kasi Cloud, AFRICLOUD and Nigeria's National Cloud Policy 2025
  (nairametrics.com, connectingafrica.com, itweb.africa, leadership.ng, tech.africa,
  techeconomy.ng). Messaging prices: developers.facebook.com/docs/whatsapp/pricing and Nigerian
  reporting (yournotify.com, msmeafricaonline.com). Data law: NDPA 2023 and GAID 2025
  commentary (mondaq.com, privacymatters.dlapiper.com, captaincompliance.com). Safety rules
  after the December 2024 stampedes: gazettengr.com, primebusiness.africa. The plan's SLOs
  and stack were its own proposals, not benchmarks; §9.18 rewrites them for this host.
- **Events market research (§9.2, 18 Oct 2026).** Reported figures, not confirmed with the
  companies. Ticketing fees and Tix Pro's end:
  [Dealroom, Tix Africa](https://app.dealroom.co/companies/tix_africa),
  [The Ticketing Business, Tix Africa launches in the UK](https://www.theticketingbusiness.com/2026/08/tix-africa-launches-in-the-uk/),
  [IQ International Ticketing Report 2025, Nigeria](https://www.iqmagazine.com/publications/international-ticketing-report-2025/market-report-nigeria/),
  [Demfati, ticketing platforms in Nigeria](https://blog.demfati.com/top-10-ticketing-platforms-in-nigeria-2025---why-demfati-is-the-innovation-leader-to-watch1754736322)
  (a competitor's ranking). Paid voting:
  [Daily Trust](https://dailytrust.com/?p=1925653),
  [.ng Awards voting](https://techpoint.africa/?p=344477),
  [NHEA 2025 voting](https://businessday.ng/news/article/public-voting-begins-for-nhea-2025-nominees/),
  [AFRIMA 2025 voting](https://guardian.ng/?p=2672731). Award and event fraud:
  [Peoples Gazette on an unauthorised awards event](https://gazettengr.com/serial-impersonator-kunle-onabekun-defrauds-tinubu-wike-others-despite-expulsion-from-uk-institution/).
  Lagos safety rules:
  [TheCable, events over 250 must register](https://www.thecable.ng/?p=791282),
  [Prime Business Africa](https://primebusiness.africa/stampede-lagos-govt-warns-event-organisers-show-promoters-to-priortise-crowd-management),
  [Peoples Gazette, clearance in Lagos](https://gazettengr.com/deadly-stampedes-event-organisers-need-clearance-in-lagos-sanwo-olu-says/);
  confirm current thresholds with the Lagos State Safety Commission. GAID tiers and audit fees:
  [KPMG review](https://assets.kpmg.com/content/dam/kpmg/ng/pdf/2025/05/Review%20of%20the%20NDPA%20General%20Application%20and%20Implementation%20Directive%20GAID%202025.pdf),
  [Afriwise](https://www.afriwise.com/blog/key-updates-from-the-nigeria-data-protection-act---general-application-and-implementation-directive-2025)
  (the sources disagree on some band edges; read the GAID itself). Paystack settlement
  schedules: [Paystack support, splits](https://support.paystack.com/hc/en-us/articles/360009881460-How-do-I-split-a-payment-ac-)
  and community notes that `manual` may need enabling
  ([mctaba.com](https://mctaba.com/learn/paystack/delayed-settlement-patterns-for-marketplaces));
  Paystack's API reference refused automated reading, so confirm with Paystack. Cloudflare
  Waiting Room plans: [Cloudflare docs](https://developers.cloudflare.com/waiting-room/plans).

- **Award platforms.** Award Force configures an entry form per *season* (their word for an
  edition). Each field applies to all categories or only selected ones, and fields can be
  shown or hidden based on earlier answers. That is the model in §8: per-cycle layering,
  category conditions and conditional fields.
  [Field configuration](https://support.awardforce.com/hc/en-us/articles/206965226) ·
  [Seasons](https://support.awardforce.com/hc/en-us/articles/217063066) ·
  [Conditional fields](https://support.awardforce.com/hc/en-us/articles/9821252087951-Using-conditional-fields-VIDEO) ·
  [Multiple categories](https://support.awardforce.com/hc/en-us/articles/9568633646351-Can-entries-be-submitted-into-multiple-categories)
- **Form versioning.** Form.io stores each published version as a complete numbered
  snapshot and tags every submission with the revision it was captured against, so old
  submissions still render against their own questions. The general rule across schema
  tools is that a published version is frozen and any change is a new version. That is the
  rule in §8.2 point 1, and it matches this repo's own "retired, never deleted" rule.
  [Form.io form revisions](https://form.io/features/form-revisions)
- **Modular monolith.** Shopify kept one codebase and enforced boundaries between
  components with a static checker (Packwerk), rather than splitting into services. Their
  retrospective says componentising was only a first step, and that boundaries had to be
  enforced, not just drawn. That is why §5.2 makes every rule a test.
  [Enforcing modularity with Packwerk](https://shopify.engineering/enforcing-modularity-rails-apps-packwerk) ·
  [A Packwerk retrospective](https://shopify.engineering/a-packwerk-retrospective) ·
  [InfoQ on Shopify's modular monolith](https://www.infoq.com/news/2019/07/shopify-modular-monolith)
- **Volunteer hours and verification.** Volunteer platforms separate a scheduled
  **assignment** from **completed hours**. They make approval a gate before hours count,
  prefer recorded arrival and departure (QR, kiosk) over self-reported time, keep the
  original value and the reason for any correction, and let verification strictness be set
  per programme. That is §7.3's evidence grading.
  [VSys One: assignments vs hours](https://vsysone.com/docs/VSys/HTMLDocs/4.0/4155.htm) ·
  [Jodoo: approval before totals](https://www.jodoo.com/ai/volunteer-hours-tracker) ·
  [Rosterfy: tracking hours](https://www.rosterfy.com/wp-content/uploads/2026/08/Track-Volunteer-Hours-Checklist-_-Rosterfy.pdf) ·
  [Civic Champs: validating hours](https://www.civicchamps.com/post/how-to-validate-volunteer-hours) ·
  [Helper Helper: validation](https://helperhelper.com/?p=12112)
- **Scoped access.** Multi-tenant systems scope every role to a tenant rather than to the
  user globally, because a global role cannot say "admin here, viewer there". They move to
  relationship-based checks where sharing and hierarchy grow. That is §7.2's "a grant is a
  role at a scope", with the scope hierarchy carrying inheritance.
  [Clerk: RBAC for B2B SaaS](https://clerk.com/articles/role-based-access-control-rbac-for-b2b-saas-2) ·
  [Permit.io: multi-tenant authorisation](https://www.permit.io/blog/best-practices-for-multi-tenant-authorization) ·
  [OpenFGA: RBAC vs ReBAC](https://openfga.dev/docs/learn/rbac-vs-rebac.md)
- **Assistants that act.** Practitioner guidance on agent design is consistent:
  - scale human approval to the cost of a mistake;
  - make an approval a specific proposal, not a generic confirm;
  - keep confirmed facts, proposed actions and completed actions visibly separate;
  - offer a fallback when consent is declined;
  - hand over warmly, with the whole conversation and what was already tried.

  That, with the Cloudflare conversation you shared as the worked example, is §12.
  [Agent UX design](https://www.parallelhq.com/blog/ai-agent-ux-design) ·
  [Handoff best practices](https://www.eesel.ai/blog/ai-agent-handoff-best-practices) ·
  [Consent-first handoffs](https://petronellatech.com/blog/consent-first-voice-bots-that-keep-agent-handoffs-smooth/)
- **Agents like Gee.**
  [Intercom: Fin procedures](https://www.intercom.com/help/en/articles/13449439-building-fin-procedures) ·
  [Intercom: data connectors in procedures](https://www.intercom.com/help/en/articles/13459820-how-to-use-data-connectors-in-fin-procedures) ·
  [OpenAI's agent guide, summarised](https://ibl.ai/blog/openai-a-practical-guide-to-building-agents.md) ·
  [OpenAI: ChatGPT agent](https://help.openai.com/en/articles/11752874-chatgpt-agent%253F.class) ·
  [Anthropic: writing tools for agents](https://anthropic.com/engineering/writing-tools-for-agents) ·
  [Agent loops and stopping, summarised](https://blog.promptlayer.com/how-to-build-effective-anthropic-agents/) ·
  [Sierra: τ-bench](https://sierra.ai/de/blog/tau-bench-shaping-development-evaluation-agents) ·
  [Sierra: benchmarking agents](https://sierra.ai/kr/blog/benchmarking-ai-agents)
- **How the large platforms do it.**
  [YouTube Partner Program eligibility](https://support.google.com/youtube/answer/72851?hl=en-GB) ·
  [Meta Business Suite](https://buffer.com/resources//how-to-use-meta-business-suite/) ·
  [Meta Business Suite and Ads Manager](https://www.gomarble.ai/blog/meta-ads-manager-business-suite) ·
  [Marketplace seller app and verification](https://www.techrepublic.com/article/news-meta-facebook-marketplace-seller-app/) ·
  [Material 3 canonical layouts (via SAP Fiori)](https://www.sap.com/design-system/fiori-design-android/v26-4/foundations/adaptive-layout/canonical-layouts) ·
  [Google HEART framework](https://ixdf.org/literature/article/google-s-heart-framework-for-measuring-ux) ·
  [Google SRE in IT operations](https://services.google.com/fh/files/misc/sre-it-operations-whitepaper.pdf) ·
  [Getting started with SRE: error budgets](https://speakerdeck.com/frathgeber/how-to-sre-getting-started-with-site-reliability-engineering) ·
  [Google's OKR playbook](https://whatmatters.com/resources/google-okr-playbook) ·
  [Grading OKRs](https://www.whatmatters.com/okrs-explained/grading-okrs) ·
  [Card sorting vs tree testing](https://cleverx.com/blog/card-sorting-vs-tree-testing-vs-first-click-complete-comparison) ·
  [Tree testing guide](https://www.koji.so/docs/tree-testing-guide)
