# The homepage globe — "We are Africa"

`templates/partials/we-are-africa.twig` · `public/assets/js/we-are-africa.js` ·
`public/assets/css/components/home.css` (`.waa`) · `src/Services/GlobeBand.php`

Rebuilt in Phase 4 from `design/WeAreAfrica.dc.html` (the previous light band, its d3 script and
its stat card were destroyed — their rules are in `docs/handoff/inventory/pages--home.md`,
`_partials.md`, `_scripts.md`, `_stylesheets.md`). A dark band, 560px tall on a phone and 720 from
600px, with a large orthographic globe centred on Africa, the heading over it and one line at its
foot. The band narrows into the page and rounds its corners while it is away from the middle of
the scroller and reaches the edges as it arrives there; under reduced motion it is simply drawn
full-bleed.

## What is on it, and what it is allowed to claim

The design pops photo pins of invented people over invented cities on a timer ("Ngozi in Enugu
just joined"). None of that is recorded here, and this component has already been rebuilt once to
remove exactly that kind of fake telemetry (sixteen cities, ballot counts, verification latencies).
So the band is driven from the one geographic fact the platform holds: **where the nominees are**.

A marker is a nation with an **approved nominee standing in a live award** — `NationsLive`'s
definition, reached by the same joins, so the globe and the footer's "live in …" sentence cannot
disagree. The join reaches only active programmes, which is also what keeps the sandbox off the
homepage without a filter anybody has to remember.

The line at the foot is `GlobeBand::note()`: what the markers are, and — said out loud — any live
nation the map has no outline for (`GlobeBand::unplaced()`).

## Two marker shapes, and the difference is a fact

A plain dot is a nation still competing. The ringed marker with the check is a nation where an
award has been **decided** — a nominee crowned (`decided`, `status = 'winner'`). That is rare by
nature, which is why it is the highlight: an earlier cut mapped the ring onto "has votes", which
nearly every nation qualifies for the moment an award opens, and the band came out all rings. A
highlight almost everything qualifies for is a background. Decided nations carry a name label;
where none is decided yet, the busiest one does, so a new edition is not a globe of unlabelled dots.

Each marker is a real `<button>`. Enter or a click opens the nation's card — **nominees standing**
and **votes cast**, the only two rows the platform can fill — and outlines that one country. Only
the selected country is ever outlined.

## Geometry

Natural Earth 110m, `public/assets/geo/countries-110m.json`, served from this origin. The script
projects it itself (the DC's own orthographic projection on a canvas), so no vendored library is
loaded. A marker sits at the **centroid of its country's largest polygon**, matched by Natural
Earth's own **name** (`GlobeBand::GEOMETRY`, e.g. `CD` → "Dem. Rep. Congo"). A name that does not
match throws nothing — the marker is simply absent — so `GlobeBandTest` pins every name against the
shipped file, against the script's `AFRICA` set and against `NationsLive::NAMES`.

The sphere is the DC's: radius `max(w × 0.62, 520)` (phone `max(w × 0.72, 260)`), centred at
`h × 0.5 + R × 0.12`. Colours are `Support\Accent` tokens read off `:root` and mixed in the script;
no colour is typed there.

## Things that will bite

- **`setPointerCapture` eats a marker's click** if the press that starts a drag can begin on a
  marker: the browser sends the following `click` to the capturing element. Only the canvas listens
  for `pointerdown`; the markers are its siblings.
- **`touch-action: pan-y`, never `none`.** The band is up to 720px tall; `none` would stop a finger
  that lands on it from scrolling the page. A finger turns the globe round the equator only.
- **A far-side marker is still focusable** (it fades, it does not leave the tab order), so focusing
  it turns the globe to it (WCAG 2.4.7); the arrow keys turn the globe (2.5.7).
- **The card sits inside the band at `z-index:2`**, above every marker. The old band put its card
  inside a lower stacking context than a sibling that rose into the stage, and its rows were
  underneath.
- **Do not reintroduce a fallback marker set.** With nothing live the globe draws with no markers
  and the note says why.
