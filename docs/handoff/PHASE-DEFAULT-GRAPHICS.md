# Default graphics — the cover and the avatar (8 Oct 2026)

Built from `handoff-2026-10-05/` — `specs/DEFAULT-GRAPHICS.md`, `design/DefaultCover.dc.html`,
`design/DefaultAvatar.dc.html`, `design/DefaultGraphics.dc.html`. Every image slot with no upload
now draws `partials/cover.twig` (a thing) or `partials/avatar.twig` (a person or organisation),
never a stock photo, a grey box or a broken `<img>`.

## Files

| File | What |
|---|---|
| `src/Support/CoverKind.php` | The ONE resolver: kind/subject → label, tone, pattern, glyph (§4); content rule (§5); title scales; the `games` tile SVG. Twig and GD both read it. |
| `src/Support/AvatarMark.php` | Initials (small words skipped, Unicode first letter, one letter ≤32px, `?` last) and tone (the DC's 31-hash of the profile id). |
| `src/Support/Accent.php` | The cover tones: 5 × `bg`/`line`/`ink`/`edge`, emitted as `--ag-cover-{tone}-{slot}`; `coverTones()`, `coverHex()`. |
| `templates/partials/cover.twig`, `avatar.twig` | The spec's markup and class names (§3, §8). |
| `templates/partials/icons.twig` | `cover_glyph()`: the DC's `GLYPH` paths, verbatim (§7a). |
| `public/assets/css/components/cover.css` | Patterns, masks, RTL mirroring, density tiers, avatar. Zero colour literals. Loaded by the shell after `components.css`, and in `AssetBundle::STYLESHEETS`. |
| `templates/partials/photo.twig` | Kept as the one slot partial; its no-upload branches now route to the cover and the avatar, so its 18 callers moved at once. Callers now say their `subject` (award, blog, event, campaign). |
| `src/Services/PhotoCover.php` | **Deleted** — the 4 Oct accent cover, which §4 replaces ("tone by kind, never organiser-editable"). `EventsFront` hands the cover its kind, award link and LOCAL date instead. |
| Route `GET /img/patterns/games-{tone}.svg` | The challenge pattern tile, built from Accent. |
| `tests/Unit/CoverKindTest.php` | The §4 table, the tones' exact hex, no hex in `cover.css`, NULL-kind rule, no date tile without a date, rendering per mode, initials, tone hashing, badges, the pattern. |

## Deviations — what, why, needs approval

| # | What | Why |
|---|---|---|
| DG-1 | **Five colours not in the palette** are typed in Accent at the spec's values: gold line `#c99a06`, info wash `#e6f0f4`, info ink `#1f5f8b`, and the avatar edges live `#f6c7d3`, info `#c9dde8`. The other 15 slots reference existing tokens. | §4 calls the tones "Accent pairs" but gives these values, which no token has (Accent's gold is `#f3b416`, info-wash `#e8f1f7`). Swapping in the nearest token would move every gold and info cover visibly off the DC. **Owner: keep, or map to the nearest tokens?** |
| DG-2 | **Cover type steps by container size** (six bands, type tokens), instead of `clamp(…, n cqmin, …)`. Geometry keeps the DC's cqmin clamps exactly. | `TypeScaleTest` (owner, 3 Oct) refuses container units and clamp() in font sizes. Each band is the DC's formula evaluated in that band. |
| DG-3 | The cover's month and pill never go under **11.5px** (the DC's minimum is 10.5px and 11px); avatar letters snap to the type tokens (24px avatar: 11.5px, not 9–11px). | The closed small-text ladder has no 10.5 or 11. |
| DG-4 | **A verified organisation keeps its kind badge** (the DC draws the tick for any verified record). | §8's text: "a verified organisation keeps its kind badge and shows the verified shield beside its name". The written spec outranks the DC. |
| DG-5 | The `games` pattern is **a route, not five static SVG files**. | A static SVG would be a second place each colour is typed; the route reads Accent and is cached a day. CSP is unchanged (`img-src 'self'`). |

## Still to do (next commit)

`cover_kind` column on `gates_site_events` (both schema files, VARCHAR not ENUM) with the admin
picker; the GD `full` variant (`Services\CoverImage`) behind `GET /og/{subject}/{id}-{ratio}.png`
with the Event JSON-LD `image` array and `og:image:alt`.

## Verification

`DefaultGraphics.dc.html` rendered locally (React/Babel served from npm, the repo's own fonts) beside
a board of the Twig partials at the same sizes: modes, the four density sizes, all twelve kinds, the
five subjects, the six avatar kinds and sizes. Screenshots in the session; matches apart from DG-2/3.
