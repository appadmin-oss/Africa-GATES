# Africa GATES: architecture audit and restructuring plan

*Draft for the owner's review, 16 Oct 2026; Africa GATES Events added 17 Oct 2026 and restructured 18 Oct 2026 (§9). **Events and volunteers audited against the code and brought up to date 13 Nov 2026** (§7.4, §9 Part D, §14, D39–D43). Each phase waits for your approval.*

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

**Events and volunteers on 13 Nov 2026.** Both have shipped far past this plan's first
draft (§7.4, §9.21), and the audit found the controls under them lagging the features: a lead
can approve their own spend, a lead can verify their own hours, stand payments are filed as
vote money, an offline door can admit a replayed flush twice, one automation emails people
every fifteen minutes once switched on, the volunteer "leave" button does nothing, and the
WhatsApp community invite reaches unvetted 16-year-olds. Meanwhile the award layer the Events
product was positioned on (§9.1) has not been started, and none of stage E0's evidence
exists. §9.24 sets the corrected order: harden, four resolvers, then the award layer.

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
  *Superseded (28 Oct 2026, consoles v7 C1): volunteers are never admin actors; they work in
  `/volunteer` and at events in `/lead/event/{id}`, cut to their role's ticks. See 7.4.*
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
5. **Work.** Shifts and tasks. *(Shifts removed by the owner, 29 Oct 2026: tasks, and check-in and check-out by a lead. See 7.4.)*
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

### 7.4 Volunteers: the state on 13 Nov 2026

*Added 13 Nov 2026. 7.2 and 7.3 are the target as written on 16 Oct. What shipped, what the
owner changed since, and what the 13 Nov audit found are here; the build log is in
`CONSOLES-V7-REVIEW.md` §6 and `WORKSPACE-COMPARISON.md`.*

**Owner decisions that change 7.2 and 7.3.**

- **Volunteers are never admin actors** (consoles v7 handoff §0.4; review C1). They work in
  their own area, `/volunteer`, and at events through `/lead/event/{id}` with only the pages
  their role's ticks open. 7.2's "a volunteer sees the same console as staff" is superseded.
- **Shifts were removed** (29 Oct). A volunteer's role at an event or award is a grant that
  ends on its own; work is tasks; hours on site come from **check-in and check-out**
  (`Volunteer\Attendance`, 30 Oct): a lead's tap counts at once, the volunteer's own taps wait
  for a lead. `gates_vol_shifts` and `gates_vol_signups` stay, unread and unwritten, because
  verified hours point at them. Step 5 of 7.3 ("shifts and tasks") and the "door steward"
  row ("check-in and check-out by QR scan") now mean this. Whether the **door** should also
  check volunteers in, with a pass per role, is still the owner's question.
- **Effectiveness is one resolver** (`Volunteer\Effect`, 30 Oct): over six months, verified
  hours, tasks finished, handed back and late, events turned up to; reliability shown only
  after three.
- **No broadcast** (29 Oct): every message goes to one person about their own work.
- **Meetings** of up to twelve people a volunteer already works with; a minor is invited only
  by a team lead and never organises.
- **The WhatsApp community invite on applying** (13 Nov, aed96da).

**7.3 against the code.**

| Step or element | State | Where |
|---|---|---|
| Apply | Partial: a fixed form, not the form engine (§8) | `Volunteers::apply` |
| Screen: references | Partial: stored; nothing contacts the referee, staff tick "replied" by hand, and the applicant's email says "we have asked your referee" | `Volunteers.php:215, 247` |
| Screen: ID check for roles that see personal data, money or a door (D12) | **Missing** | — |
| Screen: age, guardian consent (D12) | Partial: date of birth asked, under 16 refused. Under 18, "consent" is the **applicant's own tickbox**, stored as a timestamp; no guardian name, contact or confirmation | `Volunteers.php:162-170, 192` |
| Train | Built: modules, a check, passes that lapse | `Training` |
| Training gates a role | **Missing.** `unlocks` is only displayed; no grant reader asks for a pass, although the docblock says the door role stops when Safety lapses | `Training.php:11` |
| Activate: a grant at a scope | Built | `Grants::grant` |
| Activate: versioned code of conduct | Partial: the version is stored and never compared, so a new version asks nobody again | `Volunteers.php:26, 197` |
| Work | Built: tasks, check-in, outreach, nomination checks (masked on the server), voter drive | `Tasks`, `Attendance`, `Outreach`, `NominationChecks`, `VoterDrive` |
| Recognise: hours, levels, points | Built; levels from evidence, thresholds in `Volunteer\Rules` (D36 defaults), not settable by hand | `Level`, `Rewards` |
| Recognise: certificate or reference letter | **Missing.** "Ask for a letter" files a request nothing fulfils | `WorkspacePages.php:446` |
| Level 3 is a lead | **Missing.** Lead powers come from a `vol.team_lead` grant whatever the level | `Team.php:20-26` |
| Spot-check share that moves with accuracy; Level 2 publishes after a spot check | **Missing** | — |
| Provenance on volunteer work | Built | hours source and confirmer, task poster, check author |
| Masked data, audited unmasking | Partial: nomination checks are masked on the server; outreach shows full contacts after a terms agreement, and no view is audited | `Outreach.php:277-281` |
| Offboard | **Broken** (VO-1) | — |
| The four numbers | Hours and reliability built; outcomes partial (outreach and drive credits not in `Effect`); quality only as nomination-check agreement, not on the roster | `Effect.php:39-92` |

**Defects found** (P as in 9.22; each fix starts with the failing test).

| # | P | Defect | Evidence | Fix |
|---|---|---|---|---|
| VO-1 | P0 | **"Leave for good" and "Withdraw my application" do nothing** (verified). `VolunteerController::act` has two `'leave'` arms in one `match`; PHP takes the first, the event check-out, so both buttons answer "Not checked in." and no grant is ever revoked from the workspace. Offboarding (step 7) is not reachable by the volunteer. | `VolunteerController.php:333, 371` | Rename one verb (`checkout`); a test posts each. |
| VO-2 | P1 | Staff can accept a task with any hours. On MySQL, 10,000 or more overflows `DECIMAL(6,2)`; the task is already marked done and the hours insert fails with no transaction. Negative hours also close the task silently. | `VolunteersController.php:93`, `Tasks.php:76`, `Hours.php:79` | The 24-hour cap the event path uses; one transaction. |
| VO-3 | P0 | **Staff can give any role to anybody with a volunteer row** (verified): declined applicants, current applicants and people who have left, and any capability `Grants` knows, including `event.lead`, `event.own` and `event.finance`, bypassing the owner (EV-5). | `VolunteersController.php:218-225`, `Grants.php:73` | Refuse non-`active` volunteers and non-`vol.` capabilities, as `RoleInvites::eligible` does. |
| VO-4 | P0 | **The WhatsApp community invite goes to every applicant at once**, including 16- and 17-year-olds and people not yet vetted, into a group where adults the platform has not vetted can message them. The platform's own rules say otherwise elsewhere (nothing for under-18s in the Afrovanguard list; only a lead may invite a minor to a meeting). The link is a bearer credential committed to the repository as a default (verified); a blank setting falls back to it, so staff cannot switch the invite off; any `https` host is accepted. | `VolunteerController.php:306-311`, `Volunteers.php:35-52`, `Meetings.php:85` | Invite only approved volunteers aged 18 or over; no default in code (blank means off); accept only `chat.whatsapp.com`; rotate the link, since the old one stays in git history. D39. |
| VO-5 | P1 | A break that has ended stays a break until the volunteer opens their own page, so reminders skip them indefinitely. | `VolunteerController.php:503`, `Reminders.php:86` | Resume in the maintenance sweep. |
| VO-6 | P1 | **Approved requests change nothing**: an approved hours correction writes no hours, approved time off does not pause, an approved role grants nothing. The requests look decided and the work is not done. | no consumer of approved `hours`, `off`, `role` requests | A handler per request type, run by the decision. |
| VO-7 | P1 | Applicants, declined and departed volunteers can log hours; no daily total; proof accepts `http://` though the message says https. | `Hours.php:32, 40` | Status check; a 24-hour day; https only. |
| VO-8 | P1 | A lead's "team" is everybody with any grant at the place, so a lead confirms the hours of event leads and finance admins too. | `Team.php:34` | `capability LIKE 'vol.%'` through `Grants::live`. |
| VO-9 | P1 | Seven readers hand-roll "is this grant live" and none checks `starts_at`; a role that has not started already allows check-in, team membership and meeting invites. | `Attendance.php:36, 112`, `Effect.php:67`, `Team.php:34`, `Meetings.php:80`, `WorkspacePages.php:726`, `Rewards.php:78` | `Grants::live` only (9.24 K1). |
| VO-10 | P0 | **A lead can verify their own hours.** The lead console's check-in never refuses the lead's own volunteer row, and the hours land verified with the lead as witness. | `LeadController.php:427-433` | One approval rule (9.24 K2). |
| VO-11 | P2 | Three age calculations (365.25-day years in applying), one of which can be a day wrong at a birthday — the boundary of the minimum age. | `Volunteers.php:166`, `Meetings.php:64-66`, `VolunteersController.php:110` | One resolver (9.24 K4). |
| VO-12 | P1 | Leaving revokes only `vol.*` roles; `event.lead`, `event.role` and `event.finance` survive offboarding. | `Volunteers.php:309` | Revoke every member grant, with the event owner told. |
| VO-13 | P2 | Stale access notes say `/admin/volunteers` is superadmin only; it maps to `volunteers` (superadmin and admin, 31 Oct). Staff audit rows are all typed `volunteer` whatever the id points at. Meeting reminders read 500 rows with no order, so past meetings can crowd out the next. | `VolunteersController.php:30-34, 98`, `routes.php:4915`, `Meetings.php:265` | Correct; type the target; order and filter by date. |

**Checked and clean:** every volunteer status column is VARCHAR; no `where($col, $array)`;
nothing reachable writes the shift tables; reminders are claimed before they are sent;
nomination checks strip nominator data and the desk's signals.

**What 7.3 should now say, step by step.** Apply (the form engine when §8 lands) → screen
(referee contacted by the platform with a one-click reply; ID check for the roles D12 names;
**a guardian confirms by their own email or phone**, not the applicant's tick) → train (a pass
**gates** the role that needs it) → activate (a grant; a new conduct version asks again) →
work (tasks and check-in by a lead; never their own) → recognise (the four numbers;
certificates and reference letters generated from the record, G12) → offboard (every grant
ends, the person is told). Each step has an owner and a due time: an application unanswered
in 7 days, an hours row unconfirmed in 3, escalate to the scope's lead and then staff.

**Safeguarding is the gap with the highest cost.** The platform now admits 16- and
17-year-olds, holds their dates of birth and emergency contacts, and (until VO-4 is fixed)
sends them into an unmoderated adult group. Before any more volunteer recruitment: VO-4, a
guardian's own confirmation, a written safeguarding policy with a named lead (the five tests'
"owned"), and minors' data classed as sensitive under D33.

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
- **Part D, the state on 13 Nov 2026** (9.21–9.26): what shipped, the defects the audit found,
  the five tests now, the corrected order, and refreshed research. **Where Part D and Parts
  A–C disagree, Part D is current.**

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

**What does not exist.** *(As of 18 Oct. Much of the "Plan" and "Operate" list below has
since been built; 9.21 is the current list.)*

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
| **E4. The free core, open to anybody** | Self-serve sign-up for any organiser; attendee reports that pause sales; sales velocity limits; staff and volunteer hours verified by check-in (shifts were removed, 29 Oct; 9.24); the incident log; the safety evidence pack; a live view by polling. | Pilot 3 passes; thirty days open with every report closed inside its deadline and no organiser paid for an event that did not happen. |
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

### Part D. The state on 13 Nov 2026: audit and corrected plan

*Added 13 Nov 2026. §9.1–9.20 were written on 17–18 Oct, before any of the event planning or
volunteer code existed. Since then about 11,000 lines have shipped in `Services\EventPlan` and
`Services\Volunteer`, the owner made several decisions that change the plan (shifts removed,
one login, roles given by address, owners and finance admins per event), and progress was
logged in `CONSOLES-V7-REVIEW.md` §6 and `WORKSPACE-COMPARISON.md` rather than here. This part
brings the plan back into line with the code. Every finding below cites the code and was
traced by reading it; the ones marked **verified** were re-read line by line before being
written here. No test run backs this part: see 9.25.*

### 9.21 Where the code stands against Part A and Part B

**Built since 18 Oct, which §9.3's "does not exist" list still described as missing.**

| Area | State | Where |
|---|---|---|
| The event as a scope, with roles that end on their own | Built | `Grants` (`gates_grants`, `starts_at`/`expires_at`, read live by `Grants::live`), `EventPermissions::standing` |
| Leads, owners, custom roles, teams, a fixed permission menu, two people for any access | Built | `EventPlan\Leads`, `Teams`, `EventPermissions::MENU`, `RoleInvites` (a role offered to an address, switched on once that address is verified) |
| A finance admin per event, approved by the owner | Built, with gaps (9.22, EV-1 to EV-6) | `Leads::giveFinance`, `Changes::decide`, `EventFinanceAdminTest` |
| Work with an owner and a due date; readiness counted, not typed | Built | `Plan::addWork`, `Plan::isLate`, the team loop (`TeamLoop`: reports, proof before done) |
| Risks (level computed), decisions (reversed, never edited), sponsors, safety checks, seating, run of show | Built as plain lists | `Scope` |
| Budget lines (estimate, committed, paid), a lead's limit, spend over it as a request | Built, with gaps (EV-1, EV-2) | `Plan::approveLine`, `Plan::askLine`, `Requests` |
| Requests as the first real work item (decider, append-only history, reminder) | Built; no due date | `Requests`, `gates_requests`, `gates_request_events` |
| Goals and gates that count themselves, with targets worked back from assumptions | Built (not in the plan) | `Goals`, `Planner`, `Setup` |
| A prospect pipeline before nomination | Built (not in the plan) | `Pipeline`, `Outreach` |
| Rules (automations) with shadow mode and a daily digest, run on the tick | Built (not in the plan), with a fault (EV-7) | `Rules::runAll()` from `Maintenance` |
| Change tiers: small changes apply after 24 hours unless a lead objects; near a gate, everything is large | Built (not in the plan), with bypasses (EV-8) | `Changes`, `Tiers` |
| The AI suggests teams and reviews roles; a person decides | Built, advisory, degrades with no provider | `TeamSuggest` (§9.17's "agents propose, people approve", in its first form) |
| The written post-event report, signed off by the owner | Built (the generated report of E5 is not) | `EventReport`, `gates_event_writeups` |
| Vote and ticket income on the event's money view | Built | `Goals::voteIncome`, `Scope::ticketIncomeMinor` |
| The volunteer workspace, staff side, training, hours, levels, meetings, nomination checks, voter drive, outreach, rewards | Built (7.4) | `Services\Volunteer\*` |

**Still missing from §9.6, §9.10 and §9.15.** These are the features §9.1 says the product
leads with, and they are the ones that have not been built:

| Plan item | State | Evidence |
|---|---|---|
| The award's timeline as the event's milestones, read from the cycle's own dates (9.6) | **Missing.** Cycle dates are read once, as suggested goal deadlines | `Setup::dates()` (`Setup.php:87-99`); the Timeline tab shows work, decisions and the night only (`EventPages.php:485-505`) |
| Run-of-show cues generated per category (9.6) | **Partial.** Cues are typed by hand | `Scope::addCue` (`Scope.php:68-90`) |
| Results released at the cue (9.6) | **Missing.** No EventPlan file calls `CycleMaterialiser`; marking a cue done only stamps it | `Scope::cueDone` (`Scope.php:92-99`) |
| Honours seating tied to the door's greeting (9.6) | **Partial.** Seats exist; the door does not read them | `Scope::seat` (`Scope.php:243-311`) |
| Nominee mobilisation stages (9.6, D32) | **Missing** as a programme. `ShareKit` and `VoteRecruit` exist; nothing runs them as stages with work items | — |
| Dependencies and a computed critical path (9.10) | **Missing.** A yes/no "the night waits on it" and a free-text note | `EventPages.php:479` |
| Cloning an event with its plan (9.10) | **Partial.** The event row and its tiers only. Not work, roles, teams, run of show, safety checks, budget, goals, rules or the award link | `Admin\Services\EventCopy` (`EventCopy.php:31-55`) |
| A complete audit trail for the event (9.10) | **Partial.** Money and access writes do not appear on the event's own audit tab (EV-9) | `Scope::audit` (`Scope.php:365-375`) |
| Capacity and density calculator, state permit rules as data, incident log, evidence pack (9.15) | **Missing**, all four | no `incident` or permit rule anywhere in `EventPlan` |
| Message cost shown before a send, a per-event budget (9.14) | **Missing** | `Outreach.php` |
| Per-organiser settlement (9.13) | **Missing.** Events have no organiser column at all | `PaymentService.php:644-645`, `PaymentDestination.php:631` |
| Signed tickets, revocation list, clock offset, failover, virtual accounts, installments, velocity limits, VAT, FX fields (9.12, 9.13) | **Missing** (FX partial: naira is checked, no currency or rate stored) | `EventTicketService.php:668`; no `sodium_crypto_sign` in `src/` |

**The finding that matters most is the shape of this table, not any row in it.** §9.1 decided
that the product leads with what nobody else does: the whole award behind the ceremony. Since
then the general project-management layer (goals, pipeline, rules, change tiers, team loop)
has been built in depth, and the award-specific layer (timeline from the cycle, release at the
cue, cues per category, mobilisation stages, cloning an award event) has not been started.
The order drifted from the position. That is recoverable, and 9.24 corrects it.

### 9.22 Defects found in the 13 Nov audit

Priority: **P0** is money, access or a safeguard that is wrong now; **P1** is wrong output a
person acts on; **P2** is debt that will become P1. Each has the test that must fail before
the fix and pass after it. Defects in the volunteer domain are in 7.4.

| # | P | Defect | Evidence | Fix | Test that holds it |
|---|---|---|---|---|---|
| EV-1 | P0 | **A lead approves their own spend** (verified). A lead holds both `money.plan` and `money.approve`; `approveLine` never compares approver and proposer, and the limit is per line, so ₦1m in five ₦200k lines is self-approved. `LeadConsoleTest.php:204-216` asserts the self-approval is allowed, which contradicts the finance-admin rule shipped in 39b34dd ("the person who asks for money is never the one who approves it"). | `Plan.php:361-383`, `EventPermissions.php:151-153` | One rule, `Approval::mayDecide($who, $line)`: never the proposer, never a person holding both roles at the event. A per-event running total against the limit, not per line. | A lead approving a line they proposed is refused; five lines under the limit summing over it are refused. Rewrite the `LeadConsoleTest` case that asserts the opposite. |
| EV-2 | P0 | **Committed and paid have no ceiling.** Once a line is approved, any committed or paid figure is accepted. | `Plan::setSpend` (`Plan.php:418-432`) | Over the approved estimate (plus a set tolerance) is a new approval. | Recording ₦10m paid on a ₦50k line is refused. |
| EV-3 | P0 | **The finance admin's limit is unlimited by default and cannot be set** (verified). `financeCeiling()` returns `PHP_INT_MAX` when `event_finance_limit_naira` is unset; neither it nor `lead_spend_limit_naira` nor `lead_week_hours` has a settings field. The menu's help text promises "never above the lead's own limit". | `EventPermissions.php:82, 185-193` | A default (D41) and fields on `/admin/settings`, one resolver each (CLAUDE.md: anything operational must be settable there). | The setting is on the settings page; with it unset the ceiling is the default, not `PHP_INT_MAX`. |
| EV-4 | P0 | **A spend request skips the grant and the ceiling.** The decider is fixed when the request is made; `canDecide` compares ids and never re-checks that the `event.finance` grant is still live; `financeCeiling()` is never asked on this path. | `Requests.php:230-234, 262-270`, `LeadController.php:418-423` | Re-resolve the decider and the ceiling at decision time through `Grants::allows`. | A finance admin whose grant has ended cannot decide; a request above the ceiling goes to platform finance. |
| EV-5 | P0 | **The owner's approval of a finance admin holds on one path only.** Staff `finance_give` grants directly; nothing stops staff later making the finance admin a lead, at which point one person proposes and approves any amount. | `EventsController.php:1357-1368`, `Leads.php:97-102`, `Changes.php:169` | Check "not a lead or owner of this event" in `Grants::grant` for `event.finance`, and the reverse for `event.lead`. | Both orders of giving the two roles are refused. |
| EV-6 | P1 | **A lead can undo the owner alone.** `finance_end` revokes with `revoked_by = 0`; `vol_invite_cancel` cancels any invitation, including an owner-approved `event.finance` or a staff-given `event.own`. | `LeadController.php:323-326`, `RoleInvites.php:138-142` | Record who revoked; a lead cancels only invitations a lead could have made. | A lead cannot cancel an `event.own` or `event.finance` invitation. |
| EV-7 | P0 | **Reminders are emailed on every tick, not once a day** (verified). The `reminder` rule sends the mail inside `evaluate()`; the once-a-day check in `run()` only skips the log row, and `heardOf()` looks at reports and the work row, which a reminder changes neither of. With the rule active, each owner of work due within two days gets about 96 emails a day per item. | `Rules.php:121-123, 158-169, 250-255` | Claim the send in `gates_broadcast_log` (as `Volunteer\Reminders` does) before mailing; evaluate returns intentions, `run()` acts on the claimed ones. Same for the digest (`Rules.php:214-217`), which claims nothing. | Two runs in one day send one email. |
| EV-8 | P1 | **Large-tier changes apply at once.** `Tiers` says an assumption a target rests on, and the budget, need an owner; `Planner::setAssumption` and `Plan::setEventSettings` apply immediately. A lead alone can also place anybody into an owner-approved role that carries `money.approve`. | `Tiers.php:18-21`, `Planner.php:95-117`, `Plan.php:461-479`, `Teams.php:204-212` | Route both through `Changes`; membership of a role with a risky tick is large. | Each write lands as a pending change. |
| EV-9 | P1 | **The event's audit tab misses money and access.** It filters `target_type='event' AND action LIKE 'lead.%'`; approvals, spend, requests, work and staff `event.finance.*`/`event.owner.*` writes have other types or prefixes. §9.10 requires every state change. | `Scope.php:365-375`, `LeadController.php:176-188, 254-261` | Store the event id on every event write (a column or meta key) and filter on that. | Every verb in `EventPermissions::MENU` and every staff event action appears on the tab. |
| EV-10 | P1 | An approved change can apply nothing and still say "Approved and applied": `draftGates` deletes open gates, and a pending gate change then updates zero rows. | `Planner.php:190`, `Changes.php:185-200` | `apply()` returns false on zero rows; the decision says the change no longer applies. | — |
| EV-11 | P1 | **Stand money is filed as vote money and falls outside every backstop** (verified; worse than §2 fault 8). `AFG-STAND-…` references have no branch in `streamForReference` and fall through to `votes`, so checkout applies the **votes** subaccount, the webhook looks in donations and logs `unmatched`, and refunds do the same. | `PaymentDestination.php:631-634`, `StandFee.php:217`, `PaymentService.php:644-645`, `PaymentController.php:533-647` | A `stands` stream, in `CHECKOUT_STREAMS`, with webhook, reconciler, `GatewayLedger` and `RefundService` branches (D8's ledger is the long fix). | A test that every reference prefix any service mints maps to a stream other than the fallback. |
| EV-12 | P0 | **A balance payment overwrites the deposit** (verified). `StandFee::confirm` sets `paid_naira` to the latest payment rather than adding it, and treats a second payment smaller than the first as already recorded. A second "Pay" in another tab overwrites `payment_ref` and orphans the first. | `StandFee.php:229, 277-285` | Record each payment as its own row (or add), keyed by reference. | Deposit then balance records the sum. |
| EV-13 | P0 | **A tier oversells when a payment lands after its hold expired.** `sold()` stops counting a pending row after 30 minutes; `confirm()` checks only `status='pending'`. A bank transfer that settles after the seat was resold confirms a second buyer. The reconciler's comment describes the resale and fixes only the lost money. | `EventTicketService.php:241-260, 676-688`, `PaymentReconciler.php:217-221, 451` | On a late confirm, re-check capacity: confirm if a seat is free, otherwise refund automatically and tell the buyer. | Hold lapses, seat resold, late payment: no oversell, a refund row. |
| EV-14 | P1 | **Money with no ticket.** An expired paid waitlist offer is cleared without the paid-row guard `releaseExpired` has; `rotateReference` orphans an earlier reference still settling. Both log `unmatched`. Waitlist `promote()` reads then updates, so two promotions can offer one seat twice. | `EventWaitlist.php:186-216, 254-263`, `EventsController.php:663-674` | Keep superseded references matchable; guard and lock as `releaseExpired` does. | — |
| EV-15 | P0 | **A replayed offline flush admits extra seats** (verified). The door's script sends a client id so a retry cannot count twice, and `DoorController::sync()` reads the id and never uses it; a flush resent after a lost response admits the same seats again on a multi-seat ticket. `DoorOfflineTest` uses a one-seat ticket, so it cannot see it. | `DoorController.php:503-521`, `door.twig:704-706` | Store the client scan id with a UNIQUE key; a repeat returns the first verdict. | The same flush twice on a four-seat ticket admits two, not four. |
| EV-16 | P1 | **Scans queued when a door pass closes are never recorded.** After the pass ends, every flush gets 403 and the queue stays on the phone for good. | `DoorController.php:479-483`, `EventScanPass.php:45` | Accept a flush for scans timed inside the pass's window for a grace period after it closes. | — |
| EV-17 | P1 | **A refund can race the door.** `checkIn` updates by id and seat count without `status='confirmed'`, so a cancel committed between its read and its write leaves a guest refunded and admitted. Offline, a refunded ticket is admitted by eye (no revocation list, 9.12). | `EventTicketService.php:1339-1343` | Add the status and code to the UPDATE's WHERE. | — |
| EV-18 | P0 (latent) | **An outside organisation's paid event would put its money in Africa GATES's account.** Events have no organiser column; every ticket settles to the one `events` destination. Only staff can create tiers today, so nothing has happened, but staff can already set up an event for an outside organisation (owners and leads are member accounts precisely so organisations can run events). That is the platform holding client funds (D29). | `PaymentService.php:644-645`; no organiser column on `gates_site_events` | Until per-organiser settlement exists, a paid tier is refused on an event whose organiser is not Africa GATES (needs the organiser column, D40). | Creating a paid tier on an outside organiser's event is refused. |
| EV-19 | P2 | The "Nominees confirmed" goal falls at results (counts `approved`, and release moves winners to `winner`/`runner_up`); "Nominations submitted" counts drafts. | `Goals.php:104-105`, `CycleMaterialiser.php:640-644` | Count through the awards domain's own definitions. | — |
| EV-20 | P2 | `gates_event_rules.review_at` is written and read by nothing; "is this a money change" is spelled in three files; staff `finance_decide` does not check the change belongs to the event. | `Rules.php:64, 81`; `Changes.php:146`, `GoalPages.php:105`, `TeamPages.php:232`; `EventsController.php:1378-1386` | Read or drop; one predicate; scope the decide. | — |

**Still open from §2**, re-checked: fault 4 (no index on `gates_donations.payment_ref`; the
webhook still reads by it, and `RecurringGiving.php:225` works around it), fault 5 (no
transaction in `RefundService`, `PaymentReconciler`, `ShopOrderService`, `StandFee` or
`PaymentController`), fault 8 (now EV-11). Ticket sales and the stand fee are what Events
adds to these paths, so they are Events' faults too.

**Checked and clean.** The event and volunteer migrations use `SchemaIndex::ensure` and
VARCHAR statuses, so none of the ENUM or `CREATE INDEX IF NOT EXISTS` traps; no
`where($col, $array)`; lead audit rows write `admin_id` null, not 0; the webhook and verify
paths cannot both issue a ticket (one conditional UPDATE); reserve fails a race as "sold out"
rather than overselling.

### 9.23 What the five tests say now

| Process | Repeatable | Visible | Owned + due | Verified | Replicable |
|---|---|---|---|---|---|
| Event work (tasks) | yes: the team loop | yes: readiness counted | **yes**: owner and due on every item | partial: proof before done, a lead accepts | **no**: cloning drops the plan |
| Event spend | partial | partial: not on the audit tab (EV-9) | partial: requests have a decider and **no due date** | **no**: self-approval (EV-1), no ceiling on paid (EV-2) | no |
| Access at an event | yes | yes: Leads page | yes: two people | yes: owner decides | partial: per event, by hand |
| Volunteer application and screening | partial: no runbook for approving; referees contacted off-platform | partial | **no**: no owner or deadline on an application | **no**: no ID check, guardian consent is the applicant's own tick (7.4) | no: one national list of states, one community link |
| Volunteer hours | yes | yes | partial: unanswered hours have no owner or escalation | partial: check-in by a lead is observed; a lead can verify their own (VO-10) | yes |
| Door check-in | yes | yes | partial | **yes**, with EV-15 and EV-17 to close | yes |
| Stand fees | partial | **no**: filed as votes (EV-11) | no | **no** | no |

The pattern from §6.2 holds, sharpened: where the platform records the fact itself (scans,
counted readiness, check-ins by a lead), it passes. Where a person approves a person's own
claim, or money crosses a path built for another stream, it fails.

### 9.24 The corrected order (supersedes 9.8 for what comes next)

**The proposition.** Stop adding event and volunteer features until the controls under them
are true, then build the award layer the product was positioned on. Three moves, each
shippable on its own:

**H. Harden (now, before any new feature).** The P0 rows of 9.22 and 7.4, in this order:
EV-7 (it mails people today if any lead switches the rule on), EV-15, EV-13, EV-12, EV-11,
EV-1 to EV-5, EV-18's guard, VO-1, VO-3, VO-4. Then §2 faults 4 and 5 for the ticket and
stand paths. Exit test: every row's test exists, failed before its fix, and passes; a MySQL
parity run of the event and volunteer suites is green (`scripts/mysql-parity.sh --filter
'Event|Lead|Volunt|Door|Stand|Ticket'`).

**K. Four resolvers, one each, because each fault above is two spellings of one rule.**

1. **`Grants::live` is the only answer to "is this role live"**, `starts_at` included. Seven
   readers hand-roll it today (VO-9). A sweep, like `OtpAttemptCapTest`, fails on a second
   copy.
2. **One approval rule** (`Approval::mayDecide`): never the proposer, never somebody holding
   the deciding and the asking role at once, re-resolved at decision time, with a due date.
   Spend lines, requests, changes and hours all ask it. This is the §6 "owned and due" block
   arriving through the one process that needs it most.
3. **One map from reference prefix to money stream**, with an exhaustive test over every
   prefix minted in `src/`. Stands first; it is the precondition for D8's one ledger.
4. **One age resolver** (calendar dates, not 365.25-day years), used by applying, meetings and
   the staff list (VO-11).

**A. The award layer, which is the product (E1 proper).** In the order a pilot needs it:
(1) the award's timeline on the event's plan, read live from the cycle; (2) cloning an award
event with its plan, roles, run of show, budget lines, goals, rules and award link, so the
second centre is "venue, date, lead"; (3) cues per category generated from the cycle, and
**release at the cue** through `CycleMaterialiser::release()` (the seal and the announcement
rules stay exactly as they are; the cue is only the moment); (4) honours seats read by
`DoorWelcome`; (5) mobilisation stages as work items over `ShareKit` and `VoteRecruit`, the
same for every nominee (D32); (6) the capacity check and Lagos's permit rules as data, which
are cheap and are the one safety feature competitors lack (9.2).

**Then E0 and the rest of 9.8 stand as written**, with these changes: E4 no longer contains
"staff and volunteer shifts verified by scans" (shifts were removed, 29 Oct; hours come from
check-in by a lead, `Volunteer\Attendance`); per-organiser settlement (E2) needs an organiser
column on events first (D40); E0's evidence is still entirely missing — no backup, no
restore drill, no load test, no legal advice and no Paystack answer is recorded anywhere in
the repository — so **no stage past E1 can open**, and pilot 1 should not be an on-sale
large enough to need the ceiling until the load test exists.

**Stage status on 13 Nov 2026.**

| Stage | Status |
|---|---|
| E0 Ready | **Not started.** None of the five pieces of evidence exists. |
| E1 Award events in house | **Partial.** Plan, work, budget, people and the night as lists are built; the award layer (A above) and the capacity and permit checks are not. Pilots 1 and 2 have not run. |
| E2 Award bundle open | Not started. Blocked on E0, D40, per-organiser settlement, EV-18. |
| E3 Door and selling hardened | Not started. EV-15 to EV-17 are prerequisites, not E3 work. |
| E4 Free core open | Not started. |
| E5 Learn | The written report and its sign-off exist; the generated report does not. AI change-sets exist in one place (`TeamSuggest`). |

### 9.25 What this audit could not do

- **No test run.** `vendor/` is not installed in this container and the lock file needs PHP
  8.4 (the container has 8.3); the install timed out on the network. Every finding is from
  reading the code, and those marked verified were re-read; each P0 fix starts by writing the
  failing test in 9.22.
- **No MySQL parity run**, so nothing here confirms the absence of a width or ENUM fault on
  production beyond reading the migrations. VO-2 is MySQL-only by construction.
- **Not read in depth:** `PartnerOrg`, `EventSales`, `EventArrivals`, `InvitePass`,
  `GatewayLedger`'s matching, all of `WorkspacePages`, and whether `uq_evreg` survives on a
  production database built by `2026_06_21` (if it does, a second ticket for one email is
  refused).

### 9.26 Research refreshed (13 Nov 2026)

Reported figures, not confirmed with the companies or regulators. Sources in §17.

| Topic | What changed or was confirmed | Effect on the plan |
|---|---|---|
| WhatsApp pricing | Meta has billed **per delivered template message**, not per conversation, since 1 July 2025. Reported Nigerian rates are about USD 0.0101 per utility message and USD 0.062 per marketing message, matching 9.14. Utility messages inside an open customer-service window are free. One Nigerian provider reports service messages becoming chargeable from 1 Oct 2026; that page could not be read from here, so it is unconfirmed. | 9.14's cost table must price per message and per category, and treat the service-window rule as a setting that will change. Check Meta's own rate card before the first paid send. |
| GAID 2025 | In force since 19 Sept 2025. Commentaries disagree on the tiers: one describes Ultra-High, Extra-High and Ordinary-High levels with thresholds of about 5,000 and 200 people in six months, which does not match the "2,500, 10,000 and 50,000" in 9.2. Audit filing fees of ₦100,000–₦1,000,000 apply from the 2026 audit cycle; the annual filing date moved to 31 March, and the NDPC extended the 2025 filing to 30 May 2026. | 9.2's thresholds are unreliable; D27's legal advice must settle the tier from the GAID text itself. Volunteers add a new class of data: dates of birth, emergency contacts and minors (7.4). |
| Paystack `manual` settlement | Still documented only by third parties as needing Paystack to enable it; no published maximum hold. | Unchanged: confirm with Paystack in E0. |
| Lagos safety | The 250-guest registration and Event Safety Permit, marshals above 500 and "two marshals per 500 guests" are repeated in current reporting; the Safety Commission announced tourism safety marshals for December 2025 events. No single current guideline document was found. | Unchanged: rules as data with a last-checked date (9.15); confirm with the Commission before encoding. |

### 9.27 Fixed on 14 Nov 2026

The first part of step H in 9.24. Each fix shipped with a test, and each of those tests was
run against the code **before** the fix and failed there (fourteen tests, fourteen failures),
then passed after it.

| # | What changed | Test |
|---|---|---|
| EV-1 | Nobody approves a line they added (`Plan::approveLine`). The test that asserted a lead's self-approval now asserts the refusal. | `LeadConsoleTest::test_a_lead_approves_up_to_their_limit_and_asks_finance_above_it`, `EventTeamsTest::test_a_roles_money_ceiling_is_its_own_and_never_above_the_leads` |
| EV-2 | Committed and paid can never exceed the approved estimate (`Plan::setSpend`); the difference is a new line somebody approves. | the `LeadConsoleTest` case above |
| EV-3 | The finance admin's ceiling defaults to ₦1,000,000 instead of no limit, read through `Plan::rule()` (one resolver); all three event-plan limits have fields on `/admin/settings`. The default is the D41 recommendation and is a setting. | `EventFinanceAdminTest::test_a_spend_above_the_finance_limit_goes_to_platform_finance` |
| EV-4 | A spend request is decided by its named finance admin only while their grant is live and the amount is within the finance limit; otherwise it falls to platform finance, which now sees it (`Requests::canDecide`, `waitingOn`). | `EventFinanceAdminTest::test_a_finance_admin_whose_role_ended_cannot_decide_and_finance_can` |
| EV-5 | **Partly.** A person who holds `money.plan` at an event never gets the finance ceiling there (`EventPermissions::standing`). Refusing the second grant outright is still to do. | — |
| EV-7 | The reminder and the digest are claimed in `gates_broadcast_log` before sending, once per item (or lead) per day. | `EventGoalsTest::test_a_reminder_is_sent_once_a_day_however_many_ticks_run`, `…test_the_digest_is_claimed_once_per_lead_per_day` |
| EV-11 | **Partly.** `AFG-STAND-` references are `events` money, not `votes`; the webhook credits a stand payment after re-verifying it with the gateway, and a stand reversal alerts staff. The reconciler, `GatewayLedger` and `RefundService` still do not read stands (§2 fault 8's remainder). | `ShopEventPaymentCoverageTest::test_every_reference_this_platform_mints_maps_to_a_stream` |
| EV-12 | One row per stand payment (`gates_stand_payments`, migration `2027_05_21_stand_payments.php`); a payment is credited once and **added**, and every reference ever minted stays matchable. | `StandFeeTest::test_a_deposit_and_then_the_balance_add_up_to_the_fee`, `…test_an_earlier_reference_paid_after_a_newer_one_was_minted_is_still_credited` |
| EV-13 | A payment that lands after its hold lapsed is confirmed only if a seat is still free; otherwise no ticket, the money is recorded as owed back (`refund_status = pending`) and staff are alerted. | `EventTicketServiceTest::test_a_payment_after_the_hold_lapsed_does_not_oversell_a_resold_seat` (and its "seat still free" twin) |
| EV-15 | The door remembers each offline scan's client id (`gates_door_sync_ids`, migration `2027_05_20_door_sync_ids.php`); a resent flush answers "Already recorded" and admits nobody. | `DoorOfflineTest::test_a_resent_flush_does_not_admit_more_of_a_party` |
| EV-17 | The admission UPDATE also requires `status = 'confirmed'`; a cancel landing between read and write is refused as cancelled. | covered by the existing door suites; the race itself is not reproducible in SQLite |
| VO-1 | Event check-out is its own verb, `checkout`; "Leave for good" and "Withdraw my application" reach `Volunteers::leave`. | `VolunteerWorkspaceTest::test_leaving_for_good_ends_every_volunteer_role` |
| VO-3 | Staff give roles only to approved volunteers, and only `vol.*` roles. | `VolunteersAdminTest::test_a_role_is_given_only_to_an_approved_volunteer_and_only_a_volunteer_role` |
| VO-4 | **Partly.** The community link goes only to applicants aged 18 or over (`Volunteers::communityFor`), in the email, the toast and the workspace; only `chat.whatsapp.com` links are accepted; the email no longer claims "we have asked your referee". The built-in default link and its timing (on applying) are the owner's choice and stay until D39 is decided; the link is in git history either way. | `VolunteerCommunityTest::test_the_link_goes_to_adults_only` and two more |
| VO-10 | Nobody confirms their own hours, by any road (`Hours::confirm`), and a lead cannot check themselves in or accept their own task from the event console. | `VolunteersTest::test_nobody_confirms_their_own_hours` |
| VO-11 | **Partly.** `Volunteers::age()` counts by the calendar and is used by applying and the community rule. `Meetings::adult` and the staff list still have their own. | `VolunteerCommunityTest::test_age_is_counted_by_the_calendar` |

**Still open from 9.22 and 7.4:** EV-6, EV-8, EV-9, EV-10, EV-14, EV-16, EV-18 (needs D40),
EV-19, EV-20; VO-2, VO-5 to VO-9, VO-12, VO-13; and §2 faults 4 and 5. Both new migrations
are additive (two new tables, `SchemaIndex::ensure` only) and must be applied before the
fixes they back take effect; until then both paths behave as before.

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
  - **Paid checkout (3 Nov 2026, `Ads\AdCheckout`):** its own stream, `ads`
    (`CfisCatalogue::BUILT_IN`, references `AFG-ADS-…`); VAT added on top at `ads_vat_bps`
    (7.5% by default), shown as its own line and on the receipt; prepaid, starting when both
    paid and approved for the days bought; unspent budget refunded with its VAT by the
    maintenance run. Prices are set daily by `Ads\AdMarket`.
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
| **4. Volunteers** | §7.3 on work items, grants, evidence and forms: apply, screen, train, activate, tasks and check-in, hours, levels, certificates. **Status 13 Nov 2026: mostly built ahead of phases 1–3 (§7.4)**; what remains is VO-1 to VO-13, safeguarding (D39, D12), the referee and guardian loops, training that gates a role, certificates, and a due date on every application and hours row. | 1, 2, 3 (built ahead of them on K1–K3, CONSOLES-V7-REVIEW §4) | A volunteer can be recruited, screened (a guardian confirming for a minor), trained, given a scoped grant, checked in by a lead (never themselves), and see verified hours, and can leave, all without staff doing anything by hand except the screening decision. |
| **5. Africa GATES Events** | §9, award events first (§9.1), in six stages (§9.8), each opening only when the one before passes: E0 ready (backups, legal advice, retention, Paystack manual settlement, a load test of the host's ceiling); E1 award events in house (the award's timeline as the plan, mobilisation, ceremony cues, release at the cue, honours seating, capacity and permits); E2 the award bundle open to outside award organisers (verification, per-organiser settlement, integrity rules, per-edition billing); E3 the door and selling hardened (signed tickets, failover, virtual accounts, installments); E4 the free core open to anybody (reports, velocity limits, shifts, incidents, the evidence pack); E5 learn, then general tools on demand. Three pilots decide the openings (§9.7). Stands onto the ledger. | 0 (backups), 1, 4, and the ledger from 6 for E2 | Each stage's exit test in §9.8. The last: a post-event report with no manual figures. **Status and corrected order 13 Nov 2026: §9.24** — harden (EV/VO P0s), four resolvers, then the award layer; E0 not started, E1 partial. |
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
| D36–D38 | Volunteer levels, who decides requests, who writes training. | See `CONSOLES-V7-REVIEW.md` §5; the code runs on the recommended defaults (`Volunteer\Rules`). |
| D39 | Who may be invited to the volunteer WhatsApp community, and when? (§7.4 VO-4) | **Approved volunteers aged 18 or over only**, after approval, never on applying. No link in the code; blank in Settings means off. Under-18s get the platform's own messages only. Rotate the current link. |
| D40 | Should every event name its organiser (Africa GATES, a centre, or a `PartnerOrg`)? | **Yes, now**: it is the precondition for per-organiser settlement (E2), for refusing a paid tier on an outside organiser's event until then (EV-18), and for an organiser's scope (§9.5). |
| D41 | The default limit for an event's finance admin, and for a lead, and may one person hold both? | **A finance admin defaults to ₦1,000,000 per line and a lead to ₦200,000, both settable on `/admin/settings`; one person may never hold both at one event, and nobody approves their own line** (EV-1 to EV-5). Above the finance admin's limit is platform finance. |
| D42 | Should the event plan's automations (`Rules`) stay switched off for every event until EV-7 is fixed? | **Yes.** Shadow mode is safe; active mode mails on every tick. |
| D43 | A safeguarding policy for under-18 volunteers, with a named lead, before more recruitment? | **Yes**: a guardian confirms by their own email or phone, minors never enter group chats, their data is classed sensitive (D33). The organisation standard requires the named owner. |

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

- **Refreshed 13 Nov 2026 (§9.26).** WhatsApp per-message pricing since 1 July 2025:
  [Meta, pricing on the WhatsApp Business Platform](https://developers.facebook.com/docs/whatsapp/pricing),
  [SleekFlow, per-message pricing](https://help.sleekflow.io/en_US/pricing),
  [Chakra, July 2025 changes](https://chakrahq.com/article/pricing-updates-for-whatsapp-business-platform-effective-july-2025-onwards/),
  [Salesforce rate card, Aug 2025](https://www.salesforce.com/en-us/wp-content/uploads/sites/4/assets/pdf/WhatsApp_Business-Messaging-Rate-Card-Aug-1-2025.pdf);
  the report of service messages becoming chargeable from 1 Oct 2026
  ([yournotify.com](https://yournotify.com/blog/whatsapp-pricing-update-2026/)) could not be
  read from this session's network. GAID 2025:
  [Mondaq, GAID questions answered](https://www.mondaq.com/nigeria/data-protection/1684204/unlocking-gaid-2025-answers-to-all-your-burning-questions),
  [LegalBytes](https://legalbytes.substack.com/p/gaid-2025-what-nigerian-businesses),
  [JD Supra](https://production.jdsupra.com/legalnews/nigeria-ndpc-issues-gaid-key-compliance-6645594),
  [Mondaq, 2025 filing extended](https://www.mondaq.com/nigeria/privacy-protection/1770448/),
  and the KPMG review above. Paystack:
  [split payments](https://paystack.com/docs/payments/multi-split-payments/),
  [delayed settlement patterns](https://mctaba.com/learn/paystack/delayed-settlement-patterns-for-marketplaces).
  Lagos: [TheCable, events over 250](https://www.thecable.ng/?p=791282),
  [Peoples Gazette, December 2025 protocols](https://gazettengr.com/?p=380147),
  [Platform Times](https://platformtimes.com.ng/?p=62587).

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
