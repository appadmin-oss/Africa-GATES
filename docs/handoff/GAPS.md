# Redesign recon — what exists, what does not, and what blocks a later phase

**Phase 0 of `design_handoff_africa_gates`.** No template, CSS or JS was changed. The only
other file this phase created is `.claude/skills/app-ux-standards/SKILL.md`, which Phase 0
item 1 requires.

Everything below was read out of the codebase, not assumed. Where a row says MISSING, the
searches that found nothing are named, because "I could not find it" and "it is not there"
are different claims and only the second one is worth acting on.

---

## 1. How the route list was produced

`src/routes.php` is **not parsed anywhere in this document.** `CLAUDE.md` records that a
parser found 563 routes where the router has 755, because the API group is a closure
mounted twice and the group proxy variable `$a` is shared by three unrelated trees. So the
container and the route file are booted the way `public/index.php` does and
`getRouteCollector()->getRoutes()` is read — the same method `RouteTableIntegrityTest`
uses.

| | count |
|---|---|
| verb–path pairs registered | **793** |
| GET | 401 |
| GET, excluding `/admin`, `/api`, `/judge`, `/__cron`, `/__setup`, and file extensions | **202** |
| …of those, controller-backed | 93 |
| …of those, closure-backed real handlers | 44 |
| …of those, 301 aliases from the alias table | 65 |
| **public GET routes that render a page** | **106** |

**A closure is not a redirect.** `/philosophy`, `/integrity`, `/status`, `/cookies`,
`/terms`, `/privacy` and `/legal/{slug}` are all real pages registered as closures
(`src/routes.php:2782`, `:2820`, `:2896`, and the legal group). The 65 genuine aliases were
identified by reading the `$aliases` table (`src/routes.php:1852`, applied by the loop at
`:1943`) and subtracting it, not by guessing from the handler type.

---

## 2. Stack facts (confirmed)

| Fact | Answer | Where |
|---|---|---|
| CSP nonce | a Twig **global** named `csp_nonce`, not a function | registered `config/container.php:243`; produced by `Csp::nonce()`, `src/Support/Csp.php:50-57`; used as `{{ csp_nonce }}` throughout `templates/layout/gates.twig` |
| …inside macros | macros do **not** see globals, so the nonce is passed as an argument — `article_ld(d, nonce)`, `script(d, nonce)` | `templates/partials/article.twig:64, 323` |
| `asset()` | Twig function → `Assets::url()`. Per-file **content hash** (`?v=<hash>`), not a manifest. `xxh3` where available, else `crc32b`. Falls back to the shared `asset_version` for a missing file | registered `config/container.php:377-380`; `src/Support/Assets.php:106-160` |
| CSS bundle | separate concept: `AssetBundle::url()` content-hashes a built bundle, exposed as the `css_bundle` global; the layout falls back to individual sheets when null | `config/container.php:250`; `templates/layout/gates.twig:227-262` |
| Alpine | **3.13.5**, self-hosted and vendored, loaded `defer`. No npm or composer dependency | `public/assets/js/vendor/alpine-3.13.5.min.js`; `templates/layout/gates.twig:523` |
| …and the CSP | `'unsafe-eval'` is in the policy *because* Alpine 3 compiles expressions with `new Function` | `src/Support/Csp.php:29-34` |
| `article.twig` macros | `styles()`, `cite_meta(d)`, `article_ld(d,nonce)`, `masthead(d)`, `tools(d)`, `contents(groups,wide)`, `blocks(list)`, `next(d)`, `cite(d)`, `script(d,nonce)` | `templates/partials/article.twig:30,42,64,88,112,177,202,230,247,323` |
| `gee.js` entry points | an IIFE with no init API. Globals: `window.openGee`, `window.closeGee`, `window.toggleGee`. State in sessionStorage under `gee.msgs.v1`, `gee.size.v1`, `gee.sheet.v1`, `gee.seen.v1` | `public/assets/js/gee.js:522-524`, `:31-34` |
| `supportDesk()` | **exists, but it is not Gee's.** It is an Alpine component defined inside a nonce'd inline script in the support page template, and appears nowhere in `gee.js` or `src/` | defined `templates/pages/support-assistant.twig:395`, consumed `:215` |

---

## 3. The gaps table

The handoff's expected status is quoted from `phases/PHASE-0-recon.md` §15. **Where the
column "Found" disagrees with it, the codebase wins and the phase brief needs correcting.**

| # | Feature | Handoff says | Found | Where |
|---|---|---|---|---|
| 1 | Recognitions + verified issuers | NEW | **MISSING** | no `gates_recognitions`, no issuer table, no `issuer_type`/`verified_at` on an issuer. `grep -rn "gates_recognitions\|gates_issuers"` → 0 hits across all four schema files and every migration |
| 2 | Award terms versioning + acceptance | NEW | **PARTIAL** | body exists as a single mutable blob `gates_award_programmes.terms` (`database/migrations/2026_06_30_programme_terms.php:9-12`), rendered at `/terms/{slug}` → `templates/pages/programme-terms.twig`. No `gates_award_terms`, no acceptance table, no version/effective_at/changelog. The accept checkboxes on nominate (`templates/pages/nominate.twig:413`) and vote (`templates/pages/vote-nominee.twig:1186,1260`) are **client-side only and persist nothing** |
| 3 | Coming-soon awards + notify (double opt-in) | NEW | **PARTIAL** | the state is fully wired: `CyclePhase::Upcoming` (`src/Services/CyclePhase.php:27`), label "Opens soon" (`:62`), `gates_award_cycles.status ENUM('upcoming',…)`, rendered in `awards/index.twig:41,102,108` and `vote.twig:117,189,…`. A notify signup exists (`gates_newsletter`, `POST /api/v1/newsletter/subscribe`, `src/Controllers/ApiController.php:439-476`) but is **single opt-in** (no `confirm_token`/`confirmed_at`; the mail sent is a welcome, not a confirmation) and **not award-scoped** (no `programme_id`/`cycle_id`; `source` is free text). No notify form on any awards page |
| 4 | Overall edition winner + top 3 | NEW (compute) | **EXISTS** | `ResultRelease::overall()` (`src/Services/ResultRelease.php:687-768`) ranks every `in_running` nominee across the cycle; `PublicResults::overallFor()` returns `top4` (`src/Services/PublicResults.php:422`) and the page already renders 2nd–4th (`templates/pages/results/edition.twig:291`). **Do not rebuild this in Phase 5.** One nuance: it is computed on read and never sealed, and always flags `reconstructed => true` (`PublicResults.php:364-378,423`) |
| 5 | Wall of recognition | NEW | **PARTIAL** | a real moderated approved-only wall exists, but it is **per nominee** and made of supporter messages: `gates_vote_messages` (`database/migrations/2026_08_22_vote_messages.php:56-113`), `VoteMessageService::wall()`, routes `src/routes.php:2187,2190`. No `gates_testimonials` (0 hits), no site-wide wall, no masonry (`column-count`/`grid-auto-rows` → 0 hits) |
| 6 | Search API + palette | NEW | **PARTIAL** | the palette **exists**: `templates/partials/site-search.twig`, `public/assets/js/ag-search.js` (full ARIA combobox, focus trap, `/` and ⌘K at `:224-225`). The JSON endpoint exists at **`/activity/search`** (`src/routes.php:2112`), not `/search` — `/search` is a **301 alias to `/activity`** (`src/routes.php:1885`). **No `scope` parameter exists at all**, and the kind vocabulary (`result, award, org, nominee, profile, phase, post, event, thread, page`) is not the spec's (All/People/Awards/Events/Pages), and kinds are produced only by an intent pass, never accepted from the client |
| 7 | DisplayReading persistence + first-paint | NEW | **MISSING** | `grep -rn "ag-a11y"` → 0 hits. No settings screen, no profile column, no first-paint class application. The two nonce'd `<head>` scripts in `gates.twig` are lazy-CSS promotion (`:202-214`) and the intro loader (`:319-334`). `public/assets/css/a11y.css` is a static WCAG correction layer with no user control |
| 8 | Quick settings sheet + first-visit language prompt | NEW | **MISSING** | no bottom sheet, **no avatar in the header at all** (`grep -n avatar templates/layout/nav.twig` → 0), no `role="switch"` rendered anywhere, and **no i18n layer of any kind** — see §5.3 |
| 9 | Celebrations | NEW; JS/CSS supplied | **PARTIAL, and the names collide** | an engine exists under a different name: `public/assets/js/celebrate.js` (155 lines, three-beat, honours reduced motion), `templates/partials/celebrate.twig`, vendored `canvas-confetti-1.9.3.js`, six call sites. Seen keys are `ag-celebrated:<key>` (`celebrate.js:58,62`), declared in `src/Support/CookieRegistry.php:123` — **not** `ag-cel-*`. The files to ship (`celebration.js`, `celebration.twig`, `celebration.css`) do not exist, and **there is no celebration CSS file at all** |
| 10 | Gee restyle + privacy note + `--ag-bottom-ui` | EXTEND `gee.js` | **EXISTS, two gaps** | `public/assets/js/gee.js` (627), `public/assets/css/components/gee.css` (303), markup `gates.twig:391-470`. **No dismissible privacy note** — only a fixed line "Gee can make mistakes — check important details." (`gates.twig:468`). **`--ag-bottom-ui` is not implemented**: the FAB rail is hand-stacked with fixed offsets and `!important` (`gee.css:15,16,19,237-239`). The variable appears exactly once in the repo, in the skill file installed by this phase |
| 11 | Pulse post kinds + guest banner | EXTEND | **PARTIAL** | exactly 4 reactions ✓ (`cheer/insight/respect/support`, `pulse.twig:1514-1520`, stored as `gates_cheers.kind`). Guest banner ✓ (`pulse.twig:773-776`, `:1098`). **No post kind**: `gates_threads` has no kind column, and the only discriminator is `'result' \| 'post'` (`src/Services/PulseFeedService.php:181`). "Photo" is the presence of `media_path`, not a kind |
| 12 | Event tier colours, glow, states, waitlist | EXTEND | **PARTIAL** | tiers carry **one** column, `colour VARCHAR(12)` holding a **slot name** (`2026_09_17_tier_colour.php:42-48`); hex is derived at read time from `gates_site_events.ticket_accent` by `EventTierPalette`, which ships `fill/ink/edge` — **not** `accent/accent_light/wash/deep/glow` (0 hits for `accent_light`). A 10-layer animated glow exists (`events/detail.twig:400-443`) but **`@property` and `--ev-a` do not exist anywhere in the repo**. All five states exist but spread across three variables plus an extra `early`. Waitlist works (`src/Services/EventWaitlist.php`, `POST /events/{slug}/waitlist`) with `email`/`tier_id`/`hold_expires_at` — but on `gates_event_registrations` with `status='waitlisted'`, deliberately, not a waitlist table |
| 13 | Shop multi-select filters, load more, restock alerts, order page | EXTEND | **PARTIAL** | category filter is a **single-select radio** (`shop/index.twig:414,420`) and the server reads a scalar (`ShopController.php:96`, `ShopCatalogue.php:462,491`) — no `c[]` anywhere. Pagination is **numbered**, no load-more (`shop/index.twig:614-631`). Restock alerts **exist** (`gates_stock_alerts`, `2026_09_09_stock_alerts.php:34-74`, `POST /shop/{slug}/notify-me`, stop-by-token page) — but the column is `cancelled_at`, not `stopped_at`, and identity is `product_id`+`variant_id`, not `sku`. The reference-only order page **exists** (`GET /shop/order/{ref}` → `templates/pages/shop/order.twig`) |
| 14 | Status by country, maintenance, subscribe | NEW | **MISSING (all three)** | `SystemStatus` probes six **subsystems**, not regions; no country dimension anywhere in `report()`/`payload()`/`timeline()`. No maintenance table or column (`gates_maintenance`, `maintenance_window` → 0 hits); incidents are derived retroactively from snapshots. Subscribe is absent **and explicitly refused in the template's own rules** — see §5.4 |
| 15 | Two-link mega nav | EXTEND `nav.twig` | **PARTIAL** | the nav is at **`templates/layout/nav.twig`**, not `partials/nav.twig`. The two mega panels exist and are the right two (`data-ag-mega` at `:49` and `:67`). But there are **four** top-level items: a standalone `Pulse` (`:47`) and a standalone `Leaderboard` (`:83`, also duplicated inside Participate at `:57`) flank them |
| 16 | Nominee race, ballot, winner state | EXISTS; restyle | **PARTIAL** | race block exists with rank, count, gap both ways, and a bar plus a next-higher tick (`vote-nominee.twig:639-677`) — but the count is **server-rendered, not polled**; live tallies exist only on the programme page (`/vote/{program}/tallies`). Ballot has all the parts, in a different order, and **the paid path has no phone field** (`:1069,1107,1128`). **There is no `phase=won`** — the winner state is a separate controller variable `award_kind` from `gates_nominees.status` (`VoteController.php:609`, rendered `vote-nominee.twig:773-789`), deliberately (comment at `:1399-1402`) |
| — | `gates_award_cycles.edition_number` | assumed addable | **DOES NOT EXIST** | the table has `year` (YEAR) + `edition_label` (free text). An edition is identified by `programme_slug + year` (`PublicResults::editionUrl()`). Three migrations touch this table; none adds `edition_number` |

---

## 4. Route → design reference map

106 public GET routes render a page. Every DC in `design/` maps as follows. `design/_archived/`
and `Nominate.dc.html` (the review canvas) are not specs and are excluded.

| Route(s) | Design reference | Phase | Template today |
|---|---|---|---|
| `/` | `HomePageV3` + `WeAreAfrica` | 4 | `pages/home.twig` |
| — **no route exists** — | `DiscoverPage` | 4 | **none — see §5.5** |
| `/activity`, `/activity/search` | retired; 301 to Discover | 4 | `pages/activity.twig` |
| `/awards` | `AwardsPage` `view=index` | 5 | `pages/awards/index.twig` |
| `/awards/{p}` | `AwardsPage` `view=detail\|soon` | 5 | `pages/awards/programme.twig` |
| `/vote` | `VoteHub` | 5 | `pages/vote.twig` |
| `/vote/{program}` | `VotePage` | 5 | `pages/vote-program.twig` |
| `/vote/{program}/{slug}` | `NomineePage` + `VoteBallot` | 5 | `pages/vote-nominee.twig` |
| `/vote/verify`, `/vote/paid/success` | `VotePage` `view=verify\|done` | 5 | `pages/vote-verify.twig`, `pages/vote-paid-success.twig` |
| `/results`, `/results/{edition}`, `/results/{slug}`, `/winners` | `ResultsPage` | 5 | `pages/results/{index,edition,show,hall}.twig` |
| `/leaderboard` | `Leaderboard` | 6 | `pages/leaderboard.twig` |
| `/legacy`, `/legacy/{slug}` | `LegacyVault` `view=index\|edition` | 6 | `pages/legacy/{index,event}.twig` |
| `/registry/{slug}` | `ProfilePage` | 6 | `pages/registry/profile.twig` (**not** `pages/profile.twig`) |
| `/events`, `/events/{slug}` | `EventsPage` `view=index\|detail` × 5 states | 7 | `pages/events.twig`, `pages/events/detail.twig` |
| `/events/ticket/{ref}` | `TicketPage` | 7 | `pages/events/ticket.twig` |
| `/shop`, `/shop/{slug}`, `/shop/success`, `/shop/order/{ref}`, `/shop/back-in-stock/stop/{token}` | `ShopPage` `view=index\|item\|done\|order\|stopped` | 7 | `pages/shop/*.twig` |
| `/giving`, `/giving/{slug}`, `/giving/success` | `GivingPage` `view=campaign\|checkout\|done` | 7 | `pages/donate*.twig` |
| `/pulse`, `/pulse/reels` | `PulsePage` | 8 | `pages/pulse.twig` |
| `/nominate`, `/nominate/success` | `NominateHub` + `NominationFlow` | 8 | `pages/nominate.twig`, `pages/nominate-success.twig` |
| `/account/login`, `/account/register`, `/signin` | `SignIn` `view=phone\|code\|profile\|interests` | 8 | `pages/account/*.twig` |
| `/integrity`, `/philosophy`, `/terms`, `/privacy`, `/cookies`, `/legal/{slug}`, `/terms/{slug}` | `DocPage` `doc=…` | 9 | `pages/integrity.twig`, `pages/philosophy.twig`, `pages/legal.twig`, `pages/terms.twig`, `pages/programme-terms.twig` |
| `/help`, `/help/c/{cat}`, `/help/{slug}` | `HelpCentre` `view=index\|article` | 9 | `pages/help*.twig` |
| `/blog`, `/blog/{slug}` | `BlogPage` `view=index\|post` | 9 | `pages/blog/*.twig` |
| `/status` | `StatusPageV2` | 9 | `pages/status.twig` |
| every page | `SiteHeader`, `AppBar`, `MobileMenu`, `DisplayReading`, `CookieConsent` | 2 | `templates/layout/nav.twig`, footer, cookie partial |
| every page but `/pulse` | `Gee` | 3 | `gates.twig:391-470` |
| success surfaces | `Celebration` | 3 | `templates/partials/celebrate.twig` |

**Routes with no design reference** (they keep their current design unless a later phase says
otherwise): `/judges`, `/judges/{slug}`, `/registry`, `/opportunities`, `/community*`,
`/partner`, `/org`, `/org/login`, `/support*`, `/claim/*`, `/my-work/*`, `/interview/*`,
`/door/*`, `/honour/*`, `/stand/*`, `/form/*`, `/f/{key}`, `/email/unsubscribe`,
`/m/{token}`, `/vote/{program}/{slug}/{flier,messages,supporters}`, `/events/{slug}/stands*`,
`/refunds`, `/vendor-terms`, `/giving/manage/{token}`, `/account/{verify,forgot,reset,logout}`,
`/account`.

---

## 5. Conflicts that block a later phase

These are not preferences. Each one is a rule the repo enforces with a passing test, or a
mechanism that makes the instruction inoperative. **REFERENCE §4.2 says to stop and ask
rather than guess, so none of them has been worked around.**

### 5.1 The type scale — Phase 1 cannot build `tokens.css` as written

`TypeScaleTest` holds a **closed** ladder of `10 · 11 · 12 · 13 · 14 · 16 · 17` below an
18px display floor, and scans `templates/` and `public/assets/css`. `CLAUDE.md` records why
the gap at 15 is deliberate: "14 is the reading size, 16 is the lede, and a rung between
them is an invitation to split the difference again."

The redesign's scale is built on half-pixel sizes:

| | count |
|---|---|
| `font-size` declarations across the 32 DCs | 1,617 |
| …that are off the ladder and below the display floor | **790 (49%)** |

The offending values and their frequency: `15px` ×296, `14.5px` ×164, `12.5px` ×129,
`13.5px` ×104, `15.5px` ×57, `11.5px` ×21, `16.5px` ×13, `10.5px` ×1, `9.5px` ×4, `9px` ×1.
The handoff's own Phase-1 starting CSS (`snippets/css/components.css`) already carries six of
them, `15px` included.

**This is not avoidable by careful implementation.** Either the ladder changes or the design
does. It needs a decision before Phase 1 writes a line.

### 5.2 Colour — the redesign's token layer would be overridden at runtime

The repo moved colour **out** of CSS on purpose. `public/assets/css/base/tokens.css:6-21`
says so in as many words: "NOT HERE ANY MORE. Every colour on this site is emitted at
runtime by `Support\Accent` … which the site layout writes into a nonced `<style>` **after**
these sheets so it wins the cascade."

That is exactly what happens: `templates/layout/gates.twig:275` renders
`<style nonce>{{ ag_accents()|raw }}</style>` after the stylesheet links at `:227-262`.
`Accent::css()` emits `:root{…}` containing `--ag-ground`, `--ag-bg`, `--ag-surface`,
`--ag-line`, `--ag-line-strong`, one `--ag-{name}` per neutral, and `--ag-{role}-{slot}` for
four roles × four slots.

So Phase 1's `public/assets/css/tokens.css` would be a **linked** stylesheet whose `:root`
declarations lose to the inline one for every name they share:

| name | `Accent` emits | REFERENCE §6.1 wants | |
|---|---|---|---|
| `--ag-ground` | `#f1efe9` | `#f1efe9` | identical |
| `--ag-surface` | `#ffffff` | `#ffffff` | identical |
| `--ag-ink` | `#10292c` | `#10292c` | identical |
| `--ag-ink-2` | `#3a4a4c` | `#3a4a4c` | identical |
| `--ag-mute` | `#8b9295` | `#8b9295` | identical |
| **`--ag-line`** | **`#d6d4cc`** | **`#e8e5dd`** | **collides, and the value differs** |

Five of the six are identical, so the override is invisible — which is what makes the sixth
dangerous. And the disagreement on `--ag-line` is not a typo, it is **structural**:

REFERENCE asks for two hairline weights — `--ag-line` `#e8e5dd` for hairlines and card
borders, `--ag-line-2` `#d6d4cc` for input and chip borders. `Accent` deliberately collapsed
both of the repo's legacy hairlines into one solid line, and its own comment gives the
reason: *"The ramp has ONE line, and the reason it is solid applies to both: an alpha border
takes its value from whatever sits behind it, which is why the same rule looked like two
different rules on a card and on the ground."* The redesign reintroduces the second weight.
That is a decision to take, not a value to patch.

Two colours also exist under both naming schemes: `#626a6e` is `--ag-soft` in REFERENCE and
`--ag-ink-soft` in `Accent`; `#e8e5dd` is both `--ag-line` and `--ag-tint` in REFERENCE and
`--ag-surface-2` in `Accent`.

Two further repo rules bear on this. `CLAUDE.md`: "Colour comes from `Support\Accent` and
nowhere else … never invent a fifth name." And `DeadTokenTest` fails on a declared custom
property nothing reads — the snippet declares **82** `--ag-*` properties, so any that no
component uses would fail on the first run.

The token values themselves are not the problem: every one of REFERENCE §6.1's 24 colour
tokens is present in `snippets/css/tokens.css` at the same hex, and `Accent::PAPER` is
already `#f1efe9`, `ink` `#10292c`, `ink-2` `#3a4a4c`, `ink-soft` `#626a6e`, `card`
`#ffffff` — identical to the redesign's neutrals. **The architecture is the conflict, not
the palette.**

### 5.3 There is no internationalisation layer at all

The redesign requires eight languages (EN, FR, AR with `dir="rtl"`, SW, PT, HA, YO, IG),
"every string goes through the translation layer", a `?lang=` parameter, an `ag_lang`
cookie, language chips labelled in their own language, a first-visit language prompt, and
**an RTL screenshot at 390 in the acceptance protocol of every phase.**

The repo has none of it: `grep -rn "ag_lang|navigator.languages|setLang"` → 0 hits; no
`i18n`/`locale`/`translat*` file under `src/Support` or `src/Services`; `ag_lang` is not in
`src/Support/CookieRegistry.php`, which `CookieRegistryTest` enforces as the complete list.

Phase 2 allocates this one line — "5. Language switching, including RTL" — as the fifth of
six items. Extracting every string in ~180 page templates into a translation layer is not
that. **This needs its own phase, or an explicit decision to ship English-only and drop RTL
from the acceptance protocol.**

### 5.4 Status subscribe — the design asks for what the page's own rules forbid

Phase 9 requires the status page to gain "subscribe". `templates/pages/status.twig:26`
carries this in its header rules: `· No "subscribe for updates" with nothing behind it.`
REFERENCE §0 puts the repo's existing templates below the design in authority, and the
`StatusPageV2` brief itself says "keep every rule in the twig header comment" — so the two
instructions contradict each other directly. The condition is satisfiable (build a real
sender first), but which way it goes is the owner's call, not mine.

### 5.5 Discover does not exist

`DiscoverPage` is treated as an existing page: the `§4` table maps it to `pages/discover.twig`
and Phase 4 lists that path under "Before you start". There is **no such template and no
such route** — `ls templates/pages/discover*` → nothing, and no `/discover` in the route
table. Phase 4 must build it end to end, including whatever feeds its Live tab, and its
Phase-4 budget should reflect that.

### 5.6 The acceptance protocol is not executable as literally written

REFERENCE §17 requires screenshots at 390, 834, 1024 and 1440 "for **every** prop combination
the DC exposes", plus RTL at 390. Counting the props each DC actually declares:

| DC | combinations |
|---|---|
| `ShopPage` | 360 |
| `NominationFlow` | 160 |
| `StatusPageV2` | 60 |
| `EventsPage` | 30 |
| `Celebration` | 20 |
| `DocPage` | 18 |
| `VotePage` | 18 |
| all 32 DCs | **837** |

837 × 4 widths = 3,348 screenshots, plus 837 RTL = **4,185**, before overlay diffs and
recordings. Most of the count is orthogonal props that cannot co-occur: `ShopPage`'s
`orderState` only means anything when `view=order`, and `doneState` only when `view=done`,
so its real distinct states are nearer 20 than 360. **Confirm that "every prop combination"
means every reachable state, not the Cartesian product.**

---

## 6. Corrections to the handoff's own claims

Small, but each would cost a session to discover mid-phase.

| The handoff says | Actually |
|---|---|
| `partials/nav.twig` | `templates/layout/nav.twig` |
| `pages/profile.twig` | `templates/pages/registry/profile.twig` |
| `pages/results.twig` | `templates/pages/results/{index,edition,show,hall,open,late}.twig` — six templates |
| `pages/discover.twig` | does not exist (§5.5) |
| create `public/assets/css/tokens.css` | a tokens file already exists at `public/assets/css/base/tokens.css` (§5.2) |
| gap row "Overall edition winner + top 3 — NEW (compute)" | already built (§3 row 4) |
| gap row "Search API + palette — NEW" | the palette is built; the endpoint exists under another path with no `scope` (§3 row 6) |
| celebration seen keys `ag-cel-{seen_key}` | the repo's existing engine uses `ag-celebrated:{key}`; shipping `celebration.js` beside `celebrate.js` leaves two engines and two key schemes unless one is retired |

Everything else resolves. Every `§n` reference in the phase files points at a section that
exists — §4/5/6/9.4/9.5/10/11/12/13/17 in `REFERENCE.md`, §7.x/8.x/9.x/15 inside the phase
files themselves, §24/24.6 in the skill. All 12 snippet files, all 79 screenshots and both
celebration production files are present and readable.

---

## 7. Blocked questions

Answers needed before the phase named can start. Per REFERENCE §4.2 none has been guessed.

1. **Type scale (blocks Phase 1).** Does `TypeScaleTest`'s closed ladder give way to the
   redesign's half-pixel scale, or does the redesign move onto the ladder? 790 declarations
   turn on this.
2. **Colour architecture (blocks Phase 1).** Does `tokens.css` become the source of truth and
   `Support\Accent` stop emitting colour, or does `Accent` gain the redesign's palette and
   `tokens.css` carry only non-colour tokens? As things stand the linked sheet loses to the
   inline one and the failure is silent.
3. **Internationalisation (blocks Phase 2, and the acceptance protocol of every phase).**
   Is an eight-language translation layer with RTL in scope? If yes it needs its own phase;
   if no, the RTL requirement comes out of §17.
4. **Status subscribe (blocks Phase 9).** Build a real sender, or keep the page's existing
   rule and drop the requirement?
5. **Acceptance protocol (blocks every phase's sign-off).** Every reachable state, or the
   Cartesian product? (§5.6)
6. **Celebrations (blocks Phase 3).** `celebrate.js` and its six call sites — retired and
   replaced by `celebration.js`, or kept alongside? Two engines and two seen-key schemes is
   the outcome if this is not decided.
7. **`edition_number` (blocks Phase 5).** Editions are currently identified by
   `programme_slug + year`, and published result URLs are built from that. Does
   `edition_number` become the identity, or a display-only addition beside `edition_label`?

---

## 8. Phase 0 exit checklist

- [x] `docs/handoff/GAPS.md` exists and covers every gaps row with a status and paths — §3.
- [x] A route → DC map is included — §4.
- [x] No template, CSS or JS was changed. `git status` shows two additions only: this file
      and `.claude/skills/app-ux-standards/SKILL.md` (Phase 0 build item 1).
- [ ] **REFERENCE §17 evidence** — not applicable and not produced. Phase 0 renders no
      screen; the line is the boilerplate footer repeated in all eleven phase files. Flagged
      rather than silently dropped.

---

## 9. Phase 2 deviations

Each of these is a place the build does not match a `.dc.html` or a phase-file line, with
what was done and why. The phase file's target is none; these are the ones the design and
the constraints could not both be satisfied on, and every one is recorded rather than
quietly resolved in either direction.

### 9.1 The four icon-tile tints are tokens, not the DC's hexes

`MobileMenu.dc.html` tints its list and quick tiles `#eef7ee`, `#e9efef`, `#a47306` and
`#2b373d`. `SiteHeader.dc.html` adds `#effaf0`, `#fff8df`, `#fdecef` and `#f0f2f2/#5d7374`
for the mega panel. None of those names is in REFERENCE §6.1, whose own rule is "only the
tokens in §6. No new colours."

The rule wins over the values. Each maps to the nearest token PAIR — a fill and the ink
drawn on it — as `.ag-tint--green | --gold | --live | --neutral`:

| DC | token pair | measured |
|---|---|---|
| `#eef7ee` / `#effaf0` | `--ag-green-wash` + `--ag-green-deep` | 7.08 : 1 |
| `#fff8df` / `#a47306` | `--ag-gold-wash` + `--ag-gold-ink` | 6.06 : 1 |
| `#fdecef` / `#b0224f` | `--ag-live-wash` + `--ag-live-ink` | 5.78 : 1 |
| `#e9efef` / `#f0f2f2` / `#2b373d` / `#5d7374` | `--ag-tint` + `--ag-ink-2` | 7.37 : 1 |

An icon owes 3:1 under WCAG 1.4.11; every pair clears the 4.5 a word owes, which is what
makes a tile safe to put a label in later. `NoLiteralHexTest` would have failed on the raw
values in any case — but the reason to map rather than exempt is that four more hexes on
this ramp is how the tree reached 642 of them.

### 9.2 The Menu's wordmark takes `--ag-soft`, not `--ag-mute`

`--ag-mute` measures **2.75 : 1** on the page ground. §6.1 scopes it to "disabled text,
off-switch track", and "Africa GATES · An Afrovanguard initiative" is neither — it is a
sentence a reader can read, so 1.4.3's incidental-text exemption does not reach it.
`--ag-soft` is 4.80 : 1 and is otherwise identical in role.

### 9.3 The Menu's Explore list follows the phase file, not the DC

`MobileMenu.dc.html` lists *Discover, Pulse, Giving, Shop, Legacy Vault, **Leaderboard**,
Register a profile*. Phase §7.4 lists *Discover, Pulse, Giving, Shop, Legacy Vault,
**Blog**, Register*. REFERENCE §0 puts the phase file above the DC, so Blog is in and
Leaderboard is out of this list — it is still in the tab bar's reach through Discover and
in the desktop Explore panel.

`SiteHeader.dc.html` has the same shape of conflict on the desktop Explore panel: seven
items including Leaderboard and Shop and **no Status**, against §7.1's six ending in
Status. Same resolution.

### 9.4 The search palette uses `/activity/search`, not a new `GET /search`

Phase §7.1 asks for "`GET /search?q=&scope=` (JSON)". `/search` is already a **route
alias to `/activity`**, and `src/routes.php` explains in as many words that it exists so
that somebody who types the word people actually type lands on the results — and that
there is deliberately **not a second search page: one endpoint, one index, one set of
promises about what is covered**.

Serving JSON from `/search` would break that alias and create the second search the repo
argues against. The palette therefore calls the existing `/activity/search`, which gained
a `scope=` parameter. The chips are §7.1's five, verbatim; what the phase file does not
fix is which source answers each, so that mapping is
`ActivityFeedService::SCOPES` and `SearchScopeTest` requires every source to sit in
exactly one chip — a source added later would otherwise answer under All and under
nothing else, reachable only by somebody who never touched a chip.

`org` sits under **People**, which is a compromise: an organisation is not a person and
the fixed chip set has nowhere else for one. Dropping it would make a partner that went
through CAC and SCUML vetting unfindable from the palette, which is the worse wrong.

### 9.5 `/discover` is a 302 placeholder until Phase 4

§5.5 records that Discover has no template and no route. Phase 2's chrome puts it on the
tab bar, in the Explore panel and in the Menu — three surfaces, one href — so the choice
was between linking to a 404 and hard-coding `/registry` in three places for Phase 4 to
find again. Neither is acceptable, so `/discover` exists from today and answers **302** to
`/registry`; Phase 4 replaces that one line with the real handler. 302 and not 301
deliberately: 301 says the address is never the page, and it is about to be. The tab's
highlight comes from the page's own `active` argument rather than from the URL, so it is
correct either way.

### 9.6 Language ships as the mechanism, and the prompt's words are the only translations

§5.3 is still open, so there is no string catalogue. `Support\Languages` ships the part
that does not need one: the supported set, `?lang=`, the `ag_lang` cookie, and `lang` /
`dir` on `<html>` — which is what a screen reader's pronunciation and the whole RTL
requirement of §17 are measured against. Every surface that offers it says in one line
that the writing is still English, because a language menu that silently does nothing is
the thing worth refusing to ship.

The first-visit prompt (§7.2) must be written in the language it offers — asking in
English asks the one person least able to answer — so `ask` and `yes` are the only
translated strings in this codebase. They are short, stated once, and live beside the
language they belong to. **Each wants a speaker's eye before that prompt is switched on
for its language.**

### 9.7 `AppBar.dc.html` carries a keyboard-shortcuts dialog §7.1 does not mention

`SiteHeader.dc.html` has a `kbOpen` dialog listing eight shortcuts. No line of §7.1 asks
for it and no other phase file mentions it. Not built; flagged rather than invented, since
a shortcut list is a promise about bindings that have to exist.

### 9.8 The Explore panel carries a seventh item: Results

Neither of §7.1's two lists contains Results. `PublicResultsTest` exists because of what
that costs, and it says so in its own comment: the results page was built, the Pulse and
the congratulations emails linked it, and **nobody browsing the site could reach a decided
award** — a mechanism complete and correct in every part except the route in, which is
this repository's most expensive shape.

A specification that omits a destination does not make the destination optional. Results
is in Explore, and the guard that caught it is now able to see a data-driven nav: it used
to look for the literal `href="/results"`, which is what a hand-written list contains and
what a Twig loop over `{href:'/results', …}` never does. It would have gone quiet on
exactly the change most able to drop the link.

### 9.9 Two sub-floor type sizes, resolved in opposite directions

REFERENCE §6.2's smallest rung is **11.5px** (micro / badges). `SiteHeader.dc.html` uses
two sizes below it, and they are not the same kind of thing:

- **The cart badge, 10.5px → 11.5px.** A number somebody reads is type, and §6 outranks
  the DC for the same reason it does on colour (§9.1). It still fits the 17px badge.
- **The wordmark's "GATES", 9.5px, kept.** A logotype is not type. Five letters tracked a
  quarter of an em and locked to the 32px mark beside them are a device, not a word;
  raising it to the ladder makes the tag wider than the name above it and breaks the
  lockup. `TypeScaleTest` exempts it **by the tracking**, the way it exempts the email
  preheader by `mso-hide:all` — naming what it does, never the file that does it.

### 9.10 `components/nav.css` was split, not deleted

The old navigation's stylesheet had to go: its `.ag-menu` block is `position:fixed;
inset:0` for a full-screen overlay, and the redesign's `.ag-menu` is a bottom sheet from
`top:52px`. Same class, opposite component, nothing in either file to hint at it.

It also held every `.vc-*` rule for the vote countdown, which has nothing to do with
navigation. Those moved to `components/vote-countdown.css` rather than going with the
file. `VoteCountdownTest` reads them by name and would have said so — the reason to split
rather than rely on that is that a sheet named for one component and holding two is a
sheet the *next* deletion gets wrong the same way.

Deleting it also orphaned three compatibility tokens whose only reader it was
(`--ag-z-mega`, `--ag-z-mobile-nav`, `--ag-z-overlay`). `DeadTokenTest` named all three,
and they are gone — which is what `tokens.css`'s compatibility block says each phase
should do.

### 9.11 The palette's empty state is the latest feed, not "trending"

§7.1: "The empty query shows trending, open-now and coming-soon."

There is **no trending signal anywhere in this codebase** — no view counts, no per-item
reads, nothing that could rank one result above another by attention. Building a "Trending"
heading would mean inventing the ordering behind it, which is the fault the homepage globe
was built to undo: sixteen cities arrived in that handoff with ballot counts and a median
verification latency this platform has never recorded, and the repair was to drive the band
from the one geographic fact that exists.

So the empty panel shows what is true — the latest feed, grouped under the same headings a
query produces — and it is one parameter rather than a second code path, because
`/activity/search` already answers a short query with `latest()`. Open-now and coming-soon
are reachable honestly (`gates_cycle_transitions` is what the `phase` source reads) and
would be a real addition; trending needs a measurement first.

The live region says "12 recent items" rather than "12 results" when nobody has searched
for anything, and the count is what is **shown** rather than what arrived — a "12" over a
panel holding eight is a number somebody then goes looking for.

### 9.12 The cookie notice offers one choice, not three optional categories

§7.9: "Essential is always on, plus 3 optional categories."

This platform has one. `Support\CookieRegistry` declares five cookies across three
categories and `anyRefusable()` answers **false**, because nothing it stores is refusable:
the arrival counting reuses the session cookie that is already strictly necessary under
ePrivacy Art.5(3), so a banner asking permission to store would be asking about something
we are not storing. What *is* refusable is the counting itself, and `Services\CookiePrefs`
is the one resolver for it.

Drawing "Analytics / Marketing / Personalisation" when two of the three describe nothing
this site does is §19's shape with a checkbox on it — a document people are asked to rely
on, stating something that was never true. The notice asks the one real question and the
`/cookies` page states the rest, generated from the registry.

**GPC is already honoured, and better than §7.9 asks.** It is read from the `Sec-GPC`
request header rather than `navigator.globalPrivacyControl`, so it applies before a line of
script runs and on a browser with scripting off; `CookiePrefs`'s rule is that **if anything
said no, the answer is no**, which is stricter than the GPC specification requires and is
deliberate. The page states it in words ("Your browser has already said no… we treat that
as a refusal") and explains that the specification permits a site-specific opt-in which
this platform declines to offer.

**What did change is a regression the chrome rebuild caused.** The notice cleared
`--ag-mobile-nav-h`, a typed 64px describing `.ag-mobnav` — the bar the redesign deleted.
It reads `--ag-bottom-ui` now, which `shell.js` measures from whatever bar is on screen.
The two compatibility tokens were corrected with it: `--ag-nav-h` was 72px against a 64px
header, and about ten templates stick a rail under it.

---

## 10. Findings from driving the real pages

Everything below was measured in a real browser against the running app, not read off the
source. Each one is invisible in a diff and several were invisible in the suite.

### 10.1 Four faults in my own Phase 2 work, none of which threw

| what | how it read | why it happened |
|---|---|---|
| The Menu sheet showed a 49px sliver of grabber and header at rest, on every phone page | nothing — it was `inert`, correct in the markup, and a screenshot away | `translateY(100%)` moves a sheet by **its own height**, which clears the viewport only if its top edge started at the bottom. The Menu is pinned at `top:52px`. Fixed with `visibility` plus its own distance. |
| The scrim was never once drawn | the page behind an open sheet stayed bright | the markup carried `hidden`, which is `display:none`, and `AGShell.openSheet()` reveals a scrim by setting `data-open` — an attribute cannot beat `display:none` |
| The Menu's close button wrapped onto a second row and the title slid left | a broken header, and only when the back button was hidden | the head is a `44px 1fr 44px` grid and `hidden` is `display:none`, which takes the **cell** with it. `visibility:hidden` keeps the cell and still leaves the tab order |
| The tab bar drew at 1440, straight through the mega panel's blur | five destinations competing with the two panels that hold them | `@media (min-width:900px){ .ag-tabbar{display:none} }` was written **above** `.ag-tabbar{display:grid}`. Same specificity, so source order was the whole decision — it read correctly and did nothing |

### 10.2 Three faults that were already there, found the same way

- **`--ag-bottom-ui` was 0px on every page of the site.** `shell.js` picked the tallest
  *visible* bar with `offsetParent !== null`, commented as "the display:none test". It is
  not: **`offsetParent` is null for a `position:fixed` element**, which is what every one
  of these bars is. So the measurement excluded exactly what it exists to measure, the
  value never moved off its 0px default, and the Gee launcher and the cookie notice sat
  flat against the bottom edge — on top of the tab bar on a phone. `getClientRects().length`
  is the test the comment described. (The same `offsetParent` test one function below is
  **correct**: it filters a sheet's *children*, whose offsetParent is the fixed sheet.)
- **`[hidden]` did not hide.** `[hidden]{display:none}` is a user-agent rule, so any author
  rule setting `display` outranks it — and this design system sets `display` on nearly every
  component. `chrome.js` hides the language form's redundant "Change" button with
  `el.hidden = true`; `.ag-btn{display:inline-flex}` kept it on screen. One base rule now.
- **`box-sizing:border-box` was not global.** `.ag-btn--block{width:100%}` measured 358px on
  a `<button>` and **402** on an `<a>` with the same classes — 358 plus the button's own
  44px of padding — so the Sign-in control ran off the right edge of the Quick settings
  sheet. Nine rules in `components.css` say `box-sizing:border-box` by hand, which is what a
  missing base rule looks like from the inside.

### 10.3 One for Phase 3: the home page overflows 9999px in RTL

Measured at 390: `/?lang=en` has a document overflow of **0**, `/?lang=ar` has **9999**. The
widest offenders are `.hm-marq__row` (3405px) and two `.hm-*__glow` blocks, and the
marquee's parent is `overflow-x:hidden` in **both** directions — so the containment that
holds in LTR does not hold in RTL, which is the familiar behaviour of a child overflowing
to the "wrong" side of a right-to-left document.

`/_dev/ui`, which is nothing but Phase 1 and Phase 2 chrome, measures **0** in RTL. The
chrome mirrors correctly — the Menu's chevrons move to the left, the sheets and popovers use
logical properties throughout. This is the home page, and §17's "RTL at 390" will fail on it
until Phase 3 rebuilds that hero.

### 10.4 The phone/tablet breakpoint is 768, not 900

The old navigation hid below 900px and the first cut of the redesign's header inherited
that number. §7.1 calls this the **tablet and desktop** header and the acceptance protocol
screenshots **834** as a tablet width — so at 900 an iPad in portrait got the phone chrome
on precisely the width the specification names as the tablet case.

768 is what the bar actually needs, measured rather than guessed: logo 150 + two links 200
+ toolbar pill 180 + identity 100 + 56 of gutter is 686, and the bar's children measure
**670** at 768 with 98px to spare. The header's breakpoint and the tab bar's are now the
same number in both directions — two different figures would leave a band of widths with
both chromes, or with neither.

### 10.5 Keyboard pass, measured

At 1440, driven by keys alone:

| | result |
|---|---|
| First Tab | **Skip to content** |
| The whole header | ~8 stops — the toolbar pill is **one**, as `role="toolbar"` claims |
| Aa on Enter | opens, focus moves to the first control, `aria-expanded="true"` |
| Esc | closes, focus **returns to the trigger**, `aria-expanded="false"` |
| ← → inside the toolbar | search → Aa → language → wraps |
| ← → on the size control | moves the selection and `ag-t125` lands on `<html>` |
| Esc in the search palette | closes and returns focus to the search button |
| Back gesture with a sheet open | closes the sheet, stays on the page |
| Quick settings → "All display & reading settings" | closes that sheet, opens the Menu at its Display sub-view, **one** history entry across the handover |
