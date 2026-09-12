# Design prompt — Africa GATES nominee flier + OG image

Paste the block below into Claude (Design / artifact mode). It is written to be handed
over as-is; everything after it is context for whoever is briefing, not part of the
prompt.

Two deliverables, because they are different jobs at different sizes: the **flier** is a
1080×1350 portrait a nominee posts to a WhatsApp status or an Instagram story, and the
**OG image** is a 1200×630 landscape card that appears under a pasted link in a chat
thread — often rendered barely 300px wide. A single design scaled to both fails at one of
them.

---

## THE PROMPT

> You are designing two social graphics for **Africa GATES**, a continental cultural
> recognition platform run by Afrovanguard. Both are generated **server-side, per
> nominee**, from live data — so you are designing a *template*, not one poster.
>
> ### Deliverable 1 — the nominee flier
>
> **1080 × 1350 (4:5 portrait).** A nominee downloads this and posts it to a WhatsApp
> status, an Instagram story, or a Facebook group to ask their community to vote. It is
> the platform's highest-intent share.
>
> Layout it must accommodate (the geometry is fixed by the renderer — you are designing
> *within* it, and may argue for changes):
> - A photo panel filling the full width, **1080 × 820**, at the top. The nominee's
>   portrait, face-anchored, with a gradient scrim fading into the panel below so text
>   stays legible over any photo. When there is no photo, a two-letter monogram on a deep
>   green field.
> - Top-left kicker rail: a 6px gold vertical bar, `VOTE NOW` in tracked-out caps, and
>   the programme name beneath it.
> - Top-right: a gold pill showing rank, e.g. `#3 of 24`. Omitted entirely when the field
>   is smaller than 2.
> - The nominee's **name**, in the display serif, bottom-left of the photo area. Sizes
>   from 96px down to 50px depending on length, wrapping to at most 2 lines.
> - Under it: category, and country code, separated by a middot.
> - A standing line — e.g. `#3 of 24 — 12 votes from #2` — with a progress track beneath
>   it showing position in the field.
> - Optional momentum line in gold: `41 votes in the last 24 hours`. Shown only when
>   real and non-zero.
> - 1–3 lines of rally copy in white.
> - A white pill at the bottom holding the vote URL in bold dark-green — the most
>   actionable element on the card.
> - A single small footnote line: *Public votes are one part of the score. An independent
>   jury decides the award.*
>
> ### Deliverable 2 — the OG / link-preview image
>
> **1200 × 630 (1.91:1 landscape).** This is what renders under the URL when a nominee
> pastes their ballot link into WhatsApp, Facebook, X or LinkedIn. It carries the same
> information but it is **not** the flier cropped: at 1.91:1 there is no room for a
> full-bleed portrait plus a text stack, and it is frequently displayed at a third of its
> native size.
>
> Design it as a horizontal split: the face on one side (roughly 40% of the width,
> face-anchored, full bleed to the edge), everything else on the other. Assume it will be
> read at ~380px wide — so at most three text elements survive, and the name and the rank
> must be legible at that size. Pick which three and justify the choice.
>
> ### Brand
>
> Use these exactly; they are the platform's live tokens:
>
> | role | value |
> | --- | --- |
> | deep green (background top) | `#123B2F` |
> | deep green (background base) | `#08201C` |
> | panel green | `#0F3329` |
> | brand green / accent | `#237B22` |
> | light green (highlights, progress fill) | `#7FC87C` |
> | gold (rank pill, momentum, kicker rail) | `#C9A227` |
> | gold ink (text on gold) | `#1A1204` |
> | mint (secondary text) | `#E8F2EC` |
> | muted (tertiary text) | `#A9C7BD` |
> | white | `#FFFFFF` |
>
> Typefaces: **Playfair Display Bold** for the nominee's name and any display numeral.
> **DM Sans** (Regular / SemiBold / Bold) for everything else. Both are already bundled
> with the renderer; do not introduce a third family.
>
> ### Constraints that are not negotiable
>
> 1. **Everything on the card is real.** Rank, field size, vote gap and 24-hour momentum
>    come from the database. Do not design a slot for a statistic the platform does not
>    have, and do not design a layout that looks empty when an optional element is absent
>    — several are conditionally omitted (no rank in a field of one; no momentum on a
>    quiet day; no gap when the nominee is leading). Show me each variant.
> 2. **The face is the point.** The photo is the reason someone stops scrolling. Any
>    treatment that shrinks it, obscures it, or places text across the upper third where
>    faces sit is wrong.
> 3. **It must survive being small.** The flier is judged as a WhatsApp status thumbnail
>    before anyone opens it; the OG card is often ~380px wide. Two stops on the type
>    scale, high contrast, no thin weights, no decorative texture that turns to mud.
> 4. **Names are long and are not Latin-ASCII.** Design and demonstrate with real
>    examples: `Ọlásùnkànmí Adébáyọ̀`, `Tsehaynesh Wolde-Giorgis`, `Nomvula
>    Dlamini-Khumalo`. Diacritics must have room above the cap height — do not set the
>    name so tight that a combining mark clips.
> 5. **Renderable by PHP GD.** No blend modes, no blur, no drop shadows, no rounded
>    image masks, no gradient meshes. Available primitives: solid fills, per-scanline
>    linear gradients, filled rectangles, filled ellipses (so pills and circles are
>    fine), TrueType text, and image compositing with a rectangular crop. If a detail
>    cannot be built from those, say so and offer an alternative that can.
> 6. **The jury footnote stays.** These awards are 55% independent jury. A rank on a
>    graphic reads as a result, and omitting that line turns a share card into a
>    misleading claim.
>
> ### What to give me
>
> 1. Both graphics, at full size, as self-contained HTML/CSS mockups I can screenshot.
> 2. The flier in **four** states: with photo + full standing; with photo + no momentum;
>    no photo (monogram); and a leading nominee (no gap to show).
> 3. The OG card in two states: with photo, and monogram.
> 4. A short spec table — every element's x/y, size, weight and colour — precise enough to
>    port into the renderer without guessing.
> 5. One paragraph on what you changed from the layout above and why.

---

## Briefing notes (not part of the prompt)

**Where these are rendered.** `src/Services/FlierService.php`. It emits the same design
twice — `svg()` for viewing and vector download, `png()` via GD for the download, the
native share sheet and the `og:image`. Both must be updated together; the two are
deliberately written beside each other because a rule fixed in one and not the other has
already shipped as a bug once. `FlierService::W`, `H` and `PHOTO_H` are the geometry
constants, and `Support\Media`'s `flier` preset is pinned to them by a test.

**Why the OG image is a second deliverable.** Today the `og:image` *is* the flier PNG —
the 4:5 portrait. That was a deliberate improvement on the previous behaviour (the bare
nominee photo, which previews as a face with no name, no category, no standing and no
reason to tap). But 4:5 is the wrong aspect ratio for a link preview: Facebook and
LinkedIn crop to 1.91:1, WhatsApp to roughly square, so the bottom third of the flier —
which is where the vote URL and the rally copy live — is cut off in exactly the surface
where the most sharing happens. A purpose-built 1200×630 card fixes that. It needs a new
`FlierService` method and an `og_image` swap in `VoteController::nomineeBallot()`.

**Why the face crop is trustworthy now.** Cloudinary-hosted portraits are requested with
`c_fill,g_faces:auto` at exactly the panel dimensions, so the image the renderer receives
is already anchored on the detected face. Local files fall back to a documented 22%
upper-bias crop. See `docs/MEDIA-CLOUDINARY.md`.

**Real data to test against.** `StandingsService::forNominee()` returns `rank`, `field`,
`gap_to_next`, `progress_pct`, `momentum_24h` and `momentum_available`. The last one
matters: `momentum_available === false` means the category has no timestamped votes, which
is why the momentum line is omitted rather than printed as `0`.
