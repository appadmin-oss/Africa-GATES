# `templates/pages/honour.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /honour/{reference} → HonourController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  A GUEST OF HONOUR'S PASS.

  ── THE DIRECTION, IN ONE SENTENCE ──────────────────────────────────────────
  An engraved credential: ink ground, one gold hairline, Playfair over JetBrains
  Mono, a real perforation between the stub and the plate. The same voice as the
  invitation email that carries it — no gradients, no glass, no icon rows.

  ── WHAT THE FIRST VERSION GOT WRONG ────────────────────────────────────────
  It was three near-identical rounded cards stacked down a dark page, with a
  decorative spinner beside the code. Everything had the same weight, so nothing was
  the pass; the "countdown" span told you a code refreshes without telling you when;
  and there was no state for the two things that actually happen at a door — no
  signal, and a code that went stale in a pocket.

…
```

**Headings:** {{ event.title }}

**Data read (top-level variables/functions):** `event`, `invite`, `step`, `lowest_tier`, `audience`, `discount`

**States / branches (3 distinct conditions):** `lowest_tier` · `event.venue` · `event.location`

**Links out:** `/events/{{ event.slug }}`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-ref`, `data-step`; fetches: `/honour/`

**Accessibility affordances:** aria-hidden×3, aria-live×1; roles: status; alt=×1; prefers-reduced-motion×2

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `EventInvitesTest` (whole file, via a helper/constant/data provider: 63 tests) — test_the_ceremony_is_found_from_its_programme, test_the_minimum_support_is_the_cheapest_tier_on_sale, test_the_plan_lists_the_shortlist_and_names_who_cannot_be_reached, test_an_ambiguous_name_is_reported_rather_than_guessed, test_an_unshortlisted_nominee_is_not_invited, test_an_event_with_no_awards_says_so_and_says_where_to_fix_it, test_a_newer_empty_cycle_does_not_hide_the_shortlist, test_an_empty_nominee_row_explains_itself, test_a_send_batch_is_not_all_judges_before_any_nominee, test_build_reports_each_audience_separately, test_a_failed_mint_is_named_rather_than_silently_uncounted, test_an_organiser_can_supply_a_missing_address …

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `EventInvitesTest::test_every_action_clears_the_touch_floor` — Touch targets. A pass is used one-handed, in a queue, in the dark.
- `EventInvitesTest::test_the_countdown_is_informative_motion_not_decoration` — The countdown is a depleting ring, not a spinner. — *the ring does not deplete*
- `EventInvitesTest::test_the_id_page_is_never_cached_and_never_indexed` — The code on screen is valid for one window.
- `EventInvitesTest::test_the_id_page_shows_the_pass_the_ask_and_the_evening` — the reference is read aloud at the door — *the quota promised must be on the pass*
- `EventInvitesTest::test_the_pass_carries_two_actions_and_no_more` — Hierarchy is subtraction: two actions on the surface, and the rest on the event page. — *the pass grew a third action — the schedule and the map belong on the event page*
- `EventInvitesTest::test_the_pass_is_built_like_a_pass` — The first version was three near-identical rounded cards stacked down a dark page. — *no stub — the evening has nowhere to sit*
- `EventInvitesTest::test_the_pass_uses_the_sites_own_type_tokens` — The site already loads Playfair Display, DM Sans and JetBrains Mono and exposes them as tokens. — *the layout already loads the faces — a second link is a second render-blocking request*
- `EventInvitesTest::test_the_unhappy_states_exist` — The two things that actually happen at a door: no signal, and a stale code. — *no offline state — at a venue, on venue wifi*
