# `templates/pages/shop/alert-stopped.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /shop/back-in-stock/stop/{token:[a-f0-9]{32}} → ShopController::stopAlert`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ONE CLICK OFF THE BACK-IN-STOCK LIST.

  Reachable with the token alone — same doctrine as an event ticket and the nominee
  questionnaire. The person who asked has no account, and requiring one to STOP receiving mail
  would be the worst possible place to put a registration wall.

  The page NEVER says whether the token was real. It confirms either way, because the
  difference between "done" and "we could not find that" is a way to test tokens, and the
  outcome for an honest reader is identical: they will not hear from us about that item.
```

**Headings:** Done — you are off that list

**Data read (top-level variables/functions):** 

**Links out:** `/shop`

**Accessibility affordances:** aria-hidden×1

**Styling carried:** 0 <style> block(s), 6 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
