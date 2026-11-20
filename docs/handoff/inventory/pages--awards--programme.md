# `templates/pages/awards/programme.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /awards/{p} → AwardsController::programme`
**Extends:** layout/gates.twig · **Includes/imports:** partials/ui.twig, partials/promo-carousel.twig, partials/challenge-strip.twig

**What the page says it is (its own header comment, abridged):**
```
  ══ /awards/{slug} — BUILT FROM `AwardsPage.dc.html` (view: detail) ══════════════

  ── HOW THIS PAGE IS PUT TOGETHER ───────────────────────────────────────────────

    partials/ui.twig     subnav · facts · pill · row · empty
    components.css       what those look like everywhere
    components/awards.css  the hero, the edition card and the category race — the
                         three things only an award page has

  ── EVERY FACT IS WORKED OUT, NOT WRITTEN ───────────────────────────────────────

  The phase, its dates, the one action a reader can take and the scoring split come
  from `AwardOverview`, which reads `CyclePolicy` and `RuleEngine`. A percentage typed
  here would be the 157-points-out settings screen again.
…
```

**Headings:** {{ a.title }} · About the award · Terms · Edition {{ ed ?: a.year }}{% if ed and a.year and (a.year ~ '') not in ed %} · { · Categories · Backed by

**Data read (top-level variables/functions):** `phase`, `host`, `ui`, `st`, `nav`, `tab`, `facts`, `ed`, `action`, `aw`, `sponsors`, `assets`, `css`, `components`, `awards`, `hero`, `art`, `this`, `award`, `aria`, `current`, `are`, `published`, `yet`, `appear`, `here`, `when`, `the`, `edition`, `opens`, `nominations`, `spon__i`, `headline`, `tiers`, `programme`, `views`, `key`, `label`, `href`, `timeline`

**States / branches (22 distinct conditions):** `host` · `host.logo` · `host.url` · `a.subtitle or a.description` · `a.cover` · `tab == 'details'` · `a.description` · `facts|length` · `tab == 'terms'` · `a.cycle` · `ed and a.year and (a.year ~ '') not in ed` · `phase` · `phase and phase.detail` · `action` · `a.categories|default([])|length` · `a.categories|first.leader` · `c.leader` · `c.leader.photo_path` · `c.total_votes|default(0)` · `sponsors` · `s.website` · `s.blurb`

**Links out:** `/awards` · `{{ host.url }}` · `{{ action.href }}` · `?tab=details` · `{{ s.website }}` · `/integrity`

**JS behaviours:** data hooks: `data-state`

**Accessibility affordances:** aria-labelledby×6, aria-current×1; alt=×3

**Legal / consent lines:**
- {% elseif tab == 'terms' %}
- Terms
- {{ a.terms|nl2br }}

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/awards.css

**Guard tests that read this page (at the time of the destroy):**
- `SponsorshipIntegrityTest::test_the_public_template_marks_paid_links_as_sponsored` — The public page carries `rel="sponsored"`, which is what these links are.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AwardPageTest::test_since_is_counted_from_the_editions` — Since is counted from the editions.
- `AwardPageTest::test_the_cover_is_the_awards_own` — The award's own picture, never the stock photo the old page hotlinked for every award. — *a stock photo is not this award*
- `AwardPageTest::test_the_overview_renders_the_comps_sections` — no step is marked as now
- `AwardPageTest::test_the_scoring_fact_moves_with_the_rules` — The scoring split is the scorer's, for this programme — never a typed figure.
- `AwardPageTest::test_views_are_urls_and_terms_is_offered_only_when_written` — a Terms view over nothing is a promise the award has not made — *an unknown view falls back to the overview*
- `PhaseSurfaceRenderTest::test_programme_page_offers_voting_when_voting_is_open` — the voting CTA must render during voting, and deep-link to THIS programme, not the hub — *the phase must be stated*
- `PhaseSurfaceRenderTest::test_programme_page_offers_nominating_when_nominations_are_open` — and must not offer voting yet
- `PhaseSurfaceRenderTest::test_programme_page_offers_results_when_published` — a finished cycle is not votable
- `PhaseSurfaceRenderTest::test_programme_page_states_the_phase_even_with_no_action_available` — and say when the next action arrives
- `PhaseSurfaceRenderTest::test_programme_page_does_not_hardcode_a_cycle_year` — the year used to be a literal
- `SponsorshipIntegrityTest::test_the_public_template_marks_paid_links_as_sponsored` — The public page carries `rel="sponsored"`, which is what these links are.
