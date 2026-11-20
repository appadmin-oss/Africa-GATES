# `templates/pages/vote-flier.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/{program}/{slug:[0-9]+[^/]*}/flier → FlierController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   The nominee's shareable flier.

   THE SVG IS THE FEATURE, not the fallback. It is embedded below as a real
   <img> pointing at …/flier.svg, so with JavaScript switched off a nominee can
   still see it, right-click-save it, share it and print it. The download
   buttons only add convenience.

   THE PNG IS DRAWN HERE, IN THE BROWSER, and that is deliberate. See
   FlierService for the full reasoning; the short version is that GD on a shared
   host frequently has no TrueType font at all, so a server-rendered PNG would
   silently degrade to a bitmap face on exactly the deployment nobody can
   inspect. The browser has something the server does not: DM Sans and
   Playfair Display, already loaded and ready. `document.fonts.ready` makes that
…
```

**Headings:** A flier for {{ f.name }}

**Data read (top-level variables/functions):** `png_url`, `svg_url`, `file_name`

**States / branches (2 distinct conditions):** `f.standing.field >= 2` · `f.ai`

**Links out:** `{{ png_url }}` · `{{ svg_url }}` · `{{ f.url }}`

**JS behaviours:** 1 inline <script> block(s)

**Accessibility affordances:** aria-labelledby×1, aria-label×1, aria-live×1, aria-busy×1; roles: img, status; alt=×1

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `StandingsAndFlierTest::test_the_svg_and_the_canvas_do_not_drift_apart` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `StandingsAndFlierTest::test_the_bundled_fonts_are_the_faces_the_site_actually_loads` — Montserrat is not a face this site loads
- `StandingsAndFlierTest::test_the_svg_and_the_canvas_do_not_drift_apart` — the flier must have exactly one renderer, and it is server-side — *the footnote belongs to FlierLayout; a literal here is a second copy waiting to drift*
