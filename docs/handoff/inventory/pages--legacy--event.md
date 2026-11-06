# `templates/pages/legacy/event.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /legacy/{slug} → LegacyController::event`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** {{ e.title }} · Gallery

**Data read (top-level variables/functions):** `img`, `images`, `the`, `assets`, `africa`, `watermark`, `milestone`, `continental`, `record`, `highlights`, `jury`, `rationales`, `full`, `winner`, `attribution`, `are`, `preserved`, `vault`, `posterity`, `event`

**States / branches (3 distinct conditions):** `e.cover_path` · `e.recap_html` · `images is iterable and images|length > 0`

**Links out:** `{{ img }}` · `/legacy`

**JS behaviours:** data hooks: `data-anim-delay`

**Accessibility affordances:** aria-hidden×1; alt=×3

**Styling carried:** 0 <style> block(s), 11 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
