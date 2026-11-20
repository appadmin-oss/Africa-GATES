# Holiday themes: strict build spec

Design: `design/HolidayBanner.dc.html` (component, every theme), `design/HolidayThemes.dc.html` (board, live on `AccountPage`), `design/AccountPage.dc.html` (`holiday` prop).

## Scope
- Member pages only: `/account/*`, `/org/*` (organisation dashboard), `/my-work`, the member side of challenges. **Never** on public pages, admin, the judges’ console, checkout, ballots or the door scanner.
- What changes: (1) one `partials/holiday-banner.twig` at the top of `<main>`, inside the page's max-width, 20px from the top on desktop (12px on phone); (2) a 3px line directly under the header or app bar: `linear-gradient(90deg, {line}33, {line} 50%, {line}33)`. Nothing else: no recoloured buttons, no type changes, no animation.

## Data
Table `gates_holidays`: `slug VARCHAR(32)` (validated against `HolidayTheme::THEMES`), `starts_on DATE`, `ends_on DATE`, `country_code CHAR(2) NULL` (NULL = everyone), `active TINYINT`. Admins add dates every year (Eid and Easter are admin-entered, never computed). Member settings: `seasonal_greetings TINYINT DEFAULT 1`. Dismissals: `gates_holiday_dismissals(member_id, slug, year)`.

Resolution, once per request in `HolidayTheme::forMember()`: today in the member's time zone ∈ [starts_on, ends_on], the country matches or is NULL, greetings are on, and not dismissed this year. If two match, take the latest `starts_on`. Cache per member per day.

## Themes (exact)
| slug | tone | pattern | glyph | greeting | line | action |
|---|---|---|---|---|---|---|
| independence-ng | green | stripes | flag | Happy Independence Day, {first} | Celebrating 66 years of Nigeria, and the people who keep it moving. | Nominate someone |
| teachers | gold | ruled | book | Happy Teachers’ Day, {first} | Someone taught you to aim higher. The Alimosho Impactful Leadership Awards are open. | Nominate a teacher |
| christmas | green | snow | tree | Merry Christmas, {first} | Wishing you rest, good food and the people you love. | — |
| new-year | gold | confetti | spark | Happy New Year, {first} | Here’s to the people you’ll recognise this year. | See what’s open |
| eid | info | lattice | moon | Eid Mubarak, {first} | Wishing you and your family peace and joy. | — |
| easter | gold | sun | sunrise | Happy Easter, {first} | Wishing you a peaceful, joyful weekend. | — |
| africa-day | gold | africa | globe | Happy Africa Day, {first} | 54 countries, one record of who’s making a difference. | Explore the awards |
| democracy-ng | green | stripes | flag | Happy Democracy Day, {first} | Every vote counts here too. | See open votes |
| womens-day | live | petals | flower | Happy Women’s Day, {first} | Know a woman who leads? Put her name forward. | Nominate her |
| childrens-day-ng | info | balloons | balloon | Happy Children’s Day, {first} | For the teachers, coaches and carers who raise them. | Nominate a mentor |

The year in "66 years" is computed (`year − 1960`). The teachers' line names the award that is currently open; it comes from the theme row's `cta_programme_id`, not hard-coded. Copy goes through `|trans`.

Tones (wash · edge · ink · line): green `#eef7ee #d4e8d3 #1a6118 #237b22` · gold `#fcf6e4 #efe0b4 #7a5600 #c99a06` · live `#fdf0f3 #f3d3dc #b0224f #e0245e` · info `#ecf3f7 #cfe0ea #1f5f8b #1f6fa3` · stone `#f4f2ec #e2ddd2 #10292c #3a4a4c`. Emit them from Accent as `--ag-hol-*`; `holiday.css` holds no hex. Pattern definitions are `PAT` in the DC. Ship the SVG ones (confetti, lattice, petals, balloons) as static files in `public/assets/img/holiday/{pattern}-{tone}.svg` (CSP: no data URIs).

## Banner (exact)
- Desktop: grid `52px minmax(0,1fr) auto`, column gap 18, padding `18px 64px 18px 18px`, radius 20, wash fill + 1px inset edge.
  - The pattern sits on the end side under the mask `linear-gradient(90deg, transparent 0 40%, #000 85%)`.
  - Glyph tile: 52px white, radius 14, glyph 52% in the ink colour.
  - Greeting: Playfair 700 20px. Line: 14.5px `#3a4a4c`.
  - Action: a 44px white pill with a 1px edge ring (never green: it's chrome, not the page's primary action).
  - Dismiss: 44px × at the top end; it POSTs `/account/holiday/{slug}/dismiss`, then hides without reload.
- Phone: grid `44px minmax(0,1fr)`; the action drops under the text in column 2; padding `16px 48px 16px 16px`; pattern mask `linear-gradient(180deg, transparent 0 30%, #000 100%)`; greeting 18px, line 14px.
- `role="region" aria-label="{name} greeting"`. Mirror for RTL (`inset-inline-end`, mask angle 270deg).

## Acceptance
Screenshots of `/account` at 390 and 1440 for all 10 themes beside `HolidayThemes.dc.html`. Plus one with a dismissed theme, one for a non-Nigerian member on 1 October (no banner), and one in Arabic (RTL).
