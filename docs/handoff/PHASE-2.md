# Redesign Phase 2 — the shared chrome

**`design_handoff_africa_gates/phases/PHASE-2-chrome.md`, build items 1–5, done on 3 Oct 2026.** Item 6 (the cookie
banner, preferences and GPC) is not in this pass — another agent builds it next; this pass leaves it a stable hook
(§6). Every chrome file was **deleted and written again** from its DC; nothing was edited into shape, and no rule was
carried over from a destroyed file. Paths are relative to the repo root; screenshots are under
`docs/handoff/shots/phase-2/`. Nothing is committed.

---

## 1. Files

### Destroyed (`git rm`) and written again from the DC

| File | Now |
|---|---|
| `templates/layout/shell.twig` | Rebuilt whole. **The chrome is the layout's**: it mounts the header, the app bar (from a page's `app_bar` hash: `variant` root/child, `title`, `sub`, `back`, `share`…), the tab bar (unless `flow_page`), the flash rail, the Menu, Quick settings, the search palette and the shortcuts dialog; links `components/chrome.css`; loads `shell.js`, `a11y.js`, `chrome.js`, `header.js`, `search.js`; `data-ag-easy-font`, and for a member `data-ag-sync` + `<meta name="ag-csrf">`. Gee block empty (Phase 3). |
| `templates/partials/site-header.twig` | SiteHeader.dc.html: 64px bar, logo, Participate/Explore (the handoff's 6 + 6, Results removed), toolbar pill (Search · Aa · language), avatar or outlined Sign in, two mega panels, the Aa and language popovers. |
| `templates/partials/app-bar.twig` | AppBar.dc.html root (88·1fr·88, 52px, large Playfair 32 title = the phone `<h1>`) and child (44·1fr·auto, 56px, back with `.ag-ico-dir` — the RTL fault Phase 1 found is fixed). Root alone includes the language prompt, inside `lang_ask()`. |
| `templates/partials/tab-bar.twig` | §7.3 / HomePageV3.dc.html's tab bar: Home · Discover · Nominate · Pulse · Menu; one or zero lit. |
| `templates/partials/menu-sheet.twig` | MobileMenu.dc.html: profile/join card, 4 tiles, Explore (§7.4's seven), Settings, Help (Status with the recorded state), legal + Sign out (POST) + wordmark; pushed Display & reading and Language sub-views. |
| `templates/partials/quick-settings.twig` | §7.2's sheet: profile row, text size (48px), High contrast + Reduce motion, language chips, "All display & reading settings", 52px Done. |
| `templates/partials/display-reading.twig` | DisplayReading.dc.html: heading, 3-step size, seven 52px switch rows (44×26 switch), the language select in a real GET form. |
| `templates/partials/a11y-head.twig` | First-paint script: the member's saved settings first, else `localStorage["ag-a11y"]`; loads Atkinson Hyperlegible only when easy-read is on. |
| `public/assets/js/a11y.js` | The store; saves to the account while signed in (debounced POST), adopts a device's settings for a member who has none saved, loads the easy-read face. |
| `public/assets/js/chrome.js` | Phone sheets (one history entry, back closes), Menu sub-views (focus to the new view, back to the row that pushed), Quick settings → Menu hand-over, size/switch controls (arrow keys, RTL-aware), language form, share, the language prompt (reveal `navigator.languages[0]`'s row, hide on first scroll). |

### Created

| File | Why |
|---|---|
| `public/assets/css/components/chrome.css` | Every rule of the chrome, from the DCs' inline values; zero colour literals (bar/scrim/edge translucency is `color-mix` of tokens); breakpoint ownership last. Added to `AssetBundle::STYLESHEETS` after `components.css`. |
| `public/assets/js/header.js` | MUST RESTORE (inventory `_scripts.md`): one layer controller for both mega panels and both popovers (click toggles, opening one closes all, Esc/scrim/outside close, focus returns, arrows inside a menu), the toolbar's roving tab stop and ← →, `?` shortcuts and `G` chords. |
| `public/assets/js/search.js` | MUST RESTORE (`ag-search.js`): the palette — `/` and ⌘K/Ctrl-K, focus trap and return, WAI-ARIA 1.2 combobox (`aria-activedescendant`), chips as a tablist, 220ms debounce, stale-answer guard, one polite live region. Names no source. |
| `templates/partials/site-search.twig` | MUST RESTORE (`site-search.twig`): the palette markup; chips = "All" + `search_scopes()`. |
| `templates/partials/shortcuts.twig` | The DC's keyboard-shortcuts dialog (`kbOpen`). |
| `templates/partials/lang-prompt.twig` | The first-visit prompt, rebuilt with GAPS §3.8 fixed (§4). |
| `templates/partials/flash.twig` | MUST RESTORE (`flash.twig`): `flash_error` (alert) / `flash_ok`, `flash_notice` (status), words + an `aria-hidden` mark. |
| `src/Controllers/SearchController.php` | `GET /search?q=&scope=` JSON: rate limited (120/min/client), groups by `ActivityFeedService::SCOPES` on the server, ≤6 per group, chip order, "More" for an unmapped kind; empty query → `SearchLanding`. |
| `src/Services/SearchLanding.php` | Empty palette: **Open now** (active programmes whose current cycle's COMPUTED phase is nominations or voting) and **Coming up** (published future events). No trending (§5, deviation 9). |
| `src/Services/DisplayReadingPrefs.php`, `src/Controllers/DisplayReadingController.php` | The profile half of §7.5: normalise (exactly the store's keys), `forUser()`, `save()`; `POST /account/display` inside the `/account` group (UserAuthMiddleware, CSRF via `X-CSRF-Token`), the member is the session's. |
| `database/migrations/2027_02_26_member_display_reading.php` | `gates_users.display_json` — TEXT on SQLite, VARCHAR(255) on MySQL, added only where absent (no constraint to repair later). Also in `database/schema.sql` and `database/sqlite-schema.sql`. |
| `tests/Support/ChromeRender.php` | Renders a real page through the real router + LanguageMiddleware as a given visitor. |
| `tests/Unit/{SearchEndpointTest,DisplayReadingPrefsTest,MenuSheetTest}.php` | New guards (§7). |

### Changed

| File | Change |
|---|---|
| `src/routes.php` | Retired the `/search` and `/find` 301 aliases and `GET /activity/search`; added `GET /search` and `POST /account/display`; `/_dev/ui` accepts `?bar=root` and `?flow=1` (dev-only) so every chrome prop renders on one page. |
| `src/Controllers/ActivityController.php` | Lost `search()` and its rate-limit key (moved to `SearchController`); `/activity` (the page) is unchanged — Phase 4 retires it. |
| `config/container.php` | Twig functions `member_display()`, `status_light()`, `search_scopes()` (memoised functions, not globals). |
| `src/Services/SystemStatus.php` | `light()`: the last RECORDED overall state if ≤45 minutes old, else null — one indexed row, not six probes per page. |
| `src/Support/CookieRegistry.php` | The `ag-a11y` declaration now says the settings are also saved to a signed-in member's account (it said "kept on your device rather than fetched", which this phase made untrue). **Legal-page text — for the cookie agent/owner to confirm.** |
| `public/assets/css/tokens.css` | `--ag-z-appbar:20`, `--ag-z-header:40` restored with their reader (§6.7). |
| `src/Support/AssetBundle.php` | `components/chrome.css` after the base. |
| `templates/pages/dev-ui.twig` | Stopped mounting the chrome itself; sets `app_bar` (child) and takes the layout's chrome. |
| Tests | `SiteHeaderTest` (rewritten), `ChromeReachabilityTest`, `DisplayReadingTest`, `LanguageTest`, `SearchScopeTest`, `PublicResultsTest`, `ShellLayoutTest`, `DeadTokenTest` (eight palette tokens left `AWAITING_REBUILD` — they have readers now). |

**Not touched**: `TypeScaleTest`, `MonoAndCaseTest`, `PublicSurface`, CLAUDE.md, GAPS.md (another agent's, in flight). GAPS §3.6/3.7/3.8/3.15 and Q13/Q18 are answered by this phase and should be marked so by whoever owns GAPS.md next.

---

## 2. Owner decisions implemented

| Decision | Where |
|---|---|
| `GET /search?q=&scope=` IS the JSON endpoint; `/activity/search`, `/search`→`/activity`, `/find`→`/activity` retired; rate limit and server-side scope map kept | `SearchController`, `routes.php`; `SearchEndpointTest`, `SearchScopeTest` |
| Empty query: only measured signals; no invented trending | `SearchLanding` (Open now, Coming up); deviation 9 |
| Explore = Discover, Pulse, Events, Legacy Vault, Blog, Status; Results removed; guards rewritten to the new rule | `site-header.twig`; `SiteHeaderTest::test_each_panel_is_the_handoffs_six_exactly`, `PublicResultsTest` (the palette on every shell page is now the browsing door; the footer owes the other) |
| Fixed type sizes, no `vw`; mono only for codes (`<kbd>` only); no uppercase labels | `chrome.css` passes `TypeScaleTest` and `MonoAndCaseTest` (their remaining failures are door.css, viz.twig, share.twig, poll.twig, emails — none in this phase's files) |
| Colour only from Accent tokens | `chrome.css`: tone classes; `color-mix` for the DC's rgba; `ColourLiteralTest` green |
| Every string through `|trans`; EN FR AR(rtl) SW PT HA YO IG via Languages + LanguageMiddleware; §3.8 fixed | every template string; JS messages arrive as `|trans`-rendered data attributes; server labels via `Translator::t`; §4 |
| DisplayReading: `ag-a11y` + first-paint nonced script + member profile end to end; Atkinson actually loads | migration → `DisplayReadingPrefs` → route → head script → `a11y.js`; `DisplayReadingPrefsTest`, `DisplayReadingTest` |
| Menu → Cookies and footer re-open: a stable hook, no consent built | Menu: `<a href="/cookies#choices" data-ag-do="consent-open">` (the footer is not this phase's) |
| Cart badge only if a cart service exists | none exists (the only basket was `afg_cart` written by the destroyed shop pages; no server service) → **no badge**; Phase 7 |

---

## 3. Every prop combination → screenshot

Fonts are the real faces: Chromium cannot verify the container's proxy CA, so the capture script fetches
`fonts.googleapis.com`/`fonts.gstatic.com` (and the DCs' React from unpkg) with `curl` and serves them to the page
(`page.route`) — Chromium's TLS checks stay on. Data: a scratch SQLite database with two programmes (one voting, one
taking nominations), two upcoming events, three profiles, a member (Chioma Obi, 1,250 points) and one status record.

| Component · prop | 390 | 834 · 1024 · 1440 |
|---|---|---|
| SiteHeader · signedIn false/true · rest | — (phone has the app bar: `page-834/1024/1440-root.png` show the header in its place) | `header-{834,1024,1440}-{signedout,signedin}-rest.png` |
| · Participate open | — | `header-{w}-{state}-participate.png` |
| · Explore open | — | `header-{w}-{state}-explore.png` |
| · Aa open | — | `header-{w}-{state}-aa.png` |
| · language open | — | `header-{w}-{state}-language.png` |
| Search palette · empty / query + active option / People chip / nothing matched | — | `search-{834,1024,1440}-{empty,query,people-chip,nothing}.png` |
| Shortcuts dialog (`?`) | — | `shortcuts-1440.png` |
| AppBar · root · rest / scrolled · signed out/in | `appbar-390-root-{rest,scrolled}-{signedout,signedin}.png`, `appbar-360-root-rest.png` | not rendered ≥600 |
| AppBar · child · rest / scrolled | `appbar-390-child-{rest,scrolled}.png` | not rendered ≥600 |
| Tab bar · one lit / none lit / flow page (none) | `appbar-390-root-*` (Discover lit), `appbar-390-child-*` (none), `flow-390-no-tabbar.png` | not rendered ≥600 |
| MobileMenu · signedIn false/true · main (top and end) | `menu-390-{signedout,signedin}-main.png`, `…-main-end.png` | not rendered ≥600 |
| · Display & reading sub-view | `menu-390-{state}-display.png` | (same block in the Aa popover above) |
| · Language sub-view | `menu-390-{state}-language.png` | (language popover above) |
| Quick settings · signed out/in | `quick-390-{signedout,signedin}.png`; working: `settings-390-largest-hc.png` | not rendered ≥600 |
| Language prompt · fr / sw / gone after scroll | `langprompt-390-{fr,sw}.png`, `langprompt-390-fr-after-scroll.png` | not rendered ≥600 |
| **RTL (Arabic)** | `rtl-390-{appbar-root,appbar-child,menu,menu-display,menu-language,quick}.png` | `rtl-1440-{explore,aa,language}.png` |
| Against the DC (REFERENCE §17 overlay, DC at 50% over the build) | `compare-build-menu-open-390.png` · `compare-dc-menu-open-phone.png` · `overlay-menu-open-390.png` | `compare-build-header-mega-1440.png` · `compare-dc-header-mega-desktop.png` · `overlay-header-mega-1440.png` |
| Motion, normal vs reduced | `motion-{normal,reduced}-appbar-{000…320}ms.png`, `motion-{normal,reduced}-menu-{000…300}ms.png` | |

**The overlays.** Header at 1440: every tile, row and the bar line up to the pixel; only text widths differ (the DC's
render used a fallback face, the build DM Sans). Five DC boxes measure padding and border OUTSIDE their stated size
(content-box — the bar is 65px with its hairline, the pill 42, the language menu 224, the panel's inner 1180 + 80); the
build was measured against the DC in a browser and now matches each (bar `[0,0,1440,65]`, pill height 42, panel items at
`x=130/524.7`, language menu `[1158,70,224,334]`, Aa `[1000,70,360,…]`, palette `[380,84,680,…]`). Menu at 390: every
row lines up horizontally; everything below the profile card sits 52px lower because the DC's own render clips that
card to 28px (deviation 17).

---

## 4. Language, and the §3.8 fault

`lang_ask()` is now READ: `app-bar.twig` includes the prompt only inside `{% if _root and lang_ask() %}`. Rendered
through `LanguageMiddleware` (`LanguageTest`): no cookie → the prompt rows are drawn (French in French, its own
`lang`/`dir`); `ag_lang=en` or `fr` → nothing; a child bar → nothing. Both answers are `?lang=` links, so the middleware
stays the one writer and either answer sets the cookie; the script reveals only `navigator.languages[0]`'s row and hides
it on the first scroll (`langprompt-390-fr-after-scroll.png`). Arabic: `<html lang="ar" dir="rtl">`, logical properties
only in `chrome.css` (asserted), every directional mark `.ag-ico-dir` (mirrored), popovers anchored with
`inset-inline-end`, size arrows and toolbar arrows reversed in RTL.

---

## 5. Keyboard, screen reader, reduced motion

**Keyboard walkthrough** (Playwright, keyboard only, `p2/kb.js` — 31/31 pass). 1440 signed in, Tab from the top:
Skip to content · Africa GATES home · Participate · Explore · the toolbar (one stop) · Your account · page. Enter on
Participate opens it and focuses Nominate; ↓ → Vote; Esc closes and **returns focus to Participate**; opening Explore
closes Participate; the scrim closes it. In the toolbar ← → move Search → Aa → language (one tab stop, `[-1,-1,0]` after
roving); Enter on language opens the menu on the current language, ↓ → Français, Esc returns focus. Enter on Aa moves
into the popover (Standard text), → sets 125% (`html.ag-t125`), Esc returns focus to Aa. `/` opens the palette with
focus in the input; ↓ sets `aria-activedescendant` while focus stays in the box; Tab ×14 never leaves the palette; Esc
closes it and **returns focus to the header's Search control** (also when it was opened by the shortcut from the body).
`?` opens the shortcuts with focus on Close; Esc closes. 390 signed in: Enter on Menu opens the sheet with focus inside;
Tab ×40 stays inside; Display & reading pushes a sub-view and focuses its first control; Back returns **to the row that
pushed it**; Esc closes and **returns focus to the Menu tab**; the browser Back closes the sheet and stays on the page;
the avatar opens Quick settings, Esc returns to the avatar; "All display & reading settings" hands over to the Menu's
sub-view and closing that returns focus **to the avatar that started it**.

**Screen reader (by role and name; VoiceOver/TalkBack are not available in this container):** the header is a `banner`
with a `navigation` "Primary"; panels are `role="menu"` labelled by their trigger (`aria-haspopup`, `aria-expanded`,
`aria-controls`); Aa is a non-modal `dialog`, language a `menu` of `menuitemradio`s each `lang`-tagged; the palette a
modal `dialog` with a `search` form, combobox → `listbox` of `option`s in labelled `group`s, chips a `tablist`, one
always-present polite `status`; sheets are modal `dialog`s, `inert` when closed; switches are whole-row `role="switch"`;
the size control a `radiogroup` with one tab stop; the large app-bar title stays an `<h1>` in the tree when it collapses
(the small title is `aria-hidden`); Status says its state in words.

**Reduced motion** (`p2/motion.js`): OS `prefers-reduced-motion: reduce` computes every chrome transition to `1e-06s`, and
every captured frame is byte-identical to the next — the final state at once (app bar `658f907e36b0` ×5, Menu
`439838287b15` ×4); normal motion shows the fill/fade and the sheet slide in progress (five and four distinct frames). The
in-app Reduce motion (`html.ag-rm`) sets `transition: none`.

---

## 6. Hooks left for item 6 (cookies)

Menu → Legal → **Cookies** is `<a href="/cookies#choices" data-ag-do="consent-open">`. With nothing bound it is a plain
link to the policy's choices; the cookie agent binds the attribute to re-open the preferences in place. The footer is not
in this phase.

---

## 7. Tests

**Rebuilt and new guards — each watched failing first.** 23 mutations, 23 caught (`p2/mut.py` + two follow-ups):

| Mutation | Caught by |
|---|---|
| Results back in Explore | `SiteHeaderTest::test_each_panel_is_the_handoffs_six_exactly` |
| Sign-in pill painted green | `SiteHeaderTest::test_there_is_no_green_button_in_either_signed_in_state` (renders both states) |
| Hairline kept under an open panel | `SiteHeaderTest::test_the_hairline_is_dropped_while_a_panel_is_open` |
| Toolbar loses its roving tab stop | `SiteHeaderTest::test_the_toolbar_holds_exactly_search_display_and_language_and_answers_the_arrows` |
| A page mounts the tab bar | `SiteHeaderTest::test_a_page_never_mounts_the_chrome_itself` |
| Display & reading with a fixed id | `SiteHeaderTest::test_no_id_appears_twice_in_one_document` (on the rendered page) |
| Palette / Quick-settings trigger bound by nothing | `ChromeReachabilityTest::test_every_trigger_is_bound_by_a_script_the_layout_loads` — **its first version passed this mutation** (it accepted any mention of the selector); rewritten to require the click resolution, then caught both |
| Prompt included without `lang_ask()` | `LanguageTest::test_the_prompt_never_returns_once_either_answer_is_stored` |
| Easy-read face never loaded | `DisplayReadingTest::test_the_easy_read_font_is_actually_loaded_when_it_is_on` |
| Head script ignores the member | `DisplayReadingPrefsTest::test_the_first_paint_carries_the_members_settings` |
| The account drops a switch | `DisplayReadingTest::test_the_account_keeps_exactly_the_keys_the_store_keeps` |
| The body chooses the member | `DisplayReadingPrefsTest::test_the_route_saves_for_the_session_member_and_nobody_else` |
| The route leaves the account group | `DisplayReadingPrefsTest::test_the_route_refuses_a_visitor_who_is_not_signed_in` |
| search.js names a source | `SearchScopeTest::test_the_scope_map_stays_on_the_server` |
| `/find` alias returns | `SearchEndpointTest::test_search_is_the_endpoint_and_the_retired_addresses_are_gone` |
| Open-now reads the stored status · an invented Trending group | `SearchEndpointTest::test_the_empty_palette_is_open_now_and_coming_up_and_nothing_invented` |
| Palette leaves the shell | `PublicResultsTest::test_the_platform_actually_points_at_the_result_page` |
| Back chevron stops mirroring · a physical margin | `LanguageTest::test_arabic_mirrors_the_document_and_every_direction_mark` |
| A stale record still says Working · Blog swapped for Leaderboard · Sign out as GET | `MenuSheetTest` (three tests) |

`SearchScopeTest::test_no_search_entrance_enumerates_the_sources` was first written against the template source and read
NOTHING (every word is inside `{{ … }}`) — it now reads the rendered palette and asserts it read something.

**Runs** (no `.env`; `var/data` lock files cleared before and after): the filter
`Chrome|SiteHeader|Search|Language|Translator|DisplayReading|Shell|DevUi|Colour|Accent|DeadToken|TypeScale|Csp|Cookie|PublicIa|RouteTable|Template|Twig`
plus `PublicResults|AssetBundle|MonoAndCase|Flash|NestedForm|OneMain|Landmark|Focus|Touch|Target|AccessibilityFloor|AdminContrast|SlotFloor|Alias|Deployed|Passkey|Activity|Status|Sitemap|Security|MenuSheet|Schema|Migration|Users|Account`:
**862 tests, 11 failures** — `PasskeyTest` ×8 (PHP 8.3 container, environmental), and `TypeScaleTest` ×1 / `MonoAndCaseTest` ×2,
the other agent's in-flight guards, failing on `door.css`, `viz.twig`, `share.twig`, `poll.twig` and the email
templates only — no file of this phase. **Full suite: 6,550 tests, 11 failures — the same eleven.** (All three type
failures are gone since §10: the door is held, the mail is rebuilt.)

---

## 8. Deviations — what, why, approved by (target: zero)

| # | What | Why | Approved by |
|---|---|---|---|
| 1 | "GATES" 11.5px, not the DC's 9.5 | §6.2: nothing under 11.5; the owner applied the ladder with no exception (Q4); REFERENCE outranks a DC | REFERENCE §0 + owner Q4 — **confirm** |
| 2 | Explore: Discover, Pulse, Events, Legacy Vault, Blog, Status (DC: Leaderboard, Shop, no Status); Results removed. Status line "Is everything working?" (the DC's own search-data wording) | §7.1 + owner | Owner, 3 Oct 2026 |
| 3 | Menu Explore: Blog, not the DC's Leaderboard | §7.4 outranks the DC | REFERENCE §0 |
| 4 | DC tile hexes (`#eef7ee #a47306 #e9efef #2b373d #f0f2f2 #5d7374`) → Accent families by tone | not in the palette | Owner Q1 |
| 5 | Palette chips outlined (2px ink chosen), 44/40px tall, not dark-filled 32px | §6.1/§18.2 (no dark-filled chip), §6.7 (44) | REFERENCE §0 |
| 6 | Palette group headings sentence case 12.5/700, not uppercase 11 | no uppercase labels; nothing under 11.5 | Owner Q4/Q5 |
| 7 | Palette kind label and the Menu wordmark line `--ag-soft`, not `#8b9295` | `--ag-mute` is never a word | Owner Q2 (`Accent::ACCEPTED`) |
| 8 | Menu points DM Sans tabular, not JetBrains Mono | mono only for references and codes | Owner Q5 |
| 9 | Empty palette: Open now + Coming up; **no "Trending people"** | no trending signal is recorded (GAPS §3.6); not invented | Owner instruction — **acknowledge** |
| 10 | Empty palette with nothing open or upcoming says "Start typing to search people, awards, events and pages." | §9.4 never an empty view; copy not in the DC | **Needs approval** |
| 11 | Palette rows carry initials/icons, no photographs | the index holds no images | **Needs approval** |
| 12 | App bar small title 700 (DC 600), avatar 34 (DC 32), child bar 56 (DC 52), fade 200ms (DC .18s) | §7.2's own numbers outrank the DC | REFERENCE §0 |
| 13 | Phone search icon links to `/discover` (as the DC) — `/discover` still 302s to the destroyed `/registry` until Phase 4 | the DC's destination | **Flag — Phase 4** |
| 14 | Header Search is an `<a href="/discover">` upgraded by `search.js` (DC: a button) | something must happen with scripting off | **Needs approval** |
| 15 | Header controls at the DC/§7.1 sizes: top links, pill, avatar, Sign in 40; toolbar buttons 34; palette Esc 28; shortcuts close 36 | §7.1 states them; §6.7 asks 44 everywhere | **Header: approved by the owner, 3 Oct 2026 — §7.1 as specified** (`TargetSizeTest::OWNER_HEADER`, by selector). Palette Esc 28 and shortcuts close 36 are NOT the header and are **open** (§10.4) |
| 16 | Display & reading select 44px / 16px (DC 40 / 14) | §6.2/§9.5 inputs ≥16, §6.7 44 | REFERENCE §0 |
| 17 | Menu profile card drawn whole (the DC renders it clipped to 28px) | a flex-shrink artefact of the DC, not a design | **Needs approval** |
| 18 | Tab bar top line `--ag-line-2` (HomePageV3.dc.html's `#d6d4cc`); §7.3 says only "a top hairline" | the DC's value where the phase gives none | REFERENCE §5 |
| 19 | 600–719px: the wordmark's words hidden, 16px bar padding | the signed-out bar measured 679px at 600 and cut off Sign in; no DC value below 1440 | **Needs approval** |
| 20 | No hover-to-open on the mega panels (the old controller had it) | §7.1 and the DC: click toggles | REFERENCE §0 |
| 21 | Language rows are links with `aria-current` (DC: `role="radio"` buttons) | they must work with scripting off (`LanguageTest`) | **Needs approval** |
| 22 | Quick settings and the language prompt have no DC markup (AppBar.dc.html carries their state only): composed from the base components at the DC's state values | nothing to measure | **Needs approval** |
| 23 | The flash rail has no DC | MUST RESTORE (inventory) | **Needs approval** |
| 24 | Menu Status shows the last recorded check if ≤45 min old, else no state (DC: always "Working") | a typed all-clear outlives the outage it missed | **Needs approval** |
| 25 | The palette input draws no outline on focus (as the DC) — the dialog and the caret carry it | DC value | **Needs approval (WCAG 2.4.7)** |
| 26 | No cart badge | no basket service exists | Owner condition |
| 27 | The unanswered language prompt returns on the next page view (it hides on scroll for that page) | the server gate is the cookie; only an answer sets it | **Confirm** |
| 28 | `/_dev/ui` accepts `?bar=root`, `?flow=1` | dev-only route, to render every chrome prop | — |

Phase 1's deviations 1, 7, 9 and 10 remain open.

## 9. Blocked — not guessed

1. **Translations.** Every chrome string goes through `|trans` (and the script's messages arrive as translated
   attributes), but the catalogues hold only the prompt's two strings. Nothing new was written into them: an unreviewed
   Hausa, Yorùbá or Igbo string is the placeholder the precision contract forbids, and each entry "wants a speaker's
   eye". So Arabic at 390 mirrors correctly with English words (`rtl-390-*.png`). Who writes FR/AR/SW/PT/HA/YO/IG?
2. ~~**Text size does not reach most type.**~~ **Answered (owner, 3 Oct 2026): type in rem — done, §10.1.**
3. ~~**44px floor vs §7.1's 40/34px header controls** (deviation 15).~~ **Answered: the header as specified — §10.4.**
4. **Trending**: record a measured signal (e.g. supporters gained in 24h) to earn a "Trending" group, or accept its
   absence permanently?
5. Not possible here: Lighthouse, a 2 GB Android, VoiceOver/TalkBack. PHP is 8.3 (the app's guard wants 8.4), so the dev
   server ran through a scratch router that skips only that guard.

---

# Cookie consent — Phase 2 item 6 (3 Oct 2026)

**`phases/PHASE-2-chrome.md` §7.9, REFERENCE §10 (`ag_consent`: cookie, JSON, versioned; GPC respected), §13, §17,
`design/CookieConsent.dc.html` views `phone` and `desktop` × `gpc` false/true (`view=admin` is the admin console's and
out of scope).** Owner's answer to GAPS Q11 (3 Oct 2026): the handoff's four categories in `ag_consent` — Essential,
always on, plus Preferences, Analytics, Marketing — keeping the house rule *if anything said no, the answer is no*,
both controls plain forms that post, and the notice's answers one identical class string with no dismissal. Destroyed
and rebuilt: `CookiePrefs`, `CookieRegistry`, the generated `/cookies` section, `CookieRegistryTest`, `CookiePrefsTest`,
`CookieConsentRouteTest`. Nothing is committed. Screenshots: `docs/handoff/shots/phase-2/consent/`.

## C1. Files

| File | What |
|---|---|
| `src/Services/CookiePrefs.php` | **Destroyed and rebuilt.** The one resolver: `allows($request, $category)` for `essential` / `preferences` / `analytics` / `marketing`. Browser signal (GPC, then DNT) → no for every optional category, over any stored yes. Unanswered: Analytics follows `visits_consent_mode` (exempt = count unless refused, consent = not until allowed); Preferences and Marketing are no. `offered()` is derived (Analytics ⇔ the tracker is on; the others ⇔ the registry declares something in them — Marketing never). `mustAsk()` = an offered category is unanswered, no signal, not `/cookies`. `state()` → what the notice/sheet draw; `observe()`/`current()` the per-request memo (`consent()` in Twig). `fromPost()` (`all`/`essential`/`save`; anything else = essential), `write()` (the only writer of `ag_consent`: `{"v":1,"preferences":…,"analytics":…,"marketing":…}`, URL-encoded, `HttpOnly; SameSite=Lax`, a year), `carryOver()` (old `ag_privacy` → `ag_consent`, then `ag_privacy=; Max-Age=0`). A foreign version keeps only its refusals; an unreadable value is a no. |
| `src/Support/CookieRegistry.php` | **Destroyed and rebuilt** to list exactly what surviving code writes: cookies `PHPSESSID`, `ag_consent` (Essential), `ag_lang` (Preferences); `retired()` `ag_privacy`; storage `ag-a11y` (Preferences, local or session by the answer), `coi_declared_` (judges), `ag-door-q:` (door staff), `afStep:`, `ag-copilot`, `ag-asst` (administrators). `categories()` — the four, their words, and a derived `who` line (public entries only). Removed: the seven storage rows nothing writes and the two shop cookies whose only writer died with `gates.twig` (Phase 7 re-declares them with their writer). |
| `src/Services/LegalDocument.php` | `cookiesHtml()` rebuilt: "What each choice controls" (each category: what it controls, its state on this deployment, the names under it), then the cookie table (column "Choice"), "No longer set", the storage list with category and lifetime words, and "Counting arrivals" (the old "why there is no banner" paragraph replaced by what the notice says under each posture). Every `<code>` is a registry name and every registry name is printed. |
| `src/Services/LegalSeeder.php` + `database/migrations/2027_02_27_cookie_consent_policy_repair.php` | The authored `/cookies` prose described one switch ("the counting can be refused on its own"); rewritten for the choices. The migration carries it to production **only where `updated_by IS NULL`**, creates no row, touches no schema, is idempotent (precedent `2027_01_23_cookie_policy_repair.php`). |
| `templates/partials/cookie-consent.twig` | New. The notice (`role="region"`, three `<button name="answer">` in one POST form, identical class), the "saved" line (`role="status"`, Change), the preferences sheet (base `.ag-scrim` + `.ag-sheet`, `role="dialog" aria-modal`, a POST form of `<input type="checkbox" role="switch">` over the DC's drawn switch). |
| `public/assets/css/components/consent.css` | New. DC values; colour only from Accent tokens; type from `--ag-fs-*` (rem) plus the 22px display title in rem; no capitals, no mono; logical insets. Added to `AssetBundle::STYLESHEETS` after `chrome.css`. |
| `public/assets/js/consent.js` | New, enhancement only: opens the sheet in place through `AGChrome.openSheet` (history entry, Back closes) → `AGShell.openSheet` (focus trap, Esc/scrim, focus back); binds `[data-ag-do="consent-open"]` (Choose, Change, **the Menu's Cookies row**); `inert` at rest; opens properly on arrival at `#ag-consent`; measures the floating notice for scroll room; hides "saved" after 8s unless focused. |
| `templates/layout/shell.twig` | The include (inside `.ag-shell`, between `</main>` and the tab bar), `consent.css`, `consent.js`, and `data-ag-keep` on `<html>` (may the display store outlive the tab). |
| `src/routes.php` | `POST /cookies/choice` rebuilt (`answer` = all/essential/save/choose; `choose` stores nothing and 303s to `{return}#ag-consent`; re-writes `ag_lang` persistent or session to match the new Preferences answer; expires `ag_privacy`). The old `$cookieControl` closure and `cookie_control` (fed the destroyed `pages/legal.twig`) removed. |
| `config/container.php`, `tests/Support/AppTwig.php` | `consent()` replaces `cookie_ask()`/`cookie_return()`. |
| `src/Support/Languages.php`, `src/Middleware/LanguageMiddleware.php` | `apply(…, bool $remember)` — required, no default: `Max-Age` only with Preferences (from `CookiePrefs::allows`); otherwise a session cookie. |
| `templates/partials/a11y-head.twig`, `public/assets/js/a11y.js` | `ag-a11y` in `localStorage` only with Preferences, else `sessionStorage`; the head script MOVES a copy into the store the answer allows (so "no" takes it off the device). Both stores spelled at each call so the sweep sees every write. |
| `src/Middleware/VisitTrackingMiddleware.php`, `src/Services/VisitTracker.php` | `allows(…, ANALYTICS)`; `carryOver()` on the response. |
| `public/assets/js/ag-chat.js` | Its unused default key `'ag-chat'` removed (both callers name theirs); found by the new sweep. |
| `templates/admin/settings.twig` | The `visits_consent_mode` help text only (it said the default "needs no banner"). |
| Docs | GAPS Q11/C14 marked answered; DESTROYED.md "Stale declarations" marked resolved; CODEBASE-INDEX §24 gained "Rebuilt for four categories". |

## C2. Owner decisions implemented, and one judgement

| Decision | Where |
|---|---|
| Four categories in `ag_consent`, versioned JSON | `CookiePrefs::write()`, `CookieRegistry::categories()` |
| If anything said no, the answer is no; GPC → "Respected your browser's privacy signal" | `allows()`, `mustAsk()`; the sheet's signal note; no notice to a GPC/DNT browser |
| Plain forms that post; identical class on the answers; no dismiss | the partial; `CookieConsentNoticeTest` |
| `visits_consent_mode` keeps its meaning for Analytics; VisitTracker reads CookiePrefs | `allows()` default; `VisitTracker::record()` |
| `ag_privacy` answers carried over (a no stays a no), then not written | `answers()`, `carryOver()`, `retired()` |
| **Judgement — Marketing.** Nothing on this site does marketing (no ads, pixels, retargeting, or third-party tags; `LegalCoverageTest` already holds "no trackers"). It is drawn as the fourth row, with **"Not used" and no switch**, never stored as a yes, and the generated section says so in words. A switch for something nothing does changes nothing — a control lying about a mechanism. The category is "offered" automatically the day a Marketing entry is declared in the registry. | `CookiePrefs::offered()`; **owner to confirm** |

## C3. Every prop combination → screenshot (`docs/handoff/shots/phase-2/consent/`)

| DC prop | 390 | 834 · 1024 · 1440 | DC render (same size) |
|---|---|---|---|
| `phone/desktop`, `gpc=false`, banner | `banner-390.png` | `banner-{834,1024,1440}.png` | `dc-phone-gpcfalse-banner.png`, `dc-desktop-gpcfalse-banner.png`, `dc-reference-cookie-consent-phone.png` |
| preferences sheet (Choose) | `prefs-390.png`, `prefs-390-preferences-on.png` | `prefs-{834,1024,1440}.png` | `dc-*-gpcfalse-prefs.png` |
| saved | `saved-essential-390.png`, `saved-all-390.png`, `saved-mine-390.png` | `saved-essential-{834,1024,1440}.png` | — (the DC's `saved` stage) |
| `gpc=true` | `gpc-nobanner-390.png`, `gpc-prefs-390.png` | `gpc-nobanner-*.png`, `gpc-prefs-*.png` | `dc-*-gpctrue-*.png` (deviation 5) |
| Re-open from Menu → Cookies | `menu-cookies-row-390.png`, `reopen-from-menu-390.png` | Menu is phone-only (C6.1) | — |
| RTL (Arabic) | `rtl-banner-390.png`, `rtl-prefs-390.png` | `rtl-banner-1440.png` | — |
| No JavaScript | `nojs-banner-390.png`, `nojs-prefs-390.png` (Choose → `#ag-consent` → `:target`), `nojs-saved-390.png` | — | — |
| Overlays (DC at 50% over the build) | `overlay-banner-390.png`, `overlay-banner-390-vs-reference.png`, `overlay-prefs-390.png` | `overlay-banner-1440.png`, `overlay-prefs-1440.png` | |

**Overlays.** 1440: the card, tile, title and the three answers line up to the pixel once the DC's **content-box**
width is honoured (460 of content + 16 padding + 1 border = 494 across, measured from the DC's own render — the same
DC trait Phase 2 found in the header). The third line differs: the copy is generated from what is running (deviation 2).
390: the 12px sides and the bottom edge (12 above the tab bar) line up with `cookie-consent-phone.png`; the card is one
line taller for the same copy reason. DC renders were taken 16px larger and clipped, because the DC canvas draws its
frame 8px in from the window. `notes.txt` records the no-JS round trip (`/_dev/ui#ag-consent`; stored
`{"v":1,"preferences":true,"analytics":true,"marketing":null}`, `ag_lang` made persistent).

## C4. Keyboard, screen reader, motion

**Keyboard** (`keyboard.txt`, Playwright, keyboard only — **28/28 pass**). 390 and 1440: Tab from the top reaches
Allow all → Essential → Choose (the notice is after `<main>` in the reading order — C6.5); Enter on Choose opens the
sheet with focus on the Preferences switch; Tab ×20 cycles Preferences → Analytics → Essential only → Save and never
leaves; Space toggles a switch (announced as a switch); **Esc closes and returns focus to Choose**; the closed sheet is
`inert` again; the notice is still there (Esc is not an answer); Enter on Save posts and the notice is replaced by a
`role="status"` line. 390 via the Menu: Menu → Cookies opens the sheet in place, the Menu closes, **Esc returns focus to
the Menu tab** (not to a row in a closed sheet), and the browser Back closes the sheet and stays on the page.

**Screen reader** (by role and name; VoiceOver/TalkBack unavailable here): the notice is a `region` "Your privacy, your
choice" (not a dialog, not modal); Choose has `aria-haspopup="dialog"` `aria-controls`; the sheet is a modal `dialog`
"Your cookie choices", inert at rest; each switch is a checkbox with `role="switch"`, a `<label>` and
`aria-describedby` its description; the drawn track is `aria-hidden`; "saved" is a polite `status`.

**Motion.** The sheet uses the base sheet's transitions (Phase 2 recorded those honouring `prefers-reduced-motion` and
`html.ag-rm`); the switch's own colour transition is `none` under both. No new frame recordings were made.

## C5. Tests — each new or rebuilt guard watched failing first

Rebuilt: `CookiePrefsTest` (15), `CookieRegistryTest` (7), `Feature/CookieConsentRouteTest` (7). New:
`CookieConsentNoticeTest` (9), `LegalDocumentTest::test_the_generated_cookie_section_names_exactly_the_registry`,
`CookiePolicyRepairTest` +2. Edited to the new rule: `LanguageTest` (session cookie without Preferences, a year with it,
GPC beats it), `LegalCoverageTest` (the policy mentions the notice instead of explaining its absence),
`DisplayReadingTest` (the head script writes to the store the answer allows), `ShellLayoutTest` (consent.css in the
cascade), `ChromeReachabilityTest` (the sheet and its trigger `data-ag-do="consent-open"`), `tests/Support/ChromeRender`
(VisitTrackingMiddleware, as in `public/index.php`, plus request headers).

**25 mutations, 25 caught** (`scratchpad/p2c/mut.py`): answers given different classes · a close button · the notice's
form made non-posting · the sheet `inert` in markup · signal no longer beats a yes · the legacy no dropped · the retired
cookie written with a value · a storage key in a variable, undeclared · an unresolvable key · a stale storage row · a
stale cookie row · an extra name typed into the generated section · the storage list dropped · Marketing offered ·
Preferences on by default · the notice asked of a GPC browser · the language cookie ignoring consent · `choose` storing
an answer · the notice floating over the page on a phone · `data-ag-keep` always 1 · the repair overwriting an edited
policy · the Menu row unbound · the head script ignoring consent · the tracker ignoring a refusal. **Two passed on the
first run and were fixed, not excused:** dropping the storage list (the category lists print the same names — the test
now reads the list itself), and the repair ignoring `updated_by` (the `whereNull` on the UPDATE still held — a mutation
removing both is caught).

**The variable-key question, answered precisely:** `CookieRegistryTest` reads every `.setItem(` whatever the receiver
and resolves its first argument as a literal, a `var/let/const` in the same file (its leading literal is the prefix), an
option with a literal fallback (`opts.storageKey` → every `storageKey:'…'` passed), or a method returning one
(`this.key()`); **anything else fails, naming the file and expression.** Not seen: bracket assignment
(`localStorage['k'] = …`), `Storage.prototype`, IndexedDB, and scripts no template loads (out of scope by definition —
`gee.js` until Phase 3 mounts it). Planted proofs in `test_the_sweeps_name_what_they_were_built_to_catch`.

**Runs** (no `.env`; lock files cleared): `Cookie|Consent|Legal|Visit|Privacy|Csp|SecurityHeaders|Shell|Colour|TypeScale|
MonoAndCase|DeadToken|Translator|Chrome|DisplayReading|Language|AssetBundle|SiteHeader|MenuSheet|NestedForm|CsrfField|
TemplateContext|TwigBlock|AccessibilityFloor|PublicIa|RouteTable|Settings|Handbook|BaseComponents|Accent` — **570 tests, 1
failure**: `TypeScaleTest` on `templates/emails/*` 10/11px (the concurrent email rebuild; no file of this item).
Full suite: **6,569 tests, 9 failures** — `PasskeyTest` ×8 (PHP 8.3 container) and `TypeScaleTest` ×1 (email templates, concurrent agent). None in a file of this item.

## C6. Deviations — what, why, approved by

| # | What | Why | Approved by |
|---|---|---|---|
| 1 | Phone: the notice is a flex child of the shell column above the tab bar (12px on three sides), not absolutely positioned over the page (`bottom:96px`) | Same place on screen; the scroller shrinks for it, so it can never cover content or the tab bar — WCAG 2.4.11 without any script (the DC's own admin panel: "never covers the page") | **Needs approval** |
| 2 | Notice copy generated from what runs: "remember your settings on this device"; under the exempt posture "We count visits ourselves unless you say no."; no "load videos" | There is no video/embeds category (embeds are click-to-load per video, CLAUDE.md "An organisation's own donation page"); a notice must not promise consent for something it is not asking about | **Needs approval** |
| 3 | Category names/descriptions are the owner's (Preferences, Analytics, Marketing) and come from `CookieRegistry`; DC: Your settings, Visit counting, Videos and embeds; the `who` line lists the derived names (DC: lifetimes) | Q11; facts generated, never typed | Owner, 3 Oct 2026 (names); **acknowledge** (`who`) |
| 4 | Marketing: a row, "Not used", no switch | C2 | **Owner to confirm** |
| 5 | GPC/DNT: no notice at all (DC `gpc=true` still shows it with a note); the sheet says "Respected your browser's privacy signal" with no switches | House rule: never ask what the browser already refused (Q11 keeps it) | Owner, 3 Oct 2026 |
| 6 | Nothing pre-switched: Preferences starts off (DC state `prefs:true`); Analytics shows what is actually happening (on under `exempt`, said in words) | The DC's own admin rules: "Nothing is switched on in advance"; a switch shown off while counting would lie | **Needs approval** |
| 7 | Switch mark 11.5px (DC 11) | §6.2 ladder floor | Owner Q4 |
| 8 | "Change" 44px tall (DC 36) | §13 targets | REFERENCE §0 |
| 9 | Sheet subtitle: phone "from Menu → Cookies" (the Menu row is "Cookies"; DC "Cookie settings"); ≥600 "from the Cookies page" | No Menu at ≥600 and no footer yet | **Needs approval** |
| 10 | "Saved" hides after 8s (unless focused); DC leaves it | It sits where the notice was; read once is enough | **Needs approval** |
| 11 | Switch focus ring 3px green, 2px offset on the drawn track (DC: 3px ink, 3 offset) | §13 | REFERENCE §0 |
| 12 | Desktop sheet capped at `100dvh − 56px`, body scrolls | four rows of generated text overflow 900px | **Needs approval** |
| 13 | Saved wording "Everything optional is allowed" (DC "All cookies allowed") | Marketing is not allowed — it is not used | **Needs approval** |
| 14 | Floating notice (≥600) at `--ag-z-header` | the bar layer token (`--ag-z-bar`, 30) was removed for lack of a reader and `tokens.css` is being edited concurrently | **Confirm** — restore `--ag-z-bar` |

## C7. Blocked — not guessed

1. **Footer re-open waits for the footer (Phase 4, HomePageV3 "9. Footer").** From 600px there is no in-page way to
   re-open the choices today: the Menu is phone-only and `/cookies` (DocPage) is destroyed until Phase 9. The footer's
   link should carry `data-ag-do="consent-open"` (consent.js binds it; ChromeReachabilityTest will count it).
2. **Phase 9's `/cookies` DocPage** must draw its "Your choices, right now" card from `consent()` (rows, signal, return)
   and post the same form to `/cookies/choice` — never a second resolver. The Menu's `href="/cookies#choices"` is that
   card's anchor.
3. **Preferences refused = forgotten at browser close**, including for every GPC/DNT browser (the house rule makes a
   signal a no to Preferences too). A GPC user who picks French gets French until the browser closes. Confirm.
4. **Consent records.** The DC's admin view promises "Consent records are kept for 12 months as proof of choice" and
   shows 30-day answer rates; nothing server-side records an answer today (`ag_consent` is the only record, in the
   browser). Build a log (and declare it), or accept no proof-of-consent record?
5. **Reading order.** The notice is after `<main>` (51 Tab presses at 390 on `/_dev/ui`); it is a labelled region a
   screen reader can jump to. Move it before `<main>` in the DOM (visual position unchanged)?
6. **Translations** — every string is `|trans`; no catalogue entries were written (Phase 2's blocked question 1).
7. Not possible here: Lighthouse, a 2 GB Android, VoiceOver/TalkBack; PHP 8.3 container (`PasskeyTest` ×8).

---

## 10. Owner decisions after Phase 2 (3 Oct 2026) — implemented

Four answers, each destroyed-and-rebuilt rather than patched. Evidence under `docs/handoff/shots/phase-2/rem/`.

### 10.1 Type in rem — the Display & reading setting reaches the words

**Destroyed and written again:** `public/assets/css/tokens.css`, `shell.css`, `components.css`,
`components/chrome.css`, `dev-ui.css`; `tests/Unit/TypeScaleTest.php`.

- **One conversion, in `tokens.css`:** `--ag-fs-11-5` … `--ag-fs-17` (the closed ladder exactly) and the display steps
  `--ag-fs-18 · 19 · 23 · 32 · 44`. The NAME is the §6.2 px, the VALUE that px over 16 (`--ag-fs-14-5:.90625rem`).
  Every font size in the four sheets is `var(--ag-fs-…)` — 95 declarations; no literal px or rem is typed in them.
- **The root:** `shell.css` states `html{font-size:100%}` (the reader's own default — a px root would replace it) and
  keeps `html.ag-t125{125%}` / `html.ag-t150{150%}`, the classes the first-paint script and `a11y.js` write.
- **Decided — what stays px:** spacing, radii, borders, shadows and the HEIGHTS of controls. A 44px target is sized for
  a finger, not for text, and must not shrink under a smaller root; every chrome control holds one line whose line box
  is unitless, so it grows with the type. Measured (`p2/clip.js`): at 100/125/150%, at 390 (Menu, Quick settings,
  rest), 834 (Explore, rest), 1024 (search) and 1440 (Aa), **no chrome element clips its text**.
- **Decided — `em` and `%` are refused**, except the root's own percentage at 100/125/150. An `em` size is decided by
  nesting (`.9em` is 13.05px in a 14.5 row and 14.4 in a 16 one, both off the ladder); the ladder can be held only
  where a declaration names its size, and rem does. Relative keywords (`smaller`/`larger`) likewise.
- **`TypeScaleTest` (rebuilt):** the closed ladder (rem read at ×16); no fluid type; **no px size on a screen** (mail is
  exempt from this one rule only); no `em`/`%` but the root; `tokens.css`'s steps ARE the ladder and each name equals
  its value; **the setting moves the root and the type follows** — the three root rules exactly, nothing else on the
  public surface sets the root, both writers write the classes, and every resolved screen size is 1.5× at 150%; held
  sheets and held templates unlinked from the public surface; the door guard (§10.2). Its self-test plants a shorthand,
  a media query, an inline style, `.72rem` (11.52px), a token reached through the shorthand, `em`/`%`/`smaller`, a
  `130%` root, an on-ladder px on a screen and px in mail.
- **Proved failing first** (13 mutations, 13 caught — `scratchpad/mail/mut.py`): an email label back to 11px; a chrome
  size back to px; `.83rem` typed; `.8em`; `html.ag-t150` at 100%; a sheet pinning `html{font-size:16px}`; a token
  mis-converted (`.9rem`); the door sheet linked from another template; plus the five mail mutations in §10.3.
- **Pixel-identical at 100%:** `/_dev/ui` scrolled frame by frame at 390 / 834 / 1440 (child and root bars, 76 frames)
  plus all 57 chrome states of the Phase 2 capture, before and after, with animations and the caret frozen and the
  consent notice answered (it now mounts in the shell and would otherwise shrink the scroller): **131 identical, 2
  differ** (`compare-100.txt`) — `settings-390-largest-hc.png`, which is the 150% setting now working (`150-before-` /
  `150-after-settings-390-largest-hc.png`), and `header-1024-signedin-rest.png`, 22px in a 2px band at y=50–52, which
  differs between two captures of the SAME build too (measured noise, before×before2 and after×after2). Against the
  Phase 2 originals the remaining differences are the search rows' live data and the Menu's status line
  (`compare-100-vs-phase2-originals.txt`), identical before and after this change.
- **125% / 150%** (`125-*.png`, `150-*.png`): the root computes to 20px / 24px and every size follows. Observed, not
  fixed: at 150% on a 390 phone the tab bar's "Discover" and "Nominate" labels run edge to edge (they touch, they do
  not overlap or clip — measured); iOS caps tab-bar labels for this reason. **Needs approval** if a cap is wanted.
- **Not changed:** the other agent's `components/consent.css` already reads the new steps.

### 10.2 The door scanner — held like admin

`templates/pages/events/door.twig` and `public/assets/css/components/door.css` are untouched and are now in
`Tests\Support\PublicSurface::HELD_TEMPLATES` / `HELD_CSS` (`DOOR_TEMPLATE`, `DOOR_CSS`), with the reason in its
docblock. `TypeScaleTest::test_the_held_door_sheet_is_linked_only_by_the_held_door` reads EVERY template (public,
admin, judge) and the bundle: the sheet must be linked by the door and nothing else. `MonoAndCaseTest`'s door
failures are gone with the hold. `a11y.css` (linked only by the admin and judge layouts; GAPS §7.3 lists it held) was
added to `HELD_CSS` too: its px input floor is console type, and the public floor is `shell.css`'s, in rem.

### 10.3 The emails — destroyed and rebuilt

Inventory first: `docs/handoff/inventory/_emails.md` (renderer, transport, variables, branches, links, preheader,
unsubscribe, footer lines, translation, the tests that hold each). Then all seven `templates/emails/*.twig` were
deleted and written again:

- **Type:** every 10px and 11px label is 11.5 (the floor); nothing smaller but the 1px `mso-hide:all` preheader
  collapse. px, which is right for mail. The mono reference and the tracked uppercase micro-labels keep their voice —
  `MonoAndCaseTest` scopes mail out, and `InviteInboxCompatTest` asserts the Consolas reference.
- **Colour:** no literal anywhere — `{{ c.ink }}`, `{{ c['ink-2'] }}`. `Support\Accent::mail()` (new) is every hex
  token under the handoff's own names; each sender passes it in the render call. The newsletter's private alias table
  (`Newsletter::palette()`: `card`, `action`, `bar_soft`…) was deleted — the same colours under second names. The
  four document emails' dark-mode block is now the newsletter's (ink ground, ground words): the handoff names no dark
  palette and the hexes they carried were in none. 38 typed colours (34 in no palette) mapped by ROLE (word or field), e.g. the old
  `#1f9d55` → `green`, `#9a9c95` footer grey (2.8:1) → `soft`, `#131a15` bar → `ink`.
- **The house shell** `OtpService::brandWrap()` (the invitation and reminder arrive in it): labels 10/10.5 → 11.5,
  eleven typed colours and four `rgba(255,255,255,…)` → Accent (page `tint`, masthead `ground`, card `surface`, footer
  `ink` with `line-2` words and `surface` links; the shadow is `sh-float`). Structure unchanged.
- **Kept, by test:** every mail suite is green — `InviteMailerTest`, `InviteInboxCompatTest`, `InviteRemindersTest`,
  `EmailInboxCompatTest`, `CampaignInboxCompatTest`, `EmailCampaignTest` (the starter campaign still reads exactly like
  final-hours), `NewsletterTest`, `NewsletterInboxCompatTest`, `QuestionnaireInvitesTest`, `StandNoticeTest`,
  `MailSendRulesTest`, `CspTest`, `TranslatorTest` (no `|trans` was added — none existed — so no catalogue entry is owed).
- **New guard `MailPaletteTest`:** the mail environments are not strict, so `{{ c.inc }}` or a sender that forgets
  `c` renders an EMPTY colour — a button with `background-color:;` on every send. It asserts every `c.<name>` is a
  palette token, no template types a colour, every sender passes `Accent::mail()` in the render call, a real render
  (the campaign, the shell) has no empty colour and nothing off the palette, and the shell is on the ladder. Five
  mutations, five caught. `ColourLiteralTest`'s baseline lost the six email rows (now zero).

### 10.4 Touch targets — the header as specified

No test held 44px on the public surface before (`AccessibilityFloorTest` reads `a11y.css`, which only the consoles
link). New `TargetSizeTest` reads every class on an interactive element in the chrome partials, the shell and
/_dev/ui, and every px `height`/`min-height` a rule in `components.css`/`chrome.css` declares for it:

- **Owner's exception, by selector, at §7.1's value** (`OWNER_HEADER`): `.ag-head__top` 40, `.ag-tools__b` 34 (inside
  the 40px pill), `.ag-head__av` 40, `.ag-head__signin` 40. Held to exactly those numbers, and to the header's block.
  The app bar avatar needs none: 34px of ink inside the 44px `.ag-appbar__icon` button (asserted).
- **44 everywhere else, 24 (WCAG 2.5.8) under everything.**
- **OPEN — shipped under 44 outside the header, NOT approved, awaiting the owner** (held at today's size; the list can
  only shrink, and anything new fails): `.ag-pop__lang` 40 (the header's language menu rows — a DC value, not §7.1),
  `.ag-ss__esc` 28 and `.ag-kb__x` 36 (the rest of deviation 15), `.ag-chip` 40 and `.ag-cs__go` 40 at ≥1024 (Phase 1),
  `.ag-switch` 28 (a button inside a 44px span). Four mutations, four caught.

### 10.5 Not this agent's

`layout/shell.twig`, `CookiePrefs`, `CookieRegistry`, `LegalDocument`, the consent partials and CSS were not touched.
Nothing here needs `shell.twig` changed.
