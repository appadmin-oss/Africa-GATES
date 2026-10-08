# `templates/pages/blog/post.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /blog/{slug} → BlogController::show`
**Extends:** layout/gates.twig · **Includes/imports:** partials/poll.twig

**Headings:** {{ post.title }} · More from the blog

**Data read (top-level variables/functions):** `post`, `more`

**States / branches (5 distinct conditions):** `post.tag` · `post.cover_image` · `post.audio_path` · `more is not empty` · `p.cover_image`

**Links out:** `/blog` · `/blog/{{ p.slug }}`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** alt=×1

**Styling carried:** 1 <style> block(s), 4 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
