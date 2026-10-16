# Africa GATES: architecture audit and restructuring plan

*Draft for the owner's review, 16 Oct 2026. Nothing here has been built yet. Each phase waits for your approval.*

You asked for an audit of how the app is built, research, and a plan. You also asked that
awards stop being identical: an award's nomination form, rules and look must be its own.
This document covers both. The numbers come from counting the code itself.

---

## 1. The short version

**What is wrong.** Africa GATES works, but it is built as hundreds of separate features, not
as one system. Most features were added end to end in one go: a route, a controller, a
service, a table, a template and a test, made to work and then left. Nothing gathers the
pieces into areas such as Awards, Money or Events. So:

- **The same job is done in many places, slightly differently each time.** There are 5
  sign-in systems, at least 5 rate limiters, 3 mail transports, 28 hand-built HTTP clients,
  14 private copies of "read a setting", and 11 private copies of "who is the signed-in
  admin". Each copy is a place for a bug to live. This repo's own CLAUDE.md is mostly a list
  of bugs of exactly that shape.
- **Pages were destroyed on 3 Oct and 15 are still not rebuilt.** Their routes still exist,
  so each one returns a server error today. They include the nominee's own work page
  (`/my-work`), stand applications, claim disputes and the honour page. (The organisation
  console was rebuilt on 16 Oct, in parallel with this audit.)
- **Every award runs on the same hard-coded form and rules.** "Two to three categories",
  "at least 40 characters", the list of fields and the evidence rules are constants in code
  (`NominationRules`). Only some words can be changed per award (`AwardWording`), and three
  of those seven words are saved but never shown on the live form.
- **The code is hard to change safely.** About 1,750 of the 1,776 error handlers catch
  everything, and 1,262 of them cannot even log what they caught. Money paths such as
  refunds, reconciliation and shop orders write several rows with no database
  transaction. A quarter of the test assertions check the text of source files rather than
  behaviour.

**What I propose.** Keep one app and one deploy; no microservices and no new hosting.
Reorganise the code into **domains** (Awards, Nominations, Judging, Voting, Results,
Money, Events, Shop, Community, Accounts, Comms, Content, Ops). Each domain owns its tables,
its rules and its pages, and they sit on a small **platform kernel**: one way to read
settings, one mailer, one HTTP client, one rate limiter, one "who is signed in", and one
error reporter. Then make awards configurable through an **award profile**:

- a versioned nomination form built from a library of fields;
- per-award rules;
- a per-award look chosen from the house palette.

All of this moves one piece at a time, behind the live site, and every step ships on its own.

**What I need from you** is in §9: ten decisions. The two most important are:

- whether existing awards keep today's form as their version 1 (I recommend yes);
- whether judging access may be regrouped (that is an access change, not a tidy-up).

---

## 2. Broken right now: fix before any restructuring (Phase 0)

These are faults, not style. They are independent of the restructure and should be fixed
first. I verified each one in the code.

| # | Fault | Evidence | Effect |
|---|---|---|---|
| 1 | **15 page templates are missing.** Their routes still render them. | `pages/{my-work, my-work/interview, honour, interview, nominee-claim, claim-dispute, donate-giving, form, gated-form, registry/register, stands/apply, stands/call, stands/offer, shop/alert-stopped, pulse/_cards}.twig`, rendered by MyWorkController, HonourController and others | 500 on each. `/my-work` is where a nominee answers their questionnaire. |
| 2 | Shop stylesheet and script are missing | `shop/index.twig:67`, `shop/item.twig:49`, `:244`; no `components/shop.css` or `js/shop.js` | The shop renders unstyled, and its buttons do nothing. |
| 3 | Admin reads a table that does not exist | `QuestionnairesController.php:162` reads `gates_questionnaire_submissions`; the real table is `gates_nominee_submissions` | That action always says "That submission no longer exists". |
| 4 | Payment reference has no index | `gates_donations.payment_ref`: 28 lookups, including the webhook and verify paths; no key | Every gateway callback scans the donations table, and it gets slower as giving grows. |
| 5 | Money writes with no transaction | RefundService (6 writes), PaymentReconciler (7), ShopOrderService (11), PaymentController (4), StandFee (4): 0 transactions in each | A failure part-way leaves money half-recorded. |
| 6 | A settings table that is written and never read | `gates_admin_settings` (`SettingsController.php:803-817`): three donation-vote keys | An operator changes those settings and nothing happens. |
| 7 | The Pulse "load more" pieces don't line up | `social.js:188` sends `part=items`; the controller checks `fragment=1` and renders the missing `pulse/_cards` | "Load more" fails. |
| 8 | Stand fees have no backstop | `StandFee::confirm` is called only from `StandOfferController:302`; the webhook, reconciler, GatewayLedger and RefundService never reference stands | A paid stand whose return trip fails stays unpaid, and finance never shows it. |
| 9 | Developer documents state the retired paid-vote rule | `PaidVoteService.php:20-22` and `CODEBASE-INDEX.md:261` say "never move rank" | Whoever changes scoring next reads the wrong rule. |
| 10 | **Fixed in this commit:** my 16 Oct change replaced `Support\Brand`'s logo helpers | `OtpService:849`, `ChallengeFlier:217` and `InviteLetter:116` call `Brand::logoUrl`/`LOGO_ON_TINT` | The email masthead, challenge flier and invite letter broke. The helpers are restored, alongside the name rule. |

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
  Domain/
    Awards/            programmes, cycles, categories, award profile, terms, details
    Nominations/       forms, submissions, triage, aftercare, claims
    Judging/           judges, rubrics, scorecards, COI, questionnaires, interviews
    Voting/            ballots, OTP, paid and bonus votes, fraud, recovery
    Results/           scoring (CpiService, RuleEngine), release, seal, recognitions
    Money/             donations, recurring, gateway, reconciliation, refunds, payouts
    Events/            calendar, tiers, tickets, door, invites, stands
    Shop/              products, orders, codes
    Community/         pulse, threads, comments, moderation
    Accounts/          members, organisations, vendors, profiles
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

---

## 6. Dynamic awards and nomination forms

### 6.1 What exists today

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

### 6.2 Target: the award profile

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

### 6.3 The admin side: one "Award setup" screen

Today an award is configured across Programmes, Settings, Rubric, Questionnaires and
Judges. The plan is one screen per award with tabs:

**Basics · Look · Nomination form · Rules · Categories · Judging · Voting · Details & terms ·
Publish**

The form tab is a builder. You add a field from the library, drag it into place and set its
conditions, with a live preview at phone and desktop width. Publishing creates a new
version; until then the live form is untouched.

*The tabs link to the existing judging pages; they do not move them.* Judging access is
split across three permission gates, and moving those pages would change what whole roles
can open (see §9, D5).

### 6.4 Moving existing awards across

1. The current hard-coded form is written as a definition in code: the **house form,
   version 1**.
2. Every existing programme points at it, and every existing nomination is stamped with it.
   Nothing changes for anyone.
3. The public form and the review desk are switched to render from the definition. A test
   requires the rendered form to be **field-for-field identical** to today's.
4. Only then does the builder open, one award at a time.

---

## 7. The phased plan

Each phase ships on its own, keeps the site working, and has an exit test. None needs SSH
or a new host.

| Phase | What | Exit criteria |
|---|---|---|
| **0. Stop the bleeding** | The 9 open faults in §2: rebuild the 15 missing pages (starting with `/my-work`), shop CSS/JS, the wrong table name, the `payment_ref` index (repair migration), transactions on money writes, the dead settings table, the Pulse fragment, stand fees through the webhook and reconciler, and the two documents that state the retired rule. Add the "every route renders" sweep. | The sweep is green on every public route. A MySQL parity run is green. |
| **1. Platform kernel** | Settings registry, Actor, Reporter, Http client, one rate limiter, one Twig factory, ClientIp everywhere. Each replaces copies **behind the existing call sites** first, then the copies are deleted. | The sweeps for "second copy" in §5.2 rules 3 and 4 are in place and ratcheting down. |
| **2. Award profile and dynamic nomination forms** | §6 in full: definitions and versions, field library, interpreter, rules into the registry, look presets, Award setup screen, house form v1 migration. | Today's form renders identically from its definition. A second award with a different form passes end to end (submit, desk, export, triage, claim). |
| **3. Domains, one at a time** | Move code into `Domain/*` in this order: Nominations, Awards, Judging, Results, Voting, Money, Events, Shop, Community, Accounts, Comms, Content, Ops. Each move splits its routes into a domain routes file and turns its controllers thin. Start with the domain Phase 2 already reshaped. | Each domain's write-ownership test passes, and the controller-query ratchet goes down. |
| **4. Frontend system** | One component library shared by public and console (action bar, empty state, back, chips, cards). Console pages move onto the kit. One public nav registry. Serve `AssetBundle`. Clear the inline-style and hex backlog page by page. | Every page links the bundle. The four nav surfaces are generated from one registry. |
| **5. Data cleanup** | One schema source (baseline plus migrations, with the SQLite twin generated rather than typed); one **payment ledger** every stream writes to (donation, paid vote, shop, ticket, stand), with each stream's own table keeping its detail; a decision on person identity; votes stop cascading on delete; overlapping tables merged (settings, merge journals); the dead `/pay` price book and the unused redeem path removed; scenario hardcodes (Celebrate Nigeria, Alimosho, `teachers`) moved into data. | Schema parity runs on every test run, not only manually. |

Phases 0 and 1 are prerequisites. Phase 2 is the feature you asked for and can start as soon
as Phase 1's Settings registry and Reporter exist. Phases 3–5 can run in parallel with
other product work, one domain at a time.

---

## 8. How the work stays safe

- **No big bang.** This is the strangler-fig pattern: the new path is built beside the old,
  traffic moves, and only then is the old path deleted. Every phase is a set of normal,
  shippable commits.
- **MySQL parity for every data change.** Every migration runs on real MySQL
  (`scripts/mysql-parity.sh`) before it ships. Corrections are repair migrations, never
  edited definitions.
- **Behaviour tests before moves.** Before a domain moves, its flows get HTTP-level tests
  (today only 6 files are feature tests). The move must leave them green.
- **No access change hidden inside a restructure.** Permissions are diffed by the existing
  `AdminNavTest` mapping on every move.
- **Ratchets, not rewrites.** Each "may only go down" count is recorded today, so progress
  is visible and backsliding fails the build.

---

## 9. Decisions for you

| # | Question | My recommendation |
|---|---|---|
| D1 | Should existing awards keep today's nomination form as their version 1? | **Yes**: nothing changes until an operator edits an award. |
| D2 | Should system fields (nominee, kind, categories, nominator contact, consent) be locked on every form? | **Yes**: duplicate checks, triage, judging and claims rely on them. |
| D3 | How far does an award's look go? A closed set of presets on the house palette, or free colours and fonts? | **Presets only.** Free colours break the contrast rules the site is held to and cannot be checked. |
| D4 | Can each **category** have its own extra questions (as Award Force allows), or only each award? | **Yes, as a field condition** ("only for these categories"). It is cheap once conditions exist. |
| D5 | Judging pages sit under three permission gates. May the Award setup screen *regroup* them? | Link to them, don't move them, unless you want to change role access deliberately. |
| D6 | Person identity is spread across six tables. Should Phase 5 unify them around one person record? | Yes, but it is the largest change in the plan, so it goes last and gets its own review. |
| D7 | Should the `/__setup` tool (about 1,700 lines of HTML inside `routes.php`) move into the console? | **Yes**: it resets superadmin passwords, which is a console job behind the console's audit log. |
| D8 | One payment ledger for every stream, with each stream's table kept for its detail? | **Yes**: today stand fees fall outside every backstop because each stream wired itself to the gateway separately. |
| D9 | Should nominee consent (`nominee_confirmed_at`) be required for every award, not only challenges? | **Your call**: it is a product rule. The award profile can make it a per-award setting. |
| D10 | Order of work after Phase 0: dynamic awards first (the feature) or the kernel first (the foundation)? | **Kernel first, but only the parts Phase 2 needs**: the Settings registry and the Reporter, about a week. Then Phase 2. |

---

## 10. Research notes

- **Award platforms.** Award Force configures an entry form per *season* (their word for an
  edition). Each field applies to all categories or only selected ones, and fields can be
  shown or hidden based on earlier answers. That is the model in §6: per-cycle layering,
  category conditions and conditional fields.
  [Field configuration](https://support.awardforce.com/hc/en-us/articles/206965226) ·
  [Seasons](https://support.awardforce.com/hc/en-us/articles/217063066) ·
  [Conditional fields](https://support.awardforce.com/hc/en-us/articles/9821252087951-Using-conditional-fields-VIDEO) ·
  [Multiple categories](https://support.awardforce.com/hc/en-us/articles/9568633646351-Can-entries-be-submitted-into-multiple-categories)
- **Form versioning.** Form.io stores each published version as a complete numbered
  snapshot and tags every submission with the revision it was captured against, so old
  submissions still render against their own questions. The general rule across schema
  tools is that a published version is frozen and any change is a new version. That is the
  rule in §6.2 point 1, and it matches this repo's own "retired, never deleted" rule.
  [Form.io form revisions](https://form.io/features/form-revisions)
- **Modular monolith.** Shopify kept one codebase and enforced boundaries between
  components with a static checker (Packwerk), rather than splitting into services. Their
  retrospective says componentising was only a first step, and that boundaries had to be
  enforced, not just drawn. That is why §5.2 makes every rule a test.
  [Enforcing modularity with Packwerk](https://shopify.engineering/enforcing-modularity-rails-apps-packwerk) ·
  [A Packwerk retrospective](https://shopify.engineering/a-packwerk-retrospective) ·
  [InfoQ on Shopify's modular monolith](https://www.infoq.com/news/2019/07/shopify-modular-monolith)
