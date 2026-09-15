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
nation whose nominees have recorded ballots; the plain dot is a nation standing in a live
award and waiting for its first vote.

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
