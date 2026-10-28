# Admin console: code restructure plan (v3)

Read from `src/Admin/Support/AdminNav.php`, `ConsoleShell.php`, `Permissions.php`, `src/Admin/Controllers/*` (66 controllers) and `src/Admin/Services/*`, together with `docs/architecture/AUDIT-AND-PLAN.md` (§5, §6, §7.2, §9.10).

## 1. What the code does today (keep these properties)

- `AdminNav` is one registry. It holds Home, Top (Calendar), and six groups: Awards, Engage, Audience, Money, Content, Organisation. Each page has `children` drawn as an "also here" strip.
- Visibility is `Permissions::canOpen($role, $href)`, the guard's own function. `AdminNavTest` pins every page's gate.
- `ConsoleShell::context()` answers every shell question once: rail, crumbs, tabs, pins, counts, the alert pill, the palette's destinations, the New menu and recent pages.
- Counts come from `HomeBoard::counts()`, so the rail and Home never disagree.

## 2. What's wrong structurally

1. **Everything is platform-wide.** Awards pages filter by `?cycle=`. An edition, an event or a partner is not a place, so the rail cannot say where you are, and the event lead console has to be a separate product.
2. **`children` mixes two ideas:** views of the same object (Review, Desk, Duplicates) and neighbouring pages (Recognitions under Result release).
3. **Controllers are very large** and hold queries directly: `EventsController` is 87 KB, `NominationsController` 62 KB, `InvitesController` 38 KB and `InterviewsController` 37 KB. 63 of 116 controllers query the DB.
4. **The role gate is global.** An event lead or a scoped moderator can't be expressed without a new global role.

## 3. Target

### 3.1 Scopes in the registry

```
src/Admin/Nav/
  NavScope.php          // interface: key(), resolve(path): ?Instance, nav(Actor, Instance), tabs(page), back(), siblings(Actor, Instance)
  Scopes/RootScope.php      // today's groups, regrouped: Home, Inbox, Calendar · Run (Awards, Events, Giving) · Reach (Audience, Content) · Money · Organisation (People, Alerts, Handbook, Audit, Settings→)
  Scopes/EditionScope.php   // /admin/awards/{programme}/{edition}/...
  Scopes/EventScope.php     // /admin/events/{event}/...   (the Events PM console IS this scope)
  Scopes/PartnerScope.php   // /admin/giving/{org}/...
  Scopes/SettingsScope.php  // /admin/settings/...
  NavRegistry.php       // ordered list of scopes; resolve(path) → [scope, instance, page, tab]
```

- `AdminNav::GROUPS` becomes `RootScope::nav()`.
- Each page entry carries `tabs` (views of the same object). Neighbouring pages stay as their own nav items in the same group.
- `ConsoleShell::context()` calls `NavRegistry::resolve()`. It then returns `scope`, `instance`, `back`, `siblings` (for the switcher), `nav`, `tabs` and `crumbs`, in addition to what it returns today.

### 3.2 Routes

| Today | v3 | Notes |
|---|---|---|
| `/admin/nominations/review?cycle=12` | `/admin/awards/teachers-prize/4/nominations/review` | 301 from the old URL using the cycle → slug map |
| `/admin/judges`, `/admin/rubric`, `/admin/judging-audit` | `/admin/awards/{p}/{e}/judging/{panels,schedule,rubric,audit}` | **D5:** keep each route's current gate; the scope only regroups |
| `/admin/result-release` | `/admin/awards/{p}/{e}/results/release` | |
| `/admin/events/{id}` (+ the PM console) | `/admin/events/{id}/{today,plan,award,people,crm,money,night,growth,reports,settings}` | One console, not two |
| `/admin/partner-orgs/{id}` | `/admin/giving/{org}/...` | |
| `/admin/settings/*`, `/admin/legal`, `/admin/ai-prompts`, `/admin/sandbox`, `/admin/webhooks` | `/admin/settings/{section}` | Integrations and Email health keep their `health` gate (`SUBPATH_SECTIONS`) |

Every old URL keeps working through a redirect table that a test checks, because mail alerts link `/admin/settings/mail`.

### 3.3 Access

- `Permissions::canOpen($role, $path)` stays as it is for platform roles.
- Add `Grants::allows(Actor, capability, Scope instance)` for scoped staff, such as an event lead on one event or a moderator on two programmes.
- `canOpen` becomes `canOpen(Actor, path)`. It resolves the scope from the path, then checks the role OR a grant at that scope (or above it).
- Grants expire (`expires_at`).
- Volunteers are **not** admin actors. `AdminAuthMiddleware` refuses them just as it refuses `judge` (see the volunteer handoff).

### 3.4 Controllers → application services

Split by scope and page. Each controller parses the request, calls one service and renders.

```
src/Admin/Http/Edition/{Overview,Nominations,Judging,Voting,Results,Setup}Controller.php
src/Admin/Http/Event/{Today,Plan,People,Money,Night,Reports}Controller.php
src/Domain/Nominations/Application/{ReviewQueue,ReviewDesk,Duplicates}.php   // moved from Admin/Services
src/Domain/Events/Application/{EventPlan,Budget,RunOfShow,Door}.php           // carved from EventsController (87 KB)
```

Use a ratchet test: controllers with direct DB calls start at 63 and may only go down.

### 3.5 Templates

```
templates/admin/shell/{layout,rail,rail-scope,switcher,crumbs,tabs,bell,find}.twig
templates/admin/{root,edition,event,partner,settings}/<page>[/<tab>].twig
```

`rail-scope.twig` draws Back + the identity block + the scope nav. `switcher.twig` is one partial: a popover on desktop, a bottom sheet on phone.

### 3.6 Counts and the bell

- `HomeBoard::counts()` gains a scope argument, so a scope's nav badges come from the same read.
- `ConsoleAlerts::pill()` returns `{production:int, risk:int, other:int}`. The header shows the red "N affects production" pill only when `production > 0`.

## 4. Order (one PR each, each green)

1. `NavScope` + `NavRegistry` + `RootScope` (today's groups), with zero URL changes. Gate parity is checked by `AdminNavTest`.
2. Shell partials (rail-scope, switcher, crumbs, tabs, bell, find) on Root.
3. `SettingsScope` (lowest risk) + redirects.
4. `EditionScope` + redirects + moving `ReviewQueue`/`ReviewDesk` to the domain.
5. `EventScope`, which absorbs the PM console, + carving up `EventsController`.
6. `PartnerScope`.
7. `Grants` + the event lead root ("Your events").
8. Delete the `children` strips that tabs have replaced.

## 5. Tests to add

- Every scope's pages resolve. Every old URL redirects to a page the same role can open.
- Switching between siblings keeps the page when it exists, and otherwise lands on Overview.
- No nav item draws a 0 badge.
- For every role and grant, the bell's production count matches `/admin/alerts`.
