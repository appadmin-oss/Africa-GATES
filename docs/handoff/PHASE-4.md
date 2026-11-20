# Redesign Phase 4 — Home and Discover

`design_handoff_africa_gates/phases/PHASE-4-home-discover.md`. Two agents built this phase
concurrently: **Home** (with the site footer) below, **Discover** (with the `/activity` 301 and the
sitemap) in its own section. Nothing is committed by either.

---

# Home — `/`, and the site footer (4 Oct 2026)

§8.1 is authoritative; values are HomePageV3.dc.html's and WeAreAfrica.dc.html's (REFERENCE §5).
Screenshots: `docs/handoff/shots/phase-4/home/`. Dev data: a scratch SQLite database with four
programmes (one voting closing in ~2 days, one voting in 5, one taking nominations, one 2025
edition released with two decided awards and a judging panel), seven nominees in six nations, a
partner organisation with one live campaign, **and the sandbox seeded** (`DemoSeeder::seed`) to
prove it never shows. A second, empty database renders the empty state.

## H1. Files

### Destroyed and written again (inventoried first — `inventory/pages--home.md`, "Phase 4 destroy")
| File | Now |
|---|---|
| `src/Controllers/HomeController.php` | Passes only what the page reads: `stats`, `voting`, `decided`, `campaign` (HomeFront, cached `home:front` 300s) and `globe_countries`/`globe_note` (GlobeBand, `home:globe` 900s). The meta description's typed "live in Nigeria" is now `NationsLive::phrase()`. Five unread datasets (leaderboard, spotlight, awards, site events, promos) gone. |
| `docs/GLOBE-BAND.md` | Rewritten for the rebuilt band (it described the destroyed one). |

### Created
| File | Why |
|---|---|
| `templates/pages/home.twig` | The page, on `layout/shell.twig`, §8.1 order; `colour_tier` 2. |
| `templates/partials/we-are-africa.twig` | WeAreAfrica.dc.html on GlobeBand's markers; marker buttons, the nation card, the note. |
| `templates/partials/site-footer.twig` | The DC's footer + every MUST RESTORE destination (inventory `_partials.md`). |
| `public/assets/css/components/home.css` | The page and `.waa`, mobile-first, logical properties, zero colour literals. |
| `public/assets/css/components/footer.css` | The footer. |
| `public/assets/js/we-are-africa.js` | The globe: own orthographic projection on canvas (no vendored library), drag, arrows, focus-turns, card, hit-test, one outline, scroll-linked width, reduced motion. |
| `src/Services/HomeFront.php` | One reader for every figure on the page; memoised; sandbox-contained; phases computed. |
| `tests/Unit/HomePageTest.php` (13), `tests/Unit/SiteFooterTest.php` (9) | New guards (H6). `git status` showed both `??` before writing. |

### Changed (minimal, additive, shared files re-read before each edit)
`templates/layout/shell.twig` (footer sheet link + `{% block footer %}` inside `<main>`, not on
`hide_chrome`) · `src/Support/AssetBundle.php` (`footer.css` before `gee.css`) ·
`templates/partials/app-bar.twig` + `components/chrome.css` (`brand: true` root bar: logo, no large
title) · `public/assets/css/tokens.css` (`--ag-fs-26/28/30/36/38/40/64`, each with its DC reader) ·
`config/container.php` (HomeController wiring) · tests: `GlobeBandTest` (constants re-pointed; the
controller test rebuilt; 9 band rules re-asserted), `ShellLayoutTest` (sheet order),
`PublicResultsTest` (the footer is a browsing door again).

## H2. Section by section — what is drawn, on what data

| §8.1 | Built on | Notes |
|---|---|---|
| 1 Hero | static copy; record card = the newest **published winner** (`PublicResults::index`), linked to its result | No published result → no card. Photo = the winner's photo; none → the continent outline (Q8). |
| 2 Live stats | editions open for nominations / votes happening now (computed phase, `CyclePolicy::stateFor`) / countries taking part (`NationsLive::count`) / votes cast (`SUM(weight)` through `DemoSeeder::liveAwardOnly`) | Fourth label differs — deviation D4. |
| 3 Who recognises | **NOT DRAWN** — blocked B1 | |
| 4 Wall of recognition | **NOT DRAWN** — blocked B2 | |
| 5 We Are Africa | `GlobeBand::countries()`/`note()` | Markers at polygon centroids; ring = decided. |
| 6 Happening now | voting open (≤3, closing soonest, "All N" → `/vote`) · just decided (≤3, → result) | A column with nothing is not drawn; both empty → section not drawn. |
| 7 Giving | open campaign closing soonest, from an org that can receive (`PartnerOrg::listReceivable`, `OrgCampaign::isOpen/progress`) | None → not drawn. Rule is mine — B5. |
| 8 Recognise band | one true point + "Talk to us" → `/partner` | Blocked parts B3. |
| 9 Footer | shell partial, every shell page | H4. |

## H3. Every prop combination → screenshot

HomePageV3.dc.html exposes `layout` (phone/tablet/desktop, measured from width); WeAreAfrica
`layout` (desktop/phone) × `standalone` (the home embeds it `false`). Its `aud` tabs and `wallOpen`
belong to the two blocked sections; `searchOpen`/`youOpen` are the Phase 2 chrome.

| Combination | Build | DC |
|---|---|---|
| phone 390 | `build-390-data-{1-hero,2-hm-stats,3-waa,4-hm-now,5-hm-give,6-hm-host,7-ag-foot,full}.png` | `dc-home-390.png` |
| tablet 834 | `build-834-data-*.png` (same set) | `dc-home-834.png` |
| desktop 1024 / 1440 | `build-{1024,1440}-data-{1-hero,full}.png` | `dc-home-{1024,1440}.png` |
| empty platform, each width | `build-{390,834,1024,1440}-empty-*.png` | — (the DC has no empty state) |
| WeAreAfrica desktop / phone, embedded | `build-1440-data-full.png`, `build-390-data-3-waa.png` | `dc-weareafrica-{1440,390}.png` (standalone) |
| nation card by mouse / keyboard | `globe-{390,1440}-card-{mouse,keyboard}.png` | — |
| drag | `globe-{390,1440}-dragged.png` | — |
| overlay, DC 50% over build | `overlay-390.png`, `overlay-1440.png` | |
| RTL 390 | `rtl-390-{hero,globe,footer}.png` | |
| motion normal / reduced | `motion-{normal,reduced}-1440-{globe,turn-60ms}.png` | |
| Gee clearance | `gee-clearance-{390,834,1024,1440}-notice.png`; figures in `notes.txt` | |

**Overlays.** Hero column, H1 position, CTA rows, stat row and rule line up; offsets are the copy
(D2, the lead is a different length), the H1 size at 1440 (D1), and the card's content.

## H4. The footer

DC columns and bar; plus, from the destroyed footer's inventory (MUST RESTORE): Challenges
(Participate), Newsletter (Explore), Our philosophy (Initiative); `/org`, `/support`, `/refunds`,
`/vendor-terms`, `/results`; "live in {{ nations_live() }}, building toward {{ nations_count }}
nations"; social links new-tab and labelled. **Activity removed** (§8.23). **Cookies** is
`<a href="/cookies#choices" data-ag-do="consent-open">`, bound by `consent.js`: it reopens the
choices in place at every width — this closes GAPS §8c item 10. Every link is a literal `href`, so
`PublicIaTest` sees it.

## H5. Keyboard, screen reader, motion, clearance

Keyboard (`keyboard-1440.txt`, `keyboard-390.txt`): skip link · logo · header (or app bar search,
avatar) · Nominate someone · Recognise with Africa GATES · the record card · **the globe** (arrows
turn it) · each nation marker (Enter opens its card; Esc closes and returns focus to the marker) ·
the card's Close · All N · voting rows · Results · decided rows · See all campaigns · the campaign ·
Talk to us · footer. **Mouse**: a click on a marker opens the card (`aria-expanded=true`) — the
`setPointerCapture` fault is not present; a click on a marked country's land does too.
Screen reader (by role/name; no VoiceOver/TalkBack here): one `<h1>`; sections labelled; the globe
`role=img` with its instructions; markers are buttons named "Nigeria: an award decided, 4 nominees
standing"; the card is a labelled region with a `<dl>`; the progress bar carries "N% of the goal".
Reduced motion: turning snaps; the band is drawn full-bleed (`--waa-p` = 1 measured mid-page, 0.000
without reduced motion). **Gee clearance: 16px at 390/834/1024/1440, with and without the cookie
notice.** Console errors: 0.

## H6. Tests — each new guard watched failing

28 mutations, 28 caught (`scratchpad/p4/mut.py`) — the first run missed one: counting the STORED
status for "voting now" passed because the fixture's stale cycle happened to sit at the same count;
the fixture now has a cycle whose stored status disagrees in both directions, and the mutation is
caught. Mutations: footer loses `/newsletter`, `/vendor-terms`, `/results`, the consent hook, the
NationsLive call, a social link's new-tab; footer gains Activity or capitals; shell drops the
footer; HomeFront loses the sandbox clause, the computed phase, the memo, the receivable-org rule,
the closing-soonest order; home gains a DC name, an `onclick`, a second `<h1>`; the script captures
on the band, types a hex, drops Nigeria from AFRICA, drops the focus-turn, drops the reduced-motion
snap, gains a city list, strokes every African country; the sheet sets `touch-action:none`; the card
gains a "Verification node" row; the canvas loses `tabindex`; the controller stops passing the note.

**Runs** (no `.env`; lock files cleared): the brief's filter plus Accent/SlotFloor/SiteHeader/TwigBlock/
NestedForm/OneMain/PublicResults/Legal/Newsletter/AssetBundle/RouteTable — 576 tests; the only failures are
other agents' in-flight work (`DeadTokenTest` on `console/tokens.css`, `ShellLayoutTest` on `menu-sheet.css`,
`TemplateContextTest` on the admin dashboard, `EventTierColourFieldTest` on `admin/layout.twig`). **Full suite
(4 Oct): 6,704 tests, 144 errors, 23 failures** — attributed: `PasskeyTest` ×8 (PHP 8.3, environmental); the
admin-console rebuild in flight (`admin.css` gone, `admin/layout.twig` nav, AdminNav/AdminIa/Handbook/
Questionnaire/Interview/Shortlist/Stand/Refund/Finance/… render tests, `DoorVoiceTierTest`, `NestedFormTest`'s
`admin.js` half, `DeployedEndpointTest`'s `admin/dashboard.twig`, `TypeScaleTest`'s held-sheet list,
`CookieRegistryTest`'s `ag-copilot`); the menu sheet (`ShellLayoutTest`). **None in a Home or footer file**;
`HomePageTest`, `SiteFooterTest`, `GlobeBandTest`, `NationsLiveTest`, `StatsServiceTest`, `PublicResultsTest`,
`PublicIaTest` all pass.

## H7. Deviations — what, why, approved by (target 0)

| # | What | Why | Approved by |
|---|---|---|---|
| D1 | Desktop H1 44, not the DC's 48 | REFERENCE §6.2 outranks a DC (§0) | REFERENCE §0 |
| D2 | Hero lead rewritten: "Nominate the people who make a difference, vote for them, and see every award decided in the open and kept on the record." | The DC's ("Communities, organisations, businesses and governments use Africa GATES… Every recognition is verified") states facts the platform does not have (B1) | **Needs approval** |
| D3 | Record card: a published winner (programme tile + "Winner" chip), no verified-issuer mark or issuer type | No issuers exist (GAPS §3.1) | **Needs approval** |
| D4 | Fourth stat "votes cast", not "verified voters" | No honest count of verified people exists across an edition (synthetic hashes; CLAUDE.md) | **Needs approval** |
| D5 | Second hero CTA → `#hm-host` (the Recognise band), not `#h-who` | "Who recognises" is not drawn (B1) | follows B1 |
| D6 | Recognise band: no lead, one point, "Talk to us" → `/partner`; no "Start recognising" | The other copy promises honours, staff recognition, certificates, "free for community awards" and a self-serve start — none exist | **Needs approval** (B3) |
| D7 | WAA: GlobeBand markers (dots; ring+check where decided; name label) instead of photo pins of people; the foot line is `GlobeBand::note()` with no live dot, not "X just joined" | The DC's are invented (CLAUDE.md, globe band); the line does not change, so it is not `aria-live` | CLAUDE.md |
| D8 | WAA: a nation card the DC does not have, focusable markers, arrow keys | MUST RESTORE (inventory `_scripts.md`); WCAG 2.4.7, 2.5.7 | inventory |
| D9 | Globe and land colours mixed from tokens (ink, info, green-light), not #1f4a4f/#0f2a2e/#4f9a86/#24494d; band words `--ag-surface`, not #f1efe9 | Not palette colours (Q1) | Owner Q1 |
| D10 | "Just decided" initials on `--ag-tint`, not gold-wash/gold-ink; rows say "Winner ·" | A gold field is a third family on a tier-2 page (ColourBudgetTest); colour never alone | **Needs approval** |
| D11 | "N days left" in `--ag-live-ink`, not #cc1950 | not in the palette | Owner Q1 |
| D12 | Absent photos: initials on tint; hero shows the continent outline; campaign shows the org logo or initials | Q8 unanswered; no stock photography (§14) | **Q8** |
| D13 | Home phone bar: logo · search · avatar (DC), via `app_bar.brand`; §7.2 lists Home as a large-title root | the page's `<h1>` is the hero's; two `<h1>`s otherwise | **Needs approval** (B4) |
| D14 | "Voting open"/"Just decided" are `<h3>` (DC `<h2>` inside an `<h2>` section) | heading order | — |
| D15 | Footer: `--ag-ink` not #0c2225; titles 12.5 sentence case not 11px uppercase; "GATES" 11.5 not 10; socials 44 not 36; three extra links (H4) | Q1, Q4, Q5, §6.7; inventory MUST RESTORE | Owner Q1/Q4/Q5; inventory |
| D16 | DC shadows on the record card and nation card written as `color-mix` of ink | no typed rgba (ColourLiteralTest); same values | — |
| D17 | On a phone the open nation card sits under the heading and can cover the selected country | the note runs to four lines at the foot | **Needs approval** |

## H8. Blocked — not guessed

- **B1 "Who recognises"** — needs recognitions and verified issuers (`gates_recognitions`,
  `issuer_type`, `verified_at` — GAPS §3.1, REFERENCE §11) and an owner decision on what the
  Businesses and Governments tabs offer (staff recognition, gazetted honours): neither exists.
- **B2 Wall of recognition** — needs `gates_testimonials` (approved quotes with consent for this
  use, name, role, photo, tag, tone — GAPS §3.5). `gates_vote_messages` and OrgBrand quotes are
  different consents and were not used.
- **B3 Recognise band** — what may it promise and where does "Start recognising" go? There is no
  self-serve way to host an award; "Free for community awards" is a pricing statement nobody has made.
- **B4 Home phone bar** — DC (logo) or §7.2 (large title)?
- **B5 Featured campaign** — no "featured" flag; the open campaign closing soonest is a rule I chose
  and stated in `HomeFront::campaign()`. Confirm, or add a flag.
- **Q8** (photo slot with no real image) still unanswered; D12 is the interim.

---

# Discover — `/discover`, and `/activity` retired into its Live tab (4 Oct 2026)

§8.2 and §8.23 are authoritative; every other value is DiscoverPage.dc.html's (REFERENCE §5), except where
REFERENCE itself outranks the DC (§0) — those are listed under D-, not hidden. Screenshots, overlays, the
keyboard/screen-reader log and the curl proof: `docs/handoff/shots/phase-4/discover/`. Dev data: a scratch
SQLite database (setup + every migration) seeded with activity of **every kind the index has**: two
programmes open for nominations closing in 2–3 days, two in 9–12, one in voting, a 2025 edition released
with two decided awards, phase transitions (results, voting, nominations, upcoming), nominees this month in
five nations (two people in several categories, one with a verified dossier item, one recognised before),
34 older nominees (for "Show older updates"), three upcoming events and a past one, published posts and a
draft, two community threads, three registry profiles. Not staging.

## D1. Files

**There was never a Discover page to destroy**: `/discover` was a 302 to `/registry` (GAPS §5.3). The
activity page and the find band were destroyed on 3 Oct with the old layout; their rules were inventoried
then (`inventory/pages--activity.md`, `_partials.md` "find-band") and every MUST RESTORE rule is re-asserted
below. What survived and was retired now:

| Destroyed | Why | Replacement |
|---|---|---|
| `src/Controllers/ActivityController.php` | nothing reaches it after the 301 | `DiscoverController` |
| `tools/qa/activity-a11y.js` | drove a page that no longer exists | `DiscoverPageTest` + the Playwright log in shots |
| `tests/Unit/FindBandTest.php` (1 test left) | its band was destroyed | rewritten whole against Discover's field (8 tests) |

Created: `src/Services/Discover.php` (the page's one resolver — composes existing readers, writes only the
"most nominated this month" query), `src/Controllers/DiscoverController.php` (`GET /discover`,
`?fragment=live`, `GET /discover/count`), `templates/pages/discover.twig`, `templates/partials/discover-live.twig`
(the timeline, rendered by the page AND alone for the combobox — one renderer of a row),
`public/assets/css/components/discover.css`, `public/assets/js/discover.js`, `tests/Unit/DiscoverPageTest.php`.

Changed (shared files re-read before each edit, minimal and additive):
`src/Services/ActivityFeedService.php` (`timeline()`, `datedKinds()`, `collect()` reports `asked`, the phase
source narrows by `to_status` in its query, transition rows carry `phase`); `src/Services/SearchLanding.php`
(`currentCycles()` and `upcomingEvents()` extracted so the palette and Discover share one read — palette output
unchanged, `SearchEndpointTest` green); `src/routes.php` (the 302 replaced by the page and `/discover/count`;
`/activity` → 301 closure; `ActivityController` import removed); `config/container.php` (controller definition;
Twig functions `discover_url`, `discover_kinds`, `discover_tabs`); `src/Services/SitemapService.php` (`/discover`,
`/discover?tab=live`); `src/Support/ClientIp.php` (docblock); `tools/browser/csp-check.js` (`/activity` →
`/discover`, `/discover?tab=live`); `tests/Unit/DeadTokenTest.php` (`--ag-gold-wash` has a reader again).

**Navs:** Activity was already absent from the header, Explore, tab bar, palette scopes and `/_dev` (Phase 2
rebuilt them without it); `DiscoverPageTest` now sweeps every public template and script for a link to it.
The menu sheet is being rebuilt by another agent and I did not edit it — it contains no `/activity` link
today; the coordinator was told. The footer is the Home agent's (no `/activity` link in it).

## D2. What is drawn, on what data

| DC block | Data | Notes |
|---|---|---|
| Search field | `GET /discover?q=` form; `ActivityFeedService` through `timeline()` | the site's only search field on the page; coverage sentence + promise from `search_covers()` |
| Tabs All · **Live** · People · Organisations · Awards · Results · Events | `Discover::TABS` | links (no script) upgraded to in-place tabs with arrow keys and `pushState` |
| Happening now / Everything happening now | `ActivityFeedService::timeline()` (dated sources only) | All: 4 newest of everything + See everything; Live: 20 a page, cumulative |
| Kind chips | `Discover::KINDS` | Results = winners + "results published"; Nominations = nominees + "nominations opened"; Voting = "voting opened"; Events; Stories = posts + discussions; **Recognitions: no source** — says so |
| Open for nominations (+ "All N") | `SearchLanding::currentCycles()` — computed phase | Status facet switches it to Voting now / Decided (decided = *announced*, `PublicResults::RELEASED`) |
| Just decided | `PublicResults::index(3)` winners (cached 300s) | |
| Most nominated this month | the one new query: nominees since the 1st, active programmes only, by person (profile, else nominee) | counts categories — see D-5 |
| Award hosts | `ProgrammeHost` over active programmes | "Africa GATES" where no host is named |
| Upcoming ceremonies | `SearchLanding::upcomingEvents()` | |
| Facets | Trust: Recognised before (won/runner-up in an announced award, or a published shortlist), Reviewed evidence (`gates_nominee_evidence.verified_at`); Where: `NationsLive::codes()`; Status | Identity verified, Vouched for, Field: **blocked** (B-1) |

Sandbox: every reader walks to an **active** programme (the sandbox is inactive) and the new query also goes
through `DemoSeeder::notSandbox()`; `DiscoverPageTest` seeds the real sandbox, moves its nominees into this
month so the test cannot pass by date, and sweeps five views.

## D3. Every prop combination → screenshot

The DC exposes one prop, `layout` (phone/desktop; tablet when unset), and the phase names three states: tabs
All and Live, and the facets sheet. Each was rendered from a copy of the DC with only the state default
changed, served over http with its React/Babel vendored locally and the Google Fonts routed through the
proxy (both renders use the real faces). Phone DC renders are cropped by the 44px status bar the DC draws.

| State | 390 | 834 | 1024 | 1440 |
|---|---|---|---|---|
| All, at rest | `all-390` · `dc-all-390` · `overlay-all-390` | `…-834` | `…-1024` | `…-1440` |
| Live, at rest | `live-{w}` · `dc-live-{w}` · `overlay-live-{w}` | ✓ | ✓ | ✓ |
| All, docked (scrolled 260) | `docked-all-{w}` · `dc-…` · `overlay-…` | ✓ | ✓ | ✓ |
| Live, docked | `docked-live-{w}` · `dc-…` · `overlay-…` | ✓ | ✓ | ✓ |
| Facets open (sheet <600, end panel ≥600) | `filters-{w}` · `dc-filters-{w}` · `overlay-filters-{w}` | ✓ | ✓ | ✓ |

Build-only states (no DC frame; 390 and 1440): each section tab `tab-{people,orgs,awards,results,events}-{w}`;
`live-q-` (results for a query), `live-empty-` (nothing matched), `live-literal-` (the Literal line),
`live-kind-vote-`, `live-kind-recognition-` (no source, said), `live-older-` (page 2, anchored at row 21),
`filters-applied-` and `filters-applied-open-` (chips + the sheet showing the choice), `combobox-active-`
(typed, two ArrowDowns: the active option outlined), `status-decided-`. RTL (Arabic) at 390: `rtl-all-390`,
`rtl-docked-live-390`, `rtl-filters-390` (+ `rtl-filters-1440`, the panel at the start edge). Reduced motion:
`reduced-docked-390`, `-1440` (the dock state lands without transition). The "Understood as" line needs a
model provider, which the dev server has none of; it is held by a render test instead
(`test_understood_as_carries_its_way_out_and_literal_carries_its_way_back`).

**Overlays.** Where the build and the DC disagree on an overlay it is one of three things, all listed under D-:
the H1 (REFERENCE §6.2 44 vs the DC's 36 at desktop; 32 at tablet in both), the 44px targets (DC 40), and the
data (the DC's demo rows vs the seed). Column edges, the bar, the field, the list card and the sheet line up
once the DC's content-box 1180 is honoured (measured, then matched) and the DC's phone sheet top (72 in a
frame with a 44px status bar = 28 in a viewport).

## D4. Keyboard, screen reader, motion — `keyboard-and-screen-reader.txt`

Playwright, keyboard only, at 390 and 1440. Tab order: skip link → chrome → **search field → the selected tab
only (roving) → Filters → See everything → the four rows → All N → the cards → the sections**. Arrow keys,
Home and End move along the tablist and select (mirrored in RTL); Back restores the previous tab. **Dock**:
at rest the row copy is exposed (`aria-hidden="false"`, its selected tab the stop) and the docked copy is
`aria-hidden="true"` with every tab `tabindex="-1"`; past 8px the two swap, and focus that was on the copy that
hid moves to the one that appeared. **Combobox (Live tab only, declared by the script)**: `role=combobox`,
`aria-controls=dvLiveList`, `aria-expanded`, `aria-activedescendant` moves with ArrowDown while focus stays in
the field (logged `focus: dvQ`, `activedescendant: dv-r2`); the first Escape closes, the second clears; Enter
with nothing active submits the form. **The only live region in `<main>` is `#dvStatus`** (logged, every
step); the list is a listbox, never `aria-live`. What it announced while typing "Amara" then clearing: "Type
at least 2 characters." → "4 results for “Amara”" → "Showing the 20 most recent updates". **Filters**: Enter
opens the dialog, focus moves to Close, 14 Tabs stay inside, Escape closes and focus returns to Filters.
**Scripting off**: the Live tab link, a kind chip, the search, the Filters link (opens by `:target`) and Show
all work as plain navigation. VoiceOver/TalkBack were not available here; the notes above are what the
accessibility tree exposes (role/state/live-region), which is what those readers announce.

Reduced motion: the only motion is the dock (grid track, max-width, opacity) and the sheet slide; shell.css
takes transitions to zero, so the end state is immediate (screenshots). Nothing on the page animates
continuously; a busy list dims (`aria-busy`) rather than spinning.

## D5. Tests — each new guard watched failing first

`DiscoverPageTest` (22) and `FindBandTest` (rewritten, 8: the file existed with one test — the count is
1 → 8, +7, plus 22 new). Every one was broken on purpose and seen to fail (scripted; restored after each):
301→302; `literal` dropped from the redirect; `aria-live` on the list; `role=combobox` in the markup; the
active option focused; a typed "of 7"; Recognitions given a source; page 2 not cumulative; Where ignored;
unverified evidence counted; "Decided" by date; the sandbox gates removed (fails once the sandbox is in the
current month — the first version passed vacuously and was fixed); the dock rule removed; an inline
`onclick`; the sitemap entry removed; the empty-state query `|raw`; "1 results"; Enter always prevented;
Escape clearing first; a link to `/activity`; the count endpoint counting the wrong tab; Live moved off
second; sections capped at 4 on their own tab; and for FindBand: the promise removed, a noun typed, the
catch-all removed, `aria-describedby` removed, a second field, the hook removed. 29/29 mutations caught.

## D6. Deviations — what, why, approved by (target 0)

Resolved by the order of authority (REFERENCE §0), not by me — listed so nobody reads them as accidents:

| # | What | Authority |
|---|---|---|
| A1 | H1 32 (<1024) / 44 (≥1024); DC 32/36. Section H2 19; DC 18 | REFERENCE §6.2 |
| A2 | Tabs, kind chips, Filters, applied chips, facet options 44px; DC 40/42 | REFERENCE §6.7, §13 |
| A3 | Selected facet option = 2px ink outline on white; DC dark fill. Applied filters outlined; DC green wash `#e4f6e4` | REFERENCE §6.1, §18.2 |
| A4 | Event month "Dec" in `--ag-live-ink`; DC "DEC" in `#cc1950` | §18.4 (no capitals); `#cc1950` not in the palette (Q1) |
| A5 | The bar's "N results" is not `aria-live` (the DC's is) | §8.23: the status line is the ONLY live region |

Deviations needing approval:

| # | What | Why | Approved by |
|---|---|---|---|
| D-1 | Facets drawn: Trust (2 of 4), Where, Status. Not drawn: Identity verified, Vouched for, Field | no record behind them (B-1) | **Needs approval** |
| D-2 | Recognitions chip drawn; selecting it says "No recognitions are recorded on Africa GATES yet." | no source (GAPS §3.1); not faked | **Needs approval** |
| D-3 | The coverage sentence and "Nothing unannounced is searchable." printed as a footnote at the end of the page and as the field's description | the find band's two claims are MUST RESTORE; the DC has no place for them | **Needs approval** |
| D-4 | Timeline rows: title is the item (a name, a programme + year), the item's own label leads the detail ("Winner · Musician of the Year", "Voting opened · 7th Edition"); DC writes sentences ("Achieng won …") | the index has no sentence; composing one per kind in a template is a second renderer | **Needs approval** |
| D-5 | "Most nominated this month": "nominated in N categories", ranked by categories; DC "212 nominations" | a nomination is not linked to the nominee it attached to (no `nominee_id` on `gates_nominations`); the count would have to be rebuilt from names | **Needs approval** (B-2) |
| D-6 | A registry profile joining is a timeline kind the DC lacks: "Joined", dot `--ag-ink-2`, under Everything only | it is a source of the index; dropping it would hide activity the old page showed | **Needs approval** |
| D-7 | Relative time "16 minutes ago" (DC "4 min ago") | `ActivityFeedService::relative()` is shared with the palette; changing it changes Phase 2 | **Needs approval** |
| D-8 | Upcoming ceremonies: meta is the location only; DC adds captions / sign language / audio description | no such columns on `gates_site_events` (REFERENCE §13 asks for them) | **Needs approval** (B-3) |
| D-9 | The partial-read warning reads "({n} of {m} responded)" with `m` = sources asked (7 dated on Live), not a typed 7 | the old page typed 7 while ten sources ran | — (§19 shape) |
| D-10 | Kind dots and the "Happening now" dot are coloured through a data custom property (`--dv-dot`), which `ColourBudgetTest` treats as structure, like a programme spine; the page declares tier 2 (gold winners, green action) | the DC's legend is six hues; as fields the page would be tier 4+ | **Needs approval** — if dots are events, the page cannot be drawn as designed |
| D-11 | Section tabs list up to 48 rows; the All tab hides beyond the DC's slice (4·3·4·4·3) with CSS | a count over unreachable rows is a count of nothing | — |
| D-12 | Live combobox armed on the Live tab only; on other tabs Enter searches | the list it controls is the Live list | — |
| D-13 | Home's "Just decided" draws initials on tint (its D10); Discover draws them on gold-wash, as the DC | Discover is tier 2 with gold + green; Home is not | flag for consistency |

## D7. Blocked — not guessed

- **B-1** What do "Identity verified — checked against a government ID", "Vouched for — by 3 or more verified
  people" and "Field" mean on this platform? `verification_tier` exists but nothing records a government-ID
  check; no vouch is recorded; awards and categories carry no field taxonomy.
- **B-2** "Most nominated": count category entries (built), or link nominations to nominees (a `nominee_id`
  written at approval — a migration on both schemas) so the DC's "N nominations" can be honest?
- **B-3** Event accessibility flags (captions, sign language, audio description) — add the columns (REFERENCE
  §13 lists them) in Phase 7?
- **B-4** `/registry` still renders a destroyed template (500); GAPS §5.1 maps it "4?" to Discover. Retire it
  into `/discover?tab=people` (301), or rebuild it as the directory in Phase 6?
- **B-5** The All tab's "Happening now" shows every kind; should "Joined" appear there at all (D-6)?

---
