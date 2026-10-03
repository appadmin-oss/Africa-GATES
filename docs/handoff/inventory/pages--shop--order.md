# `templates/pages/shop/order.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /shop/order/{ref:[A-Za-z0-9\-]{8,72}} → ShopCheckoutController::order`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  A BUYER'S OWN ORDER, REACHABLE WITH THE REFERENCE ALONE.

  Same doctrine as an event ticket, a claim link and the nominee questionnaire: a shop buyer
  has no account, and requiring one to see the order they just paid for would put a
  registration between somebody and their own receipt. The reference is a twelve-hex secret
  this platform generated, it is in their emailed receipt, and the page is noindex.

  It exists because /shop/success only lived in the tab that came back from the gateway. Close
  that tab and the order was unreachable — nothing anywhere could answer "did it go through"
  or "has it shipped", so both questions arrived at a support inbox to be looked up by hand.
```

**Headings:** We can’t find that order · {% if paid and ful == 'delivered' %}Delivered {% elseif paid and ful == 'shipped

**Data read (top-level variables/functions):** `order`, `paid`, `ful`, `support_email`, `ok`, `no`, `wait`, `completed`, `items`

**States / branches (14 distinct conditions):** `order is null` · `paid and ful == 'delivered'` · `paid and ful == 'shipped'` · `paid` · `order.status == 'failed'` · `ful == 'delivered'` · `ful == 'shipped'` · `ful == 'cancelled'` · `order.tracking_note|default('')` · `l.variant|default('')` · `order.goods_naira is not null` · `(order.discount_naira|default(0)) > 0` · `order.discount_code` · `order.phone`

**Links out:** `mailto:{{ support_email }}` · `/shop` · `mailto:{{ support_email }}?subject=Order%20{{ order.reference|url_encode }}`

**JS behaviours:** data hooks: `data-page`

**Styling carried:** 1 <style> block(s), 4 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
