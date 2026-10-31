# `templates/pages/registry/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /registry → RegistryController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** Registry · No profiles match · The registry is just getting started

**Data read (top-level variables/functions):** `rows`, `gradient`, `catset`, `has_profiles`, `tc`, `assets`, `img`, `illustrations`, `illo`, `search`, `africa`, `mark`, `e6f1f4`, `eef0f1`, `fff8df`, `ag`, `ground`, `a47306`, `d49a0e`, `b03a5b`, `e0245e`, `profiles`, `eef7ee`

**States / branches (3 distinct conditions):** `p.category and p.category not in catset` · `has_profiles` · `p.avatar_path`

**Links out:** `/registry/{{ p.slug }}` · `/vote` · `/nominate` · `/account/register`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-cat`, `data-name`

**Accessibility affordances:** aria-hidden×3, aria-label×1; alt=×2

**Styling carried:** 1 <style> block(s), 5 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `CspTest::test_every_inline_script_on_a_rendered_page_carries_the_nonce` — (no docblock)
- `CspTest::test_no_rendered_page_uses_an_inline_event_handler` — (no docblock)
- `CspTest::test_every_style_block_on_a_rendered_page_carries_the_nonce` — (no docblock)
- `FormAccessibilityTest::test_the_public_pages_have_no_unlabelled_control` — (no docblock)
- `GeeSupportsTest::test_a_route_is_never_linked_as_the_prefix_of_a_longer_path` — No route may be linked as the PREFIX of a longer path.
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `PageRenderSmokeTest::test_registry_renders_profiles_not_empty_state` — (no docblock)
- `ProfileCpiClaimTest` (whole file, via a helper/constant/data provider: 5 tests) — test_a_judged_profile_is_described_as_judged, test_an_unnominated_profile_is_not_credited_to_a_jury, test_a_pending_nomination_is_told_apart_from_never_having_one, test_the_split_is_read_from_the_rules_and_not_typed_into_the_page, test_with_no_override_the_page_states_the_default_split
- `SchemaTest::test_person_omits_what_it_does_not_have` — (no docblock)
- `SeoCanonicalTest::test_a_paginated_page_canonicalises_to_itself` — THE BUG: the layout built its canonical from the path alone, so `/registry?page=4` declared `/registry` as canonical.
- `SeoCanonicalTest::test_page_one_collapses_to_the_bare_path` — `?page=1` is the same page as no parameter, so it must not mint a second URL.
- `SeoSitemapTest::test_only_approved_registry_profiles_are_listed` — ProfileService::getBySlug() requires `approved`.
- `SiteLinkIntegrityTest::test_paths_needing_a_parameter_are_not_mistaken_for_dead` — (no docblock)
- `TrailingSlashTest::test_the_query_string_survives` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `FormAccessibilityTest::test_the_public_pages_have_no_unlabelled_control` — {$path} has a control with no accessible name
- `PageRenderSmokeTest::test_registry_renders_profiles_not_empty_state` — seeded profile must appear in the grid — *must NOT fall back to the empty state when profiles exist*
