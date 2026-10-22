# Africa GATES: reference

Read this once in Phase 0 and keep it open. Phases point to its sections by number.

## 0. Order of authority (read first)

When two sources disagree, the higher one wins. Record every conflict you find in the PR.

1. **This README.** §18 lists the conflicts already resolved.
2. `skill/app-ux-standards/SKILL.md`. It governs phone UX and celebrations, and it wins over this README only on phone behaviour and §24 celebrations.
3. `design-notes/DESIGN-NOTES.md`: the standing design rules taken from the product owner's feedback.
4. The `.dc.html` design files in `design/`: exact visual values.
5. The repo's existing Twig templates. They keep every feature they already have, unless a rule above removes it.

Anything under the repo's `archive/`, and `HomePage.dc.html`, `HomePageV2.dc.html`, `StatusPage.dc.html` (v1) and `AdminAwards.dc.html`, is **not** a spec. Don't build from them.

---

## 1. Overview

Africa GATES is a continental recognition platform with these parts:

- **Awards.** Organisation → Award → Edition → Category → Nominee. Awards have versioned Terms.
- **Nominations.** 2–3 categories per nomination, a written reason for each, optional evidence.
- **Community voting.** One free vote plus optional paid contributions. Judging happens *after* voting.
- **Results.** The overall edition winner is shown first.
- **Events and ticketing.**
- **Giving, the Shop and Pulse** (a social feed).
- **Profiles.** Profiles are verifiable records: evidence is immutable once reviewed.
- **Gee.** The on-site assistant.

This redesign unifies the whole public site into one system. Each of phone, tablet and desktop is designed on purpose, not scaled.

**Stack (unchanged):** PHP 8.4 · Slim 4 · Twig 3 · vanilla JS (Alpine where already used) · nonce-based CSP · SQLite (dev) / MySQL (prod).

---

## 2. About the design files

- The `.dc.html` files in `design/` are **design references built in HTML**. Open any of them directly in a browser; `support.js` must sit next to them.
- They are **not production code.** Recreate them in the existing Twig + CSS + JS codebase using its patterns:
  - BEM classes in `public/assets/css/components/*.css`;
  - Twig partials in `templates/partials/`;
  - page templates in `templates/pages/`.
- **The one exception:** `design/assets/celebration/celebration.js` and `celebration.css` **are production code.**
  - Copy them byte-for-byte to `public/assets/js/celebration.js` and `public/assets/css/components/celebration.css`.
  - The designs execute these same files, so what you see is what ships.
  - Don't reformat them, "improve" them or port them to a framework.
- Inside a DC:
  - the markup between `<x-dc>` tags is the layout, and each element's inline `style` is the spec;
  - the `class Component` at the bottom holds demo data and the per-breakpoint values (`renderVals()` / `vals()`);
  - `{{ name }}` holes are filled from `renderVals()`.

  **All data is fake;** replace it with real queries.
- **The props matrix.** Each DC exposes props in the Tweaks panel: `layout` (phone/tablet/desktop), plus `view`, `state`, `signedIn`, `mode` and `phase` where present. **Open every combination before writing code.** Each combination is a required state. When `layout` is unset, the DC measures its own width: phone <600, tablet 600–1023, desktop ≥1024.
- **Canvas.** `design/Nominate.dc.html` is the review canvas holding every screen, grouped by turn, newest on top.
  - Turn 12 (build set) and Turn 13 (celebrations) are current.
  - Earlier turns are history; use them only to understand intent.

---

## 3. Fidelity

**High-fidelity.** Final colours, type, spacing, radii, shadows, copy, states and motion.

- **Tolerance:** ±1px on sizes and spacing, 0 on colour (exact hex), 0 on copy (exact strings, including curly apostrophes ’ and the middle dot ·), ±10ms on durations.
- **Verification viewports:** 390×844, 834×1194 and 1440×900, plus 1024×768 (the narrowest desktop) and 360×740 (a small Android).

---

## 4. Precision contract (non-negotiable)

1. **Match, don't interpret.** Keep the element order, hierarchy, copy, sizes, colours and states of the DC. Don't add, drop, rename, merge or reorder anything.
2. **If something can't be matched,** STOP: don't guess. Write the question in the PR under "Blocked", continue with other work, and never ship a placeholder.
3. **Build what's missing, end to end.** If a DC shows a feature the repo lacks, build migration (both schema files) → service → route → Twig → CSS → JS → test. No stubs and no "coming soon". §15 lists the known gaps.
4. **Reuse first.** Search the repo for an existing service, partial, route or class. Extend it; never fork it.
5. **Design each breakpoint.**
   - Phone has its **own markup branch** where the DC does, e.g. `idxPhone`/`itemPhone` in ShopPage, `phoneHero` in VotePage.
   - Never reach a layout with `zoom`, `transform: scale`, `vw`-based type or by hiding desktop blocks.
   - Switch layouts on the **measured container width** (ResizeObserver or container queries), not only on media queries, whenever a component can be embedded.
6. **Styling.**
   - Only the tokens in §6. No new colours.
   - No inline styles in Twig, **except** data-driven CSS custom properties (event tier colours, progress widths).
   - No `!important` except inside the reduced-motion blocks.
7. **Motion.** Every animation has a `prefers-reduced-motion: reduce` path that shows the final state instantly.
8. **Words.**
   - No AI wording anywhere public. "Gee" is just the name.
   - The nomination category-fit assistant is admin/judge only.
   - Never use shopping language for voting ("buy", "cart", "pay for votes").
9. **Evidence is immutable once reviewed.** There is no edit or delete route, button or API.
10. **One PR per screen.** Include every item in §17.

---

## 5. How to extract a value from a DC (method)

1. Find the element in the template. If its style reads a hole (`{{ padX }}`), look up `padX` in `renderVals()` / `vals()`. The ternary shows the phone, tablet and desktop values, e.g. `isPhone ? '16px' : '32px'`.
2. **Tablet overrides:** some DCs apply `Object.assign(o, {...})` when `L === 'tablet'`. Those values win for 600–1023.
3. `sc-if value="{{ x }}"` blocks are conditional; `x` tells you when they render. `sc-for` repeats over demo data, and the item fields show which fields the real data needs.
4. Pseudo-states are the `style-hover` / `style-active` / `style-focus` attributes on the same element.
5. **Never measure from a screenshot when the DC has the number.**

---

## 6. Design tokens

### 6.1 Colour (use these names as CSS custom properties on `:root`)

| Token | Hex | Use |
|---|---|---|
| `--ag-ink` | `#10292c` | primary text, dark UI, selected-chip border |
| `--ag-ink-2` | `#3a4a4c` | secondary text |
| `--ag-soft` | `#626a6e` | meta text, captions (4.5:1 on ground, verified) |
| `--ag-mute` | `#8b9295` | disabled text, off-switch track |
| `--ag-ground` | `#f1efe9` | page background |
| `--ag-surface` | `#ffffff` | cards, inputs |
| `--ag-bar` | `#fbfbfa` | app bars and tab bars when filled |
| `--ag-line` | `#e8e5dd` | hairlines, card borders |
| `--ag-line-2` | `#d6d4cc` | input and outlined-chip borders |
| `--ag-tint` | `#e8e5dd` | neutral fills (icon tiles, segmented track) |
| `--ag-green` | `#237b22` | **the** primary action, success |
| `--ag-green-deep` | `#1a6118` | success text |
| `--ag-green-light` | `#7fc87c` | accents on dark, sparks |
| `--ag-green-wash` | `#effaf0` | success wash |
| `--ag-green-edge` | `#cfe6ce` | success borders |
| `--ag-gold` | `#f3b416` | honour, winners |
| `--ag-gold-ink` | `#7a5600` | text on gold wash |
| `--ag-gold-wash` | `#fcf4de` / `#fff8df` | winner cards, early-bird strip |
| `--ag-live` | `#e0245e` | live dot, hearts |
| `--ag-live-ink` | `#b0224f` | live text, dates on events |
| `--ag-live-wash` | `#fdecef` | giving wash |
| `--ag-info` | `#1f6fa3` | tickets, info |
| `--ag-error` | `#b42318` | errors, destructive text ("Sign out") |
| `--ag-stock-low` | `#8a2020` | "Only N left" |
| `--ag-stock-gone` | `#8a5a00` | "Sold out" note |

Rules:
- **One accent per block.**
- **Green is only for the primary action inside the page.** There's never a green button in the header.
- Chips and filter pills are **outlined** (`1px --ag-line-2`, white). Selected = `2px --ag-ink` border, same white fill, weight 700. **Never a dark fill.**
- No dark backgrounds, except the few dark bands a DC shows explicitly (e.g. the "Achieng won" card and the vendor call). Don't add more.

### 6.2 Typography

- **Families:**
  - `Playfair Display` 700 for headlines only.
  - `DM Sans` 400/500/600/700 for everything else, with `font-variant-numeric: tabular-nums` on changing numbers.
  - `JetBrains Mono` 500/700 **only** for references and codes: order refs, ticket codes, receipt codes, times inside ticket stubs.
  - **Never** use mono for labels, stats or kickers.
- **No uppercase labels,** except the ticket-stub "CONFIRMED" stamp (a graphic). Headings are sentence case.
- **Scale** (px; line-height in parentheses):

| Role | Phone | Desktop |
|---|---|---|
| Display / page H1 (Playfair) | 32 (1.1) | 44 (1.06) |
| Celebration title (Playfair) | 34 (1.08) | 48 (1.08) |
| AppBar large title (Playfair) | 26 (1.0) at rest; collapses to 17 DM Sans 700 centred | n/a |
| Section H2 (DM Sans 700) | 19 (1.3) | 19–22 (1.3) |
| Card title | 16–17 / 700 | 17 / 700 |
| Body | 16 (1.6–1.65) | 16–17 (1.65) |
| Secondary | 14.5 (1.5) | 14.5 (1.5) |
| Meta / caption | 13–13.5 | 13–13.5 |
| Micro (badges) | 11.5–12.5 | 11.5–12.5 |
| Buttons | 15–16 / 700 primary, 600 secondary | 14.5–15.5 |
| Inputs | **≥16** always (prevents iOS zoom) | 15–16 |

- **Measure:** body ≤ 62ch; celebration sub ≤ 44ch.
- `text-wrap: balance` on headlines; `pretty` on paragraphs.
- Load fonts with `display=swap` and preconnect.

### 6.3 Spacing

4 · 6 · 8 · 10 · 12 · 14 · 16 · 18 · 20 · 22 · 24 · 28 · 32 · 36 · 40 · 48 · 56 · 64.

- **Phone page gutter 16.** Tablet 28. Desktop 32 (40 inside hero bands).
- Section gap: phone 22–28, desktop 28–40.

### 6.4 Radii

999 (pills, chips, round buttons) · 24 (major cards, sheets, celebration) · 22 (content sheet over a gallery, top corners only) · 20 (cards, drawers, popovers) · 16 (phone CTAs, rows, inline stat tiles) · 14 (inputs, list cards, small buttons) · 12 · 10 (icon tiles) · 7 (mini badges).

### 6.5 Shadows (exact)

| Use | Value |
|---|---|
| Popover / drawer | `0 24px 48px -20px rgba(16,41,44,.30)` |
| Mega panel | `0 24px 48px -28px rgba(16,41,44,.25)` |
| Bottom sheet | `0 -12px 40px rgba(16,41,44,.18)` |
| Floating round button on a photo | `0 2px 10px rgba(16,41,44,.15)` |
| Quick-add dot on a card | `0 3px 10px rgba(16,41,44,.18)` |
| Celebration badge | `0 0 0 2px {ring}, 0 16px 34px -12px {ring}99` |
| Ticket card (celebration) | `0 18px 36px -14px rgba(31,111,163,.55), 0 0 0 1px #d8e6f0` |

### 6.6 Motion

| Token | Value | Use |
|---|---|---|
| `--ag-ease` | `cubic-bezier(.2,0,0,1)` | all UI transitions |
| `--ag-ease-pop` | `cubic-bezier(.2,.9,.3,1.2)` | badges, stars |
| `--ag-dur-1` | 200ms | opacity, colour |
| `--ag-dur-2` | 260ms | collapse and expand, sheets |
| `--ag-dur-3` | 500–550ms | celebration text rise |

Scroll-linked chrome swaps on a threshold (`scrollTop > 8`); it is **never** scrubbed per pixel.

### 6.7 Layout constants

| Constant | Value |
|---|---|
| Breakpoints | phone <600 · tablet 600–1023 · desktop ≥1024. The 3-column desktop layouts start at ≥1200 |
| Touch targets | ≥44×44 everywhere, 48–54 for primary phone actions. Never hover-only |
| Z-index | sticky toolbars 4–6 · app bar 20 · buy/vote bars 30 · header 40 · Gee 60 · scrim 79 · sheets 80 |
| Page shell | root `height:100dvh; max-height:100%`; **only `<main>` scrolls**, so bars and floating buttons stay pinned |

---

## 9. Interactions & behaviour


### 9.4 Loading / empty / error (required on every data view)

- **Loading:** skeletons that match the final layout (same heights), with no spinners for content. Buttons that submit show a pressed + disabled state within 50ms and keep their label ("Sending…").
- **Empty:** one sentence built from the active filters + one action (e.g. "Nothing matched 'kente' in Size L. Clear filters"). Never an empty page.
- **Error:** inline, next to the field, in `--ag-error` with an icon, announced with `aria-live="polite"`. Form values are kept. Network failures show "We couldn’t reach Africa GATES. Your details are saved. Try again."
- **Payments:** a failed contribution or gift never removes the free vote and never takes money. Say so in the copy.

### 9.5 Forms

- Labels always visible (no placeholder-only labels). Inputs ≥16px font, 48–54px tall, radius 14.
- Use the correct `inputmode` / `autocomplete` / `enterkeyhint`. Native `<select>` on phone.
- Validate on blur and on submit, never on every keystroke. The exception is the reason counter (§8.16).

---

## 10. State management

| State | Where | Notes |
|---|---|---|
| Filters, sort, query, page | URL query | every listing survives Back |
| Region, currency | cookies `ag_region`, `ag_currency` | never query params |
| Language | `?lang=` + cookie `ag_lang` | sets `lang` and `dir` on `<html>` |
| Display & reading | `localStorage["ag-a11y"]` + profile | applied before first paint |
| Celebration seen | `localStorage["ag-cel-{seen_key}"]` | written by the engine |
| Cookie consent | cookie `ag_consent` (JSON, versioned) | GPC respected |
| Gee privacy note dismissed | `localStorage["ag-gee-privacy"]` | |
| Vote progress | server (session + OTP) | the phone bottom bar reads the server count |
| Cart | server session + badge count in the header/app bar | |
| Bottom UI height | `--ag-bottom-ui` on `<body>` | set and cleared by each fixed bar |

---

## 11. Data model changes (migrations + BOTH schema files)

- `gates_award_cycles.edition_number` (INT). Categories belong to a cycle.
- `gates_award_terms` (id, award_id, version, effective_at, body, changelog), plus `gates_award_terms_acceptance` (terms_id, profile_id or email, accepted_at, context nominate|vote).
- `gates_recognitions`:
  - columns: id, issuer_id, recipient_profile_id, kind (`award|honour|commendation|staff|certificate`), title, citation, issued_at, reference, visibility, evidence_ids (JSON), withdrawn_at, withdrawn_reason;
  - immutable apart from the withdrawal fields;
  - every withdrawal is logged publicly.
- Issuers: `issuer_type` (`community|organisation|business|government`) + `verified_at`. Government verification is manual.
- `gates_testimonials` (approved only) · blog `read_minutes`, `people` · event tier colour columns (`accent`, `accent_light`, `wash`, `deep`, `glow`) · event waitlist (email, tier_id, hold_expires_at) · `maintenance` windows · status probes by country · shop restock alerts (email, sku, created_at, stopped_at, token).
- Mind the repo `CLAUDE.md` SQLite/MySQL traps: ENUM truncation, TINYINT 255, `CREATE INDEX IF NOT EXISTS`, the datetime `T`.

---

## 12. Voting rules (from the code; don't change them)

- **One free vote** per verified person per category (email OTP).
- **Paid contributions:**
  - tiers from `vote_tiers`, shown as outlined chips with provider radios and a receipt email;
  - capped per person per category;
  - they add to the community **tally** (the 30% term) at full weight. The other 70% of the community half counts verified **people** (`VoterReach`), which no purchase moves: money buys the tally, never the reach. Points can be redeemed for a vote.
- **Required fields:** name, phone, email, message. The public display name is optional.
- **Edition order:** nominations → community voting → **judging after voting** → results and ceremony.
- **Weights:** community 45 / jury 55, locked when voting opens.
- Live totals and the race are public.

---

## 13. Accessibility (ships with each screen, not later)

- WCAG 2.2 AA. Targets ≥44px (WCAG 2.5.8 asks for 24; we require 44).
- Visible focus: a 3px `--ag-green` outline, 2px offset.
- Landmarks. Tab/radio/switch/dialog roles with correct `aria-*`. Dialogs and sheets trap focus and restore it.
- Colour is never the only signal (winners also say "Winner", stock notes carry words).
- Text contrast 4.5:1 (3:1 only for headlines ≥24px).
- Alt text on every photo; decorative particles are `aria-hidden`.
- Events carry caption, sign-language and audio-description flags. Articles and profiles have Listen.
- **Test:** 200% zoom, RTL (Arabic) at 390, VoiceOver iOS, TalkBack, keyboard-only.

---

## 14. Assets

| Asset | Source | Notes |
|---|---|---|
| `design/assets/logo-mark.png` | the Africa GATES codebase | use the repo's own logo file |
| `design/assets/countries-110m.json` | the codebase's `public/assets/geo` | Natural Earth 110m TopoJSON |
| `design/assets/africa-silhouette.svg` | derived from the TopoJSON | We Are Africa fallback |
| `design/assets/celebration/celebration.js` / `.css` | written for this project | **production files; ship verbatim** |
| Photography | Unsplash URLs in the DCs | **placeholders only.** Replace with real licensed images of real people and places. Keep the aspect ratios (4:5 products, 4:3 event detail, 16:9 phone hero, 380px home hero) |
| Icons | inline SVG paths in the DCs (24×24 grid, stroke 1.9–2.2, round caps and joins) | copy the paths into the repo's icon partial; don't substitute another icon set |
| Fonts | Google Fonts: Playfair Display 700, DM Sans 400–700, JetBrains Mono 500/700 | self-host if the CSP requires |

---

## 17. Acceptance protocol (every PR must include all of these)

- [ ] **Screenshots** at 390, 834, 1024 and 1440 next to the DC at the same size, for **every** prop combination the DC exposes.
- [ ] **Overlay diff:** the DC screenshot at 50% opacity over the build at 390 and 1440. Any visible offset over 1px is a defect.
- [ ] RTL (Arabic) screenshot at 390.
- [ ] Keyboard-only walkthrough (the tab order is written in the PR) + VoiceOver and TalkBack notes.
- [ ] Reduced-motion recording, and a normal-motion recording of every animation.
- [ ] Lighthouse mobile: Accessibility 100, CLS < 0.05; no layout shift from fonts or sticky chrome.
- [ ] Low-end check: 60fps scroll and celebration on a 2GB Android (or DevTools 4× CPU throttle).
- [ ] Gee clearance measured on every page with a fixed bar (≥16px).
- [ ] **Deviations list** (target: zero). Every entry must include what, why, and approval.
- [ ] Skill checklists passed: §14 (phone) and §24.8 (celebrations).

**PR prompt to use:**

> Implement `<DC file>` for all `layout`/`view`/`state`/`signedIn` props in `<template path>`. Read README §0–§7, the skill, and `design-notes/DESIGN-NOTES.md` first. Reuse existing services; build anything missing per §4.3. Extract every value from the DC (§5); don't estimate. Attach everything in §17.

---

## 18. Conflicts already resolved (these override older notes)

1. **Desktop cart:** an icon button with a count badge, **no subtotal text**. The drawer floats 12px from the edges, radius 20, light scrim. (Overrides "pill with subtotal".)
2. **Applied-filter chips:** **outlined**, not dark. (Overrides "removable dark chips".)
3. **Monospace:** only for references and codes, never for stats or labels. Celebrations use no mono.
4. **Uppercase:** none in labels or kickers; sentence case everywhere.
5. **Phone app bar:** Aa and language live in the avatar Quick settings sheet, not as bar buttons.
6. **Judging always comes after voting,** in copy, timelines and data.
7. **Admin screens are not designed.** Build them inside the existing admin UI from the repo notes. The nomination category-fit assistant is admin/judge only.

---
