# Destroyed scripts — feature inventory

No public script qualified for the first destroy (none read a retired name); these nine lost every loader with it and were destroyed as orphans.

# Orphans destroyed 3 Oct 2026 (owner-approved)

Scripts the first destroy left with no includer, no linker and no renderer ("Orphaned by the destroy" in DESTROYED.md). The owner approved destroying them the same day. Each entry is taken from the file as it stood at `HEAD` (`882d768`) before deletion. **Rebuild** says what a later phase owes; **MUST RESTORE** marks a feature that is a legal obligation, a promise already made elsewhere on the platform, or a live server mechanism this file was the only way into.

## `public/assets/js/account.js` (HEAD, 350 lines)

**Loaded by (at `a9d963a`):** `pages/account/dashboard.twig` (destroyed 3 Oct)

**What it did:** The /account enhancement layer: rail highlight kept in step with `:target` navigation (no `preventDefault` — the browser navigates, so a dead script leaves a stale highlight, never dead tabs), the section search filter, the phone search row, the balance eye toggle, three table filters, copy buttons, and the "add a passkey" enrolment button (calls `agPasskeys`).

**DOM hooks:** `data-me-title`, `data-me-go`, `data-find`, `data-me-copy`, `data-rf-copy`, `data-bal`  
**Element ids:** `#me`, `#meFind`, `#meFindNote`, `#meRail`, `#mePhFind`, `#meEye`, `#meKeyAdd`, `#meKeyBtn`, `#meKeyNote`  
**Endpoints:** —  
**Storage keys:** `'ag-hide-bal'`  
**Events listened for:** click, hashchange, popstate, keydown, input, blur  
**Globals exported:** —  
**Reads prefers-reduced-motion:** no

**Rebuild:** Rebuild with the account page; keep the no-`preventDefault` rule.

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/afg-features.js` (HEAD, 229 lines)

**Loaded by (at `a9d963a`):** `layout/gates.twig`

**What it did:** "Enterprise feature layer": a crypto-random per-session id in `sessionStorage` (`afg_sid`), a **canvas + navigator device fingerprint**, a funnel event tracker, nominee page-view tracking, nomination draft auto-save, a completion-percentage indicator, and a 30-second live vote-count poll.

**DOM hooks:** `data-vote-btn`, `data-share-platform`  
**Element ids:** `#draftBanner`, `#draftClear`  
**Endpoints:** `/api/funnel`, `/api/events/share`, `/api/nominations/draft`, `/api/nominees?id=${nomineeId}`  
**Storage keys:** `k`, `DRAFT_KEY`  
**Events listened for:** click, input, change, submit  
**Globals exported:** `window.AFG`  
**Reads prefers-reduced-motion:** no

**Rebuild:** **Do NOT restore as it was.** A device fingerprint is identifying processing the cookie policy and `CookieRegistry` would have to declare and `CookiePrefs` would have to gate; draft auto-save and the live count are worth rebuilding on their own, with their storage keys declared.

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/favicon.js` (HEAD, 217 lines)

**Loaded by (at `a9d963a`):** `layout/gates.twig`

**What it did:** The **dynamic favicon** (handoff Part C): one `<link rel="icon" id="agFavicon">` redrawn on a 64px canvas through `window.agFavicon` — `set('busy'|'idle'|'error'|'live')`, `unread(n)`, `track(promise)`. States are independent FLAGS and the picture is derived (priority error > live > unread > busy > idle), so a vote press does not wipe a live page's dot. Errors clear after 30s; no animation while the tab is hidden (but the state is drawn once); reduced motion gives static marks; never faster than 2Hz (~0.6Hz pulse, 0.9s ring); "(n) " in the title only while hidden and only when the count rose. Colours read from tokens via `token('--ag-…', fallback)` — sole reader of `--ag-green-light`.

**DOM hooks:** `data-favicon`  
**Element ids:** `#agFavicon`  
**Endpoints:** —  
**Storage keys:** —  
**Events listened for:** visibilitychange, change, submit, pageshow  
**Globals exported:** `window.agFavicon`  
**Reads prefers-reduced-motion:** yes

**Rebuild:** **MUST RESTORE** — the handoff specifies it. The static `public/favicon.svg` and PNG set survive (FaviconTest holds them); the live states do not. Rebuild it as the ONLY writer of the favicon (`FaviconTest::test_nothing_but_the_script_touches_the_favicon` still sweeps for a second writer).

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `FaviconTest::test_its_colours_are_tokens_that_exist` — At least five `token('--ag-…')` reads, each a token `Accent::css()` or `tokens.css` declares; outside `token()` fallbacks, no typed `#rrggbb`.


---

## `public/assets/js/globe-band.js` (HEAD, 397 lines)

**Loaded by (at `a9d963a`):** `pages/home.twig`, `partials/globe-band.twig` (destroyed 3 Oct)

**What it did:** The homepage "We are Africa" globe (d3 v7 + topojson, vendored; Natural Earth 110m, self-hosted): a dotted orthographic globe, markers from the stage's `data-countries` (written by `GlobeBand::countries()`, no fallback set), an AFRICA name set for drawing and hit-testing, drag to rotate plus arrow keys, focus on a far-side marker turning the globe to it, click a country for its card, only the selected country outlined, sphere sized by measuring the note's computed position, reduced motion snapping rather than refusing to turn.

**DOM hooks:** `data-globe-name`, `data-globe-code`, `data-globe-nominees`, `data-globe-votes`, `data-globe-none`, `data-globe-close`  
**Element ids:** `#agGlobeStage`, `#agGlobe`, `#agGlobeNodes`, `#agGlobeCard`  
**Endpoints:** —  
**Storage keys:** —  
**Events listened for:** resize, click, focus, blur, keydown, pointerdown, pointermove  
**Globals exported:** —  
**Reads prefers-reduced-motion:** yes

**Rebuild:** Rebuild with the homepage (`GlobeBand` and its geometry tests survive). Its WCAG 2.5.7 and 2.4.7 fixes are must-keeps.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `GlobeBandTest::test_every_mapped_country_is_in_the_scripts_africa_set` — Every `GlobeBand::GEOMETRY` name is in the script's `var AFRICA = new Set([…])` — a mapped country outside it has no outline and no polygon to click.
- `GlobeBandTest::test_the_sphere_measures_the_note_rather_than_copying_the_breakpoint` — `resize()` reads `getComputedStyle(note).position`, and the code (comments stripped) never contains `860` — the breakpoint belongs to the stylesheet.
- `GlobeBandTest::test_the_script_carries_no_invented_figures_and_no_routes` — None of `FALLBACK`, `verify_seconds`, `ballots`, `geoInterpolate`, `hub` (the invented city model); the markers come from `stage.dataset.countries`.
- `GlobeBandTest::test_only_the_selected_country_is_outlined` — The selected-country treatment `rgba(35,123,34,.10)` / `.55`; neither the retired field stroke `rgba(16,41,44,.16)` nor the "any activity" `rgba(35,123,34,.62)`; hit-testing by `d3.geoContains`. (The rebuild must express these as `color-mix` of tokens — ColourLiteralTest — and re-pin by token.)
- `AccessibilityFloorTest::test_the_globe_can_be_turned_without_a_drag` — WCAG 2.5.7: besides `pointerdown`, the globe turns with `ArrowLeft`/`ArrowRight`; and 2.4.7: a `focus` listener turns the globe to a focused far-side marker.
- `AccessibilityFloorTest::test_motion_is_not_forced_on_anybody` — Reads `prefers-reduced-motion`, and the easing snaps (`reduced ? 1 :`) rather than refusing to turn.


---

## `public/assets/js/header.js` (HEAD, 178 lines)

**Loaded by (at `a9d963a`):** `layout/gates.twig`

**What it did:** The site header's behaviour beside the inline mega controller: the Aa and language popovers (open one and everything else closes, NOT modal, Esc and click-outside close, focus returns to the trigger, `inert` while closed), the toolbar's arrow-key roving between search/Aa/language (`role="toolbar"`), and the basket badge (reveals `data-ag-cart` when this browser's basket is non-empty, writes `data-ag-cart-n`).

**DOM hooks:** `data-ag-head`, `data-ag-pop-panel`, `data-ag-mega-trigger`, `data-ag-pop`, `data-ag-cart`, `data-ag-cart-n`  
**Element ids:** —  
**Endpoints:** —  
**Storage keys:** `'afg_cart'`  
**Events listened for:** click, keydown, storage  
**Globals exported:** —  
**Reads prefers-reduced-motion:** no

**Rebuild:** **MUST RESTORE** — `partials/site-header.twig` survives and still declares `role="toolbar"` (a claim about arrow keys), two popover triggers and the basket badge — **all now inert on `/_dev/ui` and on every shell page that includes the header**. Its own comments still say `header.js` implements them. Phase 2 must restore the behaviour (or the shell's `chrome.js` must take it) and re-assert the toolbar rule.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `SiteHeaderTest::test_the_toolbar_holds_exactly_search_display_and_language` **(guard kept, edited)** — Lost its script half: `header.js` contains `ArrowRight` and `'[role="toolbar"]'`, because `role="toolbar"` is a claim about arrow-key navigation and a toolbar that only answers Tab tells a screen-reader user to press keys that do nothing. The markup half (three controls, the three hooks) was kept. **Currently unbacked.**


---

## `public/assets/js/nominate-share.js` (HEAD, 120 lines)

**Loaded by (at `a9d963a`):** `pages/nominate-success.twig` (destroyed 3 Oct)

**What it did:** On a landed nomination, mints a share link that prefills what the nominator knew (`/nominate/{slug}?share=`), reading its payload from a `<template>` (not a `data-` attribute an apostrophe would break, not a JSON `<script>` the inline-script sweep would have to understand). A file, not Alpine, because the shell does not load Alpine.

**DOM hooks:** `data-ag-share`, `data-ag-share-payload`, `data-ag-share-make`, `data-ag-share-out`, `data-ag-share-url`, `data-ag-share-copy`, `data-ag-share-err`, `data-ag-share-life`  
**Element ids:** —  
**Endpoints:** `/api/v1/nominations/share-link`  
**Storage keys:** —  
**Events listened for:** click, focus  
**Globals exported:** —  
**Reads prefers-reduced-motion:** no

**Rebuild:** Rebuild with the nomination success page.

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/nominate.js` (HEAD, 471 lines)

**Loaded by (at `a9d963a`):** `pages/nominate-award.twig` (destroyed 3 Oct)

**What it did:** The nomination wizard as an ENHANCEMENT over one long working form posting `/nominate`: steps, the live reason counter (characters, threshold from `data-min` = `NominationRules::MIN_REASON`, no literal), the category cap, organisation-vs-person name labels, "Why {name} for {category}?", file-input feedback, "Add another" revealing existing fields, per-step missing-field messages inside the step (never a disabled Continue), a real submit on the last step.

**DOM hooks:** `data-nf`, `data-nf-step`, `data-nf-rail`, `data-nf-back`, `data-nf-next`, `data-nf-submit`, `data-nf-reason`, `data-nf-meter`, `data-nf-why`, `data-nf-pick`, `data-nf-cat`, `data-nf-cats`, `data-nf-why-label`, `data-nf-name-label`, `data-nf-name-hint`, `data-nf-port`, `data-nf-port-label`, `data-nf-add-link`, `data-nf-dot`, `data-nf-sum-v`, `data-nf-sum-d`, `data-nf-said`, `data-nf-busy`, `data-min`, `data-max-cats`, `data-nf-tpl`, `data-nf-cat-title`, `data-min-cats`, `data-short-reason`  
**Element ids:** —  
**Endpoints:** —  
**Storage keys:** —  
**Events listened for:** input, change, click, submit  
**Globals exported:** —  
**Reads prefers-reduced-motion:** no

**Rebuild:** Rebuild with the nomination flow; keep "works with the script absent" and "the rule is the server's".

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/passkeys.js` (HEAD, 180 lines)

**Loaded by (at `a9d963a`):** `pages/account/dashboard.twig`, `pages/account/login.twig` (destroyed 3 Oct)

**What it did:** The browser half of **passkey sign-in and enrolment** (WebAuthn): base64url ↔ ArrayBuffer by hand (the JSON helpers only exist from Chrome 119/Safari 18), CSRF via `meta[name=csrf-token]` → `X-CSRF-Token`, enrol (`POST /account/passkeys/options` → `/account/passkeys`) and sign in (`[data-passkey-signin]` → `POST /account/login/passkey/options` → `/account/login/passkey`), every failure surfaced to a note element, none swallowed.

**DOM hooks:** `data-passkey-signin`, `data-passkey-note`  
**Element ids:** —  
**Endpoints:** `/account/passkeys/options`, `/account/passkeys`, `/account/login/passkey/options`, `/account/login/passkey`, `/account`  
**Storage keys:** —  
**Events listened for:** click  
**Globals exported:** `window.agPasskeys`, `window.PublicKeyCredential`  
**Reads prefers-reduced-motion:** no

**Rebuild:** **MUST RESTORE** — the server half (`Services\Passkeys`, five routes, `AccountController`, `PasskeyTest`) is live and now unreachable from any page — a mechanism with no route in (§18). The rebuilt sign-in and account pages must load this behaviour again.

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/promo-carousel.js` (HEAD, 163 lines)

**Loaded by (at `a9d963a`):** `layout/gates.twig`

**What it did:** The promo band's index only (motion is CSS): a pause button, pause on hover, on focus-within (WCAG 2.2.2) and live on `prefers-reduced-motion`; off-screen slides `inert` so the keyboard never tabs into an invisible slide.

**DOM hooks:** `data-pb-track`, `data-pb-go`, `data-pb-pause`, `data-ag-promos`  
**Element ids:** —  
**Endpoints:** —  
**Storage keys:** —  
**Events listened for:** click, mouseenter, mouseleave, focusin, focusout, keydown  
**Globals exported:** —  
**Reads prefers-reduced-motion:** yes

**Rebuild:** With promo-carousel.twig.

**Guard tests:** none read this file at the time of the destroy.

