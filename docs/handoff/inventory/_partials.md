# Destroyed partials — feature inventory (pre-patch, `a9d963a^`)


---

One section per partial. Includers are listed so the rebuild knows where each feature surfaced.


---

## `templates/partials/article.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/integrity.twig`, `templates/pages/legal.twig`, `templates/pages/philosophy.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  ARTICLE FURNITURE — the parts every published document shares
  ══════════════════════════════════════════════════════════════════════════════

  Four pages present a citable document: /philosophy, /integrity, /terms and
  /privacy. The masthead, the Copy/Download/Cite toolbar, the contents and the
  citation panel are identical on all four, and the toolbar and citation panel in
  particular are substantial markup with real accessibility obligations — a
  tablist with a roving tabindex, a popover that closes on Escape, a live region.

  Copied four times, three of those copies eventually lose the Escape handler.
  So they live here as macros and the pages pass their own metadata in.

  ── CALLING CONVENTION ──────────────────────────────────────────────────────
…
```

**Headings:** {{ d.title }} · Contents · How to cite this document

**Data read (top-level variables/functions):** `it`, `li`, `nonce`, `portable`, `assets`, `css`, `components`, `article`, `wide`, `rail`, `toc`, `groups`, `list`

**States / branches (16 distinct conditions):** `d.subtitle is defined and d.subtitle` · `d.terms_url is defined and d.terms_url` · `d.standfirst is defined and d.standfirst` · `d.version is defined and d.version` · `d.read_minutes is defined and d.read_minutes` · `portable` · `g.label` · `block.p is defined` · `block.h3 is defined` · `block.quote is defined` · `block.list is defined` · `block.steps is defined` · `block.note is defined` · `d.version` · `d.portable is not defined or d.portable` · `d.portable_note is defined and d.portable_note`

**Links out:** `{{ d.md_url }}` · `{{ d.txt_url }}` · `#cite` · `#{{ it.id }}` · `{{ d.url }}`

**JS behaviours:** 2 inline <script> block(s)

**Accessibility affordances:** aria-hidden×8, aria-label×2, aria-live×1, aria-controls×1, aria-selected×1, aria-labelledby×1; roles: status, tablist, tab, tabpanel; tabindex×1

**Legal / consent lines:**
- {% if d.terms_url is defined and d.terms_url %}"license":{{ d.terms_url|json_encode|raw }},{% endif %}

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/article.css

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route


---

## `templates/partials/cookie-choice.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/legal.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  THE CHOICE — the switch that /cookies promised and did not have
  ══════════════════════════════════════════════════════════════════════════════

  For years the only way to refuse arrival counting on this site was to send `DNT`
  or `Sec-GPC`. Chrome removed the Do Not Track setting and Safari removed it before
  that, so for most of this platform's visitors — Chrome on an Android phone — there
  was no way to say no at all, while the same page promised "you will be asked
  before it runs".

  So this is the part that has to work with nothing else: no JavaScript, no
  Alpine, no fetch. It is a plain form with two submit buttons and it posts. A
  privacy control that needs scripts to run is a privacy control that is missing
  for exactly the people most likely to have switched scripts off.
…
```

**Headings:** {{ c.headline }}

**Data read (top-level variables/functions):** `this`, `visit`, `counting`, `cookie_control`

**Forms:**
- `POST /cookies/choice` fields: _token[hidden]; buttons: Do not count my visits | Count my visits

**States / branches (3 distinct conditions):** `c.saved` · `c.locked` · `c.counting`

**Links out:** `#counting-arrivals`

**Accessibility affordances:** aria-labelledby×1; roles: status

**Legal / consent lines:**
- {% set c = cookie_control %}
- A privacy control that is loud reads as one that is selling something. The
- Remembering your answer needs one cookie of its own, {{ c.cookie_name }}. It holds a

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CookieChoiceScreenTest` (whole file, via a helper/constant/data provider: 11 tests) — test_the_panel_offers_the_answer_the_visitor_does_not_already_have, test_the_panel_offers_nothing_when_the_browser_has_already_refused, test_the_panel_carries_a_csrf_token_and_posts, test_the_panel_names_the_cookie_that_stores_the_answer, test_the_panel_confirms_a_press, test_the_notice_gives_both_answers_the_same_weight, test_the_notice_cannot_be_dismissed_without_answering, test_the_notice_carries_the_page_it_was_drawn_on, test_neither_screen_needs_javascript, test_neither_screen_leaks_the_choice_to_the_page, test_the_notice_is_not_a_modal


---

## `templates/partials/cookie-notice.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/layout/gates.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  THE NOTICE — shown only when the operator has chosen to ask first
  ══════════════════════════════════════════════════════════════════════════════

  Drawn only when {@see CookiePrefs::mustAsk()} is true, which means all four of:
  the operator set `visits_consent_mode` to `consent`, the tracker is switched on,
  the visitor has not already answered, and their browser has not answered for
  them. That last condition matters most — asking somebody to agree to something we
  have already decided not to do is a dark pattern, and it is one whether or not
  anybody intended it.

  ── WHY THE OPERATOR'S SETTING NEEDED A BANNER TO GO WITH IT ────────────────

  Because a `consent` mode with nowhere to consent is a switch that silently
…
```

**Data read (top-level variables/functions):** `cookie_return`

**Forms:**
- `POST /cookies/choice` fields: _token[hidden], return[hidden]; buttons: Yes, count it | No, thank you

**Links out:** `/cookies#counting-arrivals`

**Accessibility affordances:** aria-labelledby×1; roles: region

**Legal / consent lines:**
- them. "Accept is a button and decline is grey text" is the design the consent
- rules exist to stop, and CookieChoiceScreenTest asserts the class strings match. */
- What exactly is recorded.

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccessibilityFloorTest::test_the_cookie_notice_cannot_hide_what_the_keyboard_is_on` — (no docblock)
- `CookieChoiceScreenTest` (whole file, via a helper/constant/data provider: 11 tests) — test_the_panel_offers_the_answer_the_visitor_does_not_already_have, test_the_panel_offers_nothing_when_the_browser_has_already_refused, test_the_panel_carries_a_csrf_token_and_posts, test_the_panel_names_the_cookie_that_stores_the_answer, test_the_panel_confirms_a_press, test_the_notice_gives_both_answers_the_same_weight, test_the_notice_cannot_be_dismissed_without_answering, test_the_notice_carries_the_page_it_was_drawn_on, test_neither_screen_needs_javascript, test_neither_screen_leaks_the_choice_to_the_page, test_the_notice_is_not_a_modal


---

## `templates/partials/find-band.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/activity.twig`, `templates/pages/home.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  "WHO ARE YOU LOOKING FOR?" — the front door to the search that already exists
  ══════════════════════════════════════════════════════════════════════════════

  ── WHY THIS IS A BAND AND NOT A SEARCH PAGE ────────────────────────────────

  The obvious build for this design was a new `/search` with its own service. It would
  have been the same fault this repository has just spent a commit fixing on `/help` and
  `/support`: two front doors to one job, each unable to say the other exists.

  `ActivityFeedService` already searches announced results, categories, programmes,
  nominees, registry profiles, posts, events, discussions, cycle phases and the site's
  own pages. What it did NOT have was an entrance framed around the question people
  actually arrive with. It is called "Activity", it is filed under a heading about what
…
```

**Headings:** {{ heading|default('Who are you looking for?') }}

**Data read (top-level variables/functions):** `min_query`, `heading`, `are`, `you`, `looking`, `noun`, `live`, `_covers`, `assets`, `css`, `components`, `find`, `placeholder`, `name`, `an`, `award`, `category`, `school`, `compact`, `search_covers`

**Forms:**
- `GET /activity` fields: q[search autocomplete]; buttons: Search

**States / branches (5 distinct conditions):** `compact|default(false)` · `live|default(false)` · `min_query|default(0)` · `not loop.last` · `loop.last`

**Accessibility affordances:** aria-labelledby×1, aria-hidden×1, aria-describedby×1; roles: search; visually-hidden text×1; <label for>×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/find.css

**Guard tests that read this page (at the time of the destroy):**
- `ActivityPageAccessibilityTest::test_the_input_has_a_real_label_not_only_a_placeholder` — (no docblock)
- `FindBandTest` (whole file, via a helper/constant/data provider: 10 tests) — test_every_named_source_appears_in_the_sentence, test_the_sentence_is_not_typed_into_the_template, test_a_source_with_no_public_noun_is_still_covered_by_the_sentence, test_the_promise_is_printed, test_the_promise_has_a_test_behind_it, test_the_live_search_hooks_survive_on_the_search_surface, test_the_hooks_are_opt_in_so_they_do_not_appear_where_no_script_reads_them, test_the_field_posts_to_the_search_that_already_exists, test_no_other_search_entrance_enumerates_the_sources, test_the_field_carries_a_hidden_label


---

## `templates/partials/globe-band.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/home.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════
   Africa GATES — "We are Africa" band (globe)
   Sits directly after the hero on the homepage. Light band, dashed
   background guides, centred globe, dashed annotation card at its right,
   and the stat card overlapping the sphere's lower edge.

   Requires (see docs/GLOBE-BAND.md):
     <link rel="stylesheet" href="/assets/css/globe-band.css">
     <script src="/assets/js/vendor/d3-7.9.0.min.js"></script>
     <script src="/assets/js/vendor/topojson-client-3.1.0.min.js"></script>
     <script src="/assets/js/globe-band.js" defer></script>

   ── EVERY FIGURE ON IT IS COUNTED, NONE IS TYPED ────────────────────────

…
```

**Headings:** {{ globe_heading|default('We are Africa') }}

**Data read (top-level variables/functions):** `site_stats`, `jury_criteria`, `nations_count`, `_activity`, `globe_heading`, `are`, `globe_countries`, `globe_topojson`, `assets`, `geo`, `countries`, `globe_note`, `marker`, `nation`, `nominee`, `standing`, `live`, `award`, `community_pct`, `judge_pct`

**States / branches (1 distinct conditions):** `_activity > 0`

**JS behaviours:** data hooks: `data-countries`, `data-topojson`, `data-countup`

**Accessibility affordances:** aria-label×2, aria-hidden×1; roles: dialog

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `GlobeBandTest::test_the_band_is_on_the_homepage_with_its_assets_and_the_duplicate_strip_is_gone` — A COMPONENT WITH NO INCLUDE IS §18 AGAIN — every piece complete, nothing serving it.


---

## `templates/partials/help-nav.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/help-article.twig`, `templates/pages/help-category.twig`, `templates/pages/help.twig`, `templates/pages/support-assistant.twig`, `templates/pages/support-tickets.twig`, `templates/pages/support.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  THE HELP SURFACE'S MASTHEAD — one identity, one search, one row of tabs
  ══════════════════════════════════════════════════════════════════════════════

  ── THE FAULT THIS EXISTS TO END ────────────────────────────────────────────

  There were two front doors to the same job. `/help` opened with "What has gone
  wrong?" over a search field; `/support` opened with "We're here to help" over
  four cards, the first of which was "Search the Help Centre → Browse the
  answers" — which lands on `/help`. A stuck person hit a router that routed them
  to another router, and neither page could tell them the other one existed.

  They are one surface now, with the three things a person can actually do side by
  side: read an answer, ask the assistant to fix it, or reach a person. Whichever
…
```

**Data read (top-level variables/functions):** `_aud`, `_tab`, `_auds`, `search_model`, `assets`, `css`, `components`, `help`, `nav`, `search_placeholder`, `your`, `problem`, `tab`, `audience`, `help_audiences`

**Forms:**
- `GET /help` fields: q[search autocomplete], for[hidden]; buttons: Search

**States / branches (6 distinct conditions):** `_aud and _auds[_aud] is defined` · `search_model|default('')` · `_aud` · `_tab == 'answers'` · `_tab == 'assistant'` · `_tab == 'contact'`

**Links out:** `/help` · `/help{{ _aud ? '?for=' ~ _aud : '' }}` · `/support/assistant` · `/support`

**Accessibility affordances:** aria-current×3, aria-hidden×2, aria-label×1; roles: search; visually-hidden text×1; <label for>×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/help-nav.css

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route


---

## `templates/partials/shop-cart.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/shop/index.twig`, `templates/pages/shop/item.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 A bounced checkout's own values, merged over a complete blank shape so every key
   exists whatever the caller passed. `pages/shop/item.twig` includes this partial without
   a `checkout_retry` at all (nothing bounces to a product page), and this way that include
   needs no special case — and the template keeps working if `strict_variables` is ever
   turned on.
```

**Headings:** Your cart · Checkout

**Data read (top-level variables/functions):** `retry`, `ship_free_over`, `pv`, `currency`, `providers`, `cur`, `rate`, `fx_rate`, `sym`, `fx_symbol`, `region`, `checkout_retry`, `ship_active`, `shop_regions`

**Forms:**
- `POST /shop/checkout` fields: _token[hidden], cart[hidden], discount[hidden], name[text required,autocomplete], email[email required,autocomplete], phone[tel required,autocomplete], region[select required,autocomplete], address[textarea required,autocomplete], provider[radio]; buttons: Pay securely →

**States / branches (4 distinct conditions):** `ship_active|default(false) and ship_free_over|default(0)` · `currency is defined and currency != 'NGN'` · `providers is empty` · `retry.provider == pv.id or (retry.provider == '' and loop.first)`

**Links out:** `/partner`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `scDialog('open')`, `scDialog('checkoutOpen')`; fetches: `/shop/quote`

**Accessibility affordances:** aria-label×5, aria-modal×3, aria-labelledby×2, aria-hidden×1; roles: dialog, status; <label for>×6; tabindex×3; autocomplete×6

**Styling carried:** 1 <style> block(s), 11 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.


---

## `templates/partials/success.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/donate-success.twig`, `templates/pages/partner-success.twig`, `templates/pages/pay-success.twig`, `templates/pages/registry/register-success.twig`, `templates/pages/shop/success.twig`, `templates/pages/vote-paid-success.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
 ════════════════════════════════════════════════════════════════════
   Unified success / confirmation card — Africa GATES v3
   Mirrors Success Pages.dc.html. Renders inside layout/gates.twig
   {% block content %}; uses the real site nav/footer/Gee chrome.
   ALL copy + data is supplied by the caller — this partial fabricates
   nothing. Inline-styled (no asset-cache dance), reduced-motion safe.

   Caller vars (all optional, sensibly defaulted):
     sc_kicker     uppercase eyebrow                    (string)
     sc_heading    display headline                     (string)
     sc_body       supporting paragraph                 (string)
     sc_accent     'green' | 'gold' | 'slate'           (badge/check tone)
     sc_art        '' | 'green' | 'gold' | 'slate'      (art panel tone; defaults to sc_accent)
     sc_glyph      check|plane|ballot|heart|ticket|bag|tree|link|shield
…
```

**Headings:** {{ sc_heading|default('Thank you') }}

**Data read (top-level variables/functions):** `_ac`, `sc_receipt`, `badge`, `sc_secondary`, `sc_celebrate`, `sc_body`, `sc_email_note`, `_ar`, `_tones`, `code`, `art`, `glow`, `m1`, `m2`, `a47306`, `_glyphs`, `sc_steps`, `sc_primary`, `_fc`, `_gly`, `sc_accent`, `sc_kicker`, `sc_heading`, `you`, `sc_next_label`, `happens`, `next`, `green`, `eef7ee`, `cfe8cd`, `d9ead1`, `gold`, `fff8df`, `f0e0b0`, `f7f0db`, `e0a414`, `slate`, `eef1f1`, `d6dddd`, `e9efef`, `sc_art`, `check`, `plane`, `ballot`, `heart`, `ticket`, `bag`, `tree`, `link`, `shield`, `clock`, `circle`, `cx`, `cy`, `sc_glyph`, `sc_confetti`, `fbc329`, `e0245e`

**States / branches (8 distinct conditions):** `sc_celebrate|default('')` · `sc_body is defined and sc_body` · `sc_receipt is defined and sc_receipt and sc_receipt.code` · `sc_receipt.amount is defined and sc_receipt.amount` · `sc_secondary is defined and sc_secondary and sc_secondary.label` · `sc_email_note is defined and sc_email_note` · `sc_confetti|default(false) and not sc_celebrate|default('')` · `sc_steps is defined and sc_steps is not empty`

**Links out:** `{{ sc_primary.href|default('/') }}` · `{{ sc_secondary.href|default('/') }}`

**JS behaviours:** data hooks: `data-celebrate`

**Accessibility affordances:** aria-hidden×4; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 9 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route


---

## `templates/partials/vote-message-assets.twig` — feature inventory (pre-patch, `a9d963a^`)

**Included by:** `templates/pages/vote-message.twig`, `templates/pages/vote-messages.twig`, `templates/pages/vote-nominee.twig`  
**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** — · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 The styles and behaviour for partials/vote-message.twig. Included ONCE per page
   that renders any messages — the item partial is rendered in a loop and neither a
   stylesheet nor a component definition wants to be.
```

**Data read (top-level variables/functions):** 

**JS behaviours:** 1 inline <script> block(s)

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccessibilityFloorTest::test_the_cookie_notice_cannot_hide_what_the_keyboard_is_on` `[partials/cookie-notice.twig]` — the notice moved; check scroll-padding-bottom still matches what covers the page — *the notice covers the mobile tab bar*
- `CookieChoiceScreenTest::test_the_panel_offers_the_answer_the_visitor_does_not_already_have` `[partials/cookie-choice.twig]` — The panel offers the answer the visitor does not already have.
- `CookieChoiceScreenTest::test_the_panel_offers_nothing_when_the_browser_has_already_refused` `[partials/cookie-choice.twig]` — a choice was offered to somebody whose browser we have already agreed with
- `CookieChoiceScreenTest::test_the_panel_carries_a_csrf_token_and_posts` `[partials/cookie-choice.twig]` — The panel carries a csrf token and posts.
- `CookieChoiceScreenTest::test_the_panel_names_the_cookie_that_stores_the_answer` `[partials/cookie-choice.twig]` — The panel names the cookie that stores the answer.
- `CookieChoiceScreenTest::test_the_panel_confirms_a_press` `[partials/cookie-choice.twig]` — the confirmation is invisible to a screen reader unless it is announced
- `CookieChoiceScreenTest::test_the_notice_gives_both_answers_the_same_weight` `[partials/cookie-notice.twig]` — the notice no longer offers exactly two answers
- `CookieChoiceScreenTest::test_the_notice_cannot_be_dismissed_without_answering` `[partials/cookie-notice.twig]` — The notice cannot be dismissed without answering.
- `CookieChoiceScreenTest::test_the_notice_carries_the_page_it_was_drawn_on` `[partials/cookie-notice.twig]` — The notice carries the page it was drawn on.
- `CookieChoiceScreenTest::test_neither_screen_needs_javascript` `[partials/cookie-choice.twig]` — the {$which} depends on a script, so it is missing for anybody who blocks them
- `CookieChoiceScreenTest::test_neither_screen_leaks_the_choice_to_the_page` `[partials/cookie-choice.twig]` — Neither screen leaks the choice to the page.
- `CookieChoiceScreenTest::test_the_notice_is_not_a_modal` `[partials/cookie-notice.twig]` — The notice is not a modal.
- `GlobeBandTest::test_the_band_is_on_the_homepage_with_its_assets_and_the_duplicate_strip_is_gone` `[partials/globe-band.twig]` — A COMPONENT WITH NO INCLUDE IS §18 AGAIN — every piece complete, nothing serving it. — *the homepage does not load $asset*
- `GlobeBandTest::test_the_stage_does_not_cancel_the_page_scroll` `[partials/globe-band.twig]` — THE FAKE SET CANNOT COME BACK, AND IT IS THE KIND THAT WOULD. — *the stage must leave the vertical axis to the browser*
- `FindBandTest::test_the_field_carries_a_hidden_label` `[partials/find-band.twig]` — The field carries a hidden label.
- `FindBandTest::test_the_field_posts_to_the_search_that_already_exists` `[partials/find-band.twig]` — The field posts to the search that already exists.
- `FindBandTest::test_the_hooks_are_opt_in_so_they_do_not_appear_where_no_script_reads_them` `[partials/find-band.twig]` — the combobox hooks must be opt-in per include
- `FindBandTest::test_the_sentence_is_not_typed_into_the_template` `[partials/find-band.twig]` — the band must generate its coverage sentence
- `GlobeBandTest::test_the_note_claims_nothing_the_platform_cannot_count` `[partials/globe-band.twig]` — THE NOTE STATES WHAT THE MARKERS ARE AND CLAIMS NO MEASUREMENT.
- `GlobeBandTest::test_the_country_card_has_no_row_the_platform_cannot_fill` `[partials/globe-band.twig]` — A card row whose value can only be an em dash is worse than an absent row — it reads as data that failed to load.
