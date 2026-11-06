# Africa GATES: build handoff for Claude Code (v3, consolidated)

This is the only handoff. `archive/HANDOFF-previous.md` is history; where it disagrees with this file, this file wins.
Stack: PHP 8.4 · Slim 4 · Twig 3 · vanilla JS/Alpine · nonce-based CSP · SQLite (dev) and MySQL (prod).

---

## 0a. UX skill
Install `skills/app-ux-standards/SKILL.md` as a Claude Code skill. Every phone view must pass its §14 checklist. Where this handoff and the skill disagree on phone behaviour, the skill wins.

## 0b. Celebrations
Ship `assets/celebration/celebration.js` + `celebration.css` **verbatim** (the design runs the same files) + `partials/celebration.twig` matching `Celebration.dc.html`. Everything else follows skill §24: 5 signatures, the ambient loop after the peak, the desktop split, the inline card, once per achievement via seenKey, reduced-motion calm state, and boot code in §24.7. Canvas Turn 13 shows every variant.

## 0. Contract (non-negotiable)

1. **The `.dc.html` file is the spec.** Match order, copy, sizes (±1px), hex colours, radii and states at **390, 834 and 1440**. Don't add, drop, rename or reorder anything. If something can't be matched, STOP, ask, and record it in the PR.
2. **Build what doesn't exist, completely.** If a DC shows a feature the repo lacks, build it end to end: migration (both schema files) → service → route → Twig → CSS → test. No stubs and no "coming soon" in its place. Check §9 first.
3. **Reuse before writing.** Search the repo for the service, partial or route first. Extend it; never fork it.
4. **One PR per screen.** Screenshots at 390/834/1440 beside the DC, keyboard-only and VoiceOver passes, an RTL (Arabic) screenshot at 390, and a deviations list (target: zero).
5. **No inline styles and no new colours.** BEM classes in `public/assets/css/components/*.css`. Use only the hexes listed in §2. Data-driven colours (event tiers) come from the DB as CSS custom properties on the element.
6. **Every animation has a `prefers-reduced-motion: reduce` fallback** that shows the final state.
7. **No AI wording on public or nominator screens.** The nomination category-fit assistant is admin/judge only. "Gee" is the assistant's name; don't call it AI anywhere in the UI.
8. **Evidence is immutable once reviewed.** No edit or delete route, button or API for it.

## 1. How to read a DC

The markup between `<x-dc>` tags is the layout. The class at the bottom holds demo data and behaviour. **All data is fake.** Props (the Tweaks panel) switch `layout` (phone/tablet/desktop), `view` and `state`. Open every prop combination before you write code.
Tablet values are the `Object.assign({...})` overrides in each DC's `renderVals`. Copy those values into the tablet media query.

## 2. Tokens

- Ink `#10292c`, ink-2 `#3a4a4c`, soft `#626a6e`, ground `#f1efe9`, surface `#fff`, bar `#fbfbfa`, line `#e8e5dd`, line-strong `#d6d4cc`.
- Green `#237b22` / deep `#1a6118` / light `#7fc87c` / wash `#effaf0` / edge `#cfe6ce`. Gold `#f3b416` / honour ink `#7a5600` / wash `#fcf4de`. Live `#e0245e` / ink `#b0224f` / wash `#fdecef`. Info `#1f6fa3`. Error `#b42318`.
- Type: Playfair Display 700 (display), DM Sans 400–700 (UI), JetBrains Mono 500/700 (numbers, codes, times).
- Radii: 999 (pills), 20–24 (cards), 14–16 (rows, inputs), 10–12 (tiles).
- **Breakpoints:** phone <600, tablet 600–1023, desktop ≥1024. Phone is the base. Measure the container, not `vw`. Never use `zoom`, `transform:scale` or `vw` type to fit.

## 3. Shared chrome

- **SiteHeader** (desktop and tablet; replaces `partials/nav.twig`): logo · **Participate ▾** · **Explore ▾** · [toolbar: Search | Aa | 🌐 language] · avatar, or an outlined "Sign in" when signed out. No green button in the header.
  - Mega panels hang from the bar (no border between them), have a frosted background, 24px bottom radii, and a 3-column grid of icon tile + title + one line. Keep `data-ag-mega*`.
  - Participate = Nominate, Vote, Awards, Giving, Register, Integrity Center. Explore = Discover, Pulse, Events, Legacy Vault, Blog, Status.
  - **Search palette:** opens from the icon or "/". 680px wide, 84px from the top, scrim behind. Input → scopes (All, People, Awards, Events, Pages) → grouped results (≤6 per group; empty state shows trending/open/coming) → key hints. `/search?q=&scope=` returns JSON. ↑↓, Enter, Esc; focus is trapped and returns to the trigger on close.
- **Phone Menu** (5th tab → `MobileMenu.dc.html`):
  - A bottom sheet (top 52px, radius 24, grabber, scrim) on the ground colour, with a centred title and a 44px close button.
  - Main view: profile card (avatar, name, points), or a join card when signed out → 4 quick tiles (Nominate, Vote, Awards, Events) → grouped white lists with 56px rows (34px tinted icon tile, label, chevron): Explore (Discover, Pulse, Giving, Shop, Legacy Vault, Blog, Register) · Settings (Display & reading → pushed sub-view; Language → pushed radio list; Notifications) · Help (Help Centre, Integrity Center, Status with a live dot) → legal links, Sign out (red text), wordmark.
  - Sub-views push inside the sheet with a back button; there is no expanding accordion.
- **DisplayReading:** text size (100/125/150% root), high contrast, easy-read font (Atkinson Hyperlegible), line spacing 1.8, underline links, reduce motion, data saver, read aloud, language. Stored in `localStorage.ag-a11y` + the member profile. Applied as `<html>` classes by an inline nonce'd script in `<head>` before first paint.
- **Language:** EN, FR, AR (`dir="rtl"`), SW, PT, HA, YO, IG. Set via `?lang=` + a cookie. Every string goes through the translation layer.
- **Gee** (existing `gee.css`/`gee.js`, restyled): every page except Pulse.
  - FAB: a dark pill "G · Ask Gee" (icon-only on phone).
  - Position: 24px from the bottom, or 96px above the phone tab bar.
  - Panel: 400×580 on desktop; on phone it fills the page inset by 12px. Its height is capped to the page, never `vh`.
  - Contents: header (Online) → dismissible **privacy note** (retention period + "don't share card numbers or passwords" + policy link) → greeting and 4 starters → bubbles → help-link chips → pill composer → "Gee can make mistakes · Privacy · Talk to a person".
  - Support mode keeps the amber dot.
- **Gee clearance (strict):** the FAB always sits ≥16px above any bottom-fixed element. Tab bar: 96px. Sticky buy/give/vote/ticket bars: 108px. No bar: 40px (covers the home-indicator safe area). Implement as a CSS variable `--ag-bottom-ui` that each fixed bar sets on `<body>`; Gee uses `bottom: calc(var(--ag-bottom-ui, 0px) + 16px + env(safe-area-inset-bottom))`.
- **Page shell:** root `height:100dvh; max-height:100%`; only `<main>` scrolls, so bars and FABs stay pinned.

## 4. Screens → templates

| DC | Template(s) | Notes |
|---|---|---|
| HomePageV3 | `pages/home.twig` | Hero (unchanged) → stats → "Who recognises" (tabs + photo stage + record card) → Wall of recognition (masonry, fade + Show more) → We Are Africa → Happening now → Giving (1 featured) → Recognise band → footer |
| DiscoverPage | `pages/discover.twig` | search + facets sheet |
| AwardsPage | `awards/index.twig`, `awards/programme.twig` | index: search, phase chips with counts (incl. Coming soon), card grid. Detail tabs: **Overview \| Award details \| Terms**. `view=soon` for awards with no open phase |
| VoteHub | `pages/vote.twig` | light list: live total (pulsing), progress strip (phone) / rail (desktop), filters, rows with live counts, Vote/View |
| VotePage | `pages/vote-program.twig` | hero + countdown; tabs Vote \| About the award; race cards per category (headline, rank, live count, gap, bar vs leader) |
| NomineePage + VoteBallot | `pages/vote-nominee.twig` | race block, Support for {first}, reviewed evidence, others. Ballot = the existing flow (points → paid tiers → "or vote free" → name, phone, email, message, rules → email OTP → done). `phase=won`: celebration + roll of honour replace the ballot |
| ResultsPage | `pages/results.twig` | overall edition winner + top 3 FIRST, then summary, then category winners |
| ProfilePage | `pages/profile.twig` | owner: Share + Edit profile in the desktop header; evidence locked |
| PulsePage | `pages/pulse.twig` | desktop: single left rail (no top bar); post kinds post/photo/recognition/vote/give; 4 reactions; guest banner when signed out |
| EventsPage | `pages/events.twig`, `events/detail.twig` | see §5 (the ticket stays as is) |
| ShopPage | `shop/index.twig`, `shop/item.twig`, `shop/order.twig`, `shop/success.twig`, `shop/alert-stopped.twig` | see §5b |
| TicketPage | `events/ticket.twig` | keep the existing structure (art + tear + stub + facts + url + print/PDF). Improvements: status chip (Valid · admits N / Checked in), countdown in the kicker, tear notches, tier dot ring, 2×2 actions (Wallet, Calendar, Save/print, Directions), "Manage this ticket" collapsed (transfer, name change, refund), offline note |
| GivingPage | giving templates | campaign → checkout (mobile money/card/bank/USSD) → done |
| BlogPage | `pages/blog/*` | editorial index + article with Listen and reading progress |
| StatusPageV2 | `pages/status.twig` | keep every rule in the twig header comment; adds live incident timeline, maintenance, by-country, subscribe (only with a working sender) |
| NominateHub / NominationFlow | `pages/nominate*.twig` | 2–3 categories, reason ≥40 chars each, optional evidence |
| SignIn | auth templates | passwordless code by phone or email; WhatsApp and voice fallbacks; profile basics; interests |
| CookieConsent | cookie partial | Essential always on + 3 optional categories; GPC respected |

## 5. Events (strict)

- **Detail order on phone:** early-bird strip → hero → **tickets card** → About → Agenda (track filter) → Venue → Access → Good to know → Vendor call → Share/save → Earn → Livestream. There's also a fixed bottom bar: "From {lowest price} · Get tickets". Use CSS `order` with the rail wrapper set to `display:contents` on phone. Desktop: main column + a sticky 380px rail.
- **Tiers** carry `accent`, `accent_light`, `wash`, `deep` and `glow`. Choosing a tier re-tints only the accents inside the ticket card (eyebrow, fundraising figure, selected tier border/wash, "Only N left", Apply, total, CTA). Radios stay neutral.
- **Glow:** `@property --ev-a` animated 0→360° over 3.2s, linear. A blurred halo (inset −10px, blur 22px, 0.55, breathing) plus a 2px ring (`padding-box` white + `border-box` conic). It replays on every pick and settles to 0.35. Fallback without `@property`: a static gradient border.
- **States (`state`):** open · waitlist (join, "N waiting", 24h hold) · soldout · closed · ended (results + recording).
- **Ticket:** `TicketPage.dc.html` is a light polish of the EXISTING `events/ticket.twig`. Keep all of its rules (QR + printed code always, 190×86mm print, PDF, the D.rows switches, no scannable ticket unless confirmed) and add only the §4 improvements.
- Keep everything the twig already does: discount check, seats, flier, .ics + Google, earn link, appeals, stands.

## 5b. Shop (strict; keeps every feature of the current shop templates)

- **Index order:** title + search (desktop: side by side) → "Ask Gee" starter chips (→ `/support/assistant?q=`) → Frame-your-recognition feature + 3 promises (gives back, free delivery over ₦50,000, named makers) → [desktop: 264px sticky filter rail | grid] → "Load more".
- **Filters** (Baymard: multi-select within a type, the 5 essential types, applied-filter overview):
  - Category checkboxes with live counts. Colour swatches with names. Size chips. Price min/max (₦) with "Apply price" on desktop. In stock only. Featured. Deliver-to region and display currency (cookies `ag_region`/`ag_currency`, never query params).
  - Every filter lives in the URL (`?c[]=&col[]=&sz[]=&min=&max=&stock=1&feat=1&sort=&q=`) and survives Back.
  - Changes the current twig's single-category radio to checkboxes; the server accepts arrays.
- **Phone:** a sticky toolbar with two equal buttons (Filters + count | Sort select). Filters open a bottom sheet with a sticky footer: "Clear all" + "Show N items" (live count). Applied filters show as a horizontally scrolling row of removable dark chips above the count.
- **Count line:** "N items for 'q'". Replace pagination with **Load more** (+4 per step, "Showing X of N" + progress). Keep `?page=` as the no-JS fallback.
- **Card:** image (sold out fades to .55) + tag → tappable colour swatches (≤4, then +N; tapping swaps the card image, 28px hit area) → name → maker line → stock note (low #8a2020 / gone #8a5a00 / choose #5a6d6f) → price ("from" when options are priced differently) + action.
  - The action is "Add" when there are no options, "Choose size" when there are, and a disabled "Sold out" when nothing is left. Never offer Add for something that needs a choice.
- **Empty:** "Nothing matched" + a sentence built from the filters that are on + "Clear filters". `shopState=soon`: "The shop is opening soon" + an email alert.
- **Item:**
  - Gallery + clickable thumbnails; the colour choice swaps shot 1.
  - Name, maker, price in the display currency, plus "Charged in Naira · ₦X" when the display currency isn't NGN.
  - Colour swatches (named), then sizes. A sold-out size stays selectable, struck through, and switches the buttons to **"Tell me when {size} is back"** (email once; "Stop this alert" → `alert-stopped`).
  - Live stock line. Qty + **Buy now · total** (green) + Add to cart (outlined).
  - A delivery-to-region card showing ETA and fee, or "Free delivery on this order".
  - Accordion: Materials, Sizing & fit, Washing & care. Assurance rows: returns, gives back, secure checkout.
  - "You may also like" (alternatives only).
  - Phone gets a sticky buy bar (total, colour · size, Add to cart).
- **Cart/checkout:** unchanged structure, all amounts in the display currency, charged in NGN. Delivery fee comes from the region and is free over ₦50,000 or at a pickup point.
- **Success (`doneState`):** confirmed (order ref + amount + "receipt emailed") or pending (the current twig's reconcile copy). Both have Track your order + Continue shopping.
- **Order page (`orderState`):** reachable by reference alone and noindex.
  - Title per state (Order received / On its way / Delivered / Payment didn’t complete / Confirming your payment). Mono reference.
  - Payment and Delivery status cards, each with a coloured top bar.
  - A 4-step progress bar (Paid, Packed, Shipped, Delivered) with carrier and tracking.
  - Receipt showing prices as paid, delivery address, and support email + reference.
  - `notfound` gives no hint whether the reference exists.

### 5c. Shop on phone (separate markup, not a reflow of desktop)
- **Index:**
  - Sticky 56px app bar ("Shop" + cart icon with a badge).
  - A one-line intro, then a 52px search.
  - A sticky chip toolbar at 56px: Filters (non-category count) · All · categories (single-select chips) · native Sort select. All chips are 44px, outlined; the selected one gets a 2px ink border.
  - Removable filter chips (not categories). A compact gold "Frame your recognition" row. The count.
  - A 2-column grid: 4:5 image, a 48px quick-add hit area (34px visual), 2-line name, "N colours · maker", price + low stock.
  - Full-width 52px "Load more".
- **Item:**
  - Full-bleed 4:5 scroll-snap gallery (keyboard-focusable), with decorative dots and floating 44px back, share and cart buttons.
  - A content sheet (radius 22) overlaps the gallery by 22px.
  - Contents: maker → name → price + "Gives back 40%" → colour (48px targets, 36px swatches) → size (52px chips, outlined selection) + 44px Size guide → quantity stepper → restock alert when sold out → delivery row → description → 56px accordions → perks → horizontal "You may also like".
  - Sticky bottom bar: total + options line + 54px "Add to cart". Hidden when the chosen size is sold out. Gee sits at 108px while the bar shows.
- Inputs are ≥16px font. `enterkeyhint="search"`. `autocomplete="email"` on the alert field.

### 5f. Discover: tabs dock beside Filters on scroll. See `skills/app-ux-standards/SKILL.md` §6b (strict).

### 5e. Collapsing sticky search + filters (Awards index; reuse for Discover, Shop, Events)
- **Structure:** one sticky block at `top:0` inside `<main>`, ground background, padding `10px` vertical at rest and `8px` once scrolled. A 1px hairline `#e8e5dd` appears only when `scrollTop > 8`.
- **Search row:** always in the DOM, wrapped in `display:grid; grid-template-rows: 1fr | 0fr` with an inner `min-height:0; overflow:hidden`.
  - Animate `grid-template-rows` and `margin-bottom` (10px ↔ 0) over 260ms `cubic-bezier(.2,0,0,1)`, and `opacity` over 200ms ease.
  - Never use `display:none` / `v-if` for this; it must animate both ways.
- **Search button:** at the start of the chip row, always in the DOM.
  - Animate `width` 0 ↔ 44px (40px desktop), `margin-inline-end` −8px ↔ 0 (cancels the row gap) and `opacity`, with the same easing.
- **States:**
  1. `scrollTop ≤ 8`: row open, button hidden.
  2. Scrolled, query empty: row collapsed, button shown.
  3. Button tapped: row opens and records the scroll position `y0`.
  4. While open with an empty query, any further scroll of more than 24px from `y0` collapses it again.
  5. A non-empty query keeps it open.
  6. Returning to the top resets to state 1.
- **Accessibility:**
  - The collapsed row gets `aria-hidden="true"` and its input `tabindex="-1"`. The hidden button mirrors this.
  - After tapping the button, focus moves to the input. Esc collapses the row and returns focus to the button.
  - `prefers-reduced-motion: reduce` → no transitions (an instant swap).
- **Chips:** 44px on phone, outlined, 2px ink border when selected, a coloured phase dot, and a mono count.

### 5d. Phone chrome everywhere
- **Research basis** (Material 3 app bars; Apple HIG navigation and tab bars):
  - The app bar starts the same colour as the page and fills (#fbfbfa + hairline) once content scrolls under it.
  - Large titles collapse into a small centred 17px title on scroll and stay small until scrolled back to the top.
  - A nav bar holds at most back + title + one action.
  - 3–5 persistent tabs.
  - Pushed screens for hierarchy; sheets for scoped tasks.
- **Avatar → Quick settings sheet** (phone, root AppBar): the avatar is a button (aria-label "Account, language and display") that opens a bottom sheet:
  - profile row + Profile link;
  - text size (3-step segmented, 48px);
  - High contrast and Reduce motion switches;
  - language chips (each labelled in its own language with `lang`, horizontal scroll);
  - "All display & reading settings" → the full DisplayReading screen;
  - a 52px Done button.
  These write the same `ag-a11y` store as DisplayReading. Language and Aa are NOT separate app-bar buttons on phone (HIG: back + title + one control).
- **First-visit language prompt:** when `navigator.languages[0]` matches a supported non-English language and no `ag_lang` cookie is set, show one dismissible row under the root AppBar, written in that language (e.g. "Voir Africa GATES en français ?" · Oui · Keep English). Either choice sets the cookie and it never reappears. It hides on scroll.
- **AppBar scroll behaviour:** the page's `<main>` reports `scrollTop > 8` → `scrolled`.
  - Root: a 52px row (search · centred small title fading in · avatar) plus a large 32px Playfair title row that hides once scrolled.
  - Child: back · title + sub · share; only the fill changes.
  - Respect reduced motion: no fades, instant swap.
 (`AppBar.dc.html` → `partials/app-bar.twig`)
- **Every phone page** starts with the status-bar-safe area and then a 56px **AppBar**:
  - `root` (Home, Discover, Nominate, Vote, Events, Awards index, Pulse): a 26px Playfair title, a 44px search icon and a 34px avatar in a 44px target.
  - `child` (VotePage, Nominee, Results, Award detail/soon): 44px back · 16px bold title + 12.5px sub (both truncate) · 44px share.
- Filter/category chips on phone sit in a **sticky** horizontal row directly under the AppBar (or under the page tabs), with a 10px vertical pad, 44px chips, outlined, and a 2px ink border when selected (never a dark fill).
- **Phone heroes are light:** a rounded 16:9 photo with a white status chip, then text on the ground colour. Detail pages (event, product) use a full-bleed 4:3 or 4:5 photo with floating 44px round buttons and a content sheet (radius 22) overlapping by 22px. No dark gradient overlays with text on phone.
- **Primary action lives in a bottom bar within thumb reach** (VotePage: "N of 6 categories voted" + progress + "Review votes"; Events: Get tickets; Shop item: Add to cart). Gee moves to 108px whenever a bar shows.
- Home phone: hero photo first (full-bleed, 380px) with the recognition card, then the title, lead and **stacked full-width 54px CTAs** (radius 16).
- Vote hub phone: 88px rows ending in a chevron. The whole row is the target; no inline Vote button.

## 6. Voting rules (from the code)

- One free vote per verified person per category (email OTP). Paid votes are "contributions" (tier chips from `vote_tiers`, outlined button, receipt email, provider radios), capped per person per category, and count the same as free ones. Points can be redeemed for a vote.
- **Order of an edition:** nominations → community voting → **judging after voting** → results and ceremony. Weights are community 45 / jury 55 (`split`), locked when voting opens.
- Live totals and the race are public (as in `vote-program.twig`). Never show a "cart", "buy" or "pay" framing.

## 7. Data model changes

- `gates_award_cycles.edition_number`; categories belong to a cycle.
- `gates_award_terms` (award_id, version, effective_at, body, changelog). Nominators and voters accept the current version, and the acceptance is stored.
- `gates_recognitions` (issuer_id, recipient_profile_id, kind award|honour|commendation|staff|certificate, title, citation, issued_at, reference, visibility, evidence_ids JSON). Immutable; can be withdrawn with a public log.
- Issuers: `issuer_type` community|organisation|business|government plus `verified_at`. Government verification is manual.
- `gates_testimonials` (approved only), blog `read_minutes` and `people`, event tier colour columns, a waitlist, `maintenance`, status by-country probes.
- Mind the SQLite/MySQL traps in the repo's `CLAUDE.md`: ENUM truncation, TINYINT 255, `CREATE INDEX IF NOT EXISTS`, datetime `T`. Every column needs a migration and **both** schema files.

## 8. Accessibility (must ship)

Touch targets ≥44px, visible focus (3px green outline), landmarks, tab/radio/switch roles, alt text, colour never the only signal, 4.5:1 text contrast, captions/sign-language/audio-description flags on events, and a Listen option on articles and profiles. Test at 200% zoom and in RTL.

## 9. Gaps table (verify each row in the repo; if missing, build it)

| Feature | Status to verify |
|---|---|
| Recognitions + verified issuers | NEW |
| Award terms versioning + acceptance | NEW |
| Coming-soon awards + notify (double opt-in) | NEW |
| Overall edition winner + top 3 | NEW compute |
| Wall of recognition | NEW |
| Search API + palette | NEW |
| DisplayReading persistence | NEW |
| Gee restyle + privacy note | EXTEND gee.js |
| Pulse post kinds + guest banner | EXTEND |
| Event tier colours, glow, states | EXTEND events/detail.twig |
| Status by-country, maintenance, subscribe | NEW |
| Two-link mega nav | EXTEND nav.twig |
| Nominee race, ballot, celebration | EXISTS, restyle |

## 10. Admin (notes only; build inside the existing admin UI; no new admin designs exist)

- Award setup uses award wording throughout: Award → Edition → Category. Voting mode per edition is Free or Paid (tiers, cap, currency). Weights are locked at open.
- Award terms: an editor with versions, a changelog and a publish date.
- Events: tiers (price, capacity, colours), early-bird deadline, discount codes, waitlist hold hours, stands quota.
- Issuer console: Details → Recipients (search, spreadsheet, invite) → Publish. Immutable; withdrawals are logged.
- Moderation: Wall of recognition quotes, supporter messages, Gee transcripts (retention job).
- The nomination category-fit assistant is shown to admins and judges only.

## 11. Build order (one PR each)

1. Tokens + component CSS + page shell. 2. SiteHeader, MobileMenu, DisplayReading, search. 3. Gee. 4. Home v3. 5. Awards (index/detail/soon/terms). 6. VoteHub → VotePage → Nominee + ballot. 7. Results. 8. Events (detail, states, ticket). 9. Giving. 10. Profile, Pulse, Discover. 11. Blog, Status, Sign-in, Cookie. 12. Admin notes.

## 12. PR prompt

> Implement `<DC>` (all `layout`/`view`/`state` props) in `<template>`. Read this handoff §0–§3 and the repo `CLAUDE.md` first. Reuse existing services. Build anything missing per §0.2. Attach screenshots at 390/834/1440 + RTL 390 beside the DC, keyboard + VoiceOver notes, and the deviations list.

## 13. Shop cart (strict)

- **Cart entry point is always visible.** On desktop and tablet, SiteHeader shows the cart as a pill (icon + subtotal) in the top-right, with an ink count badge. It appears only on shop routes and whenever the cart is non-empty. On phone, the cart icon + badge sits in the top bar. Never put the cart link only in a page hero.
- **Add to cart** never navigates away. It shows an "Added to your cart" confirmation that doesn't dismiss itself. It contains:
  - the thumbnail, name, options and qty, and price;
  - the cart count and subtotal;
  - **View cart** (outline), **Checkout** (green) and "Keep shopping".
  - Desktop: a popover under the header, 380px wide, right 24px. Phone: a bottom card inset 8px. It uses `role="status"` and `aria-live="polite"`.
- After adding, the button reads "Added ✓ · add another" until the colour or size changes.
- The cart drawer holds the lines, qty, delivery and payment (M-Pesa, MTN MoMo, card, USSD) and the total with the giving share.
