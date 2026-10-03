# Destroyed stylesheets — inventory (pre-patch, `a9d963a^`; chrome.css / library.css as created in `a9d963a`)

For each: size, who linked it, the selector families it styled, the behaviours it carried that are not just looks (reduced motion, coarse-pointer targets, print, RTL, focus), the custom properties it declared, and the guard tests that read it.

## `public/assets/css/base/typography.css` (a9d963a^, 77 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════ Africa GATES — Base typography (new system; scoped to .ag-scope so legacy pages are untouched until migrated). Playfair display + DM Sans body + JetBrains mono labels, per the design. ════════════════════════════════════════════════════════════════  
**Selector families (count of rules):** `.ag-scope`×7, `.ag-figure`×5, `.ag-display`×2, `.ag-accent`×1, `.ag-eyebrow`×1, `.ag-lead`×1, `.ag-mono`×1, `.ag-count`×1  
**Non-cosmetic behaviours:** —  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/account-auth.css` (a9d963a^, 348 lines)

**Linked by:** `templates/layout/account-auth.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════════════════ Africa GATES — member account auth (component) The three PUBLIC account screens: /account/login, /account/register and the email-verification notice. One centred column on a warm ground, one white card, no split-screen brand panel. ── WHY THIS IS NOT `auth.css` ────────────────────────────────────────────── `components/au  
**Selector families (count of rules):** `.ag-acct`×85, `.is-boxed`×2  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×6, @keyframes×1  
**Custom properties declared:** `--ac-50`, `--ac-100`, `--ac-150`, `--ac-200`, `--ac-ink`, `--ac-ink-soft`, `--ac-ink-faint`, `--ac-line`, `--ac-line-firm`, `--ac-green`, `--ac-green-dark`, `--ac-err`, `--ac-err-line`, `--ac-err-fill`, `--ac-r`, `--ac-r-xl`  
**Guard tests that name it:** `AccountAuthScreensTest`

## `public/assets/css/components/account.css` (a9d963a^, 380 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ══ /account — the page's own layer, and ONLY that ══════════════════════════════ ═══════════════════════════════════════════════════════════════════════════════ Everything this page shares with any other page lives in `components.css` and is composed through `partials/ui.twig`. What is left here is the LAYOUT MODEL and the compositions that exist nowhere else: the rail, the phone hub, the balance   
**Selector families (count of rules):** `.me-rail`×15, `.me-ph`×14, `.me-bal`×10, `.me-quick`×8, `.me-sec`×7, `.me-eye`×6, `.me-saved`×6, `.me-setup`×5, `.me-find`×4, `.me`×3, `.me-redeem`×3, `.me-dots`×2, `.me-amt`×2, `.me-body`×1, `.me-pane`×1, `.me-led`×1, `.me-buy`×1, `.me-act`×1, `.me-top`×1, `.me-ov`×1, `.me-split`×1, `.me-set`×1, `.me-cards`×1, `.me-col`×1, `.me-searching`×1, `.me-cash`×1, `.me-won`×1, `.me-chart`×1, `.me-go`×1, `.me-ch`×1, `.pb`×1, `.me-more`×1, `.ag-chip`×1  
**Non-cosmetic behaviours:** @media print×1, :focus-visible×4, position:sticky×1  
**Custom properties declared:** `--me-cols`, `--me-pad`, `--me-gap`, `--me-rail-gap`, `--me-led`, `--me-buy`, `--me-act`, `--me-saved`, `--me-cards`, `--me-split`, `--me-set`, `--me-top`, `--me-ov`, `--me-bal-pad`, `--me-bal-size`, `--me-saved-dir`, `--me-saved-w`, `--me-saved-ratio`, `--me-saved-pad`, `--me-chart-h`, `--ag-cols`, `--ag-tile`  
**Guard tests that name it:** `AccountDashboardTest`, `AccountTabsTest`

## `public/assets/css/components/article.css` (a9d963a^, 417 lines)

**Linked by:** `templates/partials/article.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ ARTICLE — the shared document layout ══════════════════════════════════════════════════════════════════════════════ Four pages present a document a reader is expected to quote, keep or cite: /philosophy, /integrity, /terms and /privacy. They share this sheet. IT LIVES IN A FILE, NOT IN FOUR `head_styles` BLOCKS. The fir  
**Selector families (count of rules):** `.ar-rich`×28, `.ar-body`×19, `.ar-rail`×17, `.ar-toc`×15, `.ar-cols`×11, `.ar-table`×10, `.ar-part`×7, `.ar-refs`×7, `.ar-cite`×7, `.ar-note`×6, `.ar-next`×6, `.ar-tool`×5, `.ar-tools`×4, `.ar-sec`×4, `.ar-shell`×3, `.ar-meta`×3, `.ar-list`×3, `.ar-steps`×3, `.ar-quote`×3, `.ar-aside`×3, `.ar-tab`×3, `.ar-say`×2, `.ar-tabs`×2, `.ar-end`×2, `.ar-eyebrow`×1, `.ar-h1`×1, `.ar-sub`×1, `.ar-stand`×1, `.ar-fmt`×1, `.ar-sn`×1, `.ar-h4`×1, `.ar-tw`×1  
**Non-cosmetic behaviours:** @media print×1, position:sticky×1  
**Custom properties declared:** `--ar-ground`, `--ar-surface`, `--ar-ink`, `--ar-soft`, `--ar-faint`, `--ar-line`, `--ar-line-soft`, `--ar-accent`, `--ar-accent-dark`, `--ar-wash`, `--ar-gold`, `--ar-shadow`, `--ar-measure`  
**Guard tests that name it:** `AccessibilityFloorTest`, `ColourBudgetTest`

## `public/assets/css/components/challenge.css` (a9d963a^, 290 lines)

**Linked by:** `templates/layout/gates.twig`, `templates/pages/nominate-award.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ CHALLENGES — the theme, /challenges/{slug}, the list, and the Pulse cards ══════════════════════════════════════════════════════════════════════════════ What is HERE is only what a challenge has and nothing else does: its theme, its hero and its prize rail. The steps, the rules, the questions, the rows and the meter are  
**Selector families (count of rules):** `.mc`×22, `.ch-hero`×19, `.chl`×18, `.ch-rail`×12, `.ch-winners`×11, `.ch-strip`×10, `.ch-state`×5, `.ch-host`×5, `.ch-body`×4, `.ch-meter`×4, `.ch-flag`×3, `.ch-more`×3, `.ch-bar`×3, `.ch-sec`×2, `.ch-mine`×2, `.ch-cta`×2, `.ch`×1, `.ch-main`×1, `.ch-h2`×1, `.ch-terms`×1  
**Non-cosmetic behaviours:** :focus-visible×2, position:sticky×1  
**Custom properties declared:** `--ch-fill`, `--ch-edge`, `--ch-wash`, `--ch-solid`, `--ch-line`, `--ag-step-fill`, `--ag-meter-fill`, `--ag-notice-wash`, `--ag-notice-ink`  
**Guard tests that name it:** `ChallengeFlierTest`, `ChallengePageTest`

## `public/assets/css/components/chrome.css` (a9d963a, 637 lines)

**Linked by:** `templates/layout/gates.twig`, `templates/layout/shell.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ SHARED CHROME — app bar, tab bar, action bar, Menu, Quick settings, Display & reading, the language prompt, the site header, the announcement strip Owned by Phase 2 (design_handoff_africa_gates/phases/PHASE-2-chrome.md) ══════════════════════════════════════════════════════════════════════════════ MOVED HERE UNCHANGED,   
**Selector families (count of rules):** `.ag-menu`×33, `.ag-appbar`×17, `.ag-dr`×16, `.ag-head`×16, `.ag-qs`×14, `.ag-mega`×9, `.ag-pop`×8, `.ag-tools`×7, `.ag-tabbar`×5, `.ag-actionbar`×5, `.ag-logo`×5, `.ag-tint`×4, `.ag-langask`×4, `.ag-announce`×4, `.ag-shell`×3, `.ag-btn`×3, `.is-open`×2, `.ag-shelled`×1, `.ag-sheet`×1, `.ag-seg`×1, `.ag-icon`×1, `.ag-ico`×1  
**Non-cosmetic behaviours:** RTL ([dir=rtl])×1, position:sticky×1, safe-area-inset×2  
**Custom properties declared:** `--quiet`, `--ag-header-h`  
**Guard tests that name it:** `ShellLayoutTest`, `DevUiTest`, `SiteHeaderTest`

## `public/assets/css/components/community-modal.css` (a9d963a^, 86 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════ Africa GATES — "Join our community" modal Shown after a successful vote / nomination. Self-contained and namespaced (.agcm*) so it is independent of the legacy .modal CSS being retired in the redesign. Uses base/tokens.css variables with safe fallbacks so it renders even if tokens haven't loaded. ═════════════════════════════════════  
**Selector families (count of rules):** `.agcm`×21  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×1  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/find.css` (a9d963a^, 148 lines)

**Linked by:** `templates/partials/find-band.twig`  
**Its own header:** ═══════════════════════════════════════════════════════════════════════════ "WHO ARE YOU LOOKING FOR?" — the find band ═══════════════════════════════════════════════════════════════════════════ See partials/find-band.twig for why this is a door onto the existing search rather than a second search. ── NO COLOUR, AND THAT IS THE BUDGET ───────────────────────────────────── Paper, ink, a hairline an  
**Selector families (count of rules):** `.fb`×20  
**Non-cosmetic behaviours:** :focus-visible×3  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/flash.css` (a9d963a^, 47 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════ Africa GATES — Flash messages on the PUBLIC layout ---------------------------------------------------------------- The admin and judge layouts have always rendered flash messages. layout/gates.twig did not, so every public redirect that set one ("applications for this event are closed", "sign in first") sent the visitor to a page th  
**Selector families (count of rules):** `.ag-flash`×9  
**Non-cosmetic behaviours:** —  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/footer.css` (a9d963a^, 113 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════ Africa GATES — Footer (component) v3 design: dark teal surface (#0c2225), brand + 3 link columns, bottom bar with copyright · circular socials · legal links. Layout chrome — styled globally off :root tokens (not .ag-scope). ════════════════════════════════════════════════════════════════  
**Selector families (count of rules):** `.ag-foot`×33  
**Non-cosmetic behaviours:** :focus-visible×3, safe-area-inset×1  
**Custom properties declared:** —  
**Guard tests that name it:** `AssetBundleTest`

## `public/assets/css/components/forms.css` (a9d963a^, 123 lines)

**Linked by:** `templates/layout/gates.twig`, `templates/layout/shell.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ FORMS · the error state, and the three ways it used to fail silently ══════════════════════════════════════════════════════════════════════════════ `.ag-field[data-invalid]` and `.ag-err` were already in `components.css` and were set by exactly one file in the tree: `dev-ui.twig`, the style gallery. A styled state no fo  
**Selector families (count of rules):** `.ag-formsum`×9, `.ag-field`×4, `.ag-label`×3, `.ag-count`×3, `.ag-err`×2, `.ag-fieldset`×1, `.ag-hint`×1, `.ag-btn`×1  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×2, @keyframes×1  
**Custom properties declared:** —  
**Guard tests that name it:** `AssetBundleTest`, `FormErrorStateTest`

## `public/assets/css/components/gee.css` (a9d963a^, 303 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════════ Gee — the Africa GATES guide A page-aware assistant: a circular launcher (FAB) + a resizable panel that becomes a draggable bottom-sheet on mobile. Sitewide chrome. Everything is scoped under .gee / .gee-*; the two cross-file rules that re-stack the legacy floats are marked. ═══════════════════════════════════════════════════════  
**Selector families (count of rules):** `.gee-fab`×17, `.gee`×10, `.gee-msg`×9, `.gee-head`×7, `.gee-hand`×7, `.gee-bubble`×6, `.gee-art`×6, `.gee-send`×6, `.gee-panel`×5, `.gee-typing`×5, `.gee-resize`×4, `.gee-icon`×4, `.gee-log`×4, `.gee-used`×4, `.gee-chip`×4, `.gee-avatar`×3, `.gee-arts`×3, `.gee-input`×3, `.community-fab`×2, `.gee-suggest`×2, `.gee-foot`×2, `.gee-scrim`×2, `.gee-form`×1, `.gee-locked`×1  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×8, @keyframes×5, safe-area-inset×4  
**Custom properties declared:** —  
**Guard tests that name it:** `AssetsTest`

## `public/assets/css/components/help-nav.css` (a9d963a^, 147 lines)

**Linked by:** `templates/partials/help-nav.twig`  
**Its own header:** ═══════════════════════════════════════════════════════════════════════════ THE HELP SURFACE'S MASTHEAD ═══════════════════════════════════════════════════════════════════════════ One identity, one search field, one row of tabs — on every help surface, so the three things a stuck person can do are always all three and always in the same place. See partials/help-nav.twig for why that had to change.  
**Selector families (count of rules):** `.hn`×22  
**Non-cosmetic behaviours:** :focus-visible×4  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/library.css` (a9d963a, 406 lines)

**Linked by:** `templates/layout/gates.twig`, `templates/layout/shell.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ THE RECORDS LIBRARY — pill, table, cell, facts, meter, avatar, page head, steps, ticks, notice, FAQ, date, link card, form stack, disclosure, sub-nav Read by partials/ui.twig and the account, challenge and award pages ══════════════════════════════════════════════════════════════════════════════ MOVED HERE UNCHANGED, TO  
**Selector families (count of rules):** `.ag-facts`×15, `.ag-tbl`×14, `.ag-steps`×12, `.ag-meter`×9, `.ag-faq`×8, `.ag-pill`×7, `.ag-cell`×7, `.ag-field`×7, `.ag-disc`×6, `.ag-subnav`×6, `.ag-pagehead`×5, `.ag-notice`×5, `.ag-ticks`×4, `.ag-form`×4, `.ag-date`×3, `.ag-lead`×2, `.ag-group`×2, `.ag-inline`×2, `.ag-card`×2, `.ag-tint`×1, `.ag-avatar`×1, `.ag-empty`×1, `.ag-h2`×1, `.ag-between`×1  
**Non-cosmetic behaviours:** :focus-visible×4, position:sticky×1  
**Custom properties declared:** `--live`, `--ag-cols`, `--ag-dcell`, `--ag-mcell`, `--ag-tile`, `--link`  
**Guard tests that name it:** `ShellLayoutTest`, `DevUiTest`

## `public/assets/css/components/lip.css` (a9d963a^, 80 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ═══════════════════════════════════════════════════════════════════════════ THE LIP — the one way this platform shows depth ═══════════════════════════════════════════════════════════════════════════ A hard offset bottom border in a solid darker shade of the element's own colour. Never a blurred drop shadow: there are no shadows anywhere on this site, and the 118 that were here have been removed.   
**Selector families (count of rules):** `.btn`×6, `.ag-register`×5, `.ag-lip`×4, `.ag-signin`×1  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, pointer:coarse×1  
**Custom properties declared:** `--primary`  
**Guard tests that name it:** —

## `public/assets/css/components/loader.css` (a9d963a^, 29 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** Africa GATES — entrance loader v5 (replaces components/loader.css) Total 1.36s. Brand-green disc (#006432, from logo-mark.png), light-green continent, "Africa" in Playfair ink, tracked green "GATES", 2px hairline. 0.00–0.36 disc opens · 0.16–0.50 continent rises · 0.28–0.62 Africa · 0.34–0.68 GATES 0.20–0.92 hairline · 1.04–1.28 mark lifts and fades · 1.12–1.36 panel fades  
**Selector families (count of rules):** `.ag-loader`×17, `.ag-loading`×2  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, @keyframes×5  
**Custom properties declared:** —  
**Guard tests that name it:** `SplashScreenTest`

## `public/assets/css/components/nominate.css` (a9d963a^, 578 lines)

**Linked by:** `templates/pages/nominate-award.twig`, `templates/pages/nominate-success.twig`, `templates/pages/nominate.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ THE NOMINATION FLOW design/NominationFlow.dc.html · phase §8.16 ══════════════════════════════════════════════════════════════════════════════ ONE PAGE'S STYLES, IN ONE PAGE'S SHEET. These first went into `components.css`, which is wrong in a way that only shows up later: that file is the BASE layer — buttons, chips, fi  
**Selector families (count of rules):** `.nf`×95, `.ns`×23, `.nh`×10, `.ag-main`×1  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×4, @keyframes×1, safe-area-inset×1  
**Custom properties declared:** —  
**Guard tests that name it:** `ShorthandOverridesTest`

## `public/assets/css/components/nominee-confirm.css` (a9d963a^, 82 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ /n/confirm/{token} — the one screen a nominee ever sees ══════════════════════════════════════════════════════════════════════════════ Opened from an SMS, by somebody not signed in, who did not ask to be contacted. It is a single card on the paper ground with no chrome, because there is nothing else here for them to do   
**Selector families (count of rules):** `.nc`×21  
**Non-cosmetic behaviours:** :focus-visible×1  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/pulse-immersive.css` (a9d963a^, 135 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════════════════ Pulse on a phone ════════════════════════════════════════════════════════════════════════════ ── WHAT THIS FILE USED TO BE, AND WHY IT IS NOT THAT ANY MORE ─────────────── It was "the immersive mobile feed": the timeline taken out of flow with `position:fixed`, every post exactly `100dvh` tall, `scroll-snap-stop:always`,   
**Selector families (count of rules):** `.pf`×5, `.pl-grid`×2, `.pl-tabs`×2, `.ag-announce`×1, `.announcement`×1, `.pl-hero`×1, `.pl-rail`×1, `.pf-chips`×1, `.pl-feed`×1, `.pf-end`×1, `.pf-more`×1, `.pf-toast`×1, `.pf-new`×1  
**Non-cosmetic behaviours:** position:sticky×1, safe-area-inset×1  
**Custom properties declared:** —  
**Guard tests that name it:** `PulseTimelineTest`

## `public/assets/css/components/site-search.css` (a9d963a^, 124 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════ Site-wide search overlay. Two rules do most of the work here and both are accessibility, not styling: • `.sr-only` is clip-not-hidden. `display:none` removes an element from the accessibility tree entirely, so a visually-hidden label written that way is a label no screen reader ever reads. • Every interactive thing keeps a   
**Selector families (count of rules):** `.ags`×41  
**Non-cosmetic behaviours:** :focus-visible×3, forced-colors×1  
**Custom properties declared:** —  
**Guard tests that name it:** —

## `public/assets/css/components/vote-countdown.css` (a9d963a^, 72 lines)

**Linked by:** `templates/layout/gates.twig`  
**Its own header:** ══════════════════════════════════════════════════════════════════════════════ VOTE COUNTDOWN ══════════════════════════════════════════════════════════════════════════════ These rules lived in `components/nav.css` and had nothing to do with the navigation. That file was the redesign's first casualty — its whole nav half (`.ag-nav`, `.ag-mobnav`, `.ag-link`, `.ag-brand` and a full-screen `.ag-menu  
**Selector families (count of rules):** `.vc`×17  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, @keyframes×1  
**Custom properties declared:** —  
**Guard tests that name it:** `VoteCountdownTest`, `SiteHeaderTest`

## `public/assets/css/globe-band.css` (a9d963a^, 219 lines)

**Linked by:** `templates/pages/home.twig`, `templates/partials/globe-band.twig`  
**Its own header:** ════════════════════════════════════════════════════════════════ Africa GATES — "We are Africa" globe band (component) Pairs with templates/partials/globe-band.twig and js/globe-band.js. Tokens come from base/tokens.css (--ag-*). See docs/GLOBE-BAND.md. ── WHAT CHANGED FROM THE HANDOFF COPY, AND WHY ────────────────── Every colour literal in the delivered file is resolved to a token here, per the   
**Selector families (count of rules):** `.reg`×28, `.node`×15, `.ccard`×13, `.crow`×4  
**Non-cosmetic behaviours:** prefers-reduced-motion×1, :focus-visible×3  
**Custom properties declared:** `--reg-guide`, `--reg-hair`, `--reg-note`, `--reg-halo`, `--reg-cols-bg`, `--reg-lb-bg`  
**Guard tests that name it:** `GlobeBandTest`

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccessibilityFloorTest::test_a_policy_table_becomes_readable_records_on_a_phone` `[css/components/article.css]` — the stacked rows carry no column labels, so a value has nothing naming it
- `AccountAuthScreensTest::test_a_row_marked_hidden_is_actually_hidden` `[css/components/account-auth.css]` — a `hidden` element inside .ag-acct is overridden by the component\u{2019}s own display rules — *the passkey row is offered before anything has checked this browser can use it*
- `AccountAuthScreensTest::test_the_real_code_field_is_visible_until_the_script_paints_the_boxes` `[css/components/account-auth.css]` — the decorative boxes are shown before anything can paint them — *the real input must be hidden by the painter, not by the stylesheet*
- `DevUiTest::test_the_carved_out_rules_are_loaded_where_they_used_to_be` `[css/components/chrome.css]` — The carved out rules are loaded where they used to be.
- `FormErrorStateTest::test_the_invalid_field_is_not_signalled_by_colour_alone` `[css/components/forms.css]` — The CSS must not express the invalid state by colour alone. — *the invalid field changes only its border colour*
- `SiteHeaderTest::test_the_full_screen_overlay_menu_is_gone` `[css/components/vote-countdown.css]` — the countdown rules lived in that file and must not have gone with it
- `SiteHeaderTest::test_the_hairline_is_dropped_while_a_panel_is_open` `[css/components/chrome.css]` — The hairline is dropped while a panel is open.
- `SiteHeaderTest::test_there_is_no_green_button_in_either_signed_in_state` `[css/components/chrome.css]` — the signed-out control has no rule — *the signed-out control is green; §6.1 keeps green for the primary action INSIDE the page*
- `FormErrorStateTest::test_every_layout_carrying_a_validated_form_loads_the_validator (kept)` **(guard kept, edited)** `[css/components/forms.css]` — A page with `data-ag-validate` must sit on a layout loading form-validate.js AND components/forms.css. forms.css is destroyed and shell.twig no longer links it, so the first rebuilt validated form fails here until forms.css is rebuilt.
