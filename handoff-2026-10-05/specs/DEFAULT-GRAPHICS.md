# Default graphics: strict build spec

Design: `design/DefaultCover.dc.html` (the component) and `design/DefaultGraphics.dc.html` (every slot, at its real size). Open both before writing code. This file overrides REFERENCE §14 for any image slot with no upload, and answers GAPS Q8 (C11).

## 1. Rule

Every image slot with no uploaded image renders `partials/cover.twig`. Never a stock photo, never a grey box, never a broken `<img>`. Unsplash in the DCs marks a slot that **has** an upload in the demo; it is not a fallback.

## 2. Files to create

| File | What |
|---|---|
| `templates/partials/cover.twig` | The on-page cover (variants `graphic`). CSS only, no `<img>` except the host logo |
| `public/assets/css/components/cover.css` | All rules below. Loaded by `layout/shell.twig` after `components.css` |
| `src/Support/CoverKind.php` | `kind → [label, tone, pattern]` and `subject → …` maps (§4). The ONE resolver; Twig and GD both call it |
| `src/Services/CoverImage.php` | GD renderer for the `full` variant (§7) |
| Route `GET /og/{subject}/{id}-{ratio}.png` | `ratio ∈ 1200x630, 1200x675, 1200x900, 1200x1200`. Cache by `id + updated_at`. 404 for unknown ids |
| `tests/Unit/CoverKindTest.php` | Every kind and subject resolves; tones are §4 names only; no hex outside Accent |

## 3. Partial signature

```twig
{% include 'partials/cover.twig' with {
  subject: 'event',            {# event | award | campaign | blog | challenge | product #}
  kind:    event.cover_kind,   {# event only; §4 #}
  content: 'date',             {# auto | none | date | title — §5 #}
  ratio:   '16/9',             {# 16/9 · 4/3 · 1/1 · 4/5 · 3/1 #}
  date:    event.starts_at,    {# optional; never faked #}
  title:   event.title,        {# used only by content=title #}
  logo:    event.host.logo_url {# optional; Africa GATES mark when empty #}
} %}
```

Markup (exact class names):

```html
<div class="ag-cover ag-cover--{{ tone }} ag-cover--{{ pattern }}" style="aspect-ratio:16/9" aria-hidden="true">
  <span class="ag-cover__pat"></span>
  <span class="ag-cover__date"><b>Dec</b><span>6</span></span>        {# content=date #}
  <span class="ag-cover__title">Mathare clean-up day</span>           {# content=title #}
  <span class="ag-cover__logo"><img src="…" alt=""></span>          {# content=none or no date #}
  <span class="ag-cover__pill"><i></i>Awards ceremony</span>
</div>
```

`aspect-ratio` is the only inline style allowed (it's data). The cover is decorative (`aria-hidden="true"`); the slot's accessible name comes from the card or heading beside it.

## 4. Kinds, subjects, tones (fixed, never organiser-editable)

| Kind / subject | Label | Tone | Pattern |
|---|---|---|---|
| ceremony | Awards ceremony | gold | arches |
| gala | Gala | gold | arches |
| conference | Conference | info | dots |
| workshop · training | Workshop · Training | green | grid |
| webinar | Webinar | info | signal |
| livestream | Livestream | live | signal |
| community | Community | green | rings |
| concert | Concert | live | weave |
| sports | Sports | green | stripes |
| fundraiser | Fundraiser | live | stripes |
| exhibition | Exhibition | stone | weave |
| award | Award | gold | arches |
| campaign | Giving | live | stripes |
| blog | Story | stone | dots |
| challenge | Challenge | green | grid |
| product | Shop | stone | weave |

Tones are Accent pairs: `bg` = the role's wash, `line` = the role's fill, `ink` = the role's word colour. green `#effaf0 / #237b22 / #1a6118` · gold `#fcf4de / #c99a06 / #7a5600` · live `#fdecef / #e0245e / #b0224f` · info `#e6f0f4 / #1f6fa3 / #1f5f8b` · stone `#f1efe9 / #3a4a4c / #10292c`. Pattern lines use `line` at alpha `0x38`; soft discs use `line` at `0x14`. Emit them as custom properties from Accent; `cover.css` holds no hex.

Data: add `cover_kind VARCHAR(24) NULL` to `gates_site_events` (both schema files; **not** an ENUM — the SQLite trap). Validate against `CoverKind::KINDS` in PHP. NULL resolves to `ceremony` for award-linked events and `community` otherwise. An organiser picks the kind from a list in admin; they never pick a colour.

## 5. Content: show what the layout doesn't

| Slot | content | Why |
|---|---|---|
| Event cards, list rows, rails, Home "Happening now" | `date` | The date moves into the image for **every** card, photo or not (draw the same `.ag-cover__date` tile over photos). Remove the card body's date line. |
| Event detail hero (phone and desktop), award hero, campaign hero | `none` | Title and date are the heading right beside it. Shows the host logo |
| Featured story tile, challenge tile, share-sheet preview | `title` | The image stands alone |
| Award, giving, blog, shop cards | `none` | Their bodies already name them |

`auto` = `date` when a date exists, else `none`. No date → no date tile, ever (no "TBC").

## 6. Density (container queries, not media queries)

`.ag-cover{container-type:size}` and sizes use `cqmin` with clamps exactly as the DC. The tiers drop things; they never shrink everything:

| Tier | Shorter side | Shows |
|---|---|---|
| xs | < 96px | date tile only (logo tile if no date). No pill |
| sm | 96–199px | date tile + pill. With the logo, no pill |
| md | 200–419px | everything, full spacing |
| lg | ≥ 420px | sizes stop growing (clamp maxima) |

```css
@container (max-height: 95px) { .ag-cover__pill{display:none} }
@container (max-height: 199px) { .ag-cover:has(.ag-cover__logo) .ag-cover__pill{display:none} }
```
(Width-limited slots: the same rules with `max-width`.) Pattern masks: content on the start side fades the pattern there (`linear-gradient(100deg, transparent 0 22%, #000 70%)`); logo-centred covers fade it under the logo (`radial-gradient(circle at 50% 50%, transparent 0 18cqmin, #000 46cqmin)`). RTL mirrors both the gradient angle and every pattern origin (`x → 100 − x`).

## 7. Off-site images (`full` variant, GD)

- og:image 1200×630, plus Google's Event image set 1200×675 (16:9), 1200×900 (4:3), 1200×1200 (1:1), all listed in the Event JSON-LD `image` array.
- Layout as `DefaultCover` `variant=full`: pill + optional host logo top; day numeral, "Month Year", "Weekday · time"; title (Playfair 700, up to 3 lines; 4 on 1:1); "place · host"; Africa GATES lockup bottom-end. Banner shape (≥2.2:1, the challenge strip) puts the day block, text and logo in one row.
- Title size by length: ≤36 / ≤64 / longer characters (see `tk` in the DC). Never shrink below the clamp minimum. Truncate with an ellipsis.
- `og:image:alt` = "{title}, {weekday} {day} {month}, {place}".
- An event with an uploaded photo still uses its photo for og:image. Only the fallback is generated.

## 7a. The centre tile

When there is no host logo, the white tile shows the **type glyph** (24px grid, 1.6 stroke, round caps and joins, drawn at 44% of the tile, the tone's ink colour; one closed outline plus at most three detail strokes per icon), never the Africa GATES mark: ceremony/gala/award = trophy · conference = microphone · workshop = pencil · training = book · webinar = video · livestream = broadcast · community = people · concert = note · sports = ball · fundraiser/campaign = heart · exhibition = frame · blog = quote marks · challenge = game controller · product (shop) = bag. Paths are `GLYPH` in `DefaultCover.dc.html`; copy them verbatim into `partials/icons.twig`.

**Challenge** uses the `games` pattern: a 96-unit SVG tile of five tiny icons (star, dice, target, bolt, controller) at 42% opacity in the tone's line colour, tiled at 30cqmin. Ship it as one static SVG per tone in `public/assets/img/patterns/games-{tone}.svg` (CSP: `img-src 'self'`), not a data URI.

**Fonts:** the cover loads nothing itself on the site; `layout/shell.twig` already loads Playfair Display 700 and DM Sans. GD share images use the same two families from `resources/fonts/` (TTF, bundled, not fetched).

## 8. People and organisations without a picture (`DefaultAvatar.dc.html` → `partials/avatar.twig`)

- **Initials:** first letter of the first two significant words; skip of, the, and, &, for, de, du, la, le, des, et, da, do, e, y, al, el. One letter at ≤32px. Unicode-aware (Yorùbá, Arabic and Amharic names keep their first letter); `?` only if nothing remains.
- **Tone:** `abs(hash31(profile_id)) % 5` over green, gold, live, info, stone. Never by name, so a rename keeps the colour.
- **Fill:** `linear-gradient(150deg, wash 0 45%, edge 100%)` + a 1px inset ring in the edge colour. At ≥40px, add two hairline arcs from the bottom-end corner (line colour at 15% and 9%) so avatars and covers read as one system.
- **Type:** DM Sans 700, ink colour. 38% of the size for two letters (letter-spacing −0.02em), 46% for one.
- **Shape:** person = circle. Organisation, business, government = square with radius 28% of the size.
- **Badge (≥40px only):** 36% of the size, a white disc with a ring in the surface colour behind it (≥2px). Organisation = building · business = briefcase · government = columns. A verified person gets a blue (#1f6fa3) disc with a white tick instead; a verified organisation keeps its kind badge and shows the verified shield beside its name, not on the avatar.
- **Accessible name:** "{name}" + ", {kind}" for non-people + ", verified". When the name is printed beside the avatar, render it `aria-hidden="true"` instead.
- Sizes in use: 24 (inline mentions), 32 (comments), 44 (list rows), 56 (cards), 96 (profile phone), 160 (profile desktop).

## 9. Acceptance

Screenshots of `/events` (desktop and phone), one event detail with no photo (phone, desktop), `/awards`, `/giving`, `/blog`, `/shop` and three generated og images, beside `DefaultGraphics.dc.html` at the same sizes. Plus 72px, 168px, 320px and 560px covers side by side, and one RTL og image.
