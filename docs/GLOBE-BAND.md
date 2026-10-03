# The homepage globe band

`templates/partials/globe-band.twig` · `public/assets/css/globe-band.css` ·
`public/assets/js/globe-band.js` · `src/Services/GlobeBand.php`

The "We are Africa" band, directly after the homepage hero: a dotted orthographic globe
shown in full, the 54 African nations outlined, a marker on each nation that is live, a
dashed annotation card at the right, and the stat card overlapping the sphere's lower edge.

Four files pointed at this document before it existed. It is short on purpose — the
component's own headers carry the reasoning, and `CLAUDE.md` carries the faults.

---

## What is on it, and what it is allowed to claim

The band arrived as a design handoff whose script carried **sixteen invented cities**: Lagos
on 41,280 ballots confirmed in 1.1 seconds, Nairobi on 33,940, arcs drawn between
"verification nodes", and an annotation stating a median confirmation time as measured fact.

This platform has no verification nodes and records no per-ballot latency. There has never
been a column for either. So none of it shipped, and the band is driven from the one
geographic fact the platform holds: **where the nominees are.**

A marker is a nation with an **approved nominee standing in a live award** — the definition
in `NationsLive`, reached by the same joins, so the globe and the footer's "live in …"
sentence cannot disagree about which nations those are. That join is also what keeps the
sandbox off the homepage without a filter anybody has to remember: `DemoSeeder` puts the
demo in a programme with `is_active = 0`, and the query reaches only active ones.

Not a registered profile, and not a voter's country. Anybody may register or vote from
anywhere; neither is the platform operating in a country.

Two figures per nation, and they are the ones the award pages carry: nominees standing, and
votes cast for them. The country card shows those and nothing else — the design's other two
rows named a routing node and a latency, and a row whose value can only be an em dash reads
as data that failed to load.

## Wiring

`HomeController` resolves it, cached 900s and tagged `leaderboard`/`registry`:

```php
$band = $this->cache->remember('home:globe', 900, fn() => [
    'countries' => GlobeBand::countries(),
    'note'      => GlobeBand::note(),
], ['leaderboard', 'registry']);
```

and passes `globe_countries`, `globe_note`, plus `community_pct`, `judge_pct` and
`jury_criteria` for the stat card. Those three are **rules read per cycle**, not literals —
`RuleEngine::weights()` and the active rubric. `/integrity` resolves them the same way.

The stat card is the homepage's **only** stat strip. A `.hm-stats` block used to stand
immediately above the band with the same four cells; its live/constant logic moved into the
partial whole and the duplicate went.

Assets, in this order, all `defer`, all self-hosted and version-pinned
(`public/assets/js/vendor/PROVENANCE.md`):

```html
<link rel="stylesheet" href="/assets/css/globe-band.css">
<script src="/assets/js/vendor/d3-7.9.0.min.js" defer></script>
<script src="/assets/js/vendor/topojson-client-3.1.0.min.js" defer></script>
<script src="/assets/js/globe-band.js" defer></script>
```

`globe-band.js` no-ops when the stage, d3 or topojson is missing, so a blocked vendor file
leaves the heading, note and stat card intact and the sphere simply unpainted.

## The geometry, and the join that fails in silence

Natural Earth 110m countries, public domain, served from
`/assets/geo/countries-110m.json` — self-hosted, not fetched from a CDN.

A marker sits at `d3.geoCentroid()` of that country's own polygon. Nothing is typed, so a
marker cannot drift from the outline it sits inside and adding a nation needs no
coordinates. The price is that the join is by **Natural Earth's own name**, which is not
ISO's — it calls the DRC "Dem. Rep. Congo" — and a name that does not match throws nothing,
logs nothing and warns nobody: the marker is absent from a band that still looks finished.

`GlobeBand::GEOMETRY` is the one map, and `GlobeBandTest` pins every entry in it against
the shipped geometry file, against the script's own `AFRICA` set, and against
`NationsLive::NAMES` so the two lists cannot drift when a country is added to the
nomination form.

## Geometry of the stage

The sphere's radius is `min(W * 0.30, H * 0.43)`, centred at `0.47H` — so the whole sphere
stays inside the stage at every viewport, and `W * 0.30` leaves the marker labels room
either side. On a phone **width** decides the radius, which is why
`.reg__stage` drops to `height: min(560px, 92vw)` below 760px: it was otherwise reserving
560px of height for a 234px sphere.

Two shapes, and the difference is a fact: the outlined marker carrying the check is a
nation where an award has been **decided** — a nominee crowned (`decided` from
{@see GlobeBand::countries()}, `status = 'winner'`); the plain dot is a nation still
competing.

**This sentence used to describe the ring as marking any nation whose nominees had taken
votes at all — the mapping this component was built to reject.** `GlobeBand`'s own docblock
spells out why: that is true of nearly every nation the moment an award opens, so the ring
fired almost everywhere and the band came out five rings to one dot, the hierarchy exactly
inverted, every marker defensible and the picture noise. A highlight almost everything
qualifies for is a background. The guide went on describing the retired model after the
correction landed, which is the one route by which a reader would have rebuilt it — so
`GlobeBandTest` now holds the guide to the shipped meaning as well as the script.

(Described rather than quoted, deliberately: the sweep that keeps this true matches on the
retired phrasing, so spelling it out here would trip the very check this paragraph exists
to document.)

## On a phone

Three things are different below 900px, and each was a fault before it was a rule.

- **The stage leaves the vertical axis to the browser.** `touch-action` is `pan-y`, not
  `none`. `none` cancels every browser gesture for a press that STARTS in the element,
  page scrolling included — and the stage is up to 560px tall, so a finger landing almost
  anywhere in the band could not move the page. It reads as the page having frozen, not as
  a globe problem. The drag only ever turned on the horizontal axis anyway; up and down
  are the arrow keys, or focusing a marker.
- **The sphere is sized from where the annotation card actually is.** The width factor is
  clearance for the 186px dashed note at `right:0`; once that note drops into normal flow
  the clearance buys nothing, and the globe was coming out about 230px across on a 390px
  screen with vertical slack going spare. `resize()` reads
  `getComputedStyle(note).position` rather than carrying its own copy of the breakpoint —
  a constant in the script that has to agree with a media query in the stylesheet is one
  edit from disagreeing, and neither file shows it. The stage's mobile height moved with
  it, because a stage shorter than the sphere it is sizing for hands the saving back.
- **The country card is anchored to the stage's foot, not returned to flow.**
  `position:static` inside a fixed-height stage whose canvas is absolute lays the card out
  from the TOP — over the markers it was opened from, and below the fold entirely on a
  tall stage. That is the third time this card has been somewhere its reader could not see
  it; the first two are in the list below.

`GlobeBandTest` holds the first two. Both were watched failing against the code they
replaced, and both sweep the DECLARATIONS rather than the file — a browser never sees a
comment, and the comment above each fix necessarily names the value it replaced.

## Things that will bite

- **`setPointerCapture` on the stage eats a marker's click.** The browser dispatches the
  following `click` to the capturing element, so a marker's own listener never runs —
  keyboard `Enter` works and the mouse does not. A press that starts on a marker starts no
  drag.
- **The country card cannot climb out of `.reg__body`'s stacking context.** The stat card
  is a sibling at a higher `z-index` that rises into the stage, so the card is anchored at
  `bottom: max(7rem, 12.5vw)` to clear it rather than trying to out-stack it.
- **Label crowding is a function of the radius.** Below a 560px stage only the busiest
  nation keeps a standing label; the rest name themselves when tapped.
- **Do not reintroduce a fallback marker set.** With no data the globe draws with no
  markers, and the annotation says why. That is what a site with no approved nominee looks
  like.

## If you are holding the handoff package

A zip of this component circulates as a `handoff/` folder — `globe-band.js`, `.css`,
`.twig`, a `GLOBE-BAND.md` and a `ROUTE-MAP.md`. **It is the pre-correction design.**
It was compared against this tree file by file on 2026-09-16; nothing in it supersedes
what is here, and applying it would undo three fixes:

| In the handoff | What applying it does |
|---|---|
| A sixteen-entry fallback city list with per-city ballot counts and verification seconds | Puts fabricated telemetry on the homepage. This platform records neither, and never had a column for either. |
| `setPointerCapture` on `pointerdown` with no target check | Re-breaks every marker's click while keyboard `Enter` keeps working — so an accessibility pass signs it off. |
| A stroke over all 54 nations each frame | The reference outlines one country, the selected one. Fifty-four turns the map into a diagram competing with itself. |

It is also behind in ways that are easy to miss: it inlines the colour literals this file
resolves to tokens, carries two AA failures this file fixed (a 3.04:1 grey on card content
and a ~13px close button), hardcodes the marker label sides to the invented city ids where
this file computes them per position, and hardcodes the stat card's `45% + 55%` and its
criteria count where `HomeController` reads them from `RuleEngine` and `JudgeRubric`.

**The one thing in it worth wanting is its console harness**, which sweeps the idle
rotation range and reports four failure modes — the sphere clipping the stage, the sphere
reaching the annotation card, markers colliding with each other, and markers hidden behind
the stat card. That last is the shape of the stacking fault this component already shipped,
so a check for it is worth having.

It cannot be pasted in as delivered. This module is an IIFE (line 46), so `projection` and
`frame` are not reachable from the console and the snippet throws on its first statement —
porting it as-is would ship a harness that reads as useful and does nothing, which is the
fault this file exists to document. The half that works without them is DOM-only: compare
`.node` rects against `.reg__cols` and against each other at the current rotation. Sweeping
the rotation needs a deliberate debug hook, and that is a decision about shipping a global
for a test rather than a port.

Its `homepage-craft-pass.css` is genuinely not in this tree, but it is a delta against the
`Homepage.html` reference rather than against this homepage: roughly half its selectors
(`.arch__c`, `.fcta__in`, `.media__play`, `.ag-mega__ico`) match no markup here, and it
leans on an `--ag-line-2` token nothing defines or emits, so every rule using it would drop
its border silently. Treat it as a design reference to build from, not a patch to apply.
