# `templates/pages/community/threads.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /community → CommunityController::threadsIndex`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Community forum index (community v2) ─────────────────────────────
   Access model: guests read everything; every write needs a member.
   Data: threads, spaces, active_programme, active_sort, stats, trending,
   next_event, flash_notice, member_logged_in, member_name.
```

**Headings:** The Community · Spaces · Community charter · Discussion threads · {% if active_programme %}This space is quiet — for now{% else %}No threads here  · {{ p.title }} · Community pulse · Trending now · {{ next_event.title }}

**Data read (top-level variables/functions):** `active_programme`, `diff`, `space`, `next_event`, `tr`, `stats`, `active_sort`, `member_logged_in`, `threads`, `sp`, `on`, `login_url`, `dt`, `ui`, `member_name`, `programme`, `av`, `trending`, `sort`, `top`, `rule`, `flash_notice`, `ts`, `spaces`, `cur_path`, `the`, `signed`, `post`, `pin`, `name`, `fff8df`, `effaf0`, `e9efef`, `f3eef7`, `fdeaf0`, `e6f0f4`, `community`, `account`, `login`, `next`, `work`, `never`, `person`, `canvassing`, `stays`, `out`, `of`, `beat`, `opinions`, `every`, `time`

**States / branches (25 distinct conditions):** `diff < 60` · `diff < 3600` · `diff < 86400` · `diff < 604800` · `n == ''` · `active_programme == 0` · `active_programme == s.id` · `s.count > 0` · `member_logged_in` · `flash_notice` · `active_sort == 'latest'` · `active_sort == 'top'` · `active_programme and spaceMap[active_programme] is defined` · `threads is empty` · `active_programme` · `p.is_pinned` · `sp` · `p.status == 'locked'` · `p.body` · `p.body|striptags|length > 220` · `trending is not empty` · `spaceMap[tr.programme_id] is defined` · `next_event` · `next_event.location` · `next_event.slug`

**Links out:** `/community{{ active_sort == 'top' ? '?sort=top' : '' }}` · `/community?programme={{ s.id }}{{ active_sort == 'top' ? '&sort=top' : '' }}` · `/integrity` · `/community/new` · `{{ login_url }}` · `/community?sort=latest{{ active_programme ? '&programme=' ~ active_programme : '` · `/community?sort=top{{ active_programme ? '&programme=' ~ active_programme : '' }` · `/community/{{ p.slug }}` · `/community/{{ tr.slug }}` · `/events/{{ next_event.slug }}` · `toast.href`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `cmForum({{ {signedIn: member_logged_in ? true : false}|json_`, `{ on:false, n:{{ p.cheer_count|default(0) }}, busy:false }`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×16, aria-label×7, aria-labelledby×6, aria-pressed×4, aria-current×4; roles: status; visually-hidden text×1; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 14 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `GeeSupportsTest::test_a_route_is_never_linked_as_the_prefix_of_a_longer_path` — No route may be linked as the PREFIX of a longer path.
