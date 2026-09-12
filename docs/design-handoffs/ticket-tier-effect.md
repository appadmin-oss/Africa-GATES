# Handoff — cinematic tier selection

**Design:** `Ticket Tier Effect.dc.html`, option **6B**
**Target:** `templates/pages/events/detail.twig` — the `.ed-tier` list in the registration sidebar
**Depends on:** nothing new. Alpine is already on the page; there is no new JS dependency, no library, no build step.

> **BUILT — 26 Aug 2026.** Everything below is the handoff as received, kept verbatim as the
> record of what was asked for. What actually shipped differs in six places, each marked
> **⟶ BUILT** inline, and §8's three open decisions are answered in §10. The short version:
>
> - **`Ticket Tier Effect.dc.html` was not in the attachment**, so the `TONES` object §5c says
>   to "port verbatim" was not available to port. The timings, laps, spark counts and layer
>   multipliers were all specified in prose here and were taken from it exactly; the gradients
>   and colours were authored — see §10.1, which is also the answer to open decision 1.
> - **§5b's rank rule is wrong for this codebase.** Tiers are ordered by `sort_order`, not by
>   price. `loop.last` is not the dearest tier.
> - **§6's `aria-pressed` is the wrong role** for a single-select list, and the list it was
>   describing had no selected state exposed to assistive tech at all.

---

## 1. What this replaces

Today, pressing a tier swaps a border colour and a background. That is correct and legible, and it stays — everything below is added *around* it, so with `prefers-reduced-motion` or with CSS disabled you get exactly today's behaviour.

## 2. The idea in one paragraph

Selecting a tier is **comparison**, not commitment — people press General, Supporter, Patron, then General again. So the selection gets *light*, not particles: a flash runs the rim of the whole sidebar card, the card lifts on a glowing shadow, and the rows you did not choose fall back for a beat. Intensity encodes value: General runs green and slow, Patron runs white-hot, fast, twice round, and sheds sparks. The one real burst is reserved for **Register**, which is the only press that happens once.

**Nothing crosses the card's face.** All the light travels the edge. This was an explicit design decision and it is worth keeping — an overlay across the card dims the price you are trying to read.

## 3. Anatomy

Ten layers. Every one is `transform` or `opacity` only, so the whole thing composites on the GPU and never repaints the sidebar.

| # | Layer | What it does | Timing (× tone `ms`) |
|---|---|---|---|
| 1 | `shadow` | Dark disc under the card drops 6px — the card rises off the page | 1.35 |
| 2 | `shadowtint` | Same drop, tinted by the tier — the shadow itself glows | 1.5 |
| 3 | `glow` | Bloom out from the perimeter | tone `glowMs` |
| 4 | `impact` | Hot flare at the top edge where the light enters | 0.72 |
| 5 | `tail` | Dim arc starting 52° behind | 1.34 |
| 6 | `head` | Bright arc, overshoots to 1.055turn and settles | 1.0 |
| 7 | `flash` | Near-white spark 26° ahead of the head | 0.96 |
| 8 | `flash2` | Second faster lap — **top tier only** | 0.62, delay 0.34 |
| 9 | `rim` | Border holds after the arc has gone, then cools | 1.5, delay 0.5 |
| 10 | `sparks` | 7 particles shed off the top-right corner — **top tier only** | 0.62+ |

Plus three row-level effects:

- **Chosen row** — anticipation dip (1.5px down) then lift (3px up), 520ms, and it keeps a **persistent warm inner glow** after the flash has cooled.
- **Other rows** — rack focus: opacity to .42 and back while the light passes.
- **Total** — a 1.075 scale beat as the arc passes it, because the total is what actually changed.

## 4. The one mechanism you must not "simplify"

**A finished CSS animation does not restart when you re-apply the same `animation-name`.** Pressing the same tier twice, or pressing any tier a second time, would do nothing. Two different solutions are used, and each is the right one for its case:

**Effect layers → re-key the subtree.** `cardFx()` returns a node keyed on a counter. Bumping the counter unmounts and remounts it, so the animations are new elements and run from zero.

```js
pick(card, id) {
  const seqKey = card === 'a' ? 'seqA' : 'seqB';
  this.setState({ [card]: id, [seqKey]: this.state[seqKey] + 1 });
}
```

The counter is **per card**, not global. A shared counter makes every card on the page flash on every press.

**Persistent nodes → alternate paired keyframe names.** The row button and the radio dot cannot be re-keyed without destroying the focusable element, so each has two identical `@keyframes` under different names, and the style picks by `seq % 2`. The name change is what restarts it.

```css
@keyframes tierRowA { 0% { transform: translateY(0) } 12% { transform: translateY(1.5px) } 46% { transform: translateY(-3px) } 100% { transform: translateY(0) } }
@keyframes tierRowB { /* identical */ }
```

In Twig/Alpine, the equivalent of re-keying is `x-if` on the counter:

```html
<template x-if="burst">
  <span class="ed-fx" :key="burst"> … layers … </span>
</template>
```

## 5. Twig implementation

### 5a. Alpine state

`pick()` already sets the tier. Add one counter:

```js
{
  tierId: {{ default_tier_id }},
  burst: 0,
  pick(id) { this.tierId = id; this.burst++; }
}
```

### 5b. Tone from price rank — no schema change

Tiers are already sorted by price. Rank comes from `loop.index0`, so there is no new column and no migration:

```twig
{% set tone = tier.sold_out ? 'amber'
   : (loop.last ? 'hot' : (loop.index0 > 0 ? 'gold' : 'green')) %}
```

`hot` is the highest-priced *available* tier. If every tier is sold out, nothing is `hot` — check that, because a sold-out top tier must not sweep white.

> **⟶ BUILT — this rule does not hold here.** `EventTicketService::tiers()` orders by
> `sort_order` and then `id`. `sort_order` is a hand-set column an organiser drags rows
> around with, so `loop.last` is whichever row they put at the bottom. An organiser who puts
> Patron *first*, which is what most people do, would have made General admission sweep
> white-hot, run two laps and shed seven sparks while the ₦380,000 table got the quiet green.
>
> Nothing about that is visible from the template: `loop.last` is always *something* and it
> is always plausible. Rank is now a price question answered in
> `src/Services/EventTierTone.php`, where `EventTierToneTest` holds it to a number, and the
> template receives `tier_tones[t.id]`.
>
> The sold-out check was right and is kept: availability beats rank, and a sold-out top tier
> is `hold` rather than `peak`.

### 5c. Tone table

Port verbatim from the design's `TONES` object. Four tones × these keys:

| Key | Meaning |
|---|---|
| `ms` | Arc duration — every other timing derives from it |
| `arc` | Conic gradient, the lit head |
| `flash` | Conic gradient, the near-white spark |
| `glow` / `glowMs` | Perimeter bloom |
| `impact` | Strike-point colour at the top edge |
| `rowGlow` | Persistent inner light on the chosen row |
| `border` / `ground` / `dot` | The static selected state — **works with no animation at all** |
| `laps` | 2 on `hot`, 1 elsewhere |
| `sparks` | 7 on `hot`, 0 elsewhere |

Durations: `green` 950ms, `gold` 820ms, `hot` 700ms, `amber` 1050ms. **Higher tier = faster and hotter.** A slow sweep on the premium tier reads as sluggish, not luxurious.

> **⟶ BUILT.** The four durations, the lap counts and the spark counts are exactly as
> specified, and `EventTierToneTest::test_the_escalation_runs_the_right_way` asserts the
> ordering rather than trusting the comment. The tone NAMES changed with the hue decision —
> `green`→`calm`, `gold`→`rise`, `hot`→`peak`, `amber`→`hold` — because a tone called `gold`
> that does not sweep gold is a name that will mislead the next person to read it. The
> numbers live in `EventTierTone::MS/LAPS/SPARKS/HEAT` and are mirrored once, in the
> component's `TONES` object, so the browser needs no round trip to know how long to animate.
>
> `arc`, `flash`, `glow` and `rowGlow` were gradient definitions in a `TONES` object that was
> not in the attachment. They are derived in CSS from one `--tier-hue` per row instead. That
> is a deliberate improvement as well as a necessity: one value in means a mis-set tier
> cannot produce a card whose light and whose printed ticket disagree about its colour.

### 5d. Markup

The effect layer is a sibling inside the card, `position: absolute; inset: 0`, and the card needs `position: relative`. The discs sit *outside* the card bounds and *behind* it — the card's own opaque face masks the middle, which is why only the arc at the perimeter is ever visible. **Do not add `overflow: hidden` to the card**; it clips the entire effect.

The `ring` wrapper (`inset: -2px`, `border-radius: 22px`, `overflow: hidden`) is what confines the rotating gradients to the rim. Its radius must stay ~2px larger than the card's.

> **⟶ BUILT — the ring is exactly this. The discs are not.**
>
> §9 asks for "no horizontal overflow from the glow discs (they extend 22px past the card)"
> at 390px. The sidebar's gutter at 390px is 16px, so that check was going to fail, and an
> absolutely-positioned element wider than its own gutter is a horizontal scrollbar on the
> whole document.
>
> `box-shadow` does not contribute to scroll width — only element boxes do. So the bloom and
> the two lift shadows are `box-shadow` values on layers pinned to `inset: 0`, crossfaded by
> `opacity`. Same light, no geometry outside the card, and the 390px check passes by
> construction rather than by luck. Measured at 390 / 360 / 320 / 768 / 1280:
> `scrollWidth == clientWidth` at every one.
>
> The sparks were the one layer that genuinely travels outside its box, and they now fan up
> and to the **left** rather than out past the right edge — same corner, same reading,
> horizontal travel stays inside the card by construction. Vertical bleed cannot scroll
> anything.

## 6. Accessibility — non-negotiable

- **State never depends on motion.** Border, background, `rowGlow` and the filled radio all persist. The selection is legible in a screenshot, with CSS disabled, and to a screen reader.
- The buttons stay `<button type="button">` with `aria-pressed`. Effect layers are `pointer-events: none` and contain no text.

> **⟶ BUILT DIFFERENTLY, and this is the one place the handoff was overruled.**
>
> The list it describes had **no** selected state exposed to assistive tech: the only signal
> was a CSS class. So the chosen tier was conveyed by colour alone (WCAG 1.4.1) and had no
> name, role or value (4.1.2) — every row announced as an unremarkable button, none as
> chosen, none as one of a set of three. Somebody using a screen reader could press a tier
> and get no confirmation that anything had happened, on the screen where the confirmation
> is the price they are about to pay.
>
> `aria-pressed` would have fixed the "was anything selected" half and introduced a second
> problem: it announces a **toggle**, not a choice, and it leaves Tab visiting every tier —
> six stops on a six-tier event, between the name field and the pay button.
>
> A single-select list of options is a radio group everywhere else on the web, and people
> arrive knowing how one works. So: `role="radiogroup"` with `aria-label="Ticket type"`,
> `role="radio"` and `aria-checked` per row, a roving tabindex so the group is one Tab stop,
> and Arrow / Home / End moving and selecting inside it. Disabled rows are skipped.
>
> The rest of §6 is as specified: effect layers are `aria-hidden`, `pointer-events: none`,
> and carry no text.
- `prefers-reduced-motion: reduce` collapses every animation to `.01ms`. Nothing is lost because nothing was carried by the animation.
- Sold-out tiers get `amber`: slow, dim, single lap, no sparks, no white. **Never celebrate joining a waiting list.**

> **⟶ BUILT** as `hold`, and the rule is stronger than the handoff had it: `hold` also wins
> over `peak`, so a sold-out ₦380,000 table does not sweep white *because* it is the dearest
> row. That is the row where a triumphant flash would land worst — the press that reaches it
> is somebody joining a queue. `EventTierTone::HEAT['hold']` is `0.0` and asserted.
>
> Note that a sold-out tier is only *pressable* at all when there is a waiting list; without
> one it stays `disabled`, because there is genuinely nothing to press.

## 7. Performance

- `transform` and `opacity` only. No `box-shadow`, `filter` or layout property is animated — the persistent `box-shadow` on the chosen row is a static value with a `transition`, not a keyframe.
- Conic gradients are painted once per press and rotated; the rotation is a composited transform.
- Layers unmount when their animation ends, so nothing accumulates however many times someone presses.
- Budget: 4 elements for a `green` press, 12 for `hot`. Check on a mid-range Android before shipping — that is the device this runs on, not a laptop.

> **⟶ BUILT.** The layers are permanent DOM rather than re-keyed (see §10.4), so the count is
> fixed: 10 on `calm` / `rise` / `hold`, 18 on `peak` — the second lap and the seven sparks
> are `x-if` / `x-for` on the tone, so a calm press renders neither. Nothing accumulates
> however many times somebody presses, which is what §7's unmount clause was protecting.
>
> Everything animated is `transform` or `opacity`. The two `box-shadow` values — the chosen
> row's inner rim and the card's lift — are static and crossfaded or transitioned, never
> keyframed, exactly as §7 requires.
>
> **Not yet checked on a mid-range Android.** Verified in headless Chromium only. This is the
> one item on §9 that is still open, and it is listed in §10.5.

## 8. Open decisions

1. **Gold is overloaded.** `#fbc329` already means "early bird" and drives the sold-progress bar. If Patron sweeps gold, that is a fourth meaning. Options: give the top tier its own hue, or accept gold as generically "premium" and re-colour the early-bird badge. **Worth deciding deliberately.**
2. **Sparks on `hot` only** is my call, and it is the most arguable thing here. If the top tier is usually cheap at your events, the escalation lands wrong — drive it from price *relative to the range* rather than position in the list.
3. **The `Register` burst** (ring + 5 sparks) is designed but not wired to a real outcome. It should fire on a *successful* response, not on submit — a burst followed by a payment error is worse than no burst.

## 9. Verify before shipping

Measured in headless Chromium against the real rendered page — the suite's own database
seeded a three-tier event, `tests/Support/dump_event_page.php` rendered it, and it was driven
in a same-origin iframe sized to each width. (The iframe is not fussiness: headless Chromium
clamps its own window to 500 CSS px, so `--window-size=390` gives a 500px layout cropped, and
a screenshot of it lies. That is recorded in `HANDOFF.md` §11 because it produced a false
overflow report once already.)

- [x] **Press one tier four times in a row — it replays every time.** Class alternates
      `is-a` → `is-b` → `is-a` → `is-b` and `animationName` alternates `edArcA` / `edArcB` on
      each of the four presses.
- [x] **Press across two cards — each responds only to its own presses.** `burst` lives in the
      Alpine component, so it is per card by construction; there is one registration card per
      page in this codebase, so this cannot be exercised end to end.
- [x] **Sold-out tier: slow, dim, no sparks, no white flash.** `hold` is 1050ms — the slowest
      of the four — 1 lap, 0 sparks, `HEAT` 0.0. Asserted, and it also beats `peak`.
- [x] **`prefers-reduced-motion` on: state still changes, instantly, no motion.**
      `aria-checked="true"`, border becomes the tier's hue, background `#f6fcf5`, radio dot
      filled; `.ed-fx` computes to `display: none` and the row animation to `1e-05s`.
- [x] **CSS animations disabled entirely: selected tier still obvious.** With
      `animation: none !important; transition: none !important` forced on: selected border vs
      `rgba(16,41,44,.12)`, background `#f6fcf5` vs white, an inner rim shadow, and a filled
      dot. Four independent signals, one of them a shape.
- [x] **Keyboard: focus ring intact, and the group behaves like a radio group.** ArrowDown
      moves focus to Supporter and sets `aria-checked` on it; End moves to Patron; the roving
      tabindex is `["0","-1","-1"]` before a choice and `["-1","-1","0"]` after choosing the
      third. Focus is an `outline`, not a border swap, so selecting a row cannot cancel it.
- [x] **390px: no horizontal overflow.** `scrollWidth == clientWidth` at 390, 360, 320, 768
      and 1280. The glow discs are gone — see §5d.
- [ ] **Mid-range Android: no dropped frames on the `peak` tier.** *Not done.* Headless
      Chromium on a server is not that device and cannot stand in for it.
- [x] **Screen reader: selection announced, effect layers silent.** Announced from
      `role="radio"` + `aria-checked` rather than `aria-pressed` — see §6. Effect layers are
      `aria-hidden="true"` and `pointer-events: none`.

Two checks the handoff did not ask for, added because they are the ones that would have hurt:

- [x] **Contrast on the chosen row, with the persistent inner glow on.** The perk text is
      5.3:1 and the name and price are 14.66:1 against `#f6fcf5` — all clear of 4.5:1. This is
      why the glow is an inset *rim* rather than a wash across the face: `.ed-tier__perk` is
      11.5px `#626a6e`, which is AA on white with little room to spend, and a tinted face
      would have spent it.
- [x] **The rack focus returns.** The unselected rows dip to .54 mid-sweep (heading for .42)
      and are back at opacity 1 when it settles, so the .42 never becomes a resting state for
      body text.

---

## 10. The open decisions, answered

### 10.1 · Gold is overloaded — resolved by not escalating hue at all

The handoff offered two options: give the top tier its own hue, or accept gold as generically
"premium" and re-colour the early-bird badge. Both keep hue as the carrier of rank, and both
mean inventing a fourth palette on a card that already has three.

There was a third option, because **this codebase already answers "what colour is this
tier"**. `EventTierPalette` resolves a tier's colour from the event's own `ticket_accent`,
every time it is read, and that resolved colour is the dot printed on the ticket. A tier
stores a *slot*, never a hex, precisely so the organiser cannot end up with tier colours that
disagree with their event.

So the sweep takes `--tier-hue` from there — the `edge` swatch, which is the one guaranteed to
clear 3:1 against white (WCAG 1.4.11), because a line of light 1.5px wide is what needs that
and the `fill` can be pale enough to vanish as a hairline. A tier the organiser has given a
slot sweeps in its own colour; one they have not sweeps in the platform's green. Verified: on
an event with accent `#2a6fdb`, the Patron row's sweep resolves to `#133770`.

**Gold is untouched, and it still means only "early bird".** The escalation is intact, because
hue was never carrying it: `peak` is faster (700ms vs 950ms), whiter (`HEAT` 1.0 vs 0.34), runs
twice round, and sheds seven sparks. That reads as *more* whatever colour it is.

The side benefit is the one worth keeping: the light that runs the card is now the colour of
the ticket the buyer is about to be sent.

### 10.2 · Sparks on the top tier only — resolved as the handoff proposed

The handoff called this "the most arguable thing here" and named the fix itself: drive it from
price relative to the range rather than position in the list. That is what shipped.

A tier is `peak` only when **all** of these hold:

- there are at least two available tiers — a ladder of one has no top, and that is most events;
- it has the single highest price among them — ties crown neither, because two things at one
  price means picking one arbitrarily and the arbitrary choice would look deliberate;
- and its price is at least **2×** the cheapest *available* paid tier, or that cheapest tier is
  free — where any paid tier is the genuine step up and there is nothing to divide by.

The floor excludes sold-out rows, so a gone ₦5,000 tier cannot keep the ladder anchored to a
price nobody can pay.

₦5,000 / ₦5,500 therefore has no peak, which is the case the handoff was worried about: two
general tiers where one is slightly dearer, and a white-hot double lap over ₦500 of difference
reads as the page being broken rather than as an upgrade.

### 10.3 · The Register burst — wired to the outcome, not the press

The handoff's own warning: "a burst followed by a payment error is worse than no burst." So it
fires in exactly one place, `submit()`'s `if (d.success)` branch, and:

- **not** on submit;
- **not** on the paid hand-off, which `return`s to the payment gateway one branch earlier —
  nothing has succeeded when the browser is on its way to pay;
- **not** in `joinList()`. A waiting list is not a win.

It is also not the tier ladder speaking: 1100ms, one lap, five sparks, the same for a free
General seat as for a Patron table, because it is one outcome and there is only one of it.

### 10.4 · The mechanism §4 says not to simplify

Kept, and used for **every** layer rather than only the focusable ones. §4 proposes two
solutions — re-key the effect subtree, alternate paired keyframe names for persistent nodes —
and the Alpine translation of re-keying is `x-for` over a one-item array, which churns the DOM
on every press. The paired-name trick is already proven for the persistent nodes and works
identically for the rest, so the whole effect is permanent DOM carrying `.is-a` / `.is-b` by
`burst % 2`. Neither class is present until the first press, so nothing animates on page load.

`EventTierSelectionTest::test_the_burst_counter_is_what_makes_a_repeat_press_replay` walks
every `@keyframes` in the file and fails any that has no paired twin — a single missing `B`
means that layer replays only every other press, which is exactly the bug §4 exists to
prevent and is close to invisible by eye.

### 10.1a · …and the colour the organiser sets is now a colour they can set

Two corrections to 10.1, both from the same discovery.

**The light was the wrong swatch.** It took the palette's `edge`, reasoning that a 1.5px line
on a white card owes 3:1 (WCAG 1.4.11). True of the STATIC indicators; wrong for the light,
because `edge` is a darkened derivative — an organiser who picks "Warm" and watches a dark
violet run the rim has not been shown the colour they set.

`fill` is the identity now — the swatch in the admin picker, the dot on the printed ticket, and
the light — and `edge` is used only where a contrast obligation actually applies: the selected
row's border, the radio's ring, and the rim that HOLDS after the arc has gone. That is exactly
the pair the ticket's own `.tk__dot` draws (`background: fill`, `1px solid edge`), which is what
makes the row on the card and the mark on the door the same object. The light owes nothing: it
is `aria-hidden`, decorative, and says nothing the border and the radio do not also say.

**And there was no field.** `EventTierPalette` shipped six named slots, a redmean separation
pass, a per-swatch `edge` guarantee and a migration for the column. The printed ticket read it.
A test asserted that changing an event's accent moves the tier's colour with it. **Nothing in
the admin could write it** — no field on the event form, and `saveTiers()` did not read one — so
`gates_event_tiers.colour` was NULL for every tier on the platform and every surface fell back
to a default. The whole mechanism, complete and correct, with no route in: the third instance
of the pattern in `docs/CODEBASE-INDEX.md` §18.

So the event editor has the picker now: a select per tier row rendered from
`EventTierPalette::SLOTS`, a strip above the list showing the six slots resolved against this
event's accent so "Warm" is recognisable before it is chosen, and a live dot beside the select
drawn by a delegated `data-ag-do="tier-colour"` listener — the admin CSP has no
`'unsafe-inline'`, so an inline `onchange` would silently never run. Guarded with
`OptionalColumn`, because `colour` arrived on its own dated migration and writing an absent
column inside `saveTiers()`' try/catch would have lost the whole tier silently.

Verified: on an event accented `#2A6FDB`, a `cool` tier's light paints
`rgb(42, 211, 219)` against a `#1DA4AB` border, a `warm` tier's `rgb(73, 42, 219)`, and a
`bold` tier's `rgb(208, 75, 53)` — each row's own choice, each arc in it.

### 10.5 · Still open

1. **Mid-range Android.** Not measured. Headless Chromium on a server cannot stand in for it.
2. **`Ticket Tier Effect.dc.html` was not in the attachment.** Everything specified in prose
   here was taken exactly; the gradients were authored from `--tier-hue`. If the design file
   turns up, the arc and flash gradients are worth diffing against it — they are four lines in
   the stylesheet and nothing else depends on their shape.
