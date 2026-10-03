# `templates/pages/shop/item.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /shop/{slug} → ShopController::item`
**Extends:** layout/gates.twig · **Includes/imports:** partials/shop-cart.twig

**What the page says it is (its own header comment, abridged):**
```
 ── THE PICKER'S STATE ────────────────────────────────────────────────────────
   `variants` comes from the server already priced off the REGION-ADJUSTED base, so nothing
   here adds a delta to the wrong number. `sel` is the chosen variant object, or null while
   nothing is chosen — and every action is disabled until it is not, because picking a size on
   somebody's behalf is how they receive the wrong one.
```

**Headings:** {{ p.name }} · You may also like

**Data read (top-level variables/functions):** `gradient`, `gallery`, `variants`, `hero`, `ship_free_over`, `delivery_regions`, `option_shots`, `axes`, `related`, `waiting`, `si`, `opt__row`, `sw`, `gone`, `low`, `product`, `a47306`, `d49a0e`, `b97a12`, `fbc329`, `at`, `currency`, `ship_active`

**States / branches (19 distinct conditions):** `gallery|default([])|length or option_shots` · `gallery|default([])|length` · `not p.cover_path` · `p.tag` · `gallery|default([])|length > 1` · `g.alt` · `p.subtitle|default('')` · `currency != 'NGN'` · `p.description` · `axes|default([])|length` · `g.kind == 'swatch'` · `p.stock_note|default('')` · `ship_active|default(false) and ship_free_over|default(0)` · `p.ships_free|default(0)` · `p.details|default('')` · `delivery_regions is empty` · `related is not empty` · `r.cover_path` · `r.sold_out|default(false)`

**Links out:** `/shop` · `/shop?c={{ p.category|url_encode }}` · `/shop/{{ r.slug }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `siItem({{ variants|default([])|json_encode|e('html_attr') }}`, `{ open:false }`; data hooks: `data-page`; fetches: `/shop/`

**Accessibility affordances:** aria-label×6, aria-hidden×3, aria-pressed×2, aria-expanded×1; roles: group; alt=×2; autocomplete×1

**Styling carried:** 1 <style> block(s), 10 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
