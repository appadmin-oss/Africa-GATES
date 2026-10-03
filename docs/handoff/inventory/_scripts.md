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


# Second orphan wave, 3 Oct 2026 (owner-approved)

Six scripts and one vendored library that the first orphan pass left behind: `celebrate.js`, `vendor/canvas-confetti-1.9.3.js` and `community-modal.js` lost their last loader with `partials/celebrate.twig` and `partials/community-modal.twig`; `ag-motion.js`, `ag-search.js` and `ag-social.js` were already linked by nothing at `HEAD` (each was loaded only by the destroyed `layout/gates.twig`, plus `pages/vote-nominee.twig`, `pages/pulse.twig` and `pages/results/show.twig`). DESTROYED.md listed them under "Newly orphaned — owner to decide"; the owner's approval to destroy unused leftovers covers them. Before deletion each was grepped for across `templates/`, `src/`, `config/`, `public/assets/`, `cron/` and `bin/`: the only hits were the files naming each other (`celebrate.js` ↔ `ag-motion.js`, `celebrate.js` → canvas-confetti) and the attribute `data-ag-search-open` on the surviving header's search button, which is markup the script bound to, not a loader. Each entry is taken from the file at `HEAD` (`5b06988`).

## `public/assets/js/celebrate.js` (HEAD, 217 lines)

**Loaded by (at `a9d963a^`):** `partials/celebrate.twig` (destroyed as an orphan 3 Oct) and `pages/nominate-success.twig` directly (destroyed 3 Oct)

**What it did:** `window.agCelebrate({key, figure, anchor, kind, once})` and a declarative mount on the first `[data-celebrate]` element (`data-celebrate-figure`, `data-celebrate-anchor`, `data-celebrate-kind`). One engine and a table of beats for two kinds: `win` (two inward cannons from the lower corners at +240ms, then a wider fall from above the badge at +720ms) and `nominate` (stars fanning from the badge on two radii, about half the particles — "a nomination is not a win", so firing the winner's cannons for a nomination would tell somebody the award is theirs). An unknown kind falls back to `win`, so a typo in an attribute never silently removes the celebration. Particles on its own fixed, `aria-hidden`, `pointer-events:none` canvas, reset and removed after 3.2s. Origin measured at fire time, and placed ABOVE the badge, never on it (a clump over the word being celebrated is the one thing a photograph of the moment is of). The index count-up was delegated to `ag-motion.js`'s `window.agCount`, not a second counter. `confetti.create(canvas, {useWorker:false})` — the library's worker is a `blob:` URL that the CSP's `script-src` (no `worker-src`) refuses, logging a violation on every award page before falling back to the main thread anyway. Five hex literals (`#f3b416`, `#237b22`, `#7fc87c`, `#fffdf5`, `#10292c`) — none of them handoff tokens.

**DOM hooks:** `data-celebrate`, `data-celebrate-figure`, `data-celebrate-anchor`, `data-celebrate-kind`  
**Storage keys:** `localStorage 'ag-celebrated:' + key` (declared in `CookieRegistry::storage()`)  
**Globals exported:** `window.agCelebrate`; reads `window.confetti`, `window.agCount`  
**Reads prefers-reduced-motion:** yes

**Rebuild:** Phase 3 replaces it with the handoff's `celebration.js`/`.css` (GAPS §3.9, C10/Q7). The file is not owed back; its **refusal rules are — MUST RESTORE**, whatever engine ships:
- **No celebration on a held or delayed result.** The withheld page names nobody on purpose and the late-results holding page says nothing is decided; confetti on either announces an award the platform is refusing to announce. It was enforced by PLACEMENT — the loader sat inside the markup that names a winner, so a page with no winner block loaded no celebration. The rebuilt result pages must keep the trigger inside the winner branch (rules in `inventory/pages--results--show.md`, `pages--results--edition.md`).
- **Play once** per result per browser; a storage read that throws means "not seen yet" (it plays again), never "seen", and a write that throws is swallowed. The handoff engine's key is `ag-cel-…`, undeclared — `CookieRegistryTest` will fail on it by name until its row is written, and the `ag-celebrated:` row must then go (see the CookieRegistry note in DESTROYED.md).
- **Never instead of the page.** The name and index are the server's output, complete before any script; the engine may not unhide, paint or `innerHTML` the result.
- No sound (Permissions-Policy denies `autoplay`); honour `prefers-reduced-motion` with NO particles, on every burst; a pointer-transparent, `aria-hidden` stage that is removed afterwards; no `blob:` worker unless the CSP gains `worker-src`.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `CelebrationTest::test_the_script_reveals_nothing_and_therefore_cannot_withhold_it` — No `.hidden = false`, no `style.display`, no `innerHTML`; the count-up is `window.agCount(figure)`, with no `function countUp` and no `requestAnimationFrame` of its own; and `ag-motion.js` really exports `window.agCount = function`.
- `CelebrationTest::test_it_makes_no_sound` — None of `new Audio`, `AudioContext`, `.play(`, `<audio`.
- `CelebrationTest::test_reduced_motion_gets_the_result_and_no_particles` — Reads `prefers-reduced-motion`; returns before making a canvas when quiet or when `window.confetti` is missing; the number of `fire(` calls equals the number of `disableForReducedMotion` settings (counted against the bursts, not the literal spelling); the exported counter's first line refuses under reduced motion.
- `CelebrationTest::test_it_plays_once_per_result_and_forgetting_is_not_a_failure` — The key literal `'ag-celebrated:'`; `seen()`'s body (brace-matched, not regex-windowed) has a `catch` and `return false`; `remember()`'s has a `catch`.
- `CelebrationTest::test_the_canvas_is_taken_away_again` — `removeChild(canvas)`, `setAttribute('aria-hidden', 'true')`, `pointer-events:none`.
- `CelebrationTest::test_no_blob_worker_is_asked_for` — `useWorker: false`, never `useWorker: true`, and `src/Support/Csp.php` has no `worker-src` (the half that kept the script's comment true).
- `CelebrationTest::test_the_library_is_self_hosted_and_recorded` — see the canvas-confetti entry.

The four `CelebrationTest` methods on `MemberActivityService::backedWinners()` (the dashboard's "someone you backed won" query) test surviving service code and were kept.


---

## `public/assets/js/vendor/canvas-confetti-1.9.3.js` (HEAD, 887 lines)

**Loaded by (at `a9d963a^`):** `partials/celebrate.twig`, `pages/nominate-success.twig` (both destroyed 3 Oct)

**What it did:** `canvas-confetti@1.9.3`, `dist/confetti.browser.js`, ISC, byte-for-byte upstream and unminified (the package ships no minified build; 25 KB, deferred). Exported `window.confetti`. Its PROVENANCE.md row and its paragraph ("the unminified file … loaded on one page: the award result, and only where a winner is actually named") were removed with it.

**Rebuild:** Only if the Phase 3 engine needs a particle library — the handoff's `celebration.js` draws its own. If one comes back, it is vendored under this directory with a PROVENANCE row, never fetched from a CDN (`ThirdPartyScriptIntegrityTest`).

**Rules held by guard tests destroyed with it:**

- `CelebrationTest::test_the_library_is_self_hosted_and_recorded` — The vendored file contains `canvas-confetti v1.9.3` (the version its name claims), and `PROVENANCE.md` carries `canvas-confetti@1.9.3` — a vendored file with no provenance row cannot be reproduced or updated.


---

## `public/assets/js/community-modal.js` (HEAD, 81 lines)

**Loaded by (at `a9d963a^`):** `layout/gates.twig`, with the markup in `partials/community-modal.twig` (both destroyed 3 Oct)

**What it did:** `window.AGCommunity.open(context)/.close()` for the "Join our community" dialog (`#agCommunityModal`, `.agcm__dialog`): shown once per browser session after a successful vote or nomination; focus moved to the Join CTA, Tab trapped, focus restored on close; closes on Escape, backdrop, `[data-agcm-close]` and 150ms after `[data-agcm-join]`; body scroll locked while open; a `transitionend` hide with a 400ms failsafe; `AFG.trackFunnel('community_prompt_shown')` when present (`afg-features.js`, already destroyed).

**DOM hooks:** `data-agcm-close`, `data-agcm-join`  
**Element ids:** `#agCommunityModal`  
**Storage keys:** `sessionStorage 'ag_community_prompted'` — **never declared in `CookieRegistry`**. It was written through a variable (`var SESSION_KEY = …; setItem(SESSION_KEY, '1')`), and `CookieRegistryTest`'s storage sweep only reads a string LITERAL as the first argument, so the omission was invisible to the guard built to catch it. Destroyed, so nothing is written now; a rebuild that keeps a once-per-session guard declares the key and writes it as a literal.  
**Globals exported:** `window.AGCommunity`  
**Reads prefers-reduced-motion:** no

**Rebuild:** Optional (as `community-modal.twig`'s entry says). If rebuilt: a dialog after a completed act, never on arrival, once per session, with the focus contract above.

**Guard tests:** none read this file at the time of the destroy.


---

## `public/assets/js/ag-motion.js` (HEAD, 160 lines)

**Loaded by (at `a9d963a^`):** `layout/gates.twig`, `pages/vote-nominee.twig` (both destroyed 3 Oct); drove `css/motion.css` and `css/tokens.motion.css` (destroyed as orphans 3 Oct)

**What it did:** Page motion orchestration, opt-in: adds `.ag-motion` to `<html>` only when `IntersectionObserver` exists, and every rule in `motion.css` was scoped under that class — so a script that 404s, is blocked or throws leaves the page fully visible, never blank (the inverse of the usual hide-then-reveal shape, which turns a script failure into an unreadable ballot). Entrances: `[data-ag-reveal]`, `[data-ag-cascade]` (children indexed `--i`, capped at 12), `[data-ag-seal]` (cap 20), `[data-ag-fill]`; one observer, `rootMargin -12%`, unobserved after firing (entrances, not scroll-linked). Counters `[data-ag-count]`: read the number from the element's own text (correct with JS off), animate only when there is exactly ONE number group (a composite like "45 / 55" otherwise counts through "2733 / 55"), 900ms ease-out, land exactly on the original text; a counter inside an animated section is driven by that section's arrival, not its own (it otherwise finished counting behind an `opacity:0` parent). Exported `window.agCount(el)` for callers with their own clock (`celebrate.js`), guarded and reduced-motion-aware inside the function so a caller cannot forget either.

**DOM hooks:** `data-ag-reveal`, `data-ag-cascade`, `data-ag-seal`, `data-ag-fill`, `data-ag-count`  
**Custom properties written:** `--i`  
**Storage keys:** —  
**Globals exported:** `window.agCount`  
**Reads prefers-reduced-motion:** yes

**Rebuild:** With whichever phase brings scroll motion back (handoff §6.6 motion tokens). Keep: opt-in by a class the script adds; one counter implementation, exported; one-number-or-nothing; count from the server's own text and land on it exactly; drive a nested counter from its animated ancestor.

**Rules held by guard tests destroyed with it:**

- `CelebrationTest::test_the_script_reveals_nothing_and_therefore_cannot_withhold_it` (its ag-motion half) — `ag-motion.js` exports `window.agCount = function`.
- `CelebrationTest::test_reduced_motion_gets_the_result_and_no_particles` (its ag-motion half) — `window.agCount = function (el) { if (reduced` — the exported counter refuses under reduced motion before anything else.


---

## `public/assets/js/ag-search.js` (HEAD, 346 lines)

**Loaded by (at `a9d963a^`):** `layout/gates.twig` (destroyed 3 Oct), over the markup in `partials/site-search.twig` (destroyed as an orphan 3 Oct)

**What it did:** The site-wide search palette, as progressive enhancement over a real `GET /activity` form (with the script absent, Enter still searches). Opens from any `[data-ag-search-open]` (the surviving header's search button), from `/`, and from Cmd/Ctrl-K — never stealing the keystroke from an `INPUT`, `TEXTAREA`, `SELECT` or contenteditable. Combobox ARIA set FROM SCRIPT (`role=combobox`, `aria-expanded`, `aria-controls`, `aria-autocomplete=list`, `aria-haspopup=listbox`; the list's `role=listbox`), because markup claiming listbox behaviour with no script behind it is a lie to a screen reader. Active option moved by `aria-activedescendant`, never `focus()` (focus in the list loses the next keystroke); Up/Down/Home/End/Escape; Enter opens the active option or lets the form submit. Results are real anchors (middle-click works). Focus trapped while open, returned to the opener on close — and when the shortcut opened it from `<body>`, to the header's search button instead. Fetches `/activity/search?limit=24&q=…&scope=…` debounced 220ms; a sequence counter discards a stale response. **The chip → source map is NOT in the script**: it arrives with every response (`scopes`, from `ActivityFeedService::SCOPES`), so a second list cannot drift. Results grouped People / Awards / Events / Pages in chip order, ≤6 per GROUP (one crowded kind cannot push every other heading off), a kind the map does not name goes under "More" rather than being dropped, and `items` is rebuilt in drawn order so Down and Enter agree. Empty box = the latest feed under the same headings — there is no trending signal, and inventing one is the globe band's fault (GAPS §9.11). Live region: "Searching…", the count SHOWN (not the count received), "N recent items" for an empty query, "No matches in people for …" naming the chip, and an outage message pointing at Enter. Chips are a `tablist`: one tab stop, arrows move and re-run, focus returns to the box.

**DOM hooks:** `data-ag-search`, `data-ag-search-open`, `data-ag-search-close`, `data-ags-scope`  
**Element ids:** `#agsInput`, `#agsResults`, `#agsStatus`  
**Endpoints:** `/activity/search` (live), `/activity` (form fallback)  
**Storage keys:** —  
**Globals exported:** —  
**Reads prefers-reduced-motion:** no

**Rebuild:** **MUST RESTORE** — with `site-search.twig` (already MUST RESTORE): the surviving `partials/site-header.twig` renders the `data-ag-search-open` button with `aria-haspopup="dialog"` and it opens nothing. The **server-side scope rule** survives and is held by `SearchScopeTest`: every `ActivityFeedService::SOURCES` key is reachable from exactly one chip, the chips are the four `SCOPES` keys plus "All" (= no filter), an unknown scope widens rather than empties, a chip really narrows `collect()`, an organisation is findable under People, and the search endpoint delivers `SCOPES` with its results. The rebuilt client reads that map from the response and never carries its own copy.

**Rules held by guard tests destroyed with it:**

- `SearchScopeTest::test_the_chip_map_is_delivered_and_not_copied_into_the_javascript` **(guard kept, narrowed and renamed `test_the_chip_map_is_delivered_by_the_search_endpoint`)** — Its JS half: `ag-search.js` must not contain any `ActivityFeedService::SOURCES` key as a quoted literal — the mapping belongs on the server. The controller half (`ActivityController` delivers `ActivityFeedService::SCOPES`) tests surviving code and stays. The rebuilt palette re-asserts the JS half against its own file.


---

## `public/assets/js/ag-social.js` (HEAD, 355 lines)

**Loaded by (at `a9d963a^`):** `layout/gates.twig`, `pages/pulse.twig`, `pages/results/show.twig` (all destroyed 3 Oct)

**What it did:** `window.agSocial` — the one implementation of "react to a thing", framework-free (callers pass a plain state object it mutates), so the community thread and the Pulse could not drift into two rollback paths. Every mutation is a form POST to `/api/v1/community/*` with `X-Requested-With` (admitted by the CSRF middleware on same-origin + that header, so it works from a cached page). `cheer()` — optimistic boolean, rolled back on failure; `onSignIn` on 401/`SIGN_IN`; `onLiked` only on a transition to liked. `react()` — one of four reactions, SINGULAR, three optimistic outcomes (clear: −1; move: total UNCHANGED; set: +1), a kind at zero REMOVED from the breakdown rather than drawn as an empty pip. `toggle()` — save/repost/follow, the caller names the response field (`bookmarked`, `reposted`, `following`). `comment()` — resolves `{ok, id, status}` and the caller MUST honour `status: 'quarantined'` (showing it as live tells the author a post is public that no moderator has seen, on a platform with children in the audience). `report()`. `share()` — `navigator.share`, an `AbortError` is the person changing their mind and is not reported, otherwise clipboard (secure context only). `linkify()` — see the rules below. `timeAgo()` — "just now"/m/h/d, a date past a week; `"2026-08-01 00:16:03"` given a `T` and `Z` because Safari will not parse it; negative skew reads as "just now". `signInUrl()` carries `next`.

**Endpoints:** `/api/v1/community/cheer`, `/comment`, `/report` and the toggle paths (all live, `CommunityController`), `/account/login?next=`  
**Storage keys:** —  
**Globals exported:** `window.agSocial`  
**Reads prefers-reduced-motion:** no

**Rebuild:** **MUST RESTORE** with the Pulse and the result page's share (Phase that rebuilds `pages/pulse.twig`): the community API it called is live and has no browser client now. The **feed linkify URL rule** is owed exactly: escape FIRST, then match `\bhttps?://[^\s<>"']+` — http(s) only, never a bare `/path` (that links "and/or" and "12/06"); trailing `.,;:!?)` left OUTSIDE the link (a URL ending a sentence otherwise 404s while looking right); the URL pass runs BEFORE the `@mention` and `#hashtag` passes (which insert `href="/registry?q=…"`/`/activity?q=…` and would be matched from inside); `rel="nofollow ugc noopener"`. The result announcement's last line is the absolute URL of the full standing, and that post exists to carry people there.

**Rules held by guard tests destroyed with it** — `FeedLinkifyTest`, the whole file (7 methods; it read only this script, lifting `URL_RE` out of it into PCRE):

- `test_an_ordinary_link_is_matched` — `https://…/results/12-primary-school-principal`, `http://example.test`, a query with `&amp;`, and a `#fragment` each match inside surrounding text.
- `test_a_bare_path_is_not_linkified` — `and/or`, `on 12/06 at noon`, `/results/12`, `w/ friends` do not match.
- `test_no_other_scheme_can_reach_the_href` — `javascript:`, `data:`, `vbscript:`, `file:` do not match.
- `test_the_match_can_never_contain_a_quote_or_a_tag` — matching `https://example.test/a" onmouseover="alert(1)` captures no `"`, `'`, `<` or `>` (independent of escaping, which a reorder could remove).
- `test_trailing_punctuation_is_left_outside_the_link` — the trim `url.match(/[.,;:!?)]+$/)` is present.
- `test_urls_are_linkified_before_mentions_and_hashtags` — inside `linkify()`, `URL_RE` appears before the `@(` and `#(` patterns.
- `test_the_text_is_escaped_before_anything_is_linkified` — `escapeHtml(text)` exists in `linkify()` (asserted as an int FIRST: with it deleted, `strpos` is `false`, compares as 0 and the ordering assertion passes — caught by mutation) and precedes `URL_RE`.
