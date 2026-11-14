---
name: app-ux-standards
description: Strict, research-backed UX rules for building interfaces that feel native on every screen: phone (native-app patterns), tablet, and desktop (keyboard-first, multi-pane, dense, hover-rich), plus celebration/reward moments that feel grand without being noisy. Use for ANY web/PWA/native view, layout, navigation, form, payment/vote/ticket/checkout flow, success or winner screen, and whenever work is called "not mobile", "not desktop enough", "cramped", "stretched", "rough", "slow", "flat", or "the celebration is weak". Covers layout branching, app bars, tab bars, menus, sheets, chips, collapsing search, galleries, bottom action bars, gestures/back, safe areas, keyboard and forms, payments, loading/empty/error/offline states, performance and low-bandwidth budgets, i18n/RTL, WCAG 2.2, motion, desktop productivity patterns (command palette, shortcuts, split views, hover, density, drag), celebration choreography (confetti, badge, haptics, sound, share cards, peak-end), and PR checklists.
---


# App UX standards: phone, tablet, desktop & celebration (v3)

**Sources:** Material 3 (app bars, navigation, sheets), Android predictive back and edge-to-edge guidance, Apple HIG (navigation bars, tab bars, sheets), WCAG 2.2, Core Web Vitals (web.dev), and Baymard mobile commerce research. It also encodes Africa GATES product rules: low-bandwidth markets, mobile money, and many languages.

**Levels:** **MUST** = blocking in review. **SHOULD** = default, deviate only with a written reason in the PR. **NEVER** = reject.

If a MUST can't be met, **stop and ask**. Don't improvise.

---

## 0. Contract

1. **MUST: phone gets its own markup branch.** At `<600px` of *container* width, render phone-specific structure. Never reflow desktop blocks into one column.
2. **MUST: measure the container, not the viewport.** Breakpoints:
   - phone: <600
   - tablet: 600–1023
   - desktop: ≥1024
   - wide desktop (3-column): ≥1200

   Use a ResizeObserver or `@container` queries. Check every fixed-column grid at exactly 1024px and 1199px so no text column collapses to 0.
3. **NEVER** scale to fit: no `zoom`, no `transform:scale`, no `vw` font sizes.
4. **MUST: one scroll container per screen.** Root is `height:100dvh; max-height:100%; display:flex; flex-direction:column`. Only `<main>` scrolls. Bars live outside `<main>` or are `position:sticky` inside it.
5. **MUST: clean over busy.**
   - Hairline dividers, sentence-case headings, a light ground, and one accent per block.
   - Selected state = **2px ink outline**, never a dark fill.
   - No dark bands with overlaid text on phone.
   - Never show the same fact twice on one screen.
6. **MUST: read the existing template first** and keep every feature it has, across all its states.

## 1. Tokens

| Token | Phone | Tablet / desktop |
|---|---|---|
| Touch target (MUST) | ≥44×44 CSS px (48 for primary rows/controls) | ≥40×40 pointer, 44 touch |
| Primary button | 52–54px, radius 14–16 | 44–50px, radius 999 |
| Chip | 44px, radius 999, 1px `#d6d4cc`; selected 2px `#10292c` | 36–40px |
| List row | ≥56px (≥88 for rich rows) | ≥48px |
| Input | 52px, **font ≥16px** | 44–48px, 15–16px |
| Gutter | 16px | 28 (tablet) / 32 (desktop) |
| Sheet radius | 22–24 top corners | 20 all corners (floating 12px from edges) |
| Ground / bar / surface | `#f1efe9` / `#fbfbfa` / `#fff` | same |
| Ink / soft / line / strong line | `#10292c` / `#626a6e` / `#e8e5dd` / `#d6d4cc` | same |
| Primary action / live / honour | `#237b22` / `#e0245e` / `#f3b416` + `#7a5600` ink | same |

WCAG 2.2 sets 24×24 CSS px as the floor (AA, 2.5.8) and 44×44 as the enhanced bar (AAA, 2.5.5). **This product ships 44.**

## 2. Navigation architecture

- **Tab bar (bottom), phone:** 3–5 destinations (HIG). Ours: Home · Discover · Nominate · Pulse · **Menu**.
  - Each item: ≥52px tall, 23px icon + 12px label. Labels are always visible, never icon-only.
  - Active: `#237b22`, weight 700, `aria-current="page"`.
  - **Exactly one or zero** items active. A non-tab page (Results, Shop) shows none active.
  - Padding `6px 8px 22px` (+ `env(safe-area-inset-bottom)`), `#fbfbfa`, a top hairline.
- **Push vs sheet vs modal:**
  - **Push** (new screen + back) for hierarchy: list → detail.
  - **Sheet** (bottom, dismissible, grabber) for scoped tasks: filters, cart, quick settings, share, options.
  - **Full-screen modal** only for multi-step tasks that must not be lost by an accidental swipe (checkout, nomination); these need an explicit Close with an "unsaved changes" confirm.
- **Flow pages** (vote ballot, checkout, event detail, product, nominee): **hide the tab bar**, show a child app bar + a bottom action bar.
- **Tablet:** a navigation rail or top bar; no phone tab bar at ≥600. **Desktop:** a top bar with at most 2 mega-menu triggers + utilities.
- **Deep links:** every screen, tab, filter set and sheet state that matters is URL-addressable (`?tab=`, `?c[]=`, `#sheet=filters`) and survives Back and reload.

## 3. App bar (top)

One shared partial (`partials/app-bar.twig` / `<AppBar>`). **NEVER** hand-roll a page header.

- **Root variant** (tab destinations):
  - Row 1 (52px): `[88px] [centred small title 17px/600, opacity 0] [search 44 · avatar 44]`.
  - Row 2: the large title (display face, 700, 32px), padding `0 16px 10px`. This is the page's only `<h1>`.
- **Child variant** (pushed): `[back 44] [title 17px/600 + optional sub 12.5px; both truncate] [≤1 action 44]`. **At most back + title + one action** (HIG).
  - The sub must add information and never repeat the title (✗ "Results" / "Results · 3rd Edition"; ✓ "Results" / "3rd Edition · 2025").
- **Scroll behaviour (M3):** `<main>` reports `scrollTop > 8` as `scrolled`.
  - At rest: background = page ground, no border.
  - Scrolled: `#fbfbfa` + 1px `#e8e5dd` bottom border.
  - Root: the large title row hides and the small title fades in (180ms); it stays small until back at the top.
  - Colour-only change: no shadow, no height jump on child bars.
- **Over imagery:** when the bar floats over a photo (detail pages), use round 44px buttons with a container fill `rgba(255,255,255,.94)` and a soft shadow (M3: icon buttons need a container fill).
- **Avatar → Quick-settings sheet** (not a link):
  - profile row + Profile link;
  - text size (3-step segmented, 48px);
  - High contrast and Reduce motion switches;
  - language chips, each labelled in its own language with `lang=""`;
  - "All display & reading settings";
  - a 52px Done button.

  **NEVER** add separate language or Aa buttons to the phone app bar. They belong in the desktop toolbar only.
- **First-visit language prompt:** if `navigator.languages[0]` is a supported non-English language and there is no `ag_lang` cookie, show one dismissible row under the root bar, written *in that language* (e.g. "Voir Africa GATES en français ? · Oui · Keep English"). Either choice sets the cookie. It hides once scrolled.

## 4. Menu (5th tab)

A bottom sheet, not a page:
- `top: 52px`, radius 24, grabber 38×5, scrim `rgba(16,41,44,.32)`;
- a centred title and a 44px close button.

Main view, in order:
1. profile card, or a join card when signed out;
2. 4 quick tiles (44px tinted icon + 13px label);
3. grouped white lists with 56px rows (34px tinted icon, label, value, chevron): Explore · Settings (Display & reading, Language, Notifications) · Help (Help Centre, Integrity Center, Status with a live dot);
4. legal links, Sign out (red text), wordmark.

Sub-views **push inside the sheet** with a back button. **NEVER** use accordions inside menus.

## 5. Chips, filters, sort

- **Row:** horizontal scroll, `gap:8px`, bleeding to the edges (`margin:0 -16px; padding:10px 16px`). Background = ground; sticky under the app bar or page tabs.
- **Chips:** `white-space:nowrap`, 44px. **NEVER** wrap a chip label.
- **Order:** Filters button (badge = count of non-category filters) → category chips (single-select on phone) → native Sort `<select>` styled as a chip.
- **Applied filters:** removable outlined chips (aria-label "Remove filter: X") + "Clear all". Keep them visible, not only inside the filter sheet (Baymard: show applied filters in an overview).
- **Filter sheet:** a sticky footer with `[Clear all] [Show N items]`, where N is the live count. Allow multi-select within a type. Provide the essential types where they apply: price, colour, size, rating, brand/host.
- **Filters persist on Back.**

## 6. Collapsing sticky search + filters

1. One sticky block at `top:0` in `<main>`, padded 10px at rest and 8px once scrolled. The hairline appears only when scrolled.
2. **The search row is always in the DOM**, wrapped in `display:grid; grid-template-rows:1fr|0fr` with an inner `min-height:0; overflow:hidden`.
   - Animate `grid-template-rows` + `margin-bottom` (10↔0) over **260ms `cubic-bezier(.2,0,0,1)`**, and `opacity` over 200ms.
   - **NEVER** use `display:none` / `v-if`.
3. **The search icon button** leads the chip row, always in the DOM. Animate `width 0↔44px`, `margin-inline-end −8px↔0` and `opacity`.
4. **States:**
   - top → open;
   - scrolled with an empty query → collapsed + button;
   - tap → open and record `y0`;
   - open with an empty query and `|scrollTop−y0| > 24` → collapse;
   - a non-empty query stays open;
   - back to the top → reset.
5. **Accessibility:**
   - The collapsed row gets `aria-hidden="true"` and its input `tabindex="-1"`, mirrored on the hidden button.
   - Tap → focus moves to the input. Esc → collapse and return focus to the button.

### 6b. Docking scope tabs beside Filters (Discover pattern)
- **At rest:** search row → scope tabs row (All · People · Organisations · …) → filter row (Filters button + applied chips).
- **Scrolled** (`scrollTop > 8`):
  - The scope tabs row collapses (`grid-template-rows 1fr→0fr`, `margin-top 12→0`, opacity, 260ms `cubic-bezier(.2,0,0,1)`).
  - The same tabs appear inline in the filter row, **after** the Filters button, behind a 1px × 24px divider (`max-width 0→900px` + opacity, 300ms).
  - The sticky block gains its hairline.
- Only one copy is exposed at a time: the hidden copy gets `aria-hidden="true"` and `tabindex="-1"` on its buttons. Selection state is shared (one source of truth).
- Tabs are outlined, with a 2px ink border when selected, and `white-space:nowrap`.

## 7. Heroes, detail pages, galleries

- **Index heroes (phone):** a rounded 16:9 photo with a white status chip ("● Voting open"), then text on the ground colour. **NEVER** use gradient-over-photo text on phone.
- **Detail pages:**
  - A full-bleed 4:3 / 4:5 scroll-snap gallery (`scroll-snap-type:x mandatory`), focusable (`tabindex="0"` + aria-label "Photos of X. Swipe for more"), with decorative dots.
  - Floating 44px round buttons over it.
  - A **content sheet** (radius 22) overlapping the gallery by 22px.
- **Gallery budget:** the first image is eager with `fetchpriority="high"`; the rest are `loading="lazy"`. Use responsive `srcset`: 480/800/1200w, AVIF/WebP with a JPEG fallback.
- **Home (phone):** photo first (full bleed), then the title, the lead and **stacked full-width 54px CTAs**.

## 8. Bottom action bar (thumb zone)

- **MUST** for flows. It is pinned to the bottom:
  - padding `12px 16px 28px` + safe-area inset;
  - `rgba(251,251,250,.96)` + `backdrop-filter:blur(14px)` + a top hairline.
- Layout: `[summary: bold value + 12.5px detail, truncates] [primary 52–54px, radius 16]`.
- When the primary is impossible (nothing chosen yet), show it disabled with a clear state (grey fill, `aria-disabled`) and an explanatory summary. Hide the bar when the action doesn't exist (a sold-out size → show the restock alert instead).
- **NEVER** put a flow's primary action only at the top of a long page.

## 9. Floating UI clearance (assistant FAB, toasts)

- ≥16px above any bottom-fixed element: 96px above the tab bar, 108px above an action bar, 40px with no bar.
- Implement with `--ag-bottom-ui`, set by each fixed bar: `bottom: calc(var(--ag-bottom-ui,0px) + 16px + env(safe-area-inset-bottom))`. Update it whenever a bar mounts or unmounts.
- Toasts/snackbars sit in the same stack, above the FAB, and auto-dismiss after ≥5s (longer if they contain an action). Pause on focus or hover. Announce via `aria-live="polite"`.

## 10. Gestures, back, safe areas, orientation

- **Back:** system back (Android gesture/button, iOS edge swipe, browser Back) **MUST** close the top-most layer first: sheet → modal → pushed screen → tab root.
  - On the web, sheets push a history entry (`#sheet=…`) so Back closes them.
  - Support Android predictive back: no custom back interception; let the system animate.
- **Edge-to-edge:** content may draw under the system bars, but **no tappable control sits inside the system gesture insets**. Use `env(safe-area-inset-*)` and `viewport-fit=cover`.
- **NEVER** attach horizontal swipe gestures starting at the screen edge (they conflict with system back). Carousels start their drag ≥16px from the edge.
- **Pull-to-refresh** only on live feeds (Pulse, Vote hub totals). Elsewhere, set `overscroll-behavior-y: contain` on `<main>`.
- **Every gesture has a visible button equivalent** (swipe gallery ↔ arrows/dots are buttons on tablet and desktop; swipe-to-dismiss ↔ Close).
- **Orientation:** never lock it. Landscape phone (short height): sheets max-height 90%, bottom bars stay single-row, the app bar large title is hidden.
- **Foldables/tablets:** respond to width changes live without losing state (selected tab, scroll position, form input).

## 11. Keyboard & forms

- Inputs ≥16px font (prevents iOS zoom), 52px tall, with **visible labels** (never placeholder-only).
- Every field has `inputmode` + `autocomplete` + `enterkeyhint`:
  - email → `inputmode="email" autocomplete="email"`;
  - phone → `inputmode="tel" autocomplete="tel"`, with the country code as a separate selector;
  - OTP → `inputmode="numeric" autocomplete="one-time-code"`, a single field (not 6 boxes that break paste), with `pattern="\d{6}"`;
  - amounts → `inputmode="decimal"`.
- When the keyboard opens:
  - the focused field scrolls into view above it (`scroll-margin-bottom` ≥ 120px);
  - the bottom action bar rides above the keyboard (use `visualViewport` resize, or the `interactive-widget=resizes-content` viewport meta);
  - nothing important is hidden behind it.
- **Validation:** inline under the field, on blur or submit (not on every keystroke). Error text + icon + colour, `aria-describedby`, `aria-invalid`, and focus moved to the first invalid field.
- Long lists → native `<select>`. ≤5 options → chips or a segmented control. Dates → native `<input type="date">`.
- **Never clear user input on error.** Save drafts of long forms locally (e.g. a nomination reason) and restore them on return.

## 12. Payments, identity, trust (African markets)

- **Payment method order** follows the user's region: mobile money first where it's dominant (M-Pesa, MTN MoMo, Airtel Money), then card, bank transfer, USSD.
  - Show the provider logo + name.
  - Say what happens next: "Approve the prompt on your phone".
- **Show an async payment state:**
  - "Waiting for approval…" with a 2-minute countdown and a "Didn't get the prompt? Resend / Pay another way" link;
  - a pending/reconcile state that says nothing was taken if it failed.
- **OTP fallbacks:** SMS → WhatsApp → voice call. Resend is disabled for 30–60s with a visible countdown.
- **Money:** show the currency code or symbol and the charge currency ("Charged in Naira · ₦17,500"). Never show a price in one currency and charge another silently.
- **Voting and support** (this product): never shopping language ("Pay", "Buy", "Cart"). Use "Vote", "Support", "Send my support".

## 13. States: loading, empty, error, offline

Every data view **MUST** design all five states:
1. **Loading:** a skeleton matching the final layout (same heights, so no layout shift). Spinners only for <1s actions inside buttons (the button keeps its width; the label becomes "Sending…").
2. **Empty:** a one-line reason + one action ("No awards match 'kano'. Clear filters"). Never a blank screen.
3. **Error:** plain language + what's safe ("Nothing was charged") + Retry. Keep any content already loaded.
4. **Offline:** a thin banner "You're offline. Showing saved results." Queue writes (votes, drafts) with a clear "Will send when you're back online" state, or block with an explanation. **NEVER** fail silently.
5. **Partial / slow:** after 3s, show "Still loading… on a slow connection?" with a **Lite mode** link.

## 14. Performance & low-bandwidth (MUST)

- **Core Web Vitals, measured at p75 on mobile:** LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1.
- **Internal budget:** LCP < 2.0s on a Moto G-class device over 3G.
- **Page weight budget (phone, first load):**
  - HTML+CSS ≤ 60KB gzip;
  - JS ≤ 90KB gzip on interactive pages and 0KB required on read-only pages (progressive enhancement: everything must work without JS);
  - images ≤ 300KB above the fold.
- **Data saver mode** (user setting + `Save-Data` header + `navigator.connection.saveData`):
  - no autoplay;
  - images replaced by tap-to-load placeholders;
  - lower `srcset` caps;
  - no web fonts (system stack).
- **Lite mode:** a server-rendered, no-JS, text-first version of each key flow (vote, nominate, results, ticket), reachable from the slow-load prompt and the menu.
- Fonts: at most 2 families, `font-display:swap`, subset per script.
- Cache the shell and recent results with a service worker. The ticket QR and receipts work offline.
- **Interactions:** respond within 100ms visually (pressed state, optimistic UI). Yield long tasks (>50ms).

## 15. Internationalisation & RTL

- Every string goes through the translation layer; no text baked into images.
- Allow **+40% text expansion** (French, Portuguese) without truncating buttons: buttons grow in height or wrap to 2 lines; chips scroll.
- RTL (Arabic):
  - `dir="rtl"` on `<html>`;
  - logical CSS only (`margin-inline-*`, `inset-inline-*`, `text-align:start`);
  - mirror directional icons (back, chevrons), but not media controls or logos.
- Tag language changes inside a page (`lang="fr"` on the prompt, `lang` on each language option).
- Numbers, dates and currency via `Intl` in the user's locale. Show time zones on every event and deadline time ("23:59 EAT").
- Names: one "Full name" field (don't force first/last). Allow diacritics and non-Latin scripts everywhere.

## 16. Accessibility (WCAG 2.2 AA minimum, MUST)

- **Targets:** ≥44px (2.5.8 floor is 24; we ship 44).
- **Focus:**
  - visible focus: 3px `#237b22` outline, 2px offset;
  - focus is never hidden behind sticky bars (2.4.11): add `scroll-padding-top` equal to the bar height.
- **Structure:** landmarks, and one `<h1>` per screen (the root app bar's large title).
- **Roles:**
  - `tablist/tab`;
  - `radiogroup/radio`;
  - `switch` + `aria-checked`;
  - `dialog` + `aria-modal`: trap focus, and on close return focus to the trigger.
- **Colour** is never the only signal ("Sold out", "Winner", "Voting open" as text). Contrast: 4.5:1 text, 3:1 UI.
- **Dragging** (2.5.7): every drag has a tap alternative.
- **Redundant entry** (3.3.7): don't ask for the same info twice in a flow; prefill.
- **Accessible authentication** (3.3.8): no cognitive puzzles; allow paste and password managers; the OTP field supports autofill.
- **Text scaling:** at 200% nothing clips. Honour the user's `rem` size, never `px` only for text.
- **Reduced motion:** `@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}`, an instant swap.
- **Read-aloud:** a Listen control on articles, profiles and results. Captions and sign-language flags on events.

## 17. Motion

- 180–260ms. Easing `cubic-bezier(.2,0,0,1)` (standard decelerate); 150ms for exits.
- Animate only `opacity`, `transform`, `grid-template-rows`, `width`, `background` and `border-color`. **NEVER** animate `height`, `top` or `left` on scroll.
- Sheets: slide up 240ms, and down 200ms on dismiss. The scrim fades 200ms.
- One motion idea per interaction. No decorative looping animation on phone except live indicators (≤1 per screen).

## 18. Lists, cards, content

- The whole row is the target, ending in a chevron; no small inline buttons inside a tappable row.
- **Product/content grid:**
  - 2 columns, 12px gap;
  - image 4:5, radius 14;
  - a 48px quick-add hit area (34px visual);
  - name: 2-line clamp; meta: "N colours · maker"; price + low-stock note only when low.
- **Carousels:** 150px cards, snap, bleeding to the edges. A "See all" link sits beside the section title.
- **Pagination:** "Load N more" (52px) + "Showing X of Y". **NEVER** numbered pages on phone.
- **Copy:**
  - sentence case;
  - verbs on buttons ("Send my support", "Get tickets");
  - ≤2 lines for helper text;
  - no jargon;
  - no exclamation marks in errors.

## 19. Notifications & permissions

- Ask for permissions (notifications, location, camera) **in context, after intent**:
  - "Get a reminder when voting closes?" → then the system prompt;
  - never on first load.
- Always offer an in-app alternative (email or SMS reminder).
- Explain data use in one line before the system prompt.

## 20. Testing matrix (MUST, per PR)

- **Widths:** 360, 390, 430 (phone), 600, 834 (tablet), 1024, 1199, 1440 (desktop).
- **Devices:** a low-end Android (2GB RAM, Chrome, 3G throttle) + a recent iPhone (Safari) + a tablet.
- **Modes:** RTL at 390; 200% text; high contrast; reduced motion; data saver; offline; keyboard-only; screen reader (TalkBack + VoiceOver).
- **Automated:** axe (0 serious), Lighthouse mobile (Performance ≥ 90, Accessibility 100), CrUX/RUM p75 against §14.

## 21. PR checklist (attach, ticked)

- [ ] Screenshots at the §20 widths + RTL 390 + 200% text, beside the design; deviations list (target 0).
- [ ] Phone uses its own markup; nothing is a squeezed desktop column; no column collapses at 1024/1199.
- [ ] App bar at rest matches the ground; scrolled = fill + hairline; the root large title collapses; the sub never repeats the title.
- [ ] Tab bar: 0 or 1 active, and correct; hidden in flows.
- [ ] Smallest target found: ___px (≥44).
- [ ] Inputs ≥16px with inputmode/autocomplete/enterkeyhint; the keyboard never covers the focused field or the primary bar.
- [ ] Chips don't wrap; `<main>` `scrollWidth === clientWidth`.
- [ ] Flow has a bottom action bar; the FAB clears it by ≥16px.
- [ ] Back closes sheet → modal → screen in order; sheets are URL-addressable.
- [ ] All 5 data states designed and implemented (§13).
- [ ] CWV p75 mobile within §14; page weight within budget; works without JS (read-only) and in Lite mode.
- [ ] Reduced motion, data saver, RTL, TalkBack/VoiceOver passes noted.

## 22. Anti-patterns (reject in review)

- A desktop hero, dark band or gradient-over-photo text on phone.
- Nav bar clutter: more than back + title + 1 action, or Aa/language buttons in the phone bar.
- Dark-filled selected chips; wrapping chips; chips <44px on phone.
- Primary action only at the top of a long flow; a disabled button with no explanation.
- Accordions inside menus; full-screen menus instead of sheets; sheets that Back doesn't close.
- Numbered pagination on phone; hover-only affordances; placeholder-only labels; a 6-box OTP that breaks paste.
- Spinners for page loads (use skeletons); blank empty states; silent offline failures.
- Horizontal swipe starting at the screen edge; locked orientation.
- The same fact twice on one screen; shopping language for voting.


## 23. Desktop: make it feel like desktop (MUST at ≥1024)

Desktop users have a precise pointer, a keyboard, hover and a large canvas. **Use them.** A stretched phone layout is a defect.

- **Layout:**
  - Content max-width 1180–1240px, 32px gutters.
  - At ≥1200 use **multi-pane** layouts: left rail (navigation/categories, 200–264px, sticky) · content · right rail (context/actions, 300–380px, sticky at `top: header + 16px`).
  - At 1024–1199, drop to 2 panes and move the right rail below the content or into the left rail.
  - Never let a text column fall below 480px.
- **Top bar:**
  - 64px: logo · ≤2 mega-menu triggers · flexible space · a grouped utility toolbar (Search | Aa | Language) · account.
  - Mega panels hang from the bar (no gap), have a frosted background, 24px bottom radii, and a 3-column icon tile + title + one line.
  - No green buttons in the header.
- **Command palette** (`/` or ⌘/Ctrl+K):
  - 640–680px wide, 72–84px from the top, scrim behind.
  - Contents: input → scope chips → grouped results (≤6 per group) → key hints footer.
  - Keys: ↑↓ move, Enter opens, Esc closes and returns focus. Empty state shows trending/recent.
- **Keyboard shortcuts:**
  - `?` opens a shortcuts dialog. Go-to chords: `G` then `H/V/D/E/A`.
  - `Esc` closes the top layer. `J/K` move through lists where lists are primary.
  - Shortcuts are ignored while typing in inputs or when modifier keys are held.
  - Every shortcut has a visible UI equivalent.
- **Hover is information, not decoration:**
  - Rows and cards get a subtle background shift (`#fbfbfa`) and reveal secondary actions (Share, Save, More) on hover **and** focus-within.
  - Links show underline on hover.
  - Tooltips (≥500ms delay) name icon-only buttons and show their shortcut, e.g. "Search (/)".
  - Hover-revealed actions **MUST** also be reachable by keyboard and on touch (the "⋯" menu).
- **Density:** desktop rows 44–48px, chips 36–40px, body 15–16px. Offer tables for comparison data (results standings, orders) with sticky headers, sortable columns (`aria-sort`) and right-aligned tabular numbers (`font-variant-numeric: tabular-nums`).
- **Sheets become side panels or popovers:** cart, filters and quick settings open as **floating drawers** (12px from the edges, radius 20, light scrim) or anchored popovers. Size guides and confirmations are centred dialogs (≤620px). Nothing slides up from the bottom on desktop.
- **Selection & drag:** multi-select with Shift/⌘-click where lists are managed (admin, issuer console). Drag-to-reorder always has a keyboard alternative (Alt+↑/↓) and a menu alternative (WCAG 2.5.7).
- **Right-click is never required.**
- **Focus management:** the skip link "Skip to content" comes first. Focus rings are always visible (3px). Sticky headers use `scroll-padding-top` so focused items aren't hidden.
- **Windows & resize:** layouts reflow live between 1024 and 2560 without breaking. At ≥1600, grow whitespace and card size, not line length (≤75ch).
- **Desktop PR check:** screenshots at 1024, 1199, 1440, 1920. Every hover action is also reachable via keyboard. Shortcuts dialog is present. No column collapses. The palette works with keyboard only.

## 24. Celebration moments (MUST for wins, votes, nominations, gifts, tickets)

**Research basis:**
- **Peak–end rule:** people remember the peak and the end of an experience, so the success moment *is* the lasting impression.
- **Reward real milestones, not routine actions:** Asana and Mailchimp celebrate completion, not saves. Constant bursts lose impact through hedonic adaptation.
- **Layer celebration on real progress, never as a substitute:** Peter Ramsey / Built for Mars; e.g. "your card is on its way" beats balloons.
- **The ≈3-second rule:** celebrations that make users wait feel like being held hostage, so **actions are usable immediately; nothing blocks input.**
- **Counters that tick up** give weight to accumulation; a draw-on checkmark confirms success; haptics confirm success on supported hardware.
- **Consistency:** the same moment type always looks the same across the product.

### 24.1 Signatures (one per moment type; never mix)

| Kind | Stage signature | Badge | Haptic |
|---|---|---|---|
| **win** (category or overall winner) | Gold spotlight cone from the top; slow gold ray ring; **3 staggered firework bursts** (radial sparks with glow, at +0.5s / +0.85s / +1.25s); gold streamers falling; 2 shock rings | Crown on a gold disc, glint ×2 | `[18,60,18,60,40]` |
| **vote** | 2 green shock rings; **the check draws itself** (stroke-dashoffset, +0.55s); small sparks rise from the badge | Check on a green disc | `[14,40,22]` |
| **nominate** | **Stars fan out** on 2 radii and keep twinkling; light green/gold confetti | Star | `[14,40,22]` |
| **give** | **Hearts float up and sway** from the bottom; badge heartbeat ×2 after the pop | Heart on a pink disc | `[14,40,22]` |
| **ticket** | **The ticket drops in and tilts** (perforation + QR); a green **CONFIRMED stamp** thumps on at +0.85s; blue/gold confetti | (the ticket is the badge) | `[14,40,22]` |

### 24.2 Choreography

- **0ms:** the trigger button shows a pressed, disabled state (Beat 0).
- **50–950ms:** badge pop, `scale .2→1.14→.95→1` with a rotation settle, `cubic-bezier(.2,.9,.3,1.2)`.
- **+100 / +400ms:** shock rings, `scale .5→2.8`, fading.
- **0–2.8s:** the kind's particle signature (24.1).
- **Text rise:** kicker +350ms → headline +450ms → sentence +550ms → stats +650ms → actions +750ms, each `translateY 12→0` + fade over 500ms `cubic-bezier(.2,0,0,1)`.
- **+500ms → +1800ms:** stat **count-up** (ease-out cubic), keeping decimals and suffixes (`9.1`, `68%`, `1st`).
- **Rest:** only the win ray ring keeps moving (16s/turn, low opacity). Everything else ends.

### 24.3 Layout

- **Phone:** stacked and centred, in a card with a radial tint gradient from the kind's tint at the top centre to white.
  - Stage 210px tall · title 32px · sub 15.5px · stat tiles (mono 17px value + 12.5px label).
  - Full-width 52px buttons: primary (green) + secondary (outlined), then a text link "Celebrate again".
- **Desktop (≥1024 or a card ≥720px):** a **two-column split**, not a stretched phone card.
  - Stage left (360×320) · content right: left-aligned text, 46px title, 16.5px sub, stat tiles in a row (mono 20px), buttons side by side (auto width).
  - The tint gradient is anchored behind the stage (25% 40%). Card padding 32×40, radius 24.
- **Inline** (inside a ballot or form): a 150px stage, 24px title, no stats, full-width 48px buttons. It replaces the form in place; no modal.
- **Overall edition winner:** full desktop layout + a **shareable winner card** (1080×1350: photo, name, award, edition, verified QR) offered as the primary Share action.

### 24.4 Behaviour rules

- **Once per achievement:** pass `seenKey` (e.g. `win-kcea11-achieng`). The first view plays; later views render the **calm end state** (badge, text, final numbers, no particles). "Celebrate again" always replays.
- **Never blocks:** buttons are clickable from the first frame, with no auto-redirect. Navigation away mid-animation is fine.
- **Primary = the next best thing:** Share the win / Share your vote / Let them know / Share the campaign / Add to calendar.
- **Secondary = useful continuation:** See how it was decided / Vote in another category / Nominate someone else / See where it goes / View ticket.
- **Copy:** the kicker is the moment in 1–2 words; the headline is human and short ("Achieng won.", "Your vote is in"). The sentence carries meaning ("18,402 people backed her"), never generic praise.
- **Share:** use `navigator.share`; fall back to copying the link, with a toast "Link copied".

### 24.5 Accessibility, performance, restraint

- **`prefers-reduced-motion`:** hide all `[data-fx]` (particles, rings, glint, rays, beam), with no animations. Numbers show final values and the haptic is skipped.
- **Announce** title + sentence in `aria-live="polite"`. Particles are `aria-hidden`. No flashing more than 3 times per second.
- **Performance:** transform/opacity only; ≤110 animated nodes; must hold 60fps on a 2GB Android; remount the stage on replay; no canvas or library required.
- **Sound is off by default.** An opt-in chime is under 600ms, plays only after a user gesture, and respects silent mode.
- **Never celebrate:** saving drafts, settings changes, sign-in, adding to cart, email subscribe.

### 24.6 After the peak: the ambient loop (MUST; never go fully static)
After the burst, a **low-density loop** keeps the moment alive for as long as it's on screen. **Limits:** ≤40 ambient nodes in the DOM, and **≤12 visible at any moment**. Looping firework sparks and drifting pieces are idle (opacity 0) for about 70% of each cycle. Cycles are 3–7s with staggered delays, so the loop never syncs into a visible rhythm. The shipped `celebration.js` is within these limits: win 39 nodes, nominate 15–17, vote 8, give 7, ticket 5, inline ≤7. **Don't edit the file to reduce them.**

| Kind | Ambient loop |
|---|---|
| win | Ray ring turns (16s); 7 gold sparkles twinkle around the crown; **a small gold firework every 7s** at 2 alternating positions; gold streamers trickle down; the spotlight breathes (5s); the glint crosses the badge every 6s |
| vote | A soft ring pulses out every 3.4s; small sparks keep rising from behind the check |
| nominate | The star ring **slowly orbits** (48s/turn) while each star twinkles; a little confetti drifts down |
| give | **Little hearts keep floating up** and swaying (7 at a time, 3.4–4.6s each); the heart badge gives a soft double-beat every 2.8s |
| ticket | The ticket gently bobs and tilts (4.5s); a few blue and gold pieces drift down |

- The loop **pauses when off-screen** (IntersectionObserver → `.agc-paused`) and when the tab is hidden (`visibilitychange` → `.agc-hidden`).
- **Seen before (`seenKey`):** skip the burst, start straight in the ambient loop, and show numbers at their final values.
- **Reduced motion:** no burst and no loop; the badge and text are static.

### 24.7 Implementation (ship these files; the DC runs the same code)

- `assets/celebration/celebration.js` (vanilla, no dependencies) + `assets/celebration/celebration.css` (keyframes and `.agc-*` only). Copy both **verbatim** to `public/assets/js/` and `public/assets/css/components/`.
- **API:** `const fx = AGCelebrate.mount(stageEl, {kind, size:'full'|'inline', layout:'phone'|'desktop', seenKey, ticketLabel, ticketWhen})` → `{calm, seen, reduced, replay(), destroy()}`. Also `AGCelebrate.countUp(rootEl, fx.calm)` for `[data-agc-count]`.
- **Twig** (`partials/celebration.twig`, args `kind, size, layout, kicker, title, sub, stats[], primary, secondary, seen_key`). Markup and styles exactly as in `Celebration.dc.html`. The stage is an empty `<div class="ag-cel__stage" data-agc-kind … data-agc-seen-key …>`. Stats render the **final** value in `<b data-agc-count="18402">18,402</b>`, so no-JS and crawlers see real numbers.
- **Boot** (in the page module, nonce'd): `document.querySelectorAll('[data-agc-kind]').forEach(el => { const fx = AGCelebrate.mount(el, {kind: el.dataset.agcKind, size: el.dataset.agcSize, layout: matchMedia('(min-width:1024px)').matches ? 'desktop' : 'phone', seenKey: el.dataset.agcSeenKey}); AGCelebrate.countUp(el.closest('.ag-cel'), fx.calm); el.closest('.ag-cel').querySelector('[data-agc-replay]')?.addEventListener('click', () => { fx.replay(); AGCelebrate.countUp(el.closest('.ag-cel')); }); })`.
- **When to render:** server-side on success pages (vote done, nomination sent, gift done, ticket confirmed, the winner's nominee page, the overall result). For inline (ballot), swap the form for the partial in place and call mount after insertion. `seen_key` = `{kind}-{edition}-{subject}`, plus the user id when signed in.
- **CSP:** the engine sets only inline *style properties* via JS (allowed under `style-src` with a nonce'd stylesheet; no inline `<style>` needed). Test that nothing is blocked.
- **Fonts:** headline Playfair Display 700; everything else DM Sans (tabular numerals for stats). **No monospace and no uppercase labels** in celebrations. The kicker is a sentence-case pill.
- **Inline layout:** a card (tint gradient 135°, radius 20, padding 16): 88px stage on the left + kicker, 22px headline, sentence on the right; two 48px buttons below (`auto-fit, minmax(150px,1fr)`); no stats, no replay. Particles may overflow the stage but are clipped by the card.

### 24.8 Celebration PR check

- [ ] Correct signature per kind (24.1); palette only; the choreography timings match 24.2.
- [ ] The desktop split layout is used on wide cards; the phone stacks; inline replaces in place.
- [ ] Plays once (seenKey), replay works, and actions are usable immediately.
- [ ] The ambient loop keeps running after the peak, pauses off-screen and when hidden, and seen = loop without burst.
- [ ] Uses the shipped celebration.js/css unmodified; stats render final numbers server-side.
- [ ] No mono and no uppercase; fonts loaded.
- [ ] Reduced motion = calm state; the headline is announced; 60fps on a low-end Android.
