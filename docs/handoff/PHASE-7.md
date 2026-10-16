# Redesign Phase 7 — Commerce

`design_handoff_africa_gates/phases/PHASE-7-commerce.md`. Built so far: **Events** (§8.10 index and
detail, §8.11 ticket). Shop (§8.12) and Giving (§8.13) are not in this section.

---

# Events — `/events`, `/events/{slug}`, `/events/ticket/{ref}` (4–5 Oct 2026)

Owner, 4 Oct 2026: *"There's nothing like upcoming events or coming soon events on the site. Do the
updated events page."* §8.10/§8.11 are authoritative; values are EventsPage.dc.html's and
TicketPage.dc.html's (REFERENCE §5). Screenshots and evidence: `docs/handoff/shots/phase-7/events/`.
Dev data: a scratch SQLite database with one event in every state (open with three coloured tiers,
agenda tracks, access notes, livestream, early-bird, organiser contact, a host programme with an
open vote; coming soon with no photo; waitlist; sold out; closed; ended with a released edition and a
recording; a past event) **and an event linked to an inactive (sandbox) programme**, which never shows.

## E1. Files

### Destroyed earlier, written again from the DC (inventories `pages--events.md`, `pages--events--detail.md`, `pages--events--ticket.md`)
| File | Now |
|---|---|
| `templates/pages/events.twig` | Index: phone root app bar (h1 "Events"), sticky 44px filter chips as links (All · Upcoming · Coming soon · Livestreams · Past, `aria-current`), the featured "Next up", ≥76px rows, past in its own section. |
| `templates/pages/events/detail.twig` + `_card.twig` + `_flier.twig` | Detail: 4:3 full-bleed photo with three floating 44px buttons and the overlapping 22px sheet on a phone; hero + early-bird banner from 600; main column + sticky 380px rail from 1024 (`display:contents` + `order` below it); the card in every state; the flier generator dialog; fixed bar. |
| `templates/pages/events/ticket.twig` | Ticket: §8.11 additions on every kept rule. |
| `public/assets/css/components/events.css`, `ticket.css` | Mobile-first, logical properties, zero colour literals. |
| `public/assets/js/event-detail.js`, `event-flier.js`, `ticket.js` | Classic deferred scripts, `data-ag-do`, no inline handlers. |

### Created
| File | Why |
|---|---|
| `src/Services/EventSales.php` | **One resolver** for an event's state (open · waitlist · soldout · closed · ended · soon), the date tickets go on sale and the "From" price — read by the index row, the card, the bar and the alert sweep. |
| `src/Services/EventsFront.php` | Index lists (upcoming soonest first, past apart, limits), `liveOnly()` (an event linked to an inactive programme — the sandbox — is excluded; linked to nothing is kept), hosts from the linked programme's `host_name`, released results + open voting for the ended/main column, link re-validation on the way out. |
| `src/Services/EventSaleAlert.php` | "Email me when tickets go on sale": double opt-in, once-a-day confirmation, sweep claims before it sends, announcement through SendPolicy, stop link. |
| `src/Services/PhotoCover.php` + `templates/partials/photo.twig` | GAPS Q8: one photo slot — real image / person monogram / thing cover from the accent through `EventFlierTheme`. CSS in `components/photo.css`. |
| `templates/pages/events/alert.twig` | The confirm/stop page (GET shows, POST acts). |
| `database/migrations/2027_03_04_event_page_links.php` | `gates_site_events.livestream_url`, `recording_url`, `access_notes` (the DC's Livestream, Recording and Access had no column). |
| `database/migrations/2027_03_04_event_sale_alerts.php` | `gates_event_sale_alerts`, indexes through `SchemaIndex::ensure()`. Both schema files carry the table and the columns. |
| `tests/Unit/EventsPagesTest.php` (15), `EventSaleAlertTest.php` (7), `EventTierGlowTest.php` (4), `TicketPageTest.php` (8), `PhotoSlotTest.php` (4) | 38 tests. `git status` showed each `??` before writing. |

### Changed (minimal, additive; shared files re-read before each edit)
`src/Controllers/EventsController.php` — `index()`, `show()`, `ticket()` rewritten to pass only what the
templates read; `alertWant()`/`alertPage()`; `gcalStamp()`. **Every money path untouched** (`register`,
`quote`, `waitlist`, `callback`, `ticketPdf`, self-service). · `src/Services/EventTierTone.php` (`card()`)
+ `EventTierPalette.php` (`ramp()`) — the five DC properties derived from the slot at read time (Q6);
`hues()` unchanged. · `src/routes.php` (2 routes) · `src/Support/Maintenance.php` (one every-tick task) ·
`src/Admin/Controllers/EventsController.php` + `templates/admin/events/form.twig` (three fields:
Livestream link, Recording link, Access — **reported for the admin-console agent**) · `tokens.css`
(`--ag-fs-21`) · `components/photo.css` (the photo slot — its own sheet; DevUiTest holds `components.css` to the base) · `templates/pages/home.twig` + `home.css` (every
absent-photo slot through `partials/photo.twig`; dead `hm-av` text, `hm-give__ini`, `hm-hero__img`
rules gone) · `tests/Unit/DeadTokenTest.php` (`--ag-gold-edge` has a reader: left the list).

## E2. Coming soon, end to end
- **No new column for "sales open at"** — the schema already has it per tier (`gates_event_tiers.sale_starts_at`,
  set in the admin tier editor's "Sales open at"), and `EventTicketService::reserve()` already refuses
  an `early` tier. An event is **coming soon** when every public tier is early; the date is the
  earliest one (`EventSales::opensAt()`). An event-level field would be a second rule claiming the same
  fact. Proven: a POST to `/register` for a coming-soon event books nothing.
- Card: "Coming soon · Tickets go on sale {date}", the prices to come, the notify form (plain POST, no
  script), "N people are waiting" when >1 confirmed. Index: "Coming soon" tag + "tickets on sale {date}".
- Notify: own table (not `gates_newsletter`, whose UNIQUE-per-address row would drop somebody already
  subscribed — the stand call's latent fault, recorded here, not changed). Double opt-in, same reply
  whatever happened, 10/hour per connection, confirmation once per 24h; maintenance sends once sales
  open, claimed first; unsubscribe header + per-event stop link.

## E3. Every prop combination → screenshot (`shots/phase-7/events/`)
| DC combination | Build | DC |
|---|---|---|
| index × phone/tablet/desktop | `build-index-all-{390,834,1024,1440}[-full].png`; filters `build-index-{upcoming,soon,live,past}-{w}.png` | `dc-index-{w}.png` |
| detail × open/waitlist/soldout/closed/ended × layout | `build-detail-{state}-{w}[-card,-full].png` | `dc-detail-{state}-{w}.png` |
| detail · coming soon (not in the DC) | `build-detail-soon-{w}[-card,-full].png`, `soon-notify-sent-390.png` | — |
| ticket × valid/checkedin | `build-ticket-{valid,checkedin}-{390,834,1440}[-full].png`; also pending, notfound | `dc-ticket-{valid,checkedin}-{390,440}.png` |
| overlays 50% | `overlay-{index-all,detail-open}-{390,1440}.png`, `overlay-ticket-valid-390.png` | |
| tier pick, glow / reduced motion | `pick-motion-{390,1440}.png`, `pick-settled-*.png`, `pick-reduced-*.png` (glow `display:none`) | |
| RTL 390 | `rtl-{index,detail,ticket}-390.png` (`dir=rtl`) | |
| keyboard | `keyboard-detail-{390,1440}.txt`, `keyboard-tiers-*.png` — one tab stop into the group, ArrowDown selects, End jumps | |
| Gee clearance | `gee-clearance-{kcea-ceremony,sme-champions,education-awards}-390.png`: launcher **16px** above the bar | |
| flier | `flier-entry-390.png`, `flier-ready-390.png`; Escape closes, focus moves in | |
| print | `ticket-browser-print.pdf` (+ `-pdf-1.png`), `ticket-server.pdf` (+ png), `ticket-print-media-1024.png`; **both QRs decode to the ticket code** (OpenCV, 200dpi) | |
| notes | `notes.txt` (measurements, CSP pass with no bypass: no violations), `mutations.txt` | |

Overlays: structure, sizes, card and bar line up; the offset at 390 is the DC's simulated 44px status bar.

## E4. Tests — every new guard watched failing
24 mutations (`mutations.txt`), 24 caught; the first run missed one (an unconfirmed alert sent when a
confirmed neighbour shares its event) — the test was strengthened and then caught it. Narrow filter run:
1,393 tests; the 4 failures are other agents' in-flight work (awards pages' colour tier, `--ag-fs-46`,
three `/account/*` pages unlinked) — none in an events file.

## E5. Deviations — what, why, approved by (target 0)
| # | What | Why | Approved by |
|---|---|---|---|
| V1 | Date lines in DM Sans 12.5 700 (tracking .1em), sentence case — not mono, not capitals; "Tickets"/"Fully booked" eyebrows sentence case; "1,284 registered" not mono | §18.3–4 | Owner Q5 |
| V2 | Off-palette tints → family tokens (#e4f6e4→green-wash, #cc1950/#b0224f→live-ink, #fff8df→gold-wash-2, #b42318→error, #7fc87c→green-light); the vote dot green, not live (tier-2 budget) | Q1; ColourBudgetTest | Owner Q1 |
| V3 | Targets the DC draws at 36–40 (track chips, desktop filter chips, copy link) are 44 | §6.7 | REFERENCE |
| V4 | Body copy 17, not 17.5 | closed ladder | Owner Q4 |
| V5 | Past events are a separate section; the DC's "All" mixes them | owner, 4 Oct | Owner |
| V6 | Filters are links (`?f=`) with `aria-current`, not a tablist of buttons; a fifth chip "Coming soon" | works without script, survives Back; owner asked for coming soon | **Needs approval** |
| V7 | Ticket: **no "Add to wallet"**; the fourth action is "Event details" | no pass-signing certificate / issuer account exists — blocked B1 | **Blocked** |
| V8 | Ticket print card 180 × 86 mm, not 190 | `TicketPdf::one()` narrowed to 180 so the cut line fits A4; the two print paths must agree | code rule |
| V9 | Waitlist hold stated as **48 h** (`EventWaitlist::OFFER_HOURS`), not the DC's 24 h | Q17 unanswered — the code's value | **Q17** |
| V10 | Soldout/closed "Join the community" → "Hear about the next one" (`/newsletter`) | /community not rebuilt; the newsletter is the live door | **Needs approval** |
| V11 | The DC's "Access" chips, "Watch the livestream/recording" are drawn only when the organiser filled the new fields; nothing invented | no data otherwise | — |
| V12 | The ticket celebration is not wired (the old page's one-off confetti) | Phase 3 engine; not in this brief | **Needs approval** |

## E6. Blocked — not guessed
- **B1 Wallet passes** — Apple PassKit needs a signed `.pkpass` (Apple developer certificate), Google
  Wallet an issuer account. Build them, or drop the action?
- **Q17** — 24 h (DC) or 48 h (code) waitlist hold?
- **B2 Hosts** — "Hosted by" reads the linked programme's `host_name`; an event linked to no programme
  shows no organiser. When hosts land (GAPS §8f), events need their own owner.
- **B3** The stand call's "email me when it opens" shares `gates_newsletter`'s one-row-per-address and
  silently misses an address already subscribed (found while building E2; not changed here).
- **B5** `partials/photo.twig` is now included by six other pages (`vote`, `vote-program`, `account/dashboard`,
  `awards/index`, `awards/programme`, `layout/auth.twig`) that do not link `components/photo.css`, so their
  slots render unstyled. It was moved out of `components.css` because DevUiTest holds that file to the Phase 1
  base. Either each page links it, or the lead adds it to the shell + `AssetBundle::STYLESHEETS` (shared files
  another agent is editing — not done here).
- **B4** `/assets/css/components/forms.css` is linked by `layout/shell.twig` and 404s (another agent's
  in-flight file) — every shell page logs a refused stylesheet until it lands.
