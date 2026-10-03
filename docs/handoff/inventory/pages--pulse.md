# `templates/pages/pulse.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /pulse → PulseController::index`; `GET /pulse/reels → PulseController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 This page renders flash_* itself, beside the thing the message is about, so the
   layout rail is suppressed — otherwise every message would appear twice.
```

**Headings:** What the continent is talking about. · Pulse — what the continent is talking about · Alerts

**Data read (top-level variables/functions):** `post`, `is_member`, `feed`, `member_name`, `cover`, `flash_notice`, `flash_error`, `channels`, `posts`, `leaders`, `events`, `threads`, `pulse_max`, `media_limit`, `items`, `cursor`, `feed_cursor`, `head`, `feed_head`, `signed`, `member`, `account`, `login`, `own_flash`

**Forms:**
- `POST /pulse` [enctype,x-data] fields: _token[hidden], body[textarea maxlength], media[file]; buttons: {{ (member_name|default('You'))|first|upper }} Sha | × | Post

**States / branches (18 distinct conditions):** `is_member` · `flash_notice` · `flash_error` · `feed|default([]) is not empty` · `posts is not empty` · `post.published_at` · `cover` · `post.category is defined and post.category` · `(post.excerpt|default(post.body|default('')|striptags))|length > 160` · `feed|default([]) is empty` · `leaders is not empty` · `p.avatar_path` · `not p.avatar_path` · `events is not empty` · `e.location` · `threads is not empty` · `(t.body|default('')|striptags)|length > 120` · `t.created_at`

**Links out:** `/account/login?next=%2Fpulse` · `it.author_slug ? ('/registry/' + it.author_slug) : null` · `'/pulse?channel=' + it.programme_id` · `it.link` · `'/community/' + it.slug` · `signInUrl` · `{{ '/account/login'|e }}` · `/blog` · `toast.href` · `/community/{{ t.slug }}` · `/blog/{{ post.slug }}` · `/registry/{{ p.slug }}` · `/vote` · `/community` · `/events` · `a.href`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `plPage({{ (is_member|default(false))|json_encode }})`, `plCompose({{ pulse_max|default(600) }}, {{ (media_limit|defa`, `plFeed({{ {
               items:   feed|default([]),
      `; data hooks: `data-page`, `data-held`, `data-kind`; fetches: `/api/v1/pulse/alerts/count`, `/api/v1/pulse/alerts`, `/api/v1/pulse/alerts/read`, `/api/v1/pulse/new?`

**Accessibility affordances:** aria-hidden×26, aria-label×20, aria-pressed×16, aria-haspopup×1, aria-expanded×1, aria-modal×1, aria-labelledby×1; roles: status, alert, group, img, dialog; visually-hidden text×6; <label for>×4; alt=×2; prefers-reduced-motion×3; noscript×1

**Legal / consent lines:**
- // Parsed from the server's own limit string so the two cannot disagree.

**Styling carried:** 1 <style> block(s), 14 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `FeedLinkifyTest::test_the_feed_styles_the_class_the_shared_helper_inserts` — AND SOMETHING STYLES IT.
- `FlashKeyTest::test_pages_that_render_their_own_flash_suppress_the_layout_rail` — The pages that place their own flash must opt OUT, or every message appears twice.
- `PageRenderSmokeTest::test_pulse_renders_community_threads` — (no docblock)
- `PulsePostingTest::test_a_member_can_post_to_the_pulse` — (no docblock)
- `PulsePostingTest::test_a_guest_is_sent_to_sign_in_and_writes_nothing` — (no docblock)
- `PulsePostingTest::test_a_malformed_body_is_rejected_without_a_warning` — A malformed body is rejected cleanly, with no PHP warning and no fatal.
- `PulseTimelineTest` (whole file, via a helper/constant/data provider: 6 tests) — test_a_card_is_shaped_by_what_the_post_actually_is, test_there_is_one_action_rail_and_not_one_per_card_shape, test_the_result_panel_draws_the_typed_payload, test_the_split_is_written_out_and_not_only_drawn, test_the_feed_is_one_divided_column, test_a_phone_gets_the_timeline_rather_than_a_snap_feed
- `SeoSitemapTest::test_the_public_surfaces_and_policies_are_all_listed` — Each of these returns 200, is indexable, and was in no section.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `FeedLinkifyTest::test_the_feed_styles_the_class_the_shared_helper_inserts` — AND SOMETHING STYLES IT. — *a text post does not style ag-link, so shared links render as default blue*
- `PageRenderSmokeTest::test_pulse_renders_community_threads` — community threads must show on Pulse
- `PulseTimelineTest::test_a_card_is_shaped_by_what_the_post_actually_is` — the card no longer chooses a shape — every post is drawn the same way again
- `PulseTimelineTest::test_the_feed_is_one_divided_column` — HAIRLINES, NOT A MARCH OF BOXES.
- `PulseTimelineTest::test_the_result_panel_draws_the_typed_payload` — THE RESULT PANEL READS THE PAYLOAD, NEVER THE PROSE.
- `PulseTimelineTest::test_the_split_is_written_out_and_not_only_drawn` — THE BAR IS NEVER THE ONLY CARRIER OF A NUMBER. — *the index bar is exposed to a screen reader as content*
- `PulseTimelineTest::test_there_is_one_action_rail_and_not_one_per_card_shape` — ONE RAIL. The same markup and the same handlers sit over the picture on a media card and in flow beneath a light one; only the CSS moves it. — *the action rail has been copied per card shape*
- `PulseTimelineTest::test_a_phone_gets_the_timeline_rather_than_a_snap_feed` — THE PHONE IS NOT A DIFFERENT PRODUCT.
