# Handoff: Africa GATES, designs edited 5 Oct 2026 (strict)

This bundle holds every design edited on 5 Oct 2026, fliers excluded. It is a delta on top of the main bundle `design_handoff_africa_gates/`. Where they differ, this bundle wins.

## About the design files
`design/*.dc.html` are **design references built in HTML**. Open them in a browser with `support.js` beside them and use the Tweaks panel to switch every prop (layout, view, state, theme…). **Recreate** them in the existing PHP 8.4 · Slim 4 · Twig 3 · vanilla JS/Alpine codebase with its patterns. Don't ship the HTML. Exception: `design/assets/celebration/celebration.js` + `.css` are production files; ship them verbatim (amended per AUDIT Q7).

## Fidelity
**High.** Exact hex, ±1px, exact copy. Any value not written in a spec file comes from the DC's inline styles (`specs/REFERENCE.md` §5). Tokens are only those in REFERENCE §6.

## Strict rules
1. Read `specs/AUDIT-2026-10-05.md` first. Its P0 list blocks every item below.
2. The DC is the spec. Match it, don't interpret it. Can't match? Stop and ask. No placeholders, no TODOs.
3. Every screen has three branches: phone <600, tablet 600–1023, desktop ≥1024 (`specs/LAYOUT-BRANCHES.md`). Phone has its own markup where the DC does. Never scale or zoom.
4. Every animation has a reduced-motion path. Targets ≥44px, inputs 16px, visible 3px focus.
5. No AI wording in public. Voting is never shopping language.
6. A task is done only when its **Done when** list is ticked with screenshots at 390, 834 and 1440 beside the DC.

## What changed today, and what to build

### 1. Holiday themes (new)
- DCs: `HolidayBanner` (component, all 10 themes), `HolidayThemes` (board), `AccountPage` (`holiday` prop).
- Spec: `specs/HOLIDAY-THEMES.md` (data model, resolver, theme table, tones, banner geometry, acceptance).
- Member pages only. Banner + a 3px line under the header. Nothing else changes.
- **Done when:** `/account` at 390 and 1440 matches `HolidayThemes` for all 10 themes; dismissed, non-Nigerian on 1 Oct, and RTL cases pass.

### 2. Default graphics (new)
- DCs: `DefaultCover` (component), `DefaultAvatar`, `DefaultGraphics` (every slot at real size).
- Spec: `specs/DEFAULT-GRAPHICS.md` (cover.twig, cover.css, CoverKind resolver, GD share images).
- Every image slot with no upload renders the cover. Never a stock photo, grey box or broken `<img>`.
- **Done when:** each slot in `DefaultGraphics` matches in Twig at its real size, and share images render from GD.

### 3. Events index (rebuilt)
- DC: `EventsPage`, `view=index`, phone/tablet/desktop.
- Spec: `specs/EVENTS-INDEX.md` (order, spotlight carousel, sticky filters, type tiles, carousel rows, hosting band).
- Waitlist hold is 48h (AUDIT item 11).
- **Done when:** the order and states in EVENTS-INDEX hold at all three widths, and empty rows aren't rendered.

### 4. Voting copy fix (AUDIT item 10)
- DCs: `VotePage`, `VoteBallot`, `ResultsPage`.
- Paid votes **add to the tally** (the 30% term). The 70% term counts verified people (`VoterReach`). Remove every "counts the same as a free vote".
- Results: the overall edition winner always shows first (AUDIT Q14).
- **Done when:** no surface says paid votes count the same as free ones, and `/results/{id}` leads with the edition winner.

## Supporting DCs (unchanged today, included so the pages open)
`SiteHeader`, `AppBar`, `MobileMenu`, `DisplayReading`, `Gee`, `ChallengeBanner`, `Celebration`. Build them per the main bundle's Phase 2–3; don't redesign them here.

## Assets
`design/assets/`: `logo-mark.png`, `okun-alimosho-logo.png` (demo host logo), `africa-silhouette.svg` (africa-day pattern), `alimosho-celebrates-nigeria.png` (challenge art), `celebration/`. Holiday SVG patterns ship as static files under `public/assets/img/holiday/` (CSP: no data URIs).

## Files
- `design/`: the 10 edited DCs, 7 supporting DCs, `support.js`, `assets/`.
- `specs/HOLIDAY-THEMES.md`, `specs/DEFAULT-GRAPHICS.md`, `specs/EVENTS-INDEX.md`: strict build specs for today's work.
- `specs/AUDIT-2026-10-05.md`: build-branch faults in fix order, plus answers to GAPS Q4–Q19.
- `screenshots/`: renders of today's designs, captured from the DCs on 5 Oct. `holiday/` (account with Teachers' Day and Independence Day themes, plus the theme board), `default-graphics/` (the slot board), `events/` (index at phone, tablet and desktop), `voting/` (vote page at three widths, the ballot, results at phone and desktop). Frames are scaled to fit; where a screenshot and a DC disagree, the DC wins.
- `specs/LAYOUT-BRANCHES.md`, `specs/REFERENCE.md`, `specs/DESIGN-NOTES.md`: shared rules, tokens and the product owner's standing notes.
