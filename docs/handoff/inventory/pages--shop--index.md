# `templates/pages/shop/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /shop → ShopController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/ad-slot.twig, partials/shop-cart.twig

**What the page says it is (its own header comment, abridged):**
```
 Two tones, because not every one of these is a failure. `mismatch` means the gateway
     says money MOVED — wording that red and calling it an error is what turns a debited
     customer into a chargeback (see ShopCheckoutController::callback).
```

**Headings:** Wear the recognition · Nothing matched · The shop is opening soon

**Data read (top-level variables/functions):** `gradient`, `price_range`, `label`, `without`, `stock`, `code`, `qs`, `on`, `key`, `gone`, `alt`, `products`, `kk`, `vv`, `me`, `ship_free_over`, `sold`, `out`, `off`, `adsense_client`, `sort`, `region_priced`, `currency_enabled`, `keep`, `feat`, `choose`, `gift`, `what`, `would`, `you`, `suggest`, `my`, `size`, `will`, `delivery`, `cost`, `card`, `opt`, `low`, `here`, `a47306`, `d49a0e`, `b97a12`, `fbc329`, `found`, `category`, `total`, `page`, `pages`, `ship_active`, `categories`, `regions`, `region`, `sym`, `currencies`, `currency`, `sorts`, `to`, `adsense_slot_2`

**Forms:**
- `GET /shop` fields: q[search autocomplete], c[hidden], min[hidden], max[hidden], stock[hidden], feat[hidden], sort[hidden]; buttons: Search
- `GET /shop` fields: q[hidden], sort[hidden], c[radio], min[number inputmode], max[number inputmode], stock[checkbox], feat[checkbox]; buttons: Apply
- `GET /shop` fields: q[hidden], c[hidden], min[hidden], max[hidden], stock[hidden], feat[hidden], sort[select]; buttons: Go

**States / branches (41 distinct conditions):** `F.category` · `F.min is not null` · `F.max is not null` · `F.in_stock` · `F.featured` · `F.sort != 'featured'` · `ship_active|default(false) and ship_free_over|default(0)` · `onCount` · `F.q` · `F.category == ''` · `F.category == c` · `price_range|default(null) and price_range.max > price_range.min` · `region_priced or currency_enabled` · `region_priced` · `r == region` · `currency_enabled` · `code == currency` · `F.filtered` · `products is not empty` · `F.pages > 1` · `key == F.sort` · `label` · `kk != k and vv != ''` · `p.cover_path` · `alt` · `p.tag` · `p.subtitle|default('')` · `p.stock_note|default('')` · `p.dots|default(null) and p.dots.dots|length` · `p.dots.more` · `p.price_from|default(false)` · `p.sold_out|default(false)` · `p.choose|default(false)` · `n == 1 or n == F.pages or (n >= F.page - 2 and n <= F.page + 2)` · `n == F.page` · `n == F.page - 3 or n == F.page + 3` · `adsense_slot_2` · `F.min is not null or F.max is not null` · `F.in_stock or F.featured` · `F.min is null and F.max is null and not F.in_stock and not F.featured` · `adsense_client`

**Links out:** `/support/assistant?q={{ 'Help me choose a gift — what would you suggest?'|url_en` · `/support/assistant?q={{ 'Is my size in stock?'|url_encode }}` · `/support/assistant?q={{ 'What will delivery cost me?'|url_encode }}` · `/shop` · `/shop{{ without ? '?' ~ without|join('&') : '' }}` · `/shop/{{ p.slug }}` · `/shop?{{ qs }}&page={{ F.page - 1 }}` · `/shop?{{ qs }}&page={{ n }}` · `/shop?{{ qs }}&page={{ F.page + 1 }}`

**JS behaviours:** script `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ adsense_client }}`; 2 inline <script> block(s); Alpine x-data: `{ filter:'All' }`; data hooks: `data-page`, `data-ag-do`, `data-cookie`; data-ag-do: submit-form, set-cookie-reload

**Accessibility affordances:** aria-hidden×14, aria-label×5, aria-current×1; roles: alert; visually-hidden text×3; <label for>×1; alt=×1; tabindex×1; autocomplete×1; noscript×1

**Styling carried:** 1 <style> block(s), 6 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ShopAdvisorTest::test_the_handoff_is_a_link_and_never_a_purchase` — (no docblock)
- `ShopAdvisorTest::test_an_unfindable_handoff_falls_back_to_the_shop_rather_than_a_dead_link` — (no docblock)
- `CheckoutStartsEndToEndTest::test_the_shop_page_renders` — (no docblock)
- `CheckoutStartsEndToEndTest::test_posting_a_shop_checkout_does_not_fatal` — (no docblock)
- `CheckoutStartsEndToEndTest::test_the_shop_quote_endpoint_does_not_fatal` — The quote endpoint the basket calls on every change.
- `CheckoutStartsEndToEndTest::test_the_shop_callback_does_not_fatal` — (no docblock)
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `FormAccessibilityTest::test_a_decorative_duplicate_link_is_hidden_from_assistive_tech` — (no docblock)
- `GatewayHandoffTest::test_every_checkout_path_has_a_same_origin_handoff_route` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `LanguageTest::test_the_carried_fields_never_include_the_language_itself` — (no docblock)
- `MemberPurchasesTest::test_an_order_is_found_and_described_by_what_is_in_it` — (no docblock)
- `SeoSitemapTest::test_the_public_surfaces_and_policies_are_all_listed` — Each of these returns 200, is indexable, and was in no section.
- `ShopCheckoutNoticeTest` (whole file, via a helper/constant/data provider: 4 tests) — test_every_emitted_checkout_code_has_a_message, test_mismatch_is_worded_as_money_moved_not_as_a_failure, test_take_retry_reads_and_clears, test_take_retry_is_blank_when_nothing_was_flashed
- `ShopEventPaymentCoverageTest::test_a_bare_handoff_path_still_uses_a_question_mark` — The four bare-path callers keep the plain `?ref=` they have always had.
- `SiteSearchTest::test_a_natural_language_question_reaches_a_page` — The question a first-time visitor actually types.
- `StockAlertTest::test_the_email_carries_a_one_click_unsubscribe` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CheckoutStartsEndToEndTest::test_the_shop_page_renders` — The shop page renders.
- `FormAccessibilityTest::test_a_decorative_duplicate_link_is_hidden_from_assistive_tech` — A decorative duplicate link is hidden from assistive tech.
- `ShopCheckoutNoticeTest::test_every_emitted_checkout_code_has_a_message` — Expected to find the controller bail codes — *Expected to find the template message keys*
- `ShopCheckoutNoticeTest::test_mismatch_is_worded_as_money_moved_not_as_a_failure` — No `mismatch` entry in the checkout message map — *`mismatch` must not be styled as a failure*
