# Review: the architecture plan against the v7 consoles handoff

28 Oct 2026. Reads `docs/architecture/AUDIT-AND-PLAN.md` (§7 people and volunteers, §9 Events,
§11 experience, §14 phases, §16 decisions) against the owner's new handoff
(`docs/handoff/consoles-v7/`: README, SCREEN-MAP, ADMIN-RESTRUCTURE, the three DCs, the nav
and request JSON) and against the code as it stands on this branch.

It answers three questions: where the plan and the handoff disagree, what the handoff asks for
that nothing in the code can supply yet, and what order to build it in.

---

## 1. The short version

- **The handoff is the plan's §7.3, §9 and §11 drawn as screens.** Same domain model (grants at a
  scope, expiring access, evidence-based hours and levels, masking, one shell), now with exact
  layouts, copy and nav. Nothing in it contradicts the plan's direction.
- **It does contradict the plan's ORDER.** §14 puts volunteers in Phase 4 and Events in Phase 5,
  after the kernel, the form engine and the IA registry. The handoff's PR list starts with the
  shell and reaches volunteers at PR 9. Neither order is buildable as written: the screens need
  data that only a few kernel pieces supply, and waiting for the whole kernel delays the screens
  by months. **Build the four kernel pieces these screens stand on, and no more** (§4 below).
- **Both features are almost entirely new.** There is no volunteer area, no grant, no request, no
  shift, no event plan (work, timeline, risks, decisions, budget) and no lead role in the code.
  What exists for events is the sell-and-admit half: the event form, tiers, tickets, invites, the
  door, scan passes and refunds. The "Events PM console v2" the handoff refers to was a design,
  never code.
- **Twelve gaps need a decision or a data source before the matching screen can be honest** (§3).
  The DCs are full of figures; the README's own rule 6 forbids fake numbers, so every one of them
  has to come from somewhere real or not be drawn.

---

## 2. Where the plan and the handoff disagree

| # | Plan says | Handoff says | Resolution |
|---|---|---|---|
| C1 | §7.2: "a volunteer sees the same console as staff, with only their scope's pages and rows". | §0.4: volunteers are **never** admin actors. Their own member area, `/volunteer`, sharing the shell partials. | **Handoff wins.** It is the safer rule: an admin session can never be reached from a volunteer sign-in. The plan's §7.2 bullet is superseded. |
| C2 | §14: volunteers after phases 1–3 (kernel, forms, IA registry). | PR 9 of 13, after the shell, scopes and Grants. | **Neither.** Build the four pieces volunteers need (§4) and the volunteer workspace on them, then the lead. The full operating model can absorb them later; design the tables so it can. |
| C3 | §9.8: Events opens in stages, E0 (backups, legal, load test) before E1. | Draws the whole event scope at once: Plan, Money, Night, Growth, Reports, Devices. | **The plan's gates stand for opening the product to outside organisers** (E2, E4). For Africa GATES's own events (E1), the planning pages can ship in house. Devices' offline lists stay record-only until the D25 pilot. |
| C4 | CLAUDE.md: judging spans three gates; consolidating it is a product decision, never a nav tidy. | §4.2–4.3: Judging, Rubric, Result release move into each edition's scope. | **Regroup only with gate parity.** `Permissions::sectionForPath()` maps by path prefix, so a new path `/admin/awards/{p}/{e}/judging` would take the `programmes` gate unless it is mapped explicitly. The redirect table must carry each page's old section. `AdminNavTest` already diffs gates; extend it to the new paths before the move. |
| C5 | §11.2 and CLAUDE.md: handbook facts are computed from code (`HandbookTest`). | `handbook/*.md`: Markdown guides with typed figures and `> **Rule:**` lines. | **Both, with a rule.** The Markdown carries procedure (the five tests' "written procedure"); any figure the code holds (weights, quorum, limits) is a placeholder the renderer fills from the code. Each "Rule in force" gets a test, as the handoff itself says. |
| C6 | `Accent::console()` is the only source of console colour; four words under 4.5:1 are reported, not re-valued. | §2.1 tones: ok `#effaf0`/`#1a5e1a`, bad fg `#a52a19`, info `#e6f0f4`/`#1f5f8b`, card edge `#e8e5dd`. | **Close but not identical.** The console has `green-wash #edf5ea`, `red-ink #a3271a` and no info tone. Add the v7 tones as NEW console tokens (`ok-wash`, `info`, `info-wash` …) under the handoff's names; do not re-value the existing ones, which the v2 pages still read. Fonts already match (Geist, Geist Mono). |
| C7 | `request-types.json` names deciders as people ("Sade Bello, finance", "Ifeoma Obi, comms"). | — | **Resolve the decider from a role or a grant, never a typed name.** There is no `finance` or `comms` role in `Permissions::MATRIX` today (roles: superadmin, admin, editor, moderator, viewer); `finance` is a section. Needs D37. |
| C8 | Org standard §2.1: every capability has a written procedure, a named owner and an unassisted run by a non-designer. | The handoff ships guides for most of it (`handbook/people/volunteers.md`, `events/*.md`). | **Good fit.** Each new process (apply, screen, grant, shift, hours confirm, request decide) ships with its guide and an owner, or it fails the standard. |

---

## 3. What the screens ask for that nothing supplies yet

Each row is a figure or behaviour drawn in a DC. "Source" is where the real value must come
from; where none exists, the screen draws nothing (README rule 6) until it does.

| # | Drawn in the DC | Source today | What it takes |
|---|---|---|---|
| G1 | Verified hours, "Level 3 at 50" | none | Hours from evidence: door scans of a **volunteer's own pass**, accepted task work, verified sign-ups. The level thresholds are an owner decision (D36), stored as settings. |
| G2 | "Shifts kept 94%" (reliability) | none | Shift sign-ups with attended/no-show, from the same scans. |
| G3 | Fits you: availability, **distance**, training, past shifts | none | Availability on the profile, and a place (state and LGA, D11). No distance until a volunteer has a place. |
| G4 | Training modules, "Safety 2026 expires in 5 days" | none | A module format (content, a check, a pass mark, a validity period) and who writes the content. |
| G5 | "12 nominations to check" (nomination-check grant) | `ReviewQueue` | Reuse it, masked: volunteers never see the nominator, phone or email; their check is a recommendation with their name. |
| G6 | Voter drive "Your link" | `VoteRecruit` (D32) | Reuse it. Counts only verified sign-ups. |
| G7 | Event "{d} days to go · {r}% ready" | none | Readiness needs the event plan (work items with due dates). Without a plan, show days only. |
| G8 | Lead Analytics: "each event against its last edition on the same day" | none | Events have no series. Add `series_key` so an event knows its previous edition; a first edition says "Nothing to compare yet", as the handoff allows. |
| G9 | Budget, approvals, spending limits, "Ask to spend" | none | Budget lines (estimate, committed, actual, paid) and a lead's limit. Spend over the limit is a request (§2.6). The money itself stays on the existing gateway; no new money movement. |
| G10 | Requests everywhere | none | `gates_requests` + `gates_request_events` (append-only), with the transitions in `request-types.json`. This is the first real **work item** (owner, due, status, evidence), so build it in a shape the operating model can adopt. |
| G11 | Devices: door scanners with offline lists | `EventScanPass` | Exists as door passes. Offline **deciding** stays behind D25's pilot. |
| G12 | Reference letters "generated from the verified record" | none | After G1/G2. Generated from the record, never from free text. |

---

## 4. Build order (recommended)

Every slice ships on its own, keeps the site working, and builds only from the v7 DCs: **no
old console or public partials are embedded in the new surfaces.** The new shell lives in
`templates/shell/` and `public/assets/css/shell/`, the volunteer area in `templates/volunteer/`,
and each old template is deleted when the page that replaces it ships (handoff PR 13).

| Slice | What | Unblocks |
|---|---|---|
| **K1. Grants** | `gates_grants` (actor kind + id, capability, scope kind + id, granted_by, starts_at, expires_at, revoked_at) and `Grants::allows()`. A grant at a parent scope covers its children. Expiry is checked on every read, so an expired grant removes the scope on the next request with nothing scheduled. | Volunteer scopes, the lead root, scoped moderators. |
| **K2. Requests** | Tables, transitions from `request-types.json`, notes required where it says, Undo for 5s, deciders resolved from roles and grants (C7). | Spend over a limit, swaps, time off, hours corrections, access. |
| **K3. Volunteers** | Volunteer profile on a member account (level, availability, place), applications, shifts and sign-ups, tasks from a template library, hours with evidence and source, training modules. | The volunteer workspace's root. |
| **K4. The v7 shell** | Rail, scope rail and switcher, crumbs, tabs, bell, Find, toast, drawer, phone app bar and tab bar, built once from the DC with the v7 tokens (C6). | Volunteer and lead homes. The admin console moves onto it later (handoff PRs 2–6). |
| **V1. Volunteer workspace, root** | Today, Shifts, Tasks, Hours, Training, My record, Requests, Profile and access, for all four roles, plus the staff side that feeds it (applications, grants, shifts) so nothing is empty. | — |
| **L1. Event lead root** | Overview, Inbox, Your events, Deadlines, Risks, Approvals, People, on the event plan tables (work, risks, decisions, budget lines). | — |
| **V2/L2. Scopes** | Volunteer award and event scopes (check nominations, voter drive, briefing, who's on); the event scope for leads. | — |
| **A. Admin console v7** | Handoff PRs 2–6 and 11–13: NavRegistry, scopes, redirects with gate parity (C4), depth, the handbook scope. | — |

**Exit test for V1:** a person applies, is approved by staff, receives a grant at an event that
ends with the event, takes a shift, is scanned in and out at the door on their own pass, and
sees verified hours and their level move, with no figure on any screen that the database did
not produce.

---

## 5. Decisions for you

| # | Question | Recommendation |
|---|---|---|
| D36 | Volunteer levels: what moves someone from Level 1 to 2 to 3? | Settings, starting at Level 2 = 10 verified hours and 3 shifts kept; Level 3 = 50 verified hours, 90% reliability and a lead's endorsement. Never set by hand without a reason on the audit log (plan §7.3). |
| D37 | Who decides requests? The JSON names people. | Resolve by role and scope: spend → an admin with Money access for the platform, the event's finance grant for an event; access → the scope's lead or an admin; a swap or hours correction → the volunteer's team lead, else the event lead. |
| D11 | What is a centre? (open since the plan) | Needed only for "distance" in Fits you. Until it is answered, a volunteer's place is their state, and Fits you ranks without distance. |
| D12 | ID check and minimum age for volunteers. (open since the plan) | As the plan: an ID check for any role that sees personal data, handles money or works a door; minimum 16, with a guardian's consent under 18. The applicant flow asks for date of birth from day one either way. |
| D38 | Who writes the training modules? | Staff, in the console, as short pages with a three-question check. The first module (Safety 2026, which the door role needs) is written before door shifts open to volunteers. |

---

## 6. Progress

### V1 shipped (28 Oct 2026), on K1–K3

- **The v7 shell** (`templates/shell/`, `public/assets/css/console/shell7.css`, `public/assets/js/shell7.js`):
  rail, scope rail with Back and the identity block, crumbs, tabs, the phone app bar and a
  bottom tab bar of four pages plus More. Layout measures its container (§2.2), not the
  window. The section kinds are one macro each (`templates/shell/sections.twig`): rows,
  cards, stats, steps, note, hero, agenda, chart, table, form, article. Colour is the
  console set of `Support\Accent`, with the v7 tones added under new names (C6); type is the
  console ladder plus 28 for the v7 h1.
- **The volunteer workspace** at `/volunteer` (`Controllers\VolunteerController`,
  `Services\Volunteer\Workspace`, `WorkspacePages`, `Team`): the four roles' rails (§6.1);
  Today for applicant, new and active/lead; Shifts (Mine, Fits you, All open, Past) and the
  shift record; Tasks; Hours with the 8-week chart and Log hours; Training and the module
  check; My record (levels, badges from the record, letters); My team for leads (roster,
  hours to confirm, team requests, gaps with named fits); Requests with the composer and the
  detail page; Profile and access (profile, availability, notifications, access, privacy
  with a JSON copy, take a break or leave); and the event and award-edition places built from
  grants and shifts (Today/Overview, shifts, tasks, briefing, who's on, your access).
- **The staff side** at `/admin/volunteers` (`Admin\Controllers\VolunteersController`),
  under Team in the rail: applications and referees, roles with an end date, shifts (post,
  mark who came), tasks (post, accept with hours), training modules, and hours no lead has
  answered. Unmapped in `Permissions`, so superadmin only until the owner says otherwise.
- `/volunteer/apply` is linked from the member's account page.

Every figure is read from the record; an empty list is one sentence. Tests:
`VolunteerWorkspaceTest`, `VolunteersAdminTest` (and K1–K3's `VolunteersTest`).

### L1 shipped (28 Oct 2026): the event lead console

- **The event plan** (`database/migrations/2027_04_13_event_plan.php`, `Services\EventPlan\Plan`):
  work (owner, due, hours, "the night waits on it"), risks (likelihood × impact, the level
  COMPUTED), budget lines (estimate, committed, paid), and on `gates_site_events` a
  `series_key` (G8) and a `budget_minor`. Readiness is done ÷ all work; an event with no work
  shows days only (G7). Only a live `event.lead` grant writes a plan.
- **Spend (G9).** A lead approves a line up to `lead_spend_limit_naira` (default ₦200,000, a
  setting with one reader). Above it the line becomes a `spend` request to finance; deciding the
  request moves the line (`Plan::syncRequest`). Nothing moves money.
- **The lead root** at `/admin/lead` (`Admin\Controllers\LeadController`,
  `Services\EventPlan\LeadPages`), on the v7 shell: Overview (blocking a night, your events with
  readiness, budget and tickets, this week, next deadlines, your requests), Inbox (yours by
  name, plus work nobody has), Requests (Open · Decided, the composer, the detail as `?r=`),
  Your events, Deadlines, Risks, Approvals, Analytics (against the last edition in the series,
  on the same day out; "Nothing to compare yet" for a first edition) and People (load against
  `lead_week_hours`, default 40, with Rebalance; volunteer shift fill). Each event opens its own
  pages: Overview (with its budget and series), Work, Risks, Budget, People.
- **Who leads.** An administrator (superadmin or admin) gives an event to a lead on Your
  events; the grant ends the day after the event unless an earlier date is given. Only roles
  that can write may lead. Every lead write is audited once as `lead.<verb>`.
- **Requests get a decider's screen** at `/admin/requests` (Team › Requests), held with the
  money (`finance`: superadmin, admin). Before it, a request routed to finance or "an
  administrator" — a lead's spend, a volunteer's swap with no lead — waited for ever.
- **Access, for the owner to confirm:** `/admin/lead` is mapped to `overview` (every role may
  open it; it shows only the viewer's own grants), `/admin/requests` to `finance`.
- The shell is now configured per home (`act`, `home`, `foot`, `railLabel`), so the lead
  console and the volunteer workspace share every partial (handoff §0.5).

Tests: `LeadConsoleTest` (14). Removing the lead check and removing the spend limit each fail it.

### L2 shipped (28 Oct 2026): the event scope, Find, Ask, the switcher, Remind

- **The event scope, to `nav-admin.json`** (`Services\EventPlan\EventPages`, `Scope`;
  `database/migrations/2027_04_14_event_scope.php`): Today; Plan (Work · Timeline · Risks ·
  Decisions) and Award (read-only, from `gates_event_programmes`); People (Team · Volunteers,
  where a lead posts a shift · Nominees · Seating · Invitations); Relationships (Sponsors ·
  Contacts · Letters · Messages); Money (Budget · Approvals · Vendors · Income); The night (Run
  of show · Door · Safety); Growth; Reports (Status · Post-event · Audit); Requests; Event
  settings (General · Members · Limits · Devices). New records: decisions (reversed with a
  reason, never edited), the run of show (a bare time is on the night; a cue that ran stays),
  sponsors with their contact, safety checks, seating (seats are a refusal) and a vendor on
  each budget line. The door's figures come from `EventArrivals`, its scanning links from
  `EventScanPass` (shown once). Every lead change is audited against the EVENT, so Reports ›
  Audit can list it. The first build's `/work` and `/risks` answer 301 to Plan.
- **Remind finance** (`Requests::remind`): only the asker, only while it waits, once a day
  from the last ask; it is an event in the history and an email to the decider (by name, or
  every active admin holding the role), and the answer says whether any mail left.
- **Find** (⌘K): the bar's search matches as you type from a list the page carries (pages and
  tabs of every event the lead holds, open work, their requests, things to start); Enter on
  nothing, or no script, is a results page at `/admin/lead?q=`. **Ask** (⌘J): the console's
  own assistant (`/admin/assistant/chat`) in a side panel with starters for where the lead is;
  without script it is a link. **The switcher**: the identity block lists the lead's other
  events and keeps the page and tab.

Tests: `LeadEventScopeTest` (11). Removing the reminder's once-a-day rule, the seats refusal
and the decision's single reversal each fail it.

### Not yet (next slices)

- **The volunteer workspace's switcher and Find**, and the bell's grouped Updates (§2.4), on
  either home.
- **Seating from the guest list**: a table's guests are typed names, not linked to
  invitations or tickets.
- **Letters and big sends from the lead's side**: they stay with staff (Events ›
  Invitations, and a `message` request); the tabs show what went.

- **V2 award scopes**: Check nominations (the masked review desk, G5) and the voter drive
  (G6). The award place shows Overview, Tasks and Your access until then.
- **Shell behaviours**: Find (⌘K), Ask (⌘J), the switcher, the bell's grouped Updates, the
  drawer, remembered page per scope, smart group collapse. The bell links to Today for now.
- **Door scans as hours** (G1): a volunteer's own pass at the gate stamping
  `gates_vol_signups.check_in_at` / `check_out_at`. Staff "Came / Didn't come" is the source
  until then.
- Decisions still open: D11, D12, D36 (the defaults in `Volunteer\Rules` are the review's
  recommendations), D37, D38, and who besides superadmin opens `/admin/volunteers`.
