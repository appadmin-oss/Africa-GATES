# Africa GATES: standing design notes (from user feedback, apply everywhere)

## Visual
- Clean over busy. "Rough" = too many boxes, borders, dark fills, uppercase labels and repeated info. Default to hairline dividers, sentence-case headings, white or ground surfaces, and one accent per block.
- No dark backgrounds unless asked. Avoid heavy dark cards and bands.
- No green buttons in the header. Green is for the primary action inside the page.
- Chips and filter pills are outlined/light, not dark.
- Don't show the same fact twice on one screen (e.g. a close date in the hero and again in a rail).

## Layout
- Design each breakpoint on purpose; never scale down. Phone <600, tablet 600–1023, desktop ≥1024. Measure the container, and switch layouts on measured width, not only the layout prop (e.g. the 3-column desktop layout only at ≥1200).
- Desktop should feel like desktop: side rails, columns and sticky panels, not a stretched phone.
- **Phone = native-app patterns, with its own markup branch:**
  - a compact sticky app bar (title + icon actions);
  - a sticky horizontal chip toolbar;
  - bottom sheets for filters, cart and options;
  - full-bleed swipe galleries with a content sheet overlapping them;
  - primary actions in a sticky bottom bar within thumb reach;
  - 44–52px targets everywhere, 16px inputs (no iOS zoom), native selects;
  - no hover-only affordances;
  - no desktop blocks squeezed into one column.
- Check every fixed-column grid at 1024px so no text column collapses.

## Floating UI
- **Gee** must always clear any bottom-fixed element with ≥16px gap:
  - 96px above the tab bar;
  - 108px above sticky buy/give/vote bars;
  - 40px when there's no bar (home-indicator safe area).
- Pass `offset` on every page, and update it when a bar appears.
- The desktop cart is an icon button with a count badge (no total text). Drawers float 12px from the edges with a 20px radius and a light scrim.

## Product rules
- Voting: follow the codebase (free vote + optional paid contribution tiers; name, phone, email and message required, the name shown publicly is optional). Never shopping language. Judging happens after voting.
- Events: most require tickets. Tier colours tint the ticket card; radios stay neutral.
- Admin screens: notes only in the handoff, never designed.
- The nomination category AI is admin/judge only. Never mention AI publicly; "Gee" is just the name.
- Awards have editions and terms. The overall edition winner shows first on results.

## Process
- Read the current codebase template before redesigning any page, and keep all its features.
- Small request = small edit. Don't redesign untouched parts.
- Keep `HANDOFF-CLAUDE-CODE.md` updated in the same turn as any design change.

- Follow `skills/app-ux-standards/SKILL.md` for every screen size and every celebration moment.
