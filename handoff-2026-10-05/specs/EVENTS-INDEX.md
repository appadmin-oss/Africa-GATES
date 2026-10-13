# Events index: strict build spec

Design: `design/EventsPage.dc.html`, `view=index`, at phone, tablet and desktop. Template: `pages/events.twig`. This replaces the old index (one featured card + a flat list).

## Order (all layouts)
1. **Heading** (tablet/desktop only; on phone the AppBar title is the h1): "Events", one line of context, and an outlined "Host an event".
2. **Spotlight carousel**, 4 slides chosen by the admin (`gates_site_events.spotlight_rank`, 1–4, NULL = not featured).
3. **Sticky toolbar**: search, then the quick-filter chips (All · This week · This weekend · Online · Free) and a native Place select.
4. **Browse by type**: 6 tiles, each a `DefaultCover` (1:1, content=none, no pill) + label + "N upcoming".
5. **Carousel rows**, in this order: This week · Online and livestreams · Award ceremonies · Learn something · Watch again (recordings). A row with no items is not rendered.
6. **Hosting band**: white card, ink button "Create an event" (not green; the page's green is ticket CTAs only).

Any filter, chip, type tile or search switches 4–6 for a **results grid** (count heading with `aria-live`, "Clear filters", empty state with a cover and "Show all events").

## Spotlight (tablet/desktop)
- A card 460px tall (400 on tablet), radius 28, filled with the slide's tone wash (`EventTierTone` roles, via `CoverKind`). Text column: type pill · relative time chip ("In 62 days", "Tomorrow", "Happening now") · full date ("Saturday 6 December 2026 · 18:00 EAT") · Playfair title · place · host · faces + "1,284 going" (or "watching" if live) · the green CTA + an outlined secondary. Visual column: the event photo, or `DefaultCover` content=none with `fit=fill`; a 96px wash fade on the inner edge.
- **Motion:** crossfade 600ms. The photo eases from scale 1.06 to 1 over 7s while its slide shows. Auto-advance every 6.5s, driven by the progress bar's own CSS animation (`animationend` advances).
- **Controls (WCAG 2.2.2):** "2 / 4", tab-style progress bars (44px hit height; the active one is 56px wide and fills), Pause/Play, Previous, Next. Advancing stops while the pointer or focus is inside, when the user presses Pause, and always under `prefers-reduced-motion` (no animation at all then). Hidden slides get `visibility:hidden` after the fade, so they leave the tab order. `aria-live` is "off" while running and "polite" when paused.
- Markup: `role="region" aria-roledescription="carousel"`; each slide `role="group" aria-roledescription="slide" aria-label="2 of 4: {title}"`.

## Spotlight (phone)
A native horizontal scroll-snap row of 86%-wide cards (4:3 visual + text on the wash), with decorative dots. No auto-advance on phone.

## Cards
- Width 276px desktop · 244px tablet · 72% phone (recordings 372 / 300 / 84%). Gap 18 (phone 12). `scroll-snap-type:x mandatory`; on phone the track bleeds to the screen edges with 16px scroll padding.
- Cover 4:3, radius 18: the photo **with** the same date tile the default cover draws (month in the tone ink, Playfair day) and a "Live now" pill when live; or `DefaultCover` content=date. Save heart: a 34px white disc in a 48px target, `aria-pressed`.
- Body: title (2 lines), "place · time" (1 line), price (bold, tabular) + one status chip at most: Selling fast or Early bird (gold wash) · N left (live wash) · Waiting list (stone).
- Desktop row arrows scroll the track by 90% of its width (smooth). "See all N" appears when a row has more than 3 items and applies that row's filter.
- Recording cards: 16:9 cover, a 56px white play disc, a duration chip, then title and "host · recorded {date}".

## Data
`spotlight_rank TINYINT NULL` and `cover_kind` (see DEFAULT-GRAPHICS §4) on `gates_site_events`; recordings come from events with `recording_url` set. "Going" = confirmed tickets + RSVPs, never seeded. Faces are three random attendees who chose to be shown publicly, or are omitted.
