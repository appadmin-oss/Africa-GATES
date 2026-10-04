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
