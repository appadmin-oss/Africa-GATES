# `templates/pages/challenges/show.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** layout/gates.twig · **Includes/imports:** partials/ui.twig, partials/icons.twig

**What the page says it is (its own header comment, abridged):**
```
  ══ /challenges/{slug} — BUILT FROM `ChallengePage.dc.html` ON THE SHARED LIBRARY ══

  ── HOW THIS PAGE IS PUT TOGETHER ───────────────────────────────────────────────

    partials/ui.twig    steps · ticks · notice · faq · row · meter · pill · tile
    components.css      what each of those looks like, on every page
    challenge.css       the hero, the rail and the theme — the three things only a
                        challenge has

  ── NOT ONE SENTENCE IS WRITTEN HERE ────────────────────────────────────────────

  Every word a reader sees comes out of `copy`, which is `ChallengeCopy::for()` — the
  comp's own `derive()`, ported. A string typed into this file would be a second place
  a challenge's terms are written, and the two would disagree the first time somebody
…
```

**Headings:** {{ c.title }} · How to take part · {{ copy.count_title }} · {{ copy.win_title }} · Included · Questions · More challenges

**Data read (top-level variables/functions):** `copy`, `ui`, `host`, `spots`, `claimed_n`, `label`, `sub`, `href`, `tone`, `cta`, `btn`, `secondary`, `entry`, `winners`, `included`, `others`, `icon`, `challenges`, `pill`

**States / branches (12 distinct conditions):** `c.art_url` · `c.flag` · `host` · `host.logo` · `host.url` · `copy.has_meter and spots|length` · `entry` · `copy.steps|length` · `winners|length` · `included|length` · `copy.terms_version` · `others|length`

**Links out:** `{{ host.url }}` · `/terms` · `{{ copy.cta_href }}` · `/challenges/{{ c.slug }}/flier.png`

**JS behaviours:** data hooks: `data-theme`, `data-state`, `data-share-url`, `data-share-title`

**Accessibility affordances:** aria-labelledby×7, aria-hidden×2, aria-label×1; alt=×2

**Legal / consent lines:**
- {% if copy.terms_version %}
- Full challenge terms · {{ copy.terms_version }}

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `TemplateSyntaxTest::test_no_child_template_puts_markup_after_its_last_endblock` — The specific shape of the bug, named so a regression reads as itself rather than as one line inside a list of two hundred.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ChallengeFlierTest::test_the_themes_are_the_challenge_pages_tokens` — The flier's palette is the challenge page's own, value for value: a blue challenge whose page is blue and whose flier is green is two products. — *no $theme block*
- `ChallengePageTest::test_the_comps_sections_render_in_order_from_the_library` — {$mark} is missing — *{$piece} is not on the page*
- `ChallengePageTest::test_one_main_landmark` — the page opens a second <main>
- `ChallengePageTest::test_each_themes_solid_holds_white_text` — Every theme's primary action carries white text on a colour that holds it. — *%s: white on %s is %.2f:1*
