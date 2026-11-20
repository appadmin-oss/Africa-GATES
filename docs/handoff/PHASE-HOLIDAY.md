# Seasonal greetings — member pages (8 Oct 2026)

Built from `handoff-2026-10-05/specs/HOLIDAY-THEMES.md`, `design/HolidayBanner.dc.html` and
`AccountPage.dc.html` (`holiday` prop), checked at 390 and 1280 beside the handoff's screenshots.

## Files

| File | What |
|---|---|
| `database/migrations/2027_03_10_holiday_themes.php` | `gates_holidays` (slug VARCHAR, starts_on, ends_on, country_code, cta_programme_id, active), `gates_holiday_dismissals` (UNIQUE member, slug, year), `gates_users.seasonal_greetings` (default on; also in both schema files). Indexes through `SchemaIndex`. |
| `src/Services/HolidayTheme.php` | The ten themes (the DC's `TH`, glyphs `G`), `forMember()` (window ∋ today in the display zone, country match, switch on, not dismissed this year; latest `starts_on` wins; memoised per request), `view()`, `dismiss()`, `surfaceAllows()`, `patternSvg()`. |
| `src/Support/Accent.php` | `HOLIDAY` tones (wash · edge · ink · line), emitted as `--ag-hol-*`; `holidayHex()`. |
| `src/Support/Phone.php` `country()` | A member's country from their E.164 number — longest dial code; +1 (two countries) is null. |
| `templates/partials/holiday-banner.twig`, `public/assets/css/components/holiday.css`, `public/assets/js/holiday.js` | The 3px line and the banner, the DC's geometry; dismiss is a form that posts (JS posts in the background and hides it). |
| `templates/pages/account/_frame.twig` | Draws it at the top of every account page when there is one; links `holiday.css` only then. |
| Settings tab | "Seasonal greetings" — the member's own switch (`POST /account/greetings`). |
| `/admin/campaigns/greetings` | Add, turn off, remove windows; linked from the newsletter's holiday section (campaigns gate — no access change). |
| Route `/img/holiday/{pattern}-{tone}.svg` | The four SVG tiles, built from Accent. |
| `tests/Unit/HolidayThemeTest.php` | The table; windows and precedence; country; dismissal and the switch; the teachers' award; surfaces; the frame and the dismiss. Four planted mutations each fail it. |

## Deviations — owner to confirm

| # | What | Why |
|---|---|---|
| HT-1 | **The washes and edges are typed hex in Accent** (10 values), like the cover's DG-1. | The spec names them as Accent pairs but no palette token has them. |
| HT-2 | **SVG tiles are a route, not static files.** | A static file is a second place the colour is typed (the cover's `games` tile, DG-5). CSP unchanged (`img-src 'self'`). |
| HT-3 | **Country comes from the member's phone, else the CDN's header, else nobody's.** A Nigeria-only greeting never reaches a member whose country is unknown. | Members store no country; a wrong national-day greeting is worse than none. |
| HT-4 | **"Today" is the platform's display zone.** | Members store no time zone. |
| HT-5 | The teachers' line reads **"Nominations are open for the {award}."**, and only while that award's cycle is taking nominations. | The DC's "The {award} are open." works only for a plural name ("Nairobi Arts Award are open"). |
| HT-6 | **On the account pages only today.** `/org/*` and `/my-work` are allowed by `surfaceAllows()` but their pages are destroyed and awaiting rebuild; organisation users are not members, so their greeting needs its own identity when the dashboard returns. | — |
| HT-7 | **Two holiday calendars.** The newsletter's (`HolidayCalendar`, issue days, Easter computed) and this one (banner windows, all typed) are separate on purpose (windows vs. one send day, per country), but an operator sets dates in two places. Owner: merge? | — |
| HT-8 | Greeting text is `--ag-ink` (DC `#0d1f21`, not a palette colour). | — |
