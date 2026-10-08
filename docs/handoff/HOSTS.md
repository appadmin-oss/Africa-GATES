# Hosts — design (4 Oct 2026, awaiting the owner's answers; nothing built)

Owner: "Businesses, governments and so on should be able to use it for events, awards and so on. An award has
ran on the platform once." This is the design the build will follow once the questions at the end are answered.
Every claim about existing code cites its file. Paths are repo-relative.

## Five findings that change the plan

1. **Programme ids top out at 255.** `gates_award_programmes.id` is `TINYINT UNSIGNED` (`database/schema.sql:36`),
   as are `gates_award_cycles.programme_id` (`schema.sql:47`, FK `:66`), `community-schema.sql:9`/`:121` (FKs
   `:18`/`:148`) and `migrations/2026_11_01_event_invites.php:71`. Hosts creating programmes exhaust it. Widening is
   phase 0.
2. **A display-only host already exists.** `2027_02_14_programme_host.php` added `host_name`, `host_logo_path`,
   `host_url` — the "Hosted by Okun Alimosho" credit on the award that already ran
   (`database/seeds/2026_10_01_celebrate_nigeria.php:272`). Ownership must be a separate column from this credit.
3. **Live fault — stand fees are attributed to the votes stream.** `PaymentDestination::streamForReference()`
   (`src/Services/PaymentDestination.php:507-514`) sends every `AFG-` reference that is not a ticket or shop payment to
   `votes`; `AFG-STAND-` (`StandFee.php:215-218`) falls into it.
4. **Live fault — the finance screen counts partner gifts as platform income.** `FinanceService::bySource()`
   (`src/Admin/Services/FinanceService.php:91`) never filters `recipient_org_id`; partner gifts are written with
   `tier='donation'` (`DonationController.php:590`), so money settled into a partner's own account appears as
   platform "Donations".
5. **Judges are global; their programmes are a JSON list** (`JudgeService.php:96`, `:215`; unique `email`,
   `admin-schema.sql:63-79`). A host cannot own a judge row without touching a row another panel uses.

## 1. Data model

**A host is a partner organisation with a hosting capability**, not a new entity: `gates_partner_orgs` already has
vetting with suspension distinct from rejection (`2026_09_18_partner_orgs.php:32-38`), a settlement subaccount with
`platform_fee_bps` (`PartnerOrg.php:839-930`, `:916`), its own logins (`OrgAuth`), a session-scoped dashboard
(`OrgAuth.php:19-23`, `OrgDashboardController.php:17-22`) and a public page (`OrgBrand`).

`kind` cannot carry "host" (one value, partner|vendor, `PartnerOrg.php:77-83`; `kindOf()` turns unknowns into
`partner`, `:343-347`), and a host may also be a partner or vendor. New columns, all `VARCHAR` (no ENUM, so later
corrections need no repair migration): `host_status` (NULL = not a host, `pending|approved|suspended`),
`host_fee_bps`, `host_approved_by`, `host_approved_at`, `host_suspended_reason`, `host_suspended_at`,
`is_platform TINYINT(1)`.

**Ownership is stored once, at the root of each chain**; everything below inherits through the chain:

| Gets `host_id` | Inherits through it |
|---|---|
| `gates_award_programmes` | cycles, categories, nominees, votes, nominations, snapshots, sponsors, questionnaires |
| `gates_site_events` | tiers, registrations, stand calls/types/applications, invites, scan passes, check-ins |
| `gates_challenges` | own column (a challenge may have no scope rows, `schema.sql:1009-1017`) |
| `gates_audit_log` | adds `host_id`, `actor_type`, `org_user_id` (README §1.4: actor, host) |

`gates_org_campaigns` already has `org_id` — that owner is the host.

**Money gets a ledger written at payment time, never derived** (a programme moving host must not move its payment
history; doctrine of `2026_09_12_payment_destination.php:21-34`): `gates_host_ledger(id, host_id, reference,
stream, source_type, source_id, kind sale|refund|chargeback|adjustment|payout, gross_naira, fee_naira, net_naira,
settled_via split|main, created_at)`, `UNIQUE(reference, kind)`. A refund is a new negative row; a sale row is never
edited.

**The platform's own host is a real row** (slug `africa-gates`, `is_platform=1`, approved, no subaccount, no
logins — staff act through `/admin`), found by one resolver `HostScope::platformId()`. Chosen over "NULL means the
platform" because NULL would carry two meanings in every scoped query. Kept off partner lists: `listReceivable()`
already excludes it (no subaccount, `PartnerOrg.php:465-470`); `platformTotals()` (`:759-770`) and the admin partner
list gain `is_platform=0`.

## 2. Production migration

Two files dated after `2027_02_20`.

**A — widen the programme id** (MySQL only; SQLite is already INTEGER): drop `fk_cycle_prog`, `fk_crit_prog`,
`fk_thread_prog`; change `programmes.id` and the four `programme_id` columns to `BIGINT UNSIGNED`; re-add the FKs.
Every step checks `information_schema` first, because `MigrateCommand` does not record a file that throws and the
re-run must resume. No stored value changes; the snapshot hash payload (`cycleId|nomineeId|votes|cpi|at`) has no
programme id.

**B — hosts**: add the columns NULL-able (`hasColumn` guards, indexes via `SchemaIndex::ensure()`); insert the
platform row by slug if missing; `UPDATE … SET host_id = :platform WHERE host_id IS NULL` on programmes, events and
challenges — this covers the award that already ran, the sandbox programme, every event and challenge. Nothing that
feeds a figure is written: no `vote_count`, `status`, `gates_vote_snapshots`, `gates_cycle_transitions`,
`gates_rule_sets`, `is_active`. `RuleEngine` keys on programme/cycle id, not host (`RuleEngine.php:267-280`).

**Verification**
- In the migration: a fingerprint before and after — per released cycle (snapshot count, Σ`cpi_score`,
  `standing_rank` list, chain tail hash), per nominee (Σ`vote_count`, Σ`organic_vote_count`, statuses), Σ confirmed
  donations. A difference prints `***` and leaves the file unrecorded; never `exit`.
- A test renders `/results/{cycle}` for a sealed cycle before and after both files and asserts equal bodies (nonces
  stripped) — the `ReleasedStandingTest` method.
- The guard is proven by planting a `vote_count` change inside the migration and watching it fire.
- `scripts/mysql-parity.sh` is mandatory — migration A is invisible on SQLite.
- Ongoing: a test and a `Maintenance` alert fail on any root row with NULL `host_id`; every insert goes through
  `HostOwnership::assign()` (the column stays NULL-able because SQLite cannot add NOT NULL afterwards).

## 3. Access

**Host roles** (`gates_org_users.role` is `VARCHAR(20)`, `2026_09_18_partner_orgs.php:136`; `createUser()` allows
owner|viewer today, `OrgAuth.php:180`):

| Role | May |
|---|---|
| owner | everything, payouts, team |
| finance | read and export money; payouts only if the owner allows (question 12) |
| editor | events and awards content |
| viewer | read only |

`canRequestPayout()` stays owner-only (`OrgAuth.php:154-157`).

**A host may, in `/org`:** create and edit events, tiers, capacity, stand calls, invites, door staff; draft a
programme, cycle dates, categories, nomination form and terms; read its own nominations, tallies and judging
progress; propose a judge panel; ask for a release or schedule one (now / at the ceremony).

**The platform keeps:** nomination approval (the org console's own copy promises "Africa GATES checks every
nomination before it goes public"); the scoring rules (`gates_rule_sets`, the CPI basis); fraud, collusion holds,
recounts; appointing judges and conflict checks (judges are global; a later `gates_judge_panels` join lets hosts
invite); the seal and release (`CycleMaterialiser::release()`, `manualTransitionError()` stay the only way in);
first publication of a host's event or programme (a host programme starts `is_active=0`, so chain containment hides
it until approved).

**Platform admin powers:** a `workspaces` MATRIX key, superadmin only (`src/Admin/Support/Permissions.php:30-57`);
a host lens in the session, applied by `HostScope::apply($q, $rootCol)` in each list config, platform rows showing
"Platform"; view-as-host (`org_view_as` in the admin session; every `/org` POST refuses it 403; amber banner;
`host.view_as` audited); suspend/restore with a reason, reusing `PartnerOrg::suspend()` (`:1162-1173`) and
`approve()`. Suspension stops sales, nominations and votes; released results stay up unless the owner says
otherwise.

**Suspension must not reuse `is_active`** — `liveAwardOnly()` reads `is_active=0` as "not live"
(`DemoSeeder.php:889-901`), and `IntegrityController.php:151` and the judging services filter on the sandbox. One
clause, `HostScope::publicLive()` = `notSandbox` + `liveAwardOnly` + host not suspended, and every public reader moves
onto it.

**Places that must change** (lookups by id, or unscoped lists): `OrgDashboardController::fundableEvents()`
(`:54-67`, lists every event); `closeCampaign`'s inline ownership check (`:662` → one `HostScope::owns()`);
`StandFee::ledger($eventId)` (`:303-345`), `EventTicketService::refunds()`/`refundTally()` (`:1034`, `:1083`);
`BallotGuard::assertVotable()` (`:36-57`, the gate every vote write passes) gains the suspension check;
`PaymentService` routing (`:610-646`); the `notSandbox` readers in `PublicResults` (`:253, 473, 607, 745, 832, 1089`),
`Discover:427`, `SitemapService:431`, `ActivityFeedService:641/691/827`; the `liveAwardOnly` callers
`ClaimController:164`, `NomineeClaimService:795`, `SupportWork:168`, `SupportDesk:83`, `HomeFront:146`; the admin
`EventsController` linking an event to any programme (`:159`, `:349`); judge scope `JudgeService:96`, `:215`.

**`HostIsolationTest`** (built like `PublicSurfaceSandboxTest:314-376`): seed hosts A and B with a distinct marker in
every owned table; as A, boot the real router, walk every `/org` GET with B's ids in every placeholder and fail on B's
marker; fingerprint B, POST A's requests at B's ids with a valid CSRF token, require B unchanged and assert the
refusal's reason; a token sweep of `src/` for queries on host-owned roots not inside a `HostScope` call (like
`OtpAttemptCapTest`); every `/org` POST from a view-as session is 403. Break the code before trusting it.

## 4. Money

**Collection:** tickets, paid votes and donations for a host's programme or event split at the gateway into the host's
subaccount, the platform fee through `percentage_charge` (`PartnerOrg.php:916`). `initFieldsForPartner()`
(`PaymentDestination.php:220-249`) generalises to `initFieldsForHost()`, reading the host from the pending row by
reference as `partnerOrgIdForReference()` does (`:164-180`); recorded in `gates_payment_routes` as `host:<id>`.

**Refused split:** the fallback sends unsplit (`PaymentService.php:660-700`), so the money lands in the platform's
account — ledgered `settled_via='main'`, "Owed to host", paid through `OrgPayout` (transfer mode, `OrgPayout.php:87-92`;
in settlement mode a manual action).

**Refunds:** a refund of split money is drawn from the platform's balance (to confirm with Paystack); a negative ledger
row makes the host owe it back — recovered from the next payout or a reserve (question 3). Auto-refunds of undelivered
votes (`RefundService.php:11-35`) make this frequent for awards.

**"Gross · Fees · Net to host"** come only from the ledger, with `PartnerOrg::countableDonations()`/`totals()`'s
definition (`:1217-1222`, `:1289-1307`). `OrgPayout::available()` (`:101-115`) becomes net (`main` rows) minus payouts
holding funds minus refunds owed. Reused: `StandFee`'s copy-the-price-at-acceptance (→ copy the fee onto each ledger
row), `OrgPayout`'s state machine and fix-the-reference-before-the-gateway rule, `PlatformTip` for flat charges.

## 5. Public surface

"Hosted by {org}" on event and award pages, linking to a new `/host/{slug}` (`OrgBrand` + the host's upcoming events
and live awards; `/gift/{slug}` stays the donation page). Nothing shown for the platform host. Where `host_name` is set
it is the display credit and wins (question 11). The demo programme belongs to the platform host; any demo host row
uses `DemoSeeder::PREFIX` and `@demo.invalid` and is removed by `purge()`; the sandbox sweep's substitutions gain the
demo host's slug. Suspended hosts are left out of `/host` listings and the sitemap.

## 6. Phasing

| # | Step | Risk |
|---|---|---|
| 0 | Widen the programme id; fix stand-fee routing and partner gifts in finance (findings 3, 4) | **High** — FKs rebuilt on production; MySQL parity run mandatory |
| 1 | Host columns, platform row, fingerprint-guarded backfill, `HostScope`, audit-log columns, `HostIsolationTest` | Medium — additive; guard checks published figures |
| 2 | Admin: Hosts page, lens, view-as, suspend/restore, `host_id` filter in the list component | Medium — an access change, ships alone |
| 3 | Host events and tickets in `/org`: split routing, the ledger | **High** — real money, refunds |
| 4 | Host awards: draft → platform review; release requests | **High** — scoring and integrity |
| 5 | Public `/host/{slug}`, attribution, new roles | Low |

## Questions for the owner

1. Platform fee: one rate per host, or per stream (tickets, votes, donations)? Who pays Paystack's charge?
2. Money handling: split at the gateway (the platform never holds host money), or collect centrally and pay out
   (custodian and AML duties)?
3. A refunded host share is recovered how: next payout, a held reserve, or invoiced?
4. KYC for hosts: CAC/SCUML only, or more? What counts for a government body with no CAC number?
5. Who releases results: the host (behind the platform's checks), the platform only, or both together?
6. Who approves nominations for host awards: the platform (as the org console promises) or the host?
7. Does a host's first event or programme need platform approval before it goes public?
8. May hosts appoint judges directly, or only propose them?
9. On suspension: stop only new activity, or also hide published pages? Do released results stay public?
10. Governments: procurement invoices, VAT/TIN receipts, purchase orders, invoiced payment instead of card checkout?
    Scope beyond national (state, local)?
11. Alimosho Awards: the platform owns it with "Okun Alimosho" as a display credit — or should Okun Alimosho become its
    host later? (A transfer changes no sealed figure.)
12. Host roles: owner, finance, editor, viewer — right? May finance request payouts?
13. Can a host's appeal be attached to events run by other hosts or the platform?
