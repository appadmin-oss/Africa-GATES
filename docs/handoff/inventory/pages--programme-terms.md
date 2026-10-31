# `templates/pages/programme-terms.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /terms/{slug} → closure routes.php:2664`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** {{ programme.icon_emoji }} {{ programme.title }} — Terms

**Data read (top-level variables/functions):** `programme`

**States / branches (1 distinct conditions):** `programme.terms`

**Links out:** `/` · `/terms` · `/integrity` · `/privacy`

**Legal / consent lines:**
- Africa GATES · Terms · {{ programme.title }}
- {{ programme.icon_emoji }} {{ programme.title }} — Terms
- {% if programme.terms %}
- {{ programme.terms|sanitize_html }}
- Specific terms for this programme haven't been published yet. The platform-wide Terms of Service apply in the meantime, and our Integrity &amp; Methodology page
- See also the platform Terms of Service and Privacy Policy.

**Styling carried:** 0 <style> block(s), 13 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
