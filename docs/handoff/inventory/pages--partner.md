# `templates/pages/partner.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /partner → PartnerController::form`; `POST /partner → PartnerController::submit`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Hero ──
```

**Headings:** Power the continent’s register of excellence · Why partner with us · How partnership works · Partnership tiers · Let’s build something lasting

**Data read (top-level variables/functions):** `old`, `the`, `impact`, `stats`, `reporting`, `partnership`, `you`, `your`, `support_email`, `error`, `verified`, `prestige`, `audience`, `across`, `on`, `naming`, `tier`, `feat`, `people`, `organisations`, `being`, `recognised`, `continent`, `community`, `that`, `identity`, `bought`, `eef7ee`, `circle`, `cx`, `cy`, `excellence`, `beside`, `transparent`, `independently`, `audited`, `honour`, `association`, `earned`, `advertised`, `fff8df`, `social`, `funds`, `child`, `leadership`, `programmes`, `receive`, `can`, `take`, `to`, `board`, `fdeaf0`, `b03a5b`, `us`, `goals`, `through`, `form`, `below`, `partnerships`, `lead`, `reaches`, `out`, `proposal`, `shape`, `package`, `around`, `objectives`, `budget`, `align`, `scope`…

**Forms:**
- `POST /partner` fields: _token[hidden], organisation[text required], contact_name[text required,autocomplete], contact_email[email required,autocomplete], contact_phone[tel autocomplete], tier[select], message[textarea required]; buttons: Request a proposal

**States / branches (4 distinct conditions):** `t.feat` · `error` · `not old.tier|default('')` · `old.tier|default('') == n`

**Links out:** `#enquiry` · `#how` · `/org` · `mailto:{{ support_email }}?subject=Partnership%20enquiry`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-tier`

**Accessibility affordances:** aria-hidden×6, aria-label×6; roles: alert; autocomplete×3

**Legal / consent lines:**
- {'n':'3','t':'Agreement','b':'We align on scope, recognition and the impact you’ll fund.'},

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_the_chooser_offers_every_kind_of_account_this_platform_has` — (no docblock)
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `PaymentControllerTest::test_callback_refuses_confirm_on_amount_mismatch` — (no docblock)
- `PaymentControllerTest::test_callback_unknown_reference_redirects_error` — (no docblock)
- `PublicIaTest::test_the_partner_console_is_reachable_from_the_public_site` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PublicIaTest::test_the_partner_console_is_reachable_from_the_public_site (partner.twig dropped)` **(guard kept, edited)** — /partner carries a visible `href="/org"` (comments stripped): the cold front door back into the organisation console, alongside the footer.
