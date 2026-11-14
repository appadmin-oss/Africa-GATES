# `templates/layout/account-auth.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   MEMBER ACCOUNT AUTH — the shell every public account screen wears.

   Sign in, register and the verification notice are three screens with one
   frame: warm ground, brand lockup, one white card, and whatever each screen
   wants to put under it. The frame lives here once.

   ── WHY A LAYOUT AND NOT THREE COPIES ──────────────────────────────────────
   It was three copies. The brand lockup, the column and the footer credit were
   repeated in `login.twig`, `register.twig` and `verify-notice.twig`, so the
   copyright line and the logo mark-up existed three times and could disagree.
   They already had: the sign-in said "Member account" over a pitch about
   points while registration said something else entirely, and nothing on any
   screen showed the drift.
…
```

**Data read (top-level variables/functions):** `assets`, `css`, `components`, `account`, `auth`, `auth_wide`, `ag`, `acct__col`, `wide`

**Links out:** `/`

**Accessibility affordances:** aria-label×1, aria-hidden×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/account-auth.css

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest` (whole file, via a helper/constant/data provider: 19 tests) — test_it_reads_the_table_that_actually_serves, test_every_link_and_form_on_the_member_auth_screens_reaches_a_real_route, test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors, test_the_code_screen_posts_the_address_the_code_was_sent_to, test_a_new_code_is_asked_for_by_post_and_never_by_a_link, test_the_real_code_field_is_visible_until_the_script_paints_the_boxes, test_the_code_field_and_the_boxes_cannot_disagree, test_a_row_marked_hidden_is_actually_hidden, test_the_passkey_row_is_absent_when_the_server_cannot_verify_one, test_recovery_is_offered_and_the_route_behind_it_exists, test_the_chooser_offers_every_kind_of_account_this_platform_has, test_the_individual_form_is_one_click_in_and_keeps_what_was_typed …
- `TemplateSyntaxTest::test_no_template_overrides_a_block_its_parent_does_not_render` — A block the parent never outputs is silently discarded — no error, no page.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccountAuthScreensTest::test_every_link_and_form_on_the_member_auth_screens_reaches_a_real_route` — Every link and form on the member auth screens reaches a real route.
- `AccountAuthScreensTest::test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors` — $rel links to $door — three separate trust domains stay unlisted from the public screen
