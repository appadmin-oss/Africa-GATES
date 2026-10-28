# Africa GATES consoles: strict handoff (admin, event lead, volunteer)

28 Oct 2026 (v7). This folder replaces `design_handoff_v3/` and every earlier console note in `HANDOFF-CLAUDE-CODE.md` (§10, §10b, "v3 shells", "Events console", both "Volunteer workspace" sections). Where they disagree, **this file wins**.

| Design file (the spec) | Who | Route base |
|---|---|---|
| `AdminConsoleV7.dc.html` (`viewer` = admin \| moderator \| lead) | Platform staff | `/admin` |
| `Events PM Console v7.dc.html` (= AdminConsoleV7 with `viewer="lead"`) | Event lead | `/admin/lead`, `/admin/events/{event}` (grant-scoped) |
| `Volunteer Workspace v7.dc.html` (`role` = active \| lead \| new \| applicant) | Volunteers | `/volunteer` (member area) |

Also in this folder:
- `nav-admin.json` and `nav-volunteer.json`: every scope, group, page, tab, icon and purpose line, generated from the DCs. **Seed `NavRegistry` from these files. Don't retype them.**
- **`SCREEN-MAP.md`: every screen mapped to the repo files it must be built from, plus the rules from the code. Read it before each PR.**
- `request-types.json`: request statuses, allowed transitions, required notes, and each type's decider, usual time and fields (lead and volunteer).
- `design_handoff_v3/ADMIN-RESTRUCTURE.md` still holds the code plan (NavScope, Grants, controller split). Its §3.1 root grouping is replaced by §4.1 below.

---

## 0. Rules (non-negotiable)

1. **The DC is the spec.** Match order, copy, sizes (±1px), hex, radii and states at **390, 834 and 1440**. Don't add, drop, rename or reorder anything. If something can't be built, stop and say so in the PR.
2. **Keep every feature of the current code.** Every page and child in `src/Admin/Support/AdminNav.php::GROUPS` is reachable in v4 (§4.1). A PR that loses a page fails.
3. **Access stays per page.** `Permissions::canOpen()` decides visibility. The rail never offers a page the guard will refuse. `AdminNavTest` keeps pinning every gate.
4. **Volunteers are never admin actors.** `AdminAuthMiddleware` refuses them. No `/admin` link, redirect or asset is ever shown to a volunteer.
5. **One shell, three homes.** Admin, lead and volunteer share one set of shell partials (§2). Each product configures it; none forks it.
6. **No 0 badges, no blank pages, no fake numbers** (§6).
7. **Phone is its own markup branch** (`skills/app-ux-standards/SKILL.md` §14 must pass).

---

## 1. The concept

The model is a developer platform's console: **one shell. You go into a workspace, and the menu becomes that workspace's menu.**

- **Scope.** A place with its own menu. The scopes are `root`, `award` (an edition), `event`, `org` (a giving partner) and `settings`.
- **Drill-in.** Opening a scope swaps the rail for that scope's nav. The top of the rail shows **← Back** and an **identity block** (tile, name, status, ⇅).
- **Switcher.** The identity block opens a list of siblings (other editions, other events). Switching keeps your page when the sibling has it.
- **Tabs.** Views of one object (Review · Desk · Duplicates). Never neighbouring pages.
- **Drawer.** A record (a nomination, a payment, a shift). It is never a scope.

Depth limit: **Shell → scope → page → tab → drawer.** A scope never opens a scope inside its own rail. Cross-links move you sideways and push history.

The three homes differ only in their root and their grants:

| | Root (home) | Scopes they can enter | Gate |
|---|---|---|---|
| Admin / moderator | Console: Home, Inbox, Calendar + 5 groups | award, event, org, settings | role via `canOpen` |
| Event lead | **Lead overview**: Overview, Inbox, Your events · Deadlines, Risks, Approvals, Analytics · People | event (their grants only); award read-only cross-link | `Grants::allows(actor, cap, event)` |
| Volunteer | **My volunteering**: Today, Shifts, Tasks, Hours, Where you help… | award and event (their grants only), settings = Profile and access | volunteer grants, member auth |

---

## 2. The shell (shared partials)

`templates/shell/{layout,rail,rail-scope,switcher,crumbs,tabs,bell,find,ask,drawer,toast,phone-bar,phone-tabs}.twig` plus `shell.css` and `shell.js`. Admin and volunteer layouts both extend `layout.twig`.

### 2.1 Tokens (exact)

- **Fonts:** Geist 400/500/600/700. Numbers, counts and keys use Geist Mono. Base 14px, `font-feature-settings:'tnum'`.
- **Colours:**
  - ink `#10292c`, ink-2 `#3a4a4c`, soft `#6b7476`, faint `#8a9294`, dim `#9aa1a2`;
  - rail ground `#f3f3f0`, surface `#fff`, hairline `#efefeb` (inside the surface), `#e6e6e1` (frame), `#e3e3de` (inputs and buttons), card edge `#e8e5dd`.
- **Primary:** `#237b22` (one per page, in the page header only). Hover link `#1a5e1a`. Focus ring: 2px `#237b22`, 2px offset.
- **Tones** (bg / fg), the only chip colours: ok `#effaf0/#1a5e1a`, warn `#fbf4e4/#8a5d0b`, bad `#fdeeeb/#a52a19`, info `#e6f0f4/#1f5f8b`, mute `#f3f3f0/#3a4a4c`.
- **Dots:** production `#c4321f`, risk `#b07a12`, info `#1f5f8b`, other `#c3c7c6`.
- **Radii:** item 8, input/button 9–10, card 14, frame 14, overlay 14–16, drawer 20, sheet 20 20 0 0, chip 999.
- **Scrollbars:** thin, thumb `#d9dad4`. The rail nav hides its scrollbar.

### 2.2 Layout by breakpoint (measure the container)

| | Desktop ≥1024 | Tablet 600–1023 | Phone <600 |
|---|---|---|---|
| Rail | 240px, padding 10px, items 34px | 64px icon rail, items 40px, centred icons, badge → 7px dot | none |
| Frame | white surface, 8px inset (8 8 8 0), 1px `#e6e6e1`, radius 14 | same | full bleed |
| Header | 52px: breadcrumb · production pill · bell 36px · **New** 32px | same | 56px app bar: Back 44 · title button (name + ▾ opens switcher) · Find 44 · Bell 44 |
| Tabs | 44px underline tabs, gap 24, 2px ink underline | same | sticky 36px chip row, gap 8 |
| Content | max 1120, padding 28/32, section gap 32, h1 28 | padding 24 | padding 20/16, gap 26, h1 24, rows 60px, buttons 48 |
| Ask | docks right 360px at ≥1280; otherwise an overlay | overlay | bottom sheet |
| Bottom | none | none | 64px tab bar: the scope's first 4 pages + More (sheet) |

- Only `<main>` and the overlays scroll. Root is `100dvh`.
- Entering a scope slides the rail in 10px from the right; Back slides it in from the left. Both take 180ms; reduced motion turns them off.

### 2.3 Page anatomy (every page, in order)

1. Switch note (only after a switch that couldn't keep the page).
2. First-run tip (root Home only, until dismissed; stored per person).
3. Header:
   - kicker, which is the scope name (only on inner pages);
   - h1;
   - purpose line, from `purpose` in the JSON, or from the page's own computed `sub`;
   - actions: at most one primary, right-aligned.
4. Sections. There are five section kinds, and only these: **rows**, **cards**, **stats**, **steps**, **note**. Their measurements are in the DC template.

A row has: an optional dot, an optional initials tile, a title and a sub, an optional progress bar (160px), meta (mono), a chip, and an optional CTA, then a chevron when the row drills in. **The CTA never triggers the row's own click** (`stopPropagation`).

### 2.4 Behaviours (all required)

- **Remember where you were.** For each person and instance, store the last page and tab in the session. Re-entering a scope restores them.
- **Switching keeps your page.** When the sibling has the page, keep the page and the tab. Otherwise land on Overview (or Today) and show the switch note:
  - admin: "{Page} isn't set up for {X} yet, so you're on Overview."
  - volunteer: "{Page} isn't part of your role on {X}, so you're on Overview."
- **Switcher.** A 320px popover under the identity block (a bottom sheet on phone), with a search box. Groups, in order:
  1. **Needs you**, with a reason chip ("38 to review", "1 clash");
  2. **Live and upcoming**;
  3. **Past editions**, collapsed to 1 until the person searches.

  Each row says "you're here" or "opens on {page}". The footer reads: "Alt [ and Alt ] switch without opening this. Alt ↑ goes back."
- **Identity block.** It is hidden in the `settings` scope, which has no siblings: Back sits directly above that scope's nav. Under the identity block:
  - an award shows its **stage**: "{Stage name} · Stage n of N" with a 3px bar;
  - an event shows "{d} days to go · {r}% ready".
- **Back follows where you came from.** After a cross-link, Back reads "Back to {origin}" and the first crumb is the origin.
  - Lead: Back from an event goes to Your events.
  - Volunteer: every Back goes to "My volunteering".
- **Smart groups (root rail, desktop only).** A group is open by default when any of these holds:
  - it has no heading;
  - it holds the current page;
  - one of its pages has a count;
  - it is Run (admin) or Where you help (volunteer).

  The group with the current page is always open. Every other group shows a collapsed header with its summed count chip (warn tone). The person's own open/closed choice is stored per group. Tablet and scoped rails never collapse.
- **Find (⌘K).** Centred, 560px, 12% from the top (a bottom sheet on phone). Groups, in order:
  1. **Do** (verbs: 3 with an empty query, up to 6 when searching);
  2. **In {scope}** or **Pages** (pages and tabs of this scope first);
  3. **Moved in the new console** (admin only, only while typing: old page name → "Now in …", chip "Moved");
  4. **Open** (scopes the person can enter).

  **Enter opens the first match.** The footer hint says so.
- **Bell.**
  - Admin groups: **Affects production** (red count, and a red header pill "N affects production" only when >0), then **May affect production** (amber), then Other.
  - Lead: May affect the night, then Waiting on others.
  - Volunteer, titled **Updates**: Changes to your shifts (red), then Needs you, then Other.
  - Every row deep-links to the page that fixes it.
- **Ask (⌘J).** A toggled pane, never a page. It answers from the current scope plus the handbook (admin) or the role guide (volunteer). It shows 3 starter questions, chosen by scope.
- **Record drawer.** 400px on the right, 12px from the edges (a bottom sheet on phone). It shows: the chip, the title, the facts (Details, Where, Logged as), an optional "Note for the log", and the primary action plus **Not now**. Confirming removes the row and shows a toast with **Undo** for 5s.
- **Keyboard:** ⌘K Find, ⌘J Ask, Esc closes, Alt [ / Alt ] previous or next sibling, Alt ↑ Back.

---

### 2.5 Components added in v5 (in the DC template)

v5 brings in the core features of a developer-platform console wherever a console needs them. Each one has a fixed component:

| Platform feature | Our version | Where |
|---|---|---|
| Usage and cost dashboard (date range, group by, chart, breakdown table) | **Filters + chart + table** | Money › Finance, Data and audit › Analytics, Lead › Analytics, Event › Money › Budget, Volunteer › Hours |
| Logs with filters and a detail view | **Audit log table** (Who, Action, Where, When) → drawer | Data and audit › Audit log |
| Members and invites per workspace | **Members table** + Invitations, every scoped access has an end date | Settings › Team and roles, Event settings › Members |
| Spend limits and alerts per workspace | **Spending limits** (budget, forecast bar, lead's limit, alert thresholds) | Settings › Spending limits, Event settings › Limits |
| API keys (shown once, last used, revoke) | **API keys** and, per event, **Devices** (door scanners with offline lists) | Settings › API keys, Event settings › Devices |
| Workspace settings | Event settings tabs: General · Members · Limits · Devices | Event scope |
| Support requests or limit increases | **Requests** (§2.6) | Admin root, Lead root, Event scope, Volunteer › You |

- **Filter bar** (`k:'filters'`):
  - 36px outlined buttons: "{Label} {value} ▾". A changed filter gets a 2px-equivalent ink border (`#10292c`). "Clear" appears only when something has changed.
  - Each button opens a **searchable picker** (360px popover, 16% from the top; a bottom sheet on phone), with "Selected" on the current value.
  - Filter state is per page and tab, and lives in the URL query (`?range=30d&group=source`).
  - On phone the bar is one horizontal scroll row.
- **Chart** (`k:'chart'`):
  - a 1px `#e8e5dd` card with radius 14 and padding 18;
  - a KPI row (label 12.5, value 22/600, delta 12 coloured ok/bad/neutral);
  - 150px stacked bars with a 3px top radius and a hairline baseline;
  - first, middle and last axis labels in mono;
  - a legend when there are 2+ series.
  - Series colours, in order: `#237b22`, `#9fd09e`, `#1f5f8b`, `#d9a441`.
  - Ranges: 7 days, 30 days, or 90 days as 13 weeks. Every bar has a title tooltip.
  - Draw it with plain CSS or SVG; no chart library.
- **Table** (`k:'table'`):
  - a 1px card with radius 14, a 38px header (`#fafbf8`, 12px/500 soft), and rows of 50px minimum with a hover `#fafbf8` when clickable;
  - cells can be text (the first column is 14/500, the rest 13.5), sub text (12 faint), mono, a chip, or a bar with a label;
  - columns marked `t:0` are hidden on tablet;
  - **phone renders rows as a list**: the title, the other cells joined with " · ", and the chip on the right;
  - an empty table shows one line inside the card, and a footer note (`#fafbf8`) explains the rule behind the table.

### 2.6 Requests (admin, lead and volunteer)

People who can't do something themselves **ask**. Spend over a limit, access for their team, a big send, a venue change, a shift swap, time off, an hours correction, a role, a travel refund: each becomes a request with one owner who decides.

- **Data:** `requests` (id, type, title, from_actor, scope instance, amount, needed_by, why, decider_actor, status, created_at) + `request_events` (actor, action, note, at). Events are append-only.
- **Types and deciders:** in `request-types.json`. The decider is resolved from the type and the scope (finance for spend, comms for sends, the volunteer's lead for swaps). The type's "usually" time is shown to the requester before they send.
- **Statuses:** Waiting → Approved | Needs info | Declined. Needs info → Waiting (the requester adds info) | Withdrawn. Waiting → Withdrawn. **Asking for info and declining need a note**, and the requester sees it. Undo is available for 5s after any decision.
- **The composer** (drawer):
  1. type chips;
  2. the type's fields (What, Amount, Needed by, Why);
  3. the grey route line "Goes to {decider}, {usually}. Where: {scope}.";
  4. **Send request**.

  What and Why are required.
- **The detail drawer:**
  - the status chip ("Waiting on you" for the decider);
  - the title;
  - facts: Type, From, Where, Amount, Needed by, Why, **If approved** (the consequence, computed: budget effect, readiness change, a cleared clash) and Decides;
  - the Activity timeline;
  - the note box;
  - actions: decider **Approve · Ask for info · Decline**; requester **Add info · Withdraw**. Decided requests are read-only.
- **Where they show:**
  - Admin root **Requests** (Waiting on you · All open · Decided, with Type and Where filters). The count on the nav is what's waiting on you. Home's Today gains "{n} requests waiting on you", with the oldest and what it holds up.
  - Lead root **Requests** (Open · Decided). The event scope has **Requests** in its Admin group. New menu → New request. Find → Make a request.
  - Volunteer: **You › Requests** (Open · Decided). A team lead also gets **My team › Requests** to decide. A request needing info surfaces on Today and in Updates.
- **Spend over a limit is never a dead end.** Wherever the lead sees "Only finance approves over your limit", the action is **Ask** (opens the composer pre-filled), not a disabled button.

### 2.7 Hi-fi page kinds (v6)

Every page is built from these kinds. The simple ones (rows, cards, stats, steps, note, filters, chart, table) fill ordinary pages. The ones below are for the pages people live on.

- **Main + side.**
  - Any page can return `side` sections. At ≥1360 (so the main column keeps at least ~700px) they sit in a 300px sticky column to the right of the main column, with a 32px gap. Below that they follow the main sections. Rows always wrap their chip and button under the text when the column is narrow, and never overflow.
  - Side holds context, never the primary task: key dates, the team, who to call, recent activity, "About this guide".
- **Hero** (`k:'hero'`): the overview of any scope (edition, event, partner) and the volunteer's next shift. It replaces the page header (`noHead`).
  - Card: radius 18, padding 22.
  - Content: a 56px tile, status chips, the title (26/600; 22 on phone), one sentence, an optional 96px progress ring (stroke 8, `#e6e6e1` track), and a facts grid (auto-fit, min 150px) above a hairline. Up to 3 actions, one primary.
- **Agenda** (`k:'agenda'`): key dates, run of show, calendar, deadlines and shift briefings.
  - Layout: a time column (mono 12.5, right-aligned), a 2px rail, a 12px dot, then the title, detail, owner and an optional chip.
  - Dot states: done (filled green), now (red ring, bold title), late (filled red), next (grey ring). The last item's rail stops at the dot.
- **Board** (`k:'board'`): event work.
  - Lanes: Late · Not started · In progress · Done, on a `#f6f6f3` lane background with radius 14. Cards are white, radius 12, showing ID (mono), title, owner avatar and name, due (red when late) and budget.
  - It sits behind the View filter (Board | Table), shares the other filters, and the choice is remembered.
  - Columns: 4 at ≥1200, 2 on tablet, stacked on phone.
- **Review desk** (`k:'desk'`): nominations.
  - Desktop: queue 290px (230 on tablet) + detail. Phone: the queue, then the detail with "All {n}" back.
  - Queue rows: ID, chip, name, category. The current row has a white background and a 3px ink inset.
  - Detail, in order:
    1. the nominee header (48px initials, name, category, school, ID);
    2. who nominated and when (volunteers never see the nominator);
    3. **Why they're nominated** (15/1.65 on `#fafbf8`);
    4. Evidence (each item opens after a file check);
    5. Platform checks (✓ ! × in tone circles);
    6. the volunteer recommendation with their name and agreement rate (moderators only);
    7. the possible match card (amber) with Compare and Merge (moderators only).
  - Sticky decision bar:
    - Moderator: **Approve · Return · Reject**. Return and Reject need a reason chip and take an optional line, then confirm.
    - Volunteer: **Looks complete · Flag something** (reason chips), with "A moderator decides. Your check goes with your name."
    - Deciding moves to the next item and shows a toast with Undo.
- **Article** (`k:'article'`): the handbook.
  - 720px measure, 15/1.7 body, 19/600 headings with anchors, numbered steps (28px green-wash numerals), tables (12 header on `#fafbf8`), and callouts: **Rule** (green wash), **Watch out** (amber), **Tip** (blue-grey).
  - Side: About this guide (owner, last checked, read time, roles), On this page (scrolls to the heading), Related.

### 2.8 Handbook (v6)

- **The handbook is its own scope.** The root rail's Handbook opens it, with its own menu: Handbook home · Rules in force · one group per chapter, listing only the guides the viewer's role may read. Groups collapse except the current one.
- **Source of truth:** `handbook/handbook.json` and `handbook/{chapter}/{slug}.md` (front-matter: slug, chapter, roles, owner, reviewed, minutes, pages, open, related). Store the guides as Markdown in the repo, render them server-side, and make them searchable in ⌘K.
- **Home:**
  - main: Start here (the start chapter, numbered) and Chapters (cards with count and description);
  - side: counts, Recently checked, and how to correct a guide.
- **Rules in force:** every `> **Rule:**` from every guide, grouped by chapter, each linking to its guide. A rule describes platform behaviour, so each one needs a test.
- **Contextual:**
  - Every console page whose key appears in a guide's `pages` gets a **Guide** button in its header.
  - The Ask pane lists "From the handbook" (up to 3 guides for this page).
  - A guide's `open` link is its primary action ("Open the review queue").
- Volunteers' guides stay as each scope's **Role guide**, not this handbook.

### 2.9 Depth (v7): tables, records, forms, comparisons

- **Tables (`k:'table'` + `tk`)** gain these:
  - **Sorting:** click a header; it cycles ↑ ↓. Numbers sort numerically. The state is in the URL.
  - **Row selection:** an 18px checkbox column; the header box selects all, with ✓ for all and – for some.
  - **Bulk bar:** green wash, "{n} selected", the actions, then Clear. Every bulk action logs one audit entry per row and offers Undo.
  - **Saved views:** quiet tabs above the table with counts, plus "+ Save view", which stores the filters, the sort and the view per person.
  - **Paging:** the footer shows "1–25 of 2,588" with ‹ ›. Serve 25 rows a page.
  - **Dense rows:** 44px when the table has more than 8 rows, 50px otherwise.
- **Record pages.** Clicking a row opens the record **in the page**, not a drawer:
  - a hero (identity, chips, a 4-fact grid, actions);
  - record tabs in the tab bar;
  - a side column with related records;
  - the crumb trail ending in the record. Back closes it.
  - The record types designed:
    - **Nomination:** Overview · Checks and flags · Duplicates · Activity.
    - **Finalist:** Profile · Votes (chart against the category average) · Scores (judge × criterion) · Messages · Activity.
    - **Payment:** Summary · Gateway · Activity.
    - **Sponsor:** Overview (pipeline) · Contacts · Benefits · Activity.
    - **Volunteer:** Profile · Shifts · Hours · Activity.
    - **Shift** and **Task** for volunteers.
  - Route: `…/{list}/{id}[/{tab}]`.
- **Settings forms (`k:'form'`):** each block is a 240px label column plus fields (one column on tablet and phone).
  - Fields: text, number with unit, native select, switch, segmented radio, textarea.
  - **Locked** fields are disabled with a "Locked" chip and say why. A risky field carries a tone chip ("Production", "Top risk").
  - A floating **Unsaved changes · Discard · Save changes** bar appears on the first edit. Saving writes one audit entry per changed field.
- **Comparisons:** a chart bar can carry `p` (a previous value), drawn as a dashed outline behind the bar and named in the legend ("1st Edition, same day", "Category average", "Volunteer average").
- **Where it's applied:**
  - Nominations: review table with views, filters, bulk Approve/Return/Assign/Export, and the record.
  - Voting: chart against the last edition, the race by category → finalist record, integrity checks, held clusters, tiers.
  - Judging: the score matrix with spread flags → finalist scorecards.
  - Results release: checklist, the sealed table, what's issued.
  - Payments: gateway check with bulk Reconcile; a ledger with views and 48,210 rows paged → payment record.
  - Sponsors: pipeline stats, views → sponsor record.
  - Event and platform volunteers: roster with bulk Message/Invite → volunteer record.
  - Settings: General, Voting rules, Payments, Messaging, Security, Award setup › Rules, Event settings.
  - Volunteer: Shifts (Mine, Fits you with views and filters, All open) → shift record; Hours chart + table with views; Tasks → task record; Profile, Availability and Notifications forms.

## 3. Shell context contract

`ConsoleShell::context()` (admin) and `VolunteerShell::context()` (member area) both return this shape. **Templates read only this.**

```json
{
  "scope": "award", "instance": {"key":"tp4","name":"Teachers Prize · 4th","sub":"4th Edition · nominations open","tile":{"text":"TP","bg":"#e6f0f4","fg":"#1f5f8b"},
    "stage": {"label":"Nominations","index":1,"of":5,"pct":10}},
  "back": {"label":"All awards","href":"/admin/awards"},
  "nav": [{"group":"Nominations","open":true,"sum":0,"pages":[{"key":"nominations","label":"Nominations","icon":"noms","href":"…","count":42,"tone":"warn","current":true,"drill":false}]}],
  "tabs": [{"key":"review","label":"Review","href":"…","count":38,"current":true}],
  "crumbs": [{"label":"Awards","href":"/admin/awards"},{"label":"Teachers Prize · 4th"},{"label":"Nominations"}],
  "page": {"title":"Nominations","kicker":"Teachers Prize · 4th","purpose":"…","actions":[{"label":"…","href":"…","primary":true}]},
  "siblings": {"needs":[…],"live":[…],"past":[…]},
  "bell": {"production":1,"risk":2,"other":2},
  "find": {"do":[…],"pages":[…],"moved":[…],"open":[…]},
  "ask": {"scopeLabel":"Teachers Prize · 4th","starters":["…","…","…"]},
  "tip": true, "switchNote": null
}
```

- `count` comes from `HomeBoard::counts($actor, $scope)`, which also feeds Home, so the two never disagree. Omit counts of 0.
- `nav[].open` is computed server-side from §2.4 plus the stored preference, so there is no flash on load.
- `moved` comes from the alias table that sits next to the redirect table (§4.3).

---

## 4. Admin console (`AdminConsoleV4.dc.html`)

### 4.1 Root rail (from `AdminNav::GROUPS`, regrouped by job)

Home · Inbox · **Requests** (Waiting on you, All open, Decided) · Calendar
**Run:** Awards (Editions, Awards page) · Events (Events, Registrations, Stand presets) · Challenges · Giving (Partners, Community return)
**Reach:** Audience (Contacts, Broadcasts, Newsletter, Seasonal greetings) · Forms (Submissions, Partner enquiries)
**Content:** Posts (Posts, Opportunities, Legacy vault) · Media · Moderation (Queue, Support tickets)
**Money:** Payments (Gateway check, Ledger, Vote delivery, Vote recovery) · Refunds (Requests, Disputes) · Payouts · Finance (Overview, Vendor rules) · Shop (Orders, Products, Discount codes, Delivery charges)
**Organisation:** People (Team, Volunteers, Judges) · Alerts · Handbook · **Data and audit** (Audit log, Integrity, Analytics, All data) · Settings →

Names in brackets are tabs. Each was a `children` item, and the "also here" strip is deleted once its tabs ship. "Audit log" is renamed **Data and audit** because it holds more than the log.

### 4.2 Scopes

These are full lists in `nav-admin.json`.

- **Edition:** Overview · Nominations (Review, Review desk, Duplicates, Nominees, Profiles) · Interviews and questions · Shortlist · Judging (Panels, Schedule, Rubric, Judging audit) · Voting · Results release (Release, Recognitions) · Events · Volunteers · Award setup (Basics, Look, Nomination form, Rules, Categories, Details and terms).
- **Event:** Today · Plan (Work, Timeline, Risks, Decisions) · Award · People (Team, Volunteers, Nominees, Seating, Invitations) · Relationships (Sponsors, Contacts, Letters, Messages) · Money (Budget, Approvals, Vendors, Income) · The night (Run of show, Door, Safety) · Growth · Reports (Status, Post-event, Audit) · **Requests** · Event settings (General, Members, Limits, Devices).
- **Partner:** Overview · Pages · Donations · Payouts · Their team · Brand and page.
- **Settings:** General, Award defaults, Voting rules, Payments, **Spending limits**, Messaging · Integrations, Email health, Webhooks, **API keys** · Team and roles, Security and sign-in, Legal, AI prompts, Test data · **Tools:** Interview bot, Operations scripts.

### 4.3 Moved pages (the Find aliases; also 301s)

| Old page | Now |
|---|---|
| Programmes | Run › Awards |
| Judges, Judging rubric | each edition › Judging |
| Result release, Recognitions | each edition › Results release |
| Shortlists | each edition › Shortlist |
| Review desk, Duplicates | each edition › Nominations |
| Integrations, Email health, Webhooks, AI prompts, Legal, Test data | Settings › same name |
| Team | Organisation › People |
| Contacts, Broadcasts | Reach › Audience |
| Audit log, Analytics, Integrity | Organisation › Data and audit |

**Test:** every old URL redirects to a page the same role can open, and every alias lands on that same page.

### 4.4 Home

Home has one subtitle, computed from the queue: "{n} things need a person. One stops payments being recorded, so start there." It then has these sections, in this order:

1. **Fix now**: affects production, boxed.
2. **Today**: has a link to "Everything assigned to you".
3. **Can wait this week**.
4. **Running now**: up to 3 cards, each naming its stage ("stage 3 of 5") and showing a goal bar.
5. **Platform health**: 3 stats, always last.

Every row says **what happens if it waits**, then gives one CTA.

### 4.5 Moderator

A moderator gets the same shell. Pages are filtered by `canOpen`, and scopes by programme grants. The production pill shows only if the moderator can open Alerts.

---

## 5. Event lead (`Events PM Console v4.dc.html`)

- It is the admin shell with `viewer=lead`. The lead's root is a small console of its own, covering **only the events they hold a grant on**:
  - **Overview**: the subtitle gives the count of events, the nearest night and its blockers. Then **Blocking a night** (boxed, red), **Your events** cards (days to go, readiness bar, budget and tickets line, blocker or risk chip), **This week across both** stats (work due/late, approvals waiting, open risks, tickets), and **Next deadlines** (3 rows + "All deadlines").
  - **Inbox**: work assigned to the lead by name, with the event tile.
  - **Your events**: the list.
  - **Across your events:**
    - **Deadlines**: Late, This week, Later. Award dates carry a "Locked" chip.
    - **Risks**: High (boxed), Medium. Each with likelihood, impact and owner. "Add a risk".
    - **Approvals**: Waiting on finance (**Remind Sade**), then You can approve (under the limit), with the limit note.
    - **Analytics**: each event against its last edition on the same day before the night. A first edition says "Nothing to compare yet" and when its numbers start.
  - **Team → People**: load across events, with over-capacity in red and **Rebalance**, plus volunteer shift fill.
  - Every row deep-links into the event scope on the right page and tab.
  - Counts: Inbox, Risks (red when any risk is high), Approvals. They are computed by `HomeBoard::counts($lead, 'leadroot')` across their grants.
  - With exactly one event the lead still lands on Overview; their single event is the first card. Back from an event goes to Overview. The switcher lists only their events.
- The lead sees the event scope minus anything their grant lacks. Finance-only actions are different for a lead:
  - Over-limit spending is a **request** (§2.6). Money › Approvals lists the event's spend requests (the same rows as Requests, never a second copy), plus what's under the lead's limit. The primary action is **Ask to spend**.
- **Award** in the event menu lists the linked awards with **View** (read-only). It never drills into the edition.
- **Bell = Updates:** May affect the night (red), then Waiting on others. There is no production pill.
- The lead's grant ends with the event (`expires_at`). Team and access are managed in Event settings.
- The work, timeline and run-of-show interactions still follow `Events PM Console v2.dc.html`.

---

## 6. Volunteer workspace (`Volunteer Workspace v4.dc.html`)

### 6.1 Root (`role` prop)

| role | Rail |
|---|---|
| active | Today · Shifts (Mine, Fits you, All open, Past) · Tasks (Mine, Library, Done) · Hours (Summary, In review, Log hours) · **Where you help** (one entry per grant) · Grow: Training (Next for you, Passed), My record (Overview, Badges, Reference letters) · You: Profile and access → |
| lead | as active + **Your team**: My team (Roster, To confirm, Gaps) |
| new | Today · Shifts · Tasks · Training · Profile and access → |
| applicant | Application · Training · Profile and access → |

The brand line under "Africa GATES" is the level: "Volunteer · Level 2", "Team lead · Level 3", "Volunteer · Level 1" or "Applicant".

### 6.2 Assignment scopes (built from the person's grants)

- **Award, with a nomination check grant:** Overview · Check nominations (To check, Done, Agreement) · Tasks · Role guide · Your access.
- **Award, with a voter drive grant:** Overview · Voter drive (Your link, Where to go) · Tasks · Role guide · Your access.
- **Event:** Today · Your shifts · Tasks · Briefing (Your cues, Map and exits, Who to call) · Who's on · Role guide · Your access.
- **The menu is built from the grant's capabilities, not the scope type.** One person can hold several grants across awards and events. One event can carry several awards, and an award can end in one event or several.
- **Award Overview must show the award's progress, not only the person's.** Sections, in order:
  1. Where the award is (steps);
  2. How the award is doing (goal bars);
  3. **Your part** (their numbers);
  4. Where it ends: the event, drilling in if they're on it, otherwise "Tell me when shifts open".
- **Your access** on every scope has three short lists: You can see · Stays hidden · Ends (the date, who granted it, "Ends automatically").

### 6.3 Smart rules

- **Today (active):**
  - **Next up** comes first: the next shift with what to bring, plus **Get pass** (saves an offline pass).
  - **Needs you** follows, sorted by consequence. An expiring training names the role it keeps.
  - Lead only: **Your team** (hours to confirm, gaps with named best fits).
  - Then **Where you help** cards, each showing both the award's progress and theirs.
  - Then **Your record** stats, including "To Level 3".
- **Today (new):**
  - Getting started steps.
  - Good first shifts, with a fit reason.
  - The note "Nothing is expected of you until you pick something."
- **Today (applicant):**
  - Application steps.
  - What it's waiting on, with **Change referee**.
  - "Do the training now and you can take a shift the day you're approved."
- **Fits you:** rank by availability, distance, training held and past shifts. Every row states its reason. A shift that needs training shows "After training" with **Train first**.
- **Clashes and changes:**
  - Overlapping shifts show chip "Clashes with {x}" (bad) and **Drop one**.
  - A changed time shows "Time changed" (warn) and **Accept change**. The bell shows it first.
- **Hours:**
  - Automatic sources (gate scans, accepted task work, verified sign-ups) need no approval.
  - A person logs hours by hand only for work nobody saw. A lead confirms it.
  - The weekly load line uses the person's own limit from Availability.
  - Hours are never edited after they are confirmed.
- **Reference letters:** generated only from the verified record. The person chooses who receives one.
- **Leads confirming hours:** "Confirm only what you saw or have proof of." Declining needs a reason, and the person sees it.
- Volunteer checks reach moderators as **recommendations with their name**. They never decide anything.

---

## 7. Access model (code)

- `Grants::allows(Actor $a, string $capability, ScopeInstance $s): bool`. A grant has an actor, a capability, a scope instance, `granted_by` and `expires_at`. A grant at a parent scope covers its children.
- Capabilities used by these designs:
  - `event.lead`;
  - `award.moderate`;
  - `vol.nomination_check`;
  - `vol.voter_drive`;
  - `vol.door`;
  - `vol.usher`;
  - `vol.interview_scheduling`;
  - `vol.team_lead`.
- `canOpen(Actor, path)` resolves the scope from the path, then checks the role OR a grant at that scope (or above it).
- Volunteer data views are masked server-side: phone numbers, emails, scores, judges' notes, ticket names and payments are never sent to volunteers. **Masking in CSS is a failed PR.**
- Every grant ends on its own. An expired grant removes the scope from the rail on the next request.

---

## 8. Less data (strict)

- A scope with nothing in it yet shows its setup steps as Overview. It never shows empty stats.
- An empty tab shows one sentence: "Nothing is waiting here for {scope}. When something arrives it shows here, oldest first." Add an action only if one starts the work.
- Omit counts of 0, and never draw a 0 badge or chip. A collapsed group with nothing in it shows no chip.
- Never show stats with no denominator or history. When there's no goal, show the number without a bar.
- Home with nothing to do shows "Nothing needs a person right now." plus Running now. The Fix now, Today and Can wait headings are dropped.
- Volunteer root with no grants: "Where you help" is hidden, and Today shows Good first shifts.

---

## 9. Acceptance (attach to each PR)

1. Screenshots at 390, 834 and 1440, for every `viewer` and `role` value.
2. Every page and tab in `nav-*.json` resolves for a role that can open it, and returns 403 for one that can't.
3. No nav item, tab or chip ever draws 0.
4. Switching keeps the page where it exists, and otherwise shows the switch note.
5. The bell's production count matches `/admin/alerts` for every role and grant.
6. Every old URL redirects, and every alias opens the same page.
7. A volunteer request to any `/admin` path gets 403, never a redirect into the console.
8. The phone passes the SKILL §14 checklist: 44px targets, 16px inputs, the bottom bar, sheets, and no hover-only affordances.
9. Keyboard: ⌘K, ⌘J, Esc, Alt [ ], Alt ↑, and Enter in Find.
10. Requests: every transition in `request-types.json` works, asking for info or declining without a note is refused, the requester sees the note, and the counts match the tables.
11. Filters: they round-trip through the URL; an empty result shows the in-table line, never an empty card.

## 10. PR order

1. Shell partials, tokens and `shell.js` (rail, scope rail, switcher, crumbs, tabs, bell, find, ask, drawer, toast, phone bars), on today's root, with zero URL changes.
2. `NavRegistry` seeded from `nav-admin.json`, plus `RootScope` with §4.1, the tabs replacing `children`, and the alias and redirect table.
3. `SettingsScope`.
4. `EditionScope` (moving ReviewQueue and ReviewDesk to the domain).
5. `EventScope`, which absorbs the PM console, then carving up `EventsController`.
6. `PartnerScope`.
7. `Grants` + the lead root.
8. Shared components: filter bar + picker, chart, table (with the phone list), and Requests (tables, composer, drawer, `request_events`).
9. `VolunteerShell` + `/volunteer/*`, seeded from `nav-volunteer.json`, plus volunteer grants and masking.
10. Smart behaviours: remember last page, smart groups, fit ranking, automatic hours.
11. Depth: table sort, selection, bulk, saved views and paging; record pages; settings forms with the save bar; comparison bars.
12. Hi-fi kinds (main + side, hero, agenda, board, review desk, article) and the handbook scope with Markdown guides.
12. Platform pages: Spending limits, API keys, Devices, Members/Invitations, the Audit log table, Analytics and Finance dashboards.
13. Delete the "also here" strips and the v1, v2 and v3 console templates.
