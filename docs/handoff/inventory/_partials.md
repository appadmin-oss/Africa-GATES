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

---

# Orphans destroyed 3 Oct 2026 (owner-approved)

Templates (two layouts and seventeen partials) the first destroy left with no includer, no linker and no renderer ("Orphaned by the destroy" in DESTROYED.md). The owner approved destroying them the same day. Each entry is taken from the file as it stood at `HEAD` (`882d768`) before deletion. **Rebuild** says what a later phase owes; **MUST RESTORE** marks a feature that is a legal obligation, a promise already made elsewhere on the platform, or a live server mechanism this file was the only way into.

The two layouts (`layout/footer.twig`, `layout/nav.twig`) are filed here rather than in a `layout--*.md` of their own: neither was a page layout, both were partials of `layout/gates.twig`.

## `templates/layout/footer.twig` (HEAD, 98 lines)

**Included by (at `a9d963a`):** `layout/gates.twig` (destroyed 3 Oct)

**What it did:** The site footer on every page of the old layout: the brand line with `nations_live()` ("live in …, building toward N nations"), three link columns (Participate: nominate, vote, register, awards, leaderboard, results, hall of fame; Explore: pulse, activity, registry, legacy, community, blog, opportunities, newsletter; Initiative: about, partner, **`/org` organisation console**, help centre, support & appeals, give, **platform status**, contact), four social links (new-tab, labelled "(opens in a new tab)"), and a `nav aria-label="Legal"` row: **/privacy, /terms, /cookies, /refunds, /vendor-terms**, /integrity.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `nations_live`, `nations_count`

**Links out:** `/nominate` · `/vote` · `/account/register` · `/awards` · `/leaderboard` · `/results` · `/winners` · `/pulse` · `/activity` · `/registry` · `/legacy` · `/community` · `/blog` · `/opportunities` · `/newsletter` · `https://afrovanguard.org.ng/about/` · `/partner` · `/org` · `/help` · `/support` · `/giving` · `/status` · `https://afrovanguard.org.ng/contact/` · `https://twitter.com/afrovanguard` · `https://instagram.com/afrovanguard` · `https://linkedin.com/company/afrovanguard` · `https://youtube.com/@afrovanguard` · `/privacy` · `/terms` · `/cookies` · `/refunds` · `/vendor-terms` · `/integrity`

**Accessibility affordances:** aria-hidden×6, aria-label×6; roles: contentinfo

**Legal / consent lines:**
- Privacy
- Terms
- Cookies
- Vendor terms

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — it was the only template linking `/refunds`, `/vendor-terms`, `/support`, `/philosophy` and `/challenges` from every page, the only "front door" to `/org` from anywhere a partner arrives cold, and the only every-page link to `/newsletter` and the legal documents. The rebuilt shell footer (REFERENCE §7.x) must carry every one of those destinations; `PublicIaTest` reports the first five the day their pages are rebuilt and nothing links them.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `LegalCoverageTest::test_all_four_are_linked_from_the_footer` — The footer links `/privacy`, `/terms`, `/cookies` and `/refunds` as literal `href`s — a policy that is not linked is a policy people are told about by their bank.
- `LegalCoverageTest::test_every_policy_the_footer_links_is_a_document_we_ship` — Every legal-ish path the footer links (`terms`, `privacy`, `cookies`, `refunds`, `vendor-terms`) is a document `LegalSeeder::documents()` ships — and the footer links at least one (no vacuous pass).
- `NewsletterTest::test_the_newsletter_is_linked_from_every_page` — The footer carries `href="/newsletter"` — a newsletter nobody can find is a newsletter with no way in.
- `PublicIaTest::test_the_partner_console_is_reachable_from_the_public_site` — The footer (comments stripped) carries `href="/org"`: the organisation console must be reachable from the one place on every page a partner can get back from — a destination link, never the org sign-in form beside the member one. (The second door, `pages/partner.twig`, was destroyed earlier; its inventory holds the same rule.)
- `PublicResultsTest::test_the_platform_actually_points_at_the_result_page` **(guard kept, edited)** — Its list of browsing doors held the site header AND the footer; the footer entry was removed. The rebuilt footer goes back on the list: it must link `/results`.


---

## `templates/layout/nav.twig` (HEAD, 45 lines)

**Included by (at `a9d963a`):** `layout/gates.twig` (destroyed 3 Oct)

**What it did:** The legacy layout's chrome bundle: included `partials/site-header.twig`, then the phone chrome — `partials/lang-prompt.twig`, `partials/tab-bar.twig` (with `active: gates_page`) and `partials/menu-sheet.twig`. Deliberately did NOT mount Quick settings (its only trigger is the shell app bar's avatar, which this layout lacked) and did NOT include the search palette (gates.twig did; twice put two `id="agsInput"` in one document).

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** partials/site-header.twig, partials/lang-prompt.twig, partials/tab-bar.twig, partials/menu-sheet.twig


**Data read (top-level variables/functions):** `_p`, `gates_page`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** Nothing to restore as a file: `layout/shell.twig` already mounts the Menu and Quick settings and a page includes the header itself. **`partials/lang-prompt.twig` was included only from here**; it was destroyed in the second orphan wave the same day (entry at the end of this file) — the shell never mounted it, and Phase 2 rebuilds the prompt.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `SiteHeaderTest::test_a_shell_page_never_pulls_in_the_legacy_chrome_bundle` — No page on `layout/shell.twig` may include the legacy chrome bundle: it mounts the phone sheets a second time, and two `data-ag-menu-sheet` dialogs make the tab bar's Menu button appear dead.
- `SiteHeaderTest::test_each_sheet_is_mounted_by_the_layout_that_can_open_it` **(guard kept, edited)** — Lost its legacy half: nav.twig mounted the Menu exactly once, never Quick settings (no app bar to open it from), and included the tab bar. Re-expressed against the surviving files: the header mounts neither sheet, the shell mounts each once.
- `ColourBudgetTest::test_chrome_is_not_charged_to_a_page` **(guard kept, edited)** — Pointed at `layout/nav.twig`; now points at `layout/shell.twig` and `partials/site-header.twig`, the chrome that renders.


---

## `templates/partials/account-payout.twig` (HEAD, 130 lines)

**Included by (at `a9d963a`):** `pages/account/dashboard.twig` (destroyed 3 Oct)

**What it did:** Withdrawing referral earnings on /account. Rendered `only`. Four mutually exclusive states from `ReferralPayout::available()`: an open request (a `role=status` flash that REPLACES the form, so nobody asks twice), a `POST /account/payout` form (bank, account name, account number), a stated reason it cannot (`payout_why`), and the saved-bank-details `<details>` (open by default when nothing is saved) posting `POST /account/bank`, plus a withdrawals history list. Account number is `inputmode="numeric" pattern="[0-9]*"` and NOT `type="number"` (a leading zero is significant); labels visible, never placeholder-only; "each payout keeps its own copy, so changing these never alters where an earlier transfer went" said on screen.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** partials/ui.twig


**Headings:** Withdrawals

**Data read (top-level variables/functions):** `payout_bank`, `payout_open`, `payout_why`, `your`, `bank`, `details`, `label`, `payout_history`, `saved`, `account`, `payout_amount`, `ui`, `sub`, `pill`, `tone`, `find`, `withdrawal`, `payout_can`

**Forms:**
- `POST /account/payout` [novalidate] fields: _token[hidden], bank[text required,maxlength,autocomplete], account_name[text required,maxlength,autocomplete], account_number[text required,maxlength,pattern,autocomplete,inputmode]; buttons: Request payout
- `POST /account/bank` [novalidate] fields: _token[hidden], bank[text required,maxlength,autocomplete], account_name[text required,maxlength,autocomplete], account_number[text required,maxlength,pattern,autocomplete,inputmode]; buttons: Save details

**States / branches (7 distinct conditions):** `payout_open` · `payout_open.requested_at` · `payout_open.account_name` · `payout_can` · `payout_why` · `not payout_bank.account_number|default('')` · `payout_history|default([])|length`

**Accessibility affordances:** roles: status; <label for>×6; autocomplete×6

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — the two POST routes still exist and the money is still owed; the rebuilt account page must offer the payout and bank-details forms with these four states.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/ad-slot.twig` (HEAD, 22 lines)

**Included by (at `a9d963a`):** `pages/shop/index.twig` (destroyed 3 Oct)

**What it did:** A Google AdSense unit (`adsense_client` + `slot|default(adsense_slot)`), rendered only when both are configured, labelled "Advertisement" (`aside role=complementary`), height-reserved, lazy-loaded and collapsed when no ad returns (the loader lived in the host page).

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `_adslot`, `adsense_client`, `slot`, `adsense_slot`

**States / branches (1 distinct conditions):** `adsense_client and _adslot`

**JS behaviours:** data hooks: `data-ad-client`, `data-ad-slot`, `data-ad-format`, `data-full-width-responsive`

**Accessibility affordances:** aria-label×1; roles: complementary

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Rebuild:** Not required. If ads return, the CSP, `Permissions-Policy`, `CookieRegistry` (AdSense sets cookies) and the cookie policy all have to move with it — a third-party script is a consent question here, not a layout one.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/ai-collection-notice.twig` (HEAD, 45 lines)

**Included by (at `a9d963a`):** `pages/nominate-award.twig` (destroyed 3 Oct)

**What it did:** The NDPA 2023 / GDPR **point-of-collection notice** under a free-text field: "{where} may be sent to a third-party AI service so our reviewers can check it for spam and completeness, and place it in the right category… Contact details are replaced with placeholders first, and a person makes every decision — see exactly what we send" → `/privacy#automated-processing`. Gated on the Twig function `ai_collection_notice()`, which answers from the capability REGISTRY (is any capability that processes public-submitted content declared) — the same basis `AiPrivacy::disclosure()` uses for the privacy page, deliberately NOT live provider configuration, so the two documents cannot disagree on a key rotation. Names no provider and no capability. Not the "no AI wording" rule's business: it is a legal disclosure, not a feature.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `where`, `you`, `write`, `here`, `ai_collection_notice`

**States / branches (1 distinct conditions):** `ai_collection_notice()`

**Links out:** `/privacy#automated-processing`

**Legal / consent lines:**
- see exactly

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — this is a legal obligation. Every rebuilt form that collects free text a capability may process (the nomination reason first) must show this notice beside the field, gated on `ai_collection_notice()`, linking `/privacy#automated-processing`. `ai_collection_notice()` itself survives in `config/container.php`.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/celebrate.twig` (HEAD, 22 lines)

**Included by (at `a9d963a`):** `pages/account/dashboard.twig`, `pages/events/ticket.twig`, `pages/results/{edition,show}.twig`, `pages/vote-nominee.twig`, `partials/success.twig` (all destroyed 3 Oct)

**What it did:** Loaded the self-hosted `vendor/canvas-confetti-1.9.3.js` and `celebrate.js`, both `defer` and nonced. Included INSIDE the markup that names a winner, so a held or delayed result (no winner block) loads no celebration. `key` makes it once per result. Page hooks: `data-celebrate="<key>"`, `data-celebrate-figure`, `data-celebrate-anchor`.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `assets`, `js`, `vendor`, `canvas`, `confetti`, `celebrate`

**JS behaviours:** script `{{ asset('/assets/js/vendor/canvas-confetti-1.9.3.js') }}`; script `{{ asset('/assets/js/celebrate.js') }}`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** Restore with the rebuilt result pages. `public/assets/js/celebrate.js` and the confetti vendor file were destroyed in the second orphan wave the same day; their rules are in `_scripts.md`.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `CelebrationTest::test_both_scripts_are_deferred_and_nonced` — Exactly two scripts, both `defer` (a celebration must not block the first paint of a result page) and both carrying `csp_nonce` (the public CSP is nonce-based).
- `CelebrationTest::test_the_library_is_self_hosted_and_recorded` **(guard kept, edited)** — Lost its partial half: the include must reference `/assets/js/vendor/canvas-confetti-1.9.3.js` and no `cdn.`/`unpkg` URL (the CSP names no CDN in script-src). The vendored-version and PROVENANCE.md halves were kept.


---

## `templates/partials/challenge-strip.twig` (HEAD, 26 lines)

**Included by (at `a9d963a`):** `pages/awards/programme.twig`, `pages/nominate-award.twig` (destroyed 3 Oct)

**What it did:** One linked line saying which challenge an award counts inside, from `ChallengeService::stripFor()`: joined ("Counts toward {title} · 6/10") or everyone ("Part of {title} · 4 of 11 prizes left · Details"). The whole line is the link; middle-dot separators `aria-hidden`; the separator travels with what it introduces so a wrap never strands a dot.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `strip`, `ch`, `long`, `toward`, `of`

**States / branches (2 distinct conditions):** `strip` · `not strip.joined`

**Links out:** `/challenges/{{ strip.slug }}`

**JS behaviours:** data hooks: `data-theme`

**Accessibility affordances:** aria-hidden×2

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** Restore where the award and nomination pages are rebuilt — one partial, so the two pages cannot word the same fact two ways.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/comments.twig` (HEAD, 81 lines)

**Included by (at `a9d963a`):** none — already unincluded before the first destroy (`a9d963a`)

**What it did:** An Alpine comments + cheers panel: a cheer button (`POST /api/community/cheer`) and a comment form (`POST /api/community/comment`, name, email "hashed, never displayed", body ≥6 chars) with an inline error region. Alpine-only and full of inline styles and typed hexes.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Headings:** Comments & cheers · No comments yet

**Data read (top-level variables/functions):** `target_type`, `target_id`, `comments`, `cheer_count`

**Forms:**
- `GET (self)` [target] fields: —; buttons: —

**States / branches (1 distinct conditions):** `comments|default([])|length == 0`

**JS behaviours:** Alpine x-data: `{ cheers: {{ cheer_count|default(0) }}, cheered: false, send`; fetches: `/api/community/cheer`, `/api/community/comment`

**Accessibility affordances:** aria-hidden×2, aria-label×1, aria-pressed×1; roles: alert, status; <label for>×3

**Styling carried:** 0 <style> block(s), 16 inline style attributes; stylesheet links: —

**Rebuild:** Not required (a vestige; Alpine is not loaded by the shell).

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/community-modal.twig` (HEAD, 32 lines)

**Included by (at `a9d963a`):** `layout/gates.twig` (destroyed 3 Oct)

**What it did:** "Join the Africa GATES community" — a once-per-session modal after a successful vote or nomination, linking the WhatsApp community (`community_whatsapp_url` global, with a hard-coded default). Dialog with `aria-modal`, labelled/described, close button and "Maybe later"; behaviour (focus trap, Esc, scroll-lock, once-per-session) in `community-modal.js`.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Headings:** Join the Africa GATES community

**Data read (top-level variables/functions):** `community_whatsapp_url`, `chat`

**Links out:** `{{ community_whatsapp_url|default('https://chat.whatsapp.com/CQyjtaLB7RhLY6q5xAZ`

**Accessibility affordances:** aria-hidden×5, aria-modal×1, aria-labelledby×1, aria-describedby×1, aria-label×1; roles: dialog; tabindex×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** Optional. `public/assets/js/community-modal.js` was destroyed in the second orphan wave the same day (`_scripts.md`).

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/field.twig` (HEAD, 225 lines)

**Included by (at `a9d963a`):** `pages/account/register.twig` (destroyed 3 Oct)

**What it did:** The form-field macros that wired the error state: `summary()` (a `role=alert`, `tabindex=-1`, focus-taking error summary listing each failure as a link to its field — WCAG 3.3.1), `text()`, `textarea()` (with a polite, near-the-limit character counter), `select()`, and `message()` (`<p class="ag-err" id="{id}-err">` with an `aria-hidden` icon and the sentence as text). Each control got `aria-invalid="true"` on the CONTROL, `aria-describedby` naming hint THEN error, unique ids, a visible hint (never placeholder-only), required marked with an `aria-hidden` star plus "(required)" for screen readers. Forms are `novalidate` with `required`/`minlength` kept.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `id`, `err`, `hint`, `name`, `errors`, `described`, `text`, `field`, `message`, `label`, `old`, `value`, `opts`, `labels`, `chosen`, `selected`, `count`, `options`

**States / branches (18 distinct conditions):** `errors|length or message` · `errors|length == 1` · `errors|length` · `message` · `o.required|default(false)` · `hint` · `err` · `o.autocomplete|default('')` · `o.inputmode|default('')` · `o.minlength|default(0)` · `o.maxlength|default(0)` · `o.pattern|default('')` · `o.placeholder|default('')` · `described` · `o.suffix|default('')` · `o.rows|default(0)` · `o.counter|default(false)` · `o.blank|default('')`

**Links out:** `#f-{{ field }}`

**JS behaviours:** data hooks: `data-ag-count`, `data-ag-max`

**Accessibility affordances:** aria-hidden×4, aria-invalid×3, aria-describedby×3, aria-live×1; roles: alert; <label for>×3; tabindex×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — every rebuilt validated form owes this wiring (WCAG 1.4.1, 3.3.1, 4.1.2). Rebuild it as one macro set before the first form lands; `FormErrorStateTest`'s surviving sweeps (error state only for its own field, `novalidate` + validator together, one bag per form) still apply.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `FormErrorStateTest::test_the_error_message_carries_a_sentence_and_hides_its_icon` — The message macro prints the sentence (`{{ err }}`), hides its icon (`aria-hidden="true"`), and has a stable `class="ag-err" id="{{ id }}-err"` so `aria-describedby` can point at it — never colour or an icon alone (WCAG 1.4.1).


---

## `templates/partials/flash.twig` (HEAD, 38 lines)

**Included by (at `a9d963a`):** `layout/gates.twig` (destroyed 3 Oct)

**What it did:** The public flash rail: `flash_error` (`role=alert`), `flash_ok` and `flash_notice` (`role=status`), each with an `aria-hidden` icon and the message as text. Before it existed, a public controller that set a message and redirected showed the visitor nothing — indistinguishable from a broken link ("applications for this event are closed" written to a session key with no reader).

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `flash_error`, `flash_ok`, `flash_notice`

**States / branches (4 distinct conditions):** `flash_error or flash_ok or flash_notice` · `flash_error` · `flash_ok` · `flash_notice`

**Accessibility affordances:** aria-hidden×3; roles: alert, status

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — `layout/shell.twig` renders no flash, so every public POST that redirects with a message is silent again. The shell (or each rebuilt page) must render `flash_error`/`flash_ok`/`flash_notice` announced by role, not colour.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/lottie.twig` (HEAD, 56 lines)

**Included by (at `a9d963a`):** `pages/account/login.twig`, `pages/account/verify-notice.twig` (destroyed 3 Oct)

**What it did:** A decorative Lottie illustration: lazy-loads the self-hosted `vendor/lottie-web-5.12.2.light.min.js` once per page, plays only while on screen, a static middle frame under `prefers-reduced-motion`, `aria-hidden` unless a `label` makes it `role=img`.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `label`, `_w`, `_h`, `size`, `src`, `width`, `height`

**States / branches (1 distinct conditions):** `label|default('')`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-ag-lottie`

**Accessibility affordances:** aria-label×1, aria-hidden×1; roles: img; prefers-reduced-motion×1

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Rebuild:** Not required. `public/assets/js/vendor/lottie-web-*.js` and `/assets/anim/` remain.

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/member-autofill.twig` (HEAD, 62 lines)

**Included by (at `a9d963a`):** `pages/events/detail.twig`, `pages/vote-nominee.twig` (destroyed 3 Oct)

**What it did:** "Signed in as {name} — Use my details": an opt-in chip that fills mapped fields from `UserAccountService::memberForForms()` and toggles to "Applied ✓ — undo" (restores the previous values); dispatches `input` so plain and Alpine fields both update; renders nothing for guests. Inline styles with typed hexes.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `member`, `maf_fields`, `name`, `email`, `phone`

**States / branches (1 distinct conditions):** `member`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-ag-do`, `data-fill`, `data-member`; data-ag-do: member-fill

**Accessibility affordances:** aria-hidden×1

**Styling carried:** 0 <style> block(s), 3 inline style attributes; stylesheet links: —

**Rebuild:** Optional; if restored, rebuild it on `Accent` tokens (it carried 8 colour literals).

**Guard tests:** none read this file at the time of the destroy.


---

## `templates/partials/org-page.twig` (HEAD, 331 lines)

**Included by (at `a9d963a`):** `pages/donate.twig`, `pages/org/dashboard.twig` (destroyed 3 Oct)

**What it did:** The organisation's own donation page body on `/gift/{slug}` (CLAUDE.md "An organisation's own donation page"): the `OrgBrand` story (paragraphs from `OrgBrand::paragraphs()`), impact figures ("in their words"), gift ladder, video, quotes, FAQ, team, history, partners and links, inside `<div class="ob" style="--ob-accent…;--ob-accent-dark…;--ob-accent-wash…">` (values from `normaliseHex()` and integer `%d`, so nothing can break out of the style attribute). A block renders only when switched on AND non-empty. Video is a click-to-load facade naming the provider (no iframe in the markup), built by `OrgBrand::embedUrl()` from a provider + id, never from a typed URL. Everything is untrusted, autoescaped, no `|raw`. A platform credit the organisation cannot switch off.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Headings:** About {{ org.name }} · What {{ org.name }} has done · Where your money goes · {{ org.name }} on film · Photographs · What people say · Who runs {{ org.name }} · A short history · Who {{ org.name }} works with · What donors ask {{ org.name }} · Reports, press and elsewhere · Contact {{ org.name }}

**Data read (top-level variables/functions):** `brand`, `row`, `org`, `provider`, `brand_css`, `video`, `story_paragraphs`, `gates_credit`

**States / branches (15 distinct conditions):** `org and brand` · `brand.sections.story and story_paragraphs is not empty` · `brand.sections.impact and brand.blocks.impact is not empty` · `brand.sections.asks and brand.blocks.asks is not empty` · `brand.sections.video and brand.videos is not empty` · `v.title` · `brand.sections.gallery and brand.gallery is not empty` · `brand.sections.quotes and brand.blocks.quotes is not empty` · `brand.sections.team and brand.blocks.team is not empty` · `brand.sections.milestones and brand.blocks.milestones is not empty` · `brand.sections.partners and brand.blocks.partners is not empty` · `brand.sections.faq and brand.blocks.faq is not empty` · `brand.sections.links and brand.links is not empty` · `brand.sections.contact and brand.website` · `brand.website`

**Links out:** `{{ v.watch }}` · `{{ l.url }}` · `{{ brand.website }}` · `/philosophy` · `/giving`

**JS behaviours:** data hooks: `data-ob-src`, `data-ob-name`

**Accessibility affordances:** aria-hidden×4, aria-label×1; alt=×1

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — `OrgBrand`, its writer and its dashboard editor survive; the renderer is gone, so the feature is back to "no page out" (§17/§18 in one feature, exactly how it first shipped). The rebuilt donate page must render it and re-assert every rule below.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `OrgPageTest::test_every_block_survives_the_form` — Every block survives a round trip through its DERIVED field names — post the editor's form, save, render, and each value is on the page.
- `OrgPageTest::test_every_block_an_organisation_fills_in_reaches_the_page` — Every filled block reaches the rendered page (impact figure, ladder, quote, FAQ, team, history, partner, link).
- `OrgPageTest::test_an_empty_block_that_is_switched_on_draws_nothing` — A block switched on with nothing in it publishes no heading.
- `OrgPageTest::test_a_video_sends_nothing_to_a_provider_until_it_is_pressed` — **Zero `<iframe>` in the shipped HTML** — a frame in the markup is a third-party request nobody consented to (GDPR joint-controller line, NDPA 2023); the facade names the provider and carries a plain link out.
- `OrgPageTest::test_an_organisations_links_are_rel_hardened_and_refused_when_unsafe` — Links an organisation typed carry `rel="noopener noreferrer nofollow"`-style hardening and a `javascript:` URL is refused (and the save with it).
- `OrgPageTest::test_markup_an_organisation_typed_is_inert` — A partner's text cannot break out of the page — `<script>`, `</div>` and attribute-breaking quotes render inert.
- `OrgPageTest::test_the_platform_credit_survives_an_organisation_turning_everything_off` — The Africa GATES credit is not a section an organisation can switch off, and it says what the organisation gets for it.
- `OrgPageTest::test_the_credit_links_only_to_routes_that_exist` — Every route the credit links to is registered.


---

## `templates/partials/promo-carousel.twig` (HEAD, 116 lines)

**Included by (at `a9d963a`):** `pages/account/dashboard.twig`, `pages/awards/programme.twig`, `pages/events.twig`, `pages/home.twig`, `pages/nominate.twig`, `pages/vote.twig` (destroyed 3 Oct)

**What it did:** The promo band from `ChallengeBanner.dc.html`: `promos` from `PromoService`, per `placement`. Zero promos renders nothing (no empty band); one promo has no dots and no auto-advance; `aria-roledescription="carousel"`; `data-pb-count` (not `data-count`, which `main.js`'s GSAP count-up owns and once replaced the band with "0"); track sized from `--pb-n`. With JS off it is the first slide with a working link.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `promos`

**States / branches (7 distinct conditions):** `promos|default([])|length` · `not loop.first` · `p.kicker or p.chip` · `p.chip` · `p.sub` · `p.art` · `promos|length > 1`

**Links out:** `{{ p.href }}`

**JS behaviours:** data hooks: `data-pb-count`, `data-theme`, `data-state`, `data-pb-go`

**Accessibility affordances:** aria-hidden×5, aria-label×4, aria-roledescription×2, aria-current×1; alt=×1; tabindex×1

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Rebuild:** Restore with the pages that host it. Two colour-alone findings were on its backlog (`ColourIsNeverAloneTest`, 2), owed by the rebuild.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `ColourIsNeverAloneTest::BACKLOG` **(guard kept, edited)** — Its entry (2) was deleted; the backlog is empty, and the sweep now also fails on an entry naming a file that does not exist.


---

## `templates/partials/site-search.twig` (HEAD, 118 lines)

**Included by (at `a9d963a`):** `layout/gates.twig` (destroyed 3 Oct)

**What it did:** The header's site-wide search palette (`id="agSearch"`, opened by the header's `data-ag-search-open` button): a real GET form to `/activity` that works with no JS; a modal dialog that moves focus in, traps it, returns it to the opener; a WAI-ARIA 1.2 combobox (`aria-expanded`, `aria-controls`, `aria-autocomplete=list`, `aria-activedescendant`, `role=listbox/option`) where the active option moves by `aria-activedescendant`, never `focus()`; an always-present polite live region for "results arrived"; Escape closes, Enter opens the active result or submits. Scope chips `{k:'', label:'All'}` then one per `ActivityFeedService::SCOPES`. The AI reads the QUERY for intent and never writes a result.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Headings:** Search Africa GATES

**Data read (top-level variables/functions):** `label`, `sc`

**Forms:**
- `GET /activity` fields: q[search autocomplete]; buttons: —

**Links out:** `/activity`

**JS behaviours:** data hooks: `data-ags-scope`

**Accessibility affordances:** aria-hidden×2, aria-label×2, aria-modal×1, aria-labelledby×1, aria-describedby×1, aria-selected×1, aria-controls×1, aria-live×1, aria-atomic×1; roles: dialog, search, tablist, tab, status; visually-hidden text×2; <label for>×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — the surviving `partials/site-header.twig` still renders the search button with `aria-haspopup="dialog"`, and it now opens nothing. `public/assets/js/ag-search.js` was destroyed in the second orphan wave the same day (`_scripts.md`, also MUST RESTORE). Phase 2 must rebuild the palette (or remove the button).

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `SearchScopeTest::test_the_palette_offers_a_chip_for_every_scope_and_no_others` — The palette's chips are, in order, the empty key "All" (the absence of a filter, not a bucket) and then exactly `array_keys(ActivityFeedService::SCOPES)` — a chip with no bucket filters nothing, a bucket with no chip is results nobody can ask for.
- `FindBandTest::test_no_other_search_entrance_enumerates_the_sources` — The header search dialog's visible text (Twig comments stripped) names none of `ActivityFeedService::nouns()` — only the find band, which generates it from SOURCES, may claim coverage. (This method passed vacuously on an empty string once the file was gone, and was destroyed for that.)


---

## `templates/partials/support-prompt.twig` (HEAD, 145 lines)

**Included by (at `a9d963a`):** `pages/error.twig`, `pages/pay-success.twig`, `pages/status.twig`, `pages/vote-nominee.twig`, `pages/vote-paid-success.twig` (destroyed 3 Oct)

**What it did:** The support offer dropped onto the pressure points (a stalled payment, missing votes). Builds `/support/assistant?topic={kind}` and, with a payment reference, `&ref={url-encoded}&ask=1` so the assistant opens holding the reference and attempts the repair. Kinds `payment|votes|account|general` with per-kind title/body; CTA "Re-check it now" with a reference, "Open the assistant" without. Card form offers the configured `support_email`; compact one-line form points at the philosophy and publishes no address. Carried its own nonced `<style>` (it sat on an error page that loads no page CSS) with a dark-surface variant.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `the`, `_ref`, `assistant`, `check`, `can`, `_alt`, `_kind`, `payment`, `_href`, `_title`, `right`, `votes`, `went`, `through`, `to`, `_body`, `it`, `now`, `your`, `account`, `re`, `gateway`, `credit`, `money`, `you`, `hand`, `that`, `platform`, `person`, `support_email`, `sp_kind`, `sp_ref`, `support`, `topic`, `ref`, `ask`, `sp_title`, `but`, `nothing`, `has`, `arrived`, `showing`, `sp_body`, `this`, `takes`, `few`, `seconds`, `do`, `need`, `an`, `have`, `reference`, `never`, `appeared`, `put`, `on`, `spot`, `what`, `doing`, `pass`, `anything`, `cannot`, `settle`, `live`, `state`, `of`, `fix`, `stuck`, `when`, `better`…

**States / branches (2 distinct conditions):** `sp_compact|default(false)` · `_ref`

**Links out:** `{{ _href }}` · `{{ _altHref }}` · `mailto:{{ support_email }}{% if _ref %}?subject={{ ('Payment ' ~ _ref)|url_encod`

**Accessibility affordances:** aria-label×1, aria-hidden×1

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — every rebuilt payment/vote outcome page and the error page must carry the reference-carrying assistant link — handing somebody a bare support link asks them to copy forty characters by hand.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `SupportSurfaceRenderTest::test_the_reference_travels_into_the_assistant` — With a reference: `/support/assistant?topic=payment`, `ref=<reference>` and `ask=1` — there is a real repair to attempt, so it just happens.
- `SupportSurfaceRenderTest::test_a_reference_is_url_encoded` — The reference is URL-encoded (`ref with spaces&x=1` → `ref%20with%20spaces%26x%3D1`).
- `SupportSurfaceRenderTest::test_without_a_reference_it_does_not_auto_ask` — Without a reference there is no `ask=1` — firing a vague question on the reader's behalf teaches them the assistant guesses — and the CTA reads "Open the assistant".
- `SupportSurfaceRenderTest::test_the_call_to_action_changes_with_what_is_possible` — "Re-check it now" with a payment reference; "Open the assistant" for general.
- `SupportSurfaceRenderTest::test_the_email_fallback_uses_the_configured_inbox` — The email fallback is the configured `support_email` global, never a typed address.
- `SupportSurfaceRenderTest::test_the_compact_form_is_a_single_line_with_no_card` — The compact form is a single line with no card.
- `SupportSurfaceRenderTest::test_the_compact_line_offers_the_philosophy_and_publishes_no_address` — The compact line points at the philosophy, NOT at a mailbox — it sits under a pay button.
- `SupportSurfaceRenderTest::test_the_compact_lines_second_clause_is_overridable` — A caller can point the compact line's second clause elsewhere when the context differs.
- `SupportSurfaceRenderTest::test_the_card_form_still_offers_the_inbox` — The CARD form keeps its email — not an inconsistency.
- `SupportSurfaceRenderTest::test_every_kind_renders_rather_than_falling_through_to_nothing` — Every kind (`payment`, `votes`, `account`, `general`) renders.
- `SupportSurfaceRenderTest::test_its_styles_travel_with_it` — Its styles travel with it: a nonced `<style>`, `.ag-sprompt{`, and a `.ag-sprompt--dark` variant.


---

## `templates/partials/tile.twig` (HEAD, 23 lines)

**Included by (at `a9d963a`):** `pages/results/edition.twig`, `pages/results/hall.twig` (destroyed 3 Oct)

**What it did:** THE TILE — the one device that carries colour: `<span class="ag-tile" style="{{ tile_style(meaning) }}">` with an `aria-hidden` mark and the label as text, optional `--lg` and `--live`. `meaning`, never a hue: `Accent::for()` throws on a typo. Styled by `components/tile.css`.

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `ag`, `tile`, `live`, `size`, `lg`, `tile_style`, `meaning`, `label`

**Accessibility affordances:** aria-hidden×1

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — `Accent::tileStyle()` and the `tile_style()` Twig function survive (TileTest still holds them); the markup and sheet do not. Any rebuilt page that shows a state colour uses the four-part tile, mark hidden, word visible — the shape `ColourIsNeverAloneTest` measures every other coloured element against.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `TileTest::test_all_four_parts_reach_the_page` — The rendered tile carries `--tile-wash`, `--tile-edge`, `--tile-fill`, `--tile-ink`, the `ag-tile__mark` and the label — no edge is a wash, no fill a card, no ink decoration, no wash a floating chip.
- `TileTest::test_the_mark_is_decoration_and_the_word_is_the_fact` — `<span class="ag-tile__mark" aria-hidden="true">` and `<span>{label}</span>` — the colour is an accelerator on a word, never the fact.
- `ColourIsNeverAloneTest::test_the_tile_hides_its_mark_and_leaves_its_word_alone` — The mark span is empty and `aria-hidden`; the word `<span>{{ label }}</span>` is never inside a hidden element.


---

## `templates/partials/vote-countdown.twig` (HEAD, 72 lines)

**Included by (at `a9d963a`):** `pages/vote-nominee.twig`, `pages/vote.twig` (destroyed 3 Oct)

**What it did:** The voting-closes clock from the `CyclePolicy` view-model. Server-computed `data-vc-left` seconds (the browser only decrements; the visitor's clock is never consulted). One quiet line beyond `CLOSING_SOON_SECONDS` (48h), a live `vc--soon` panel inside it. Renders nothing with no deadline or an expired one. Group `role=group` labelled with the absolute deadline (`|when_zoned`, WAT with the zone) and the ticking digits `aria-hidden`. `variant: bare` for inside a card, inheriting its panel's ink (it was once `dark` and went invisible when the ground changed).

**Mechanics (generated from the file):**

**Extends:** — · **Includes/imports:** —


**Data read (top-level variables/functions):** `phase`, `_soon`, `_bare`, `soon`, `bare`, `variant`

**States / branches (2 distinct conditions):** `phase is defined and phase and phase.closes_at and phase.seconds_left is not null and phas` · `_soon`

**JS behaviours:** data hooks: `data-vc-left`

**Accessibility affordances:** aria-hidden×2, aria-label×1; roles: group

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Rebuild:** **MUST RESTORE** — the vote pages must show the closing deadline. The ticker in `main.js` ("LIVE VOTE COUNTDOWN") survives and `VoteCountdownTest` still holds that it never reads `Date.now`; the shell does not load `main.js`, so the rebuild owns where the ticker lives.

**Rules held by guard tests destroyed with it** — the rebuild re-asserts each, watched failing first:

- `VoteCountdownTest::test_the_remaining_seconds_are_server_computed` — `data-vc-left="7200"` — the browser is handed a duration to count down, never a date to subtract from.
- `VoteCountdownTest::test_a_distant_deadline_is_one_quiet_line` — Three weeks out: no `vc__clock`, and "Voting closes" — a clock ticking three weeks out trains people to ignore clocks.
- `VoteCountdownTest::test_inside_the_closing_window_it_becomes_a_live_clock` — Six hours out: `vc--soon`, `vc__clock`, "Closing soon".
- `VoteCountdownTest::test_no_deadline_renders_nothing` — No close date renders an empty string.
- `VoteCountdownTest::test_an_expired_deadline_renders_nothing` — A passed deadline renders an empty string.
- `VoteCountdownTest::test_the_digits_are_hidden_from_assistive_tech_and_the_deadline_is_not` — `<div class="vc…" data-vc`, `aria-label="Voting closes…"`, and `<div class="vc__clock" aria-hidden="true">` — a screen reader announcing a new value every second is unusable.
- `VoteCountdownTest::test_the_bare_variant_is_the_same_markup` — `bare` adds `vc--bare` and keeps the same accessible contract (`aria-label="Voting closes`, `aria-hidden="true"`); the card form never carries `vc--bare`.



# Second orphan wave, 3 Oct 2026 (owner-approved)

One template the first orphan pass left behind (scripts of the same wave are in [`_scripts.md`](_scripts.md)). Grepped for before deletion across `templates/`, `src/`, `config/`, `public/assets/`, `cron/` and `bin/`: no includer. Taken from the file at `HEAD` (`5b06988`).

## `templates/partials/lang-prompt.twig` (HEAD, 44 lines)

**Included by (at `a9d963a`):** `layout/nav.twig` only (destroyed as an orphan 3 Oct). `layout/shell.twig` never mounted it.

**What it did:** The first-visit language prompt (REFERENCE §7.2): one row under the root app bar asking — IN the visitor's language — whether they want the site in it. One hidden row per `lang_prompts()` entry (`Support\Languages::prompts()`: every offered language but English whose catalogue has both an `ask` and a `yes` string, so nothing is composed at runtime), each carrying its own `lang` and `dir`, the question, a "yes" link to `lang_url(code)` and a "Keep English" link to `lang_url('en')` (in English, `lang="en" dir="ltr"`: it is the option it describes). The rows were rendered and hidden rather than built in JS so there was no JSON blob in an attribute (the shape that once shredded the flier's styles), and the WORDS came from the catalogues, not `|trans` (which answers in the page's language). Both answers were LINKS carrying `?lang=`, so `LanguageMiddleware` stayed the one writer of the `ag_lang` cookie and the prompt worked with scripting off. The browser half — pick the row for `navigator.languages[0]` (first only: "English, then French" reads English), show it, hide on the first scroll of `.ag-main` — is `bindLangAsk()` in `public/assets/js/chrome.js`, which survives.

**Mechanics:** **Includes/imports:** — · **Data read:** `lang_prompts()`, `lang_url()` · **Hooks:** `data-ag-langask`, `data-ag-langask-for` · **Classes:** `ag-langask`, `ag-langask__row`, `ag-langask__q`, `ag-btn--sm`, `ag-btn--ink`, `ag-btn--quiet` · 0 `<style>`, 0 inline styles.

**Rebuild:** Phase 2 (chrome), with **the §3.8 fault fixed, not carried**: `nav.twig` included this partial UNCONDITIONALLY, while the server gate `lang_ask()` (`config/container.php`, `Languages::shouldAsk()`) was registered and called by no template — so a visitor who answered "Keep English" was asked again on every page, and on `gates.twig` pages `bindLangAsk` returned before binding the scroll-hide (no `.ag-main`). The rebuild MUST wrap the include in `{% if lang_ask() %}` — "the condition for asking is the ABSENCE of that cookie, so neither answer can bring the prompt back. A prompt that returns is an advert." — and `LanguageTest` should be extended to render through the gate rather than test `shouldAsk()` in isolation (GAPS §3.8).

**Left behind, deliberately untouched (owner to decide with Phase 2):** `config/container.php` still registers `lang_prompts` and `lang_ask`, now with no template caller; `chrome.js` `bindLangAsk()` finds no `[data-ag-langask]` and returns on its first line. Neither is wrong; both are the surviving halves of what this partial joined.

**Guard tests:** none read this file at the time of the destroy (`LanguageTest` holds `Languages` only).
