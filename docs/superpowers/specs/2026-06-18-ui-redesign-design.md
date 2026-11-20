# Africa GATES — UI/UX Redesign (Light Premium) — Design Spec

**Date:** 2026-06-18 · **Status:** approved-by-iteration, streamlined (verify live in browser preview)

Sitewide UI/UX upgrade on a new "Light Premium" language, with three concrete
deliverables. Built in order, each verified in the preview before the next.

## Design language (sitewide foundation)
- **Palette:** ivory canvas `#F6F1E7`; ink `#211C16`; deep green `#1C5A3A`; brass gold `#C49A48`. Soft surfaces `#FBF8F1` / `#EFE7D6`.
- **Type:** Display = **Source Serif 4** (warm humanist serif — added to the `gates.twig` Google Fonts line). Body = **DM Sans** (already loaded). Mono = JetBrains (already loaded).
- **Frameless cut-outs:** transparent-PNG people placed DIRECTLY on the canvas — NO frame/box/arch/disc behind them. Grounded with a soft elliptical contact shadow + deliberate edge-bleed so they read as placed, never cropped. Honor the existing `public/assets/img/cutouts/` slot convention (`map-1..3.png`, `band-1.png`; fade in when present, clean when absent).
- **Motion:** restrained — subtle rise/fade + light parallax via the already-loaded GSAP/ScrollTrigger; respect `prefers-reduced-motion`.
- **Scoping:** global layers load in `gates.twig`; scope page-specific rules via `body[data-page="…"]`. Add a single new stylesheet `premium-2026.css` linked once; pages may also use `{% block head_styles %}` / `{% block foot_scripts %}`.

## Deliverable 1 — `/nominate` rebuild (UI + logic) — FIRST
- **4-step wizard:** Programme → Nominee → Your details → Review & verify (OTP). Progress bar, one focused group per step, inline validation, sticky Continue, encouraging copy.
- **Mobile:** renders as a **draggable bottom sheet** (drag handle, snap up/down, swipe between steps). Alpine-powered.
- **Preserve the contract:** POST `/nominate`; required fields `programme_id, nominee_name, country_code, reason, nominator_name, nominator_email, nominator_phone, nominator_country, nominator_state, nominator_lga`; programme/`all_programmes` data; localStorage draft-saving; OTP verification; server-side error re-render (`error` var, 422).

## Deliverable 2 — `/` home two-column — SECOND
- Replace centered blocks with **alternating cut-out image ▌ text** rows (image left/text right, then swap).
- **Short** section copy — images do the showcasing; text is a tight headline + 1–2 lines.
- **Cinematic but restrained:** large imagery, subtle scroll reveal/parallax; elegant, not flashy.
- Keep real data (stats, leaderboard, events, blog); frameless cut-outs per the convention.

## Deliverable 3 — Footer fix — THIRD
- Tidy link columns + compact brand lockup + one CTA/newsletter line + legal row.
- Remove the oversized "Afrovanguard" wordmark that currently dominates.

## Verification
- Each deliverable verified in the live browser preview (load, interact, responsive/mobile, dark/reduced-motion where relevant) before moving on.
