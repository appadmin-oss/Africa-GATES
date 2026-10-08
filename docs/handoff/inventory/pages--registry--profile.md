# `templates/pages/registry/profile.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /registry/{slug} → RegistryController::profile`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Hero ──
```

**Headings:** {{ p.display_name }} · About · Cultural Power Index · Achievements · Moments · Community · At a glance · Connect · Also in {{ p.category }}

**Data read (top-level variables/functions):** `bio`, `cw`, `tier`, `first`, `jw`, `flag`, `ctry`, `tc`, `sav`, `profile`, `basis`, `cheer_count`, `fff8df`, `similar`, `e6f1f4`, `eef0f1`, `ag`, `ground`, `e3f1e3`, `e2e8e9`, `eef2ee`, `cpi_weights`, `comments`

**Forms:**
- `GET (self)` fields: —; buttons: —

**States / branches (20 distinct conditions):** `p.category` · `isVerified` · `p.registered_at` · `p.profile_type and p.profile_type != 'individual'` · `bioText` · `p.avatar_path` · `p.tags is iterable and p.tags|length` · `basis == 'judged'` · `p.cpi_last_computed` · `basis == 'pending'` · `p.achievements is iterable and p.achievements|length` · `p.gallery_paths is iterable and p.gallery_paths|length` · `p.region` · `p.website or p.instagram_handle or p.twitter_handle` · `p.website` · `p.instagram_handle` · `p.twitter_handle` · `similar|default([])|length` · `s.avatar_path` · `not s.avatar_path`

**Links out:** `/registry` · `/vote` · `/integrity` · `{{ p.website }}` · `https://instagram.com/{{ p.instagram_handle|replace({'@':''}) }}` · `https://x.com/{{ p.twitter_handle|replace({'@':''}) }}` · `/registry/{{ s.slug }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `profileApp()`; data hooks: `data-page`; fetches: `/api/community/cheer`, `/api/community/comment`

**Accessibility affordances:** aria-hidden×12, aria-label×2, aria-pressed×2; roles: alert, status; <label for>×3; alt=×1

**Styling carried:** 1 <style> block(s), 9 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ProfileCpiClaimTest::test_a_judged_profile_is_described_as_judged` — a judged profile was disclaimed as though no panel had scored it
- `ProfileCpiClaimTest::test_an_unnominated_profile_is_not_credited_to_a_jury` — The one that shipped wrong, and the reason this file exists. — *a profile no judge has seen was published under a jury panel heading*
- `ProfileCpiClaimTest::test_a_pending_nomination_is_told_apart_from_never_having_one` — Nominated-but-unjudged is its OWN answer, and that distinction is for the person reading their own page: "nobody has finished judging you" is not "you were never put forward", and a page that says the second to somebody in the first case is telling them their nomination did not happen. — *a nominee waiting on a panel is told they were never nominated*
- `ProfileCpiClaimTest::test_the_split_is_read_from_the_rules_and_not_typed_into_the_page` — The weights come from the rule engine. — *the page states a community weight the rule engine does not hold*
- `ProfileCpiClaimTest::test_with_no_override_the_page_states_the_default_split` — And with nothing overridden it states the platform default, rather than nothing.
- `SupportSurfaceRenderTest::test_a_registry_bio_no_longer_swallows_the_nomination_brief` — and when the two are the same text, only one of them prints
