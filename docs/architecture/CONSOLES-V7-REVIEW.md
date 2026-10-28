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
