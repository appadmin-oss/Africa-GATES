# Redesign Phase 1 — tokens, page shell, base components

**`design_handoff_africa_gates/phases/PHASE-1-foundations.md`, finished on 3 Oct 2026.** The colour system
(`Support\Accent`, the non-colour `tokens.css`, the rebuilt colour guards) and the translation layer (`|trans`) were
already rebuilt in this working tree before this pass and were not touched. This pass destroyed and rebuilt the shell,
the base components, the shell behaviour, the shell layout and the style page — after first moving every rule that
other phases had appended to the base out of it, unchanged.

Paths are relative to the repo root. Screenshots are under `docs/handoff/shots/phase-1/`.

---

## 1. Files

### Destroyed and written again from the spec (deleted first, never edited)

| File | Now |
|---|---|
| `public/assets/css/shell.css` | The shell: `.ag-shell` 100dvh, `.ag-main` the only scroller (`position:relative`), the lock on `html.ag-shelled` only (deviation 1), focus ring, defaults, Display & reading classes, reduced motion |
| `public/assets/css/components.css` | The Phase 1 base and nothing else — 1,376 lines → 554: button (primary, secondary, ink, text, danger, pill, block, round icon with the `float`/`dot` shadows), chip (+ row, dot, count, applied filter), sticky toolbar + §9.2 collapsing search, field (+ search, select, textarea, label, hint, error), card, list row 56px, icon tile, scrim + bottom sheet (+ desktop drawer), switch, segmented control, skeleton, empty, stock note, count badge |
| `public/assets/js/shell.js` | Classic `defer` script, `window.AGShell` contract kept (`watchScroll`, `collapsingSearch`, `trackBottomUI`, `openSheet` — `chrome.js` reads `openSheet`). ONE passive scroll listener per scroller (subscribers share it), boolean compare on `> 8`, §9.2 states 1–6, focus handed over in both directions, Esc, bottom-bar height written only on change |
| `templates/layout/shell.twig` | From `snippets/twig/page-shell.twig`, adapted: `lang()`/`lang_dir()`, `class="ag-shelled"` on `<html>`, `partials/a11y-head.twig`, `{{ ag_accents()|raw }}` in a nonced `<style>`, `{{ 'Skip to content'\|trans }}`, the carve-out sheets in their old position, Gee block left empty (Q19) |
| `templates/pages/dev-ui.twig` | Every component in every state, phone and desktop, with the live §9.2 search filtering the page, a real sheet, working switches, segmented control and filter chips. Route `/_dev/ui` kept as it was (404 when `APP_ENV=production`) |

### Created

| File | Why |
|---|---|
| `public/assets/css/components/chrome.css` | **Moved, unchanged, to be destroyed in Phase 2** (GAPS §7.2) |
| `public/assets/css/components/library.css` | **Moved, unchanged, to be destroyed with its readers** — Phase 5 (awards) and Q16 (account, challenges) |
| `public/assets/css/dev-ui.css` | The style page's own layout, linked from its `head_styles` only; no component styling, zero colour literals |
| `tests/Unit/ShellLayoutTest.php` | 9 guards — see §6 |
| `tests/Unit/DevUiTest.php` | 6 guards — see §6 |
| `docs/handoff/shots/phase-1/` | Evidence (§3) |

### Changed

| File | Change |
|---|---|
| `src/Support/AssetBundle.php` | `components/chrome.css`, `components/library.css` right after `components.css` |
| `templates/layout/gates.twig` | the same two links, same position (the bundle fallback must match the list — `AssetBundleTest`) |
| `public/assets/css/components/nominate.css` | `.ag-ai-note` appended, moved unchanged (its only includer is `nominate-award.twig`) |
| `tests/Unit/SiteHeaderTest.php` | reads the header rules from `components/chrome.css` (two lines) |
| `docs/handoff/GAPS.md` | §7.2: Phase 1 done, the carve-outs listed as "moved, to be destroyed in Phase N" |
| Comment pointers only | `partials/{site-header,ui,icons}.twig`, `pages/account/dashboard.twig`, `pages/challenges/show.twig`, `pages/awards/programme.twig`, `css/a11y.css`, `css/components/site-search.css` — each named `components.css`/`shell.css` for a rule that now lives in `chrome.css`/`library.css` |

### The carve, proved before the rebuild

The rules were moved first and the site photographed before and after **with nothing else changed**:
`/`, `/nominate`, `/nominate` with the Menu open, `/awards`, `/account/login`, `/account/register`, `/cookies`, at 390
and 1440 — **all thirteen PNGs byte-identical** (`shots/phase-1/carve/*-before.png` = `*-after-carve.png`, same
SHA-256). After the rebuild the same thirteen are again byte-identical except one, on purpose:
`nominate-390-menu-after-rebuild.png` — the Menu's rows are now MobileMenu.dc.html's (padding 14, the label takes the
slack, the chevron sits at the trailing edge); before, the chevron hugged the label because the old base row gave the
label no `flex:1`.

---

## 2. Every value, and where it came from

| Component | Source of the numbers |
|---|---|
| Button 52 / r16 / 15.5·700 (desktop 48 / 15); secondary 1px `line-2`, 15·600; text 44, underline offset 3 | snippet = REFERENCE §6.2; hover `bar` on secondary from VotePage `style-hover`; pressed 1px from NominationFlow `style-active` |
| Chip 44 / pill / 14.5·500, selected 2px ink + padding 15 / 700, 40 on desktop | snippet, §6.1, §18.2 |
| Sticky block: bleed, padding 10 → 8 scrolled, hairline only when scrolled, `.18s` padding transition | AwardsPage.dc.html (`stPadV`, `stLine`, `stBleed`) |
| Search row grid 1fr↔0fr, margin 10↔0, 260ms ease + opacity 200ms; button wrapper 0↔44 (40 desktop), −8 end margin | §9.2 + AwardsPage (`srRows`, `srMb`, `btnW`, `btnMe`) |
| Field 52 / r14 / 16px (48 desktop); search: `line` border, 18px glass, 44px clear around a 26px tint disc, max 560 from tablet up | snippet, §9.5, ShopPage + AwardsPage (`searchMax`) |
| Card r20 `line`; link-card hover `line-2` | snippet; LegacyVault and HelpCentre `style-hover` |
| List row flex, gap 14, padding 0 14, ≥56, label flex 1 | MobileMenu.dc.html (the DC beats the snippet's grid) |
| Sheet r24 top, 88dvh, `sh-sheet`, grabber 38×5; desktop drawer 12px from edges, 420 wide, r20, `sh-pop` | snippet, §6.5, §18.1, ShopPage cart (`cW`) |
| Switch 46×28, thumb 22, inset 3 | snippet = DiscoverPage = GivingPage |
| Segmented: tint track, 4 inset, 48 options, r11, chosen white | snippet |
| Skeleton 1.2s, 14px line at r7 | snippet |
| Stock note 12.5·600 on a card (12 phone), 14·600 size line (13·700 phone), `aria-live` on the line | ShopPage (`stockInk`, `stockLine`) |
| Count badge 18 pill green / filter 20 ink, 11.5·700; pop = `agcPop` keyframes on `--ag-ease-pop` over `--ag-dur-3` | ShopPage badges; `celebration.css` `agcPop`; §6.6 |
| Round icon button 44, `sh-float` on a photo, `sh-dot` on a card | §6.4, §6.5 |

The four tokens DeadTokenTest reported unread before this pass are now read by components the page renders:
`--ag-stock-low` / `--ag-stock-gone` by `.ag-stock--low` / `--gone`, `--ag-ease-pop` / `--ag-dur-3` by
`.ag-badge[data-pop]`. Removing the old ladder swatches left `--ag-sh-dot` unread, and the round icon button's `--dot`
is its reader. **DeadTokenTest passes** with no exemption, and `DevUiTest` fails if any of the four is read only by
something the page does not render, or if the page reads a token from an inline style.

---

## 3. Screenshots — every prop combination

`/_dev/ui` has no DC (it is the style page item 6 asks for), so its "prop combinations" are the widths, direction,
motion preference and the live states.

| Combination | File |
|---|---|
| 360 · 390 · 834 · 1024 · 1440, whole page | `devui-360-full.png`, `devui-390-full.png`, `devui-834-full.png`, `devui-1024-full.png`, `devui-1440-full.png` |
| RTL (`?lang=ar`) at 390 | `devui-390-rtl-full.png`, `devui-390-rtl-collapsed.png` |
| Reduced motion at 390 | `devui-390-reduced-motion-full.png` |
| §9.2 state 1 (top, open) | `devui-390-state1-top.png`, `devui-1440-state1-top.png` |
| §9.2 state 2 (scrolled, collapsed, button shown) | `devui-390-state2-collapsed.png`, `devui-1440-state2-collapsed.png` |
| §9.2 state 3 (button reopened the row) | `devui-390-state3-reopened.png`, `devui-1440-state3-reopened.png` |
| Empty (query matches nothing) | `devui-390-empty.png`, `devui-1440-empty.png` |
| Sheet open (phone) / drawer (desktop) | `devui-390-sheet-open.png`, `devui-1440-sheet-open.png` |
| Motion, normal vs reduced | `motion-{normal,reduced}-badge-{000..520}ms.png`, `motion-{normal,reduced}-collapse-{000..300}ms.png` |
| The carve | `carve/*` (§1) |

The whole-page captures lift the shell's height lock for the capture only (an injected style), because `<main>` is
the scroller and a viewport screenshot would show one screen. The live-state captures are plain viewport shots.
Google Fonts could not be reached from the container (`ERR_TOO_MANY_RETRIES` through the proxy), so every capture
draws the fallback faces; sizes, weights and metrics are what the stylesheets say, the typefaces are not.

---

## 4. Contrast — every text and mark on `/_dev/ui`, computed with `Support\Contrast`

| Text / mark | On | Ratio | Floor | Result | Where |
|---|---|---|---|---|---|
| `--ag-ink` #10292c | `--ag-ground` #f1efe9 | 13.28:1 | 4.5:1 | pass | H1, H2, labels, text button, app-bar title |
| `--ag-ink-2` #3a4a4c | `--ag-ground` #f1efe9 | 8.07:1 | 4.5:1 | pass | lede |
| `--ag-soft` #626a6e | `--ag-ground` #f1efe9 | 4.80:1 | 4.5:1 | pass | section notes, state captions, hint |
| `--ag-ink` #10292c | `--ag-surface` #ffffff | 15.27:1 | 4.5:1 | pass | card titles, chips, input text, secondary button, list rows, chosen segment |
| `--ag-ink-2` #3a4a4c | `--ag-surface` #ffffff | 9.28:1 | 4.5:1 | pass | card secondary text, empty sentence |
| `--ag-soft` #626a6e | `--ag-surface` #ffffff | 5.52:1 | 4.5:1 | pass | list value and sub-line, chip count, field glyph |
| `--ag-surface` #ffffff | `--ag-green` #237b22 | 5.35:1 | 4.5:1 | pass | primary button, cart badge |
| `--ag-surface` #ffffff | `--ag-green-deep` #1a6118 | 7.59:1 | 4.5:1 | pass | primary button · hover |
| `--ag-surface` #ffffff | `--ag-ink` #10292c | 15.27:1 | 4.5:1 | pass | ink button, filter badge |
| `--ag-ink` #10292c | `--ag-bar` #fbfbfa | 14.74:1 | 4.5:1 | pass | secondary button · hover; tab bar active |
| `--ag-soft` #626a6e | `--ag-bar` #fbfbfa | 5.33:1 | 4.5:1 | pass | tab bar labels |
| `--ag-ink` #10292c | `--ag-tint` #e8e5dd | 12.13:1 | 4.5:1 | pass | unchosen segments, icon tiles |
| `--ag-error` #b42318 | `--ag-ground` #f1efe9 | 5.72:1 | 4.5:1 | pass | danger text button, field error |
| `--ag-error` #b42318 | `--ag-surface` #ffffff | 6.57:1 | 4.5:1 | pass | network error in a card |
| `--ag-stock-low` #8a2020 | `--ag-surface` #ffffff | 9.10:1 | 4.5:1 | pass | “Only 3 left in L” |
| `--ag-stock-gone` #8a5a00 | `--ag-surface` #ffffff | 5.93:1 | 4.5:1 | pass | “Sold out”, “Sold out in XL” |
| `--ag-green-deep` #1a6118 | `--ag-surface` #ffffff | 7.59:1 | 4.5:1 | pass | “In stock” |
| `--ag-ink` #10292c | `--ag-ground` #f1efe9 | 13.28:1 | 3:1 | pass | selected chip's 2px border |
| `--ag-green` #237b22 | `--ag-ground` #f1efe9 | 4.65:1 | 3:1 | pass | focus ring on the page |
| `--ag-green` #237b22 | `--ag-surface` #ffffff | 5.35:1 | 3:1 | pass | focus ring on a card; switch on |
| `--ag-mute` #8b9295 | `--ag-surface` #ffffff | 3.16:1 | 3:1 | pass | switch track (off) |
| `--ag-surface` #ffffff | `--ag-mute` #8b9295 | 3.16:1 | 3:1 | pass | switch thumb on the off track |
| `--ag-line-2` #d6d4cc | `--ag-surface` #ffffff | 1.48:1 | 3:1 | below — **accepted, Q2** (`Accent::ACCEPTED`) | input and outlined-chip border |
| `--ag-chevron` #b3b9ba | `--ag-surface` #ffffff | 1.99:1 | 3:1 | below — **accepted, Q2** (`Accent::ACCEPTED`) | list chevron beside a labelled row |
| `--ag-mute` #8b9295 | `--ag-tint` #e8e5dd | 2.51:1 | — | exempt (inactive control, 1.4.3) | disabled and loading buttons |
| `--ag-mute` #8b9295 | `--ag-surface` #ffffff | 3.16:1 | — | exempt (inactive control) | disabled chip, disabled segment |
| `--ag-mute` #8b9295 | `--ag-ground` #f1efe9 | 2.75:1 | — | exempt (inactive control) | disabled field text |

No text pair fails. The busy button's "Sending…" is drawn in the disabled colours because §9.4 asks for "pressed +
disabled"; it is an inactive control, and the page says the same thing in its own words around it.

---

## 5. Keyboard, screen reader, reduced motion

**Tab order at 390** (recorded by tabbing through the live page; every stop drew the 3px ring):
1 Skip to content · 2–3 announcement strip link and its Dismiss (Phase 2) · 4–5 app bar Back, Share · 6 Search
components · 7–17 the eleven section chips · 18–28 Button: the five live variants, pill, the busy demo, `aria-disabled`
"Continue" (focusable on purpose, described by "Choose a tier first."), full width, quick-add, float · 29 Chip ·
30–35 Field: name, email (invalid, error announced), select, search, its Clear, textarea (the disabled field is skipped)
· 36 link card · 37–39 list rows (two links, one button) · 40 Open a sheet · 41–43 three switches · 44 segmented
(one stop; arrows move the choice, mirrored in RTL) · 45–46 Clear filters, Try again · 47–51 Filters and its four
chips · 52–56 tab bar. On desktop the app bar and tab bar are replaced by the site header's controls.

The hover / focus / pressed / disabled copies are `inert`: they are pictures of a state, so a screen reader meets
each control once. The sheet: focus moves in, Tab is trapped (12 presses stayed inside), Esc and the scrim close it,
focus returns to "Open a sheet" — measured. The count badge's number is `aria-hidden` with a visually hidden
", N on" beside it, so the change is read as words. VoiceOver and TalkBack were not available in this container;
the roles and names above are what they will be given.

**§9.2, measured on the live page** (`verify.js`, at 390 and 1440, normal and reduced motion — identical results):
state 1 at the top (row open, `aria-hidden="false"`, input `tabindex 0`, button wrapper `aria-hidden="true"`,
button `tabindex -1`); `scrolled` flips between 8 and 9 exactly; state 2 collapsed at 60px; state 3 the button
reopens it and focus lands in the input; state 4 stays open 20px from y0 and collapses past 24, handing focus back to
the button; state 5 a query keeps it open 200px later; state 6 the top reopens it; Esc collapses and focuses the
button. One passive scroll listener on `<main>`. `--ag-bottom-ui` = 81px = the tab bar's height on a phone, 0px on
desktop where there is none. No console errors.

**Reduced motion:** with `prefers-reduced-motion: reduce` the search row's and the sheet's transitions compute to
`1e-06s`, and the badge's animation to `1e-06s`: every frame of `motion-reduced-*` is byte-identical to the next —
the final state, at once — while `motion-normal-*` shows the overshoot and the collapse in progress. The in-app
"Reduce motion" (`html.ag-rm`) removes animations and transitions outright.

---

## 6. Tests

New, each watched failing before it was trusted — 17 mutations, 17 failures, all restored and green
(`ShellLayoutTest`, `DevUiTest`):

| Mutation | Caught by |
|---|---|
| `html,body{overflow:hidden}` in shell.css · shell.twig stops writing `ag-shelled` | `test_the_overflow_lock_belongs_to_the_shell_layout_alone` |
| `.ag-main` loses `position:relative` | `test_only_main_scrolls_and_it_is_the_positioning_context` |
| focus ring 2px | `test_focus_is_a_three_pixel_green_ring_two_pixels_out` |
| `!important` outside reduced motion | `test_important_lives_only_in_the_reduced_motion_blocks_and_the_hidden_rule` |
| a second scroll listener · the boolean compare removed | `test_one_passive_scroll_listener_and_a_boolean_on_a_threshold_of_eight` |
| bottom bar measured with `offsetParent` | `test_the_bottom_bar_measurement_sees_fixed_bars` |
| `openSheet` dropped from `AGShell` | `test_every_agshell_name_a_reader_uses_is_still_exported` |
| Esc stops returning focus · the row hidden with `display:none` | `test_the_collapsing_search_keeps_the_accessibility_tree_honest` |
| chrome and library swapped in shell.twig | `test_the_shell_loads_the_base_in_the_bundles_order` |
| a `.ag-head__bar` rule appended to the base | `test_components_css_holds_the_base_components_and_nothing_else` |
| a ladder swatch on the style page | `test_the_page_declares_data_driven_properties_and_reads_no_token_inline` |
| `--ag-stock-low` read only by a class the page does not render | `test_the_once_unread_tokens_are_read_by_components_the_page_renders` |
| the primary button's hover specimen removed | `test_every_component_is_shown_in_every_state` (per element, per state) |
| the route answers in production | `test_the_route_is_not_there_in_production` |

Run (narrow filters only, as asked): `DeadToken|Colour|Accent|TypeScale|AssetBundle|Shell|DevUi|Csp|Template|Twig|
SiteHeader|Chrome|Nominat|Shorthand|OneMain|DisplayReading|FormErrorState|SrOnly|AccessibilityFloor|Gutter|Favicon|
Challenge|Translator|NestedForm|CookieRegistry|Language|PublicSurface|RouteTable|Landmark|Focus|Touch|Target|Motion|
Celebration|Award|Account|Tile|Lip|SlotFloor` — **956 tests, 2 failures, both `PasskeyTest`** (the container runs PHP
8.3; environmental, failing before this pass too). DeadTokenTest, which failed with four unread tokens before, passes.

---

## 7. Deviations

| # | What | Why | Approved by |
|---|---|---|---|
| 1 | The overflow lock is `html.ag-shelled`, written by `layout/shell.twig`, not the snippet's global `html,body{overflow:hidden}` | `shell.css` is loaded by both layouts; the ~85 templates on `layout/gates.twig` scroll the document and have no `.ag-main`, so a global lock makes them unscrollable below the fold. Each page enters the lock when its phase moves it onto the shell; when the last has, the class becomes unconditional | **Flagged by Phase 1 — needs the owner's approval** |
| 2 | No colour in `tokens.css`; every colour and shadow is emitted by `Support\Accent` | Q1 | Owner, GAPS §8 Q1 |
| 3 | `line-2` 1.48:1 borders, the chevron, `mute` as disabled text | Q2: ship the handoff exactly | Owner, GAPS §8 Q2 |
| 4 | Shadows adopted, emitted by Accent | Q3 | Owner, GAPS §8 Q3 |
| 5 | Classic `defer` script, not ES modules; bottom bars detected with `getClientRects()`, not `offsetParent`; `partials/nav.twig` / `partials/gee.twig` not included (site header via the page's `header` block, Gee block empty) | Snippet faults, C15 — "the DC wins over the snippet"; Gee on the shell is Q19, unanswered | Owner, GAPS §8 Q12 (C15) |
| 6 | Skeleton sheen `color-mix(tint 40%, surface)` = #f6f5f1, not the snippet's #f6f4ef | #f6f4ef is not a §6.1 token and Q1 puts every colour in Accent; no mix of tokens gives it exactly | Owner, GAPS §8 Q1 |
| 7 | `[hidden]{display:none !important}` — the one `!important` outside a reduced-motion block (§4.6) | `hidden` is a user-agent rule that any component's `display` beats, so scripts' `el.hidden = true` silently failed (measured on Display & reading) | **Flagged by Phase 1 — needs the owner's approval** |
| 8 | Count badge text 11.5, not ShopPage's 11; round icon button 44 at every width, not the DC's 40 on desktop | REFERENCE outranks a DC (§0): §6.2's micro floor is 11.5; §6.7/§13 require 44 everywhere | REFERENCE §0 order of authority |
| 9 | §9.2 behaviour beyond the snippet: a settle window after the button opens the row; focus handed to whichever control appears when the other is hidden | Chromium's scroll anchoring moves `scrollTop` ~60px when the row grows, which closed the row the instant it opened (measured, 390); and the snippet left focus on an `aria-hidden` control when the row collapsed under it or the button vanished at the top | **Flagged by Phase 1 — needs the owner's approval** |
| 10 | `/_dev/ui`'s own words are not passed through `\|trans` | Dev-only route, 404 in production; nobody outside the team reads it | **Flagged by Phase 1** |

## 8. Blocked — not guessed

1. **AwardsPage desktop chip row.** The DC's sticky block pads by the gutter (32) and its chip row adds another 32
   (`bleed:'0'`, `padX:'32px'`), so on desktop the chips start 32px right of the search field. The base chip row bleeds
   and pads by the gutter at every width (aligned with the field). Intended in the DC, or an artefact? Phase 5 decides
   on the award page; the base is unchanged either way.
2. Deviations 1, 7, 9 and 10 need the owner's yes.
3. Not possible in this container: Lighthouse, a 2 GB Android, VoiceOver/TalkBack, real fonts (Google Fonts blocked by
   the proxy), and an overlay diff — `/_dev/ui` has no DC to overlay.

## Report back

- **Files:** destroyed and rebuilt `public/assets/css/{shell,components}.css`, `public/assets/js/shell.js`,
  `templates/layout/shell.twig`, `templates/pages/dev-ui.twig`; created `public/assets/css/components/{chrome,library}.css`
  (moved), `public/assets/css/dev-ui.css`, `tests/Unit/{ShellLayoutTest,DevUiTest}.php`; changed
  `src/Support/AssetBundle.php`, `templates/layout/gates.twig`, `public/assets/css/components/nominate.css`,
  `tests/Unit/SiteHeaderTest.php`, `docs/handoff/GAPS.md`, and eight comment pointers (§1).
- **DC prop combinations → screenshots:** §3.
- **Deviations:** §7 — ten; six carry an owner decision, four are flagged here for approval.
- **Blocked:** §8.
- **Found for Phase 2:** `partials/app-bar.twig` draws its back chevron without `.ag-ico-dir`, so it points the wrong
  way in Arabic (`devui-390-rtl-collapsed.png`). The Display & reading switches never drew "on": the state sat on the
  row and the base rule read only the switch — fixed in the rebuilt base switch, which now reads either.
