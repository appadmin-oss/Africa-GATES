# Events index — `/events` (8 Oct 2026)

Built from `handoff-2026-10-05/specs/EVENTS-INDEX.md` and `design/EventsPage.dc.html` (view=index),
checked side by side at 390, 800 and 1280. Replaces the 4 Oct page (one featured card and a list).

## Files

| File | What |
|---|---|
| `src/Services/EventsFront.php` | `browse()` — one read of upcoming (+ live now) and past; spotlight, type tiles, rows, Place options and the results grid are all filters over it. `card()` adds the facts a card prints. `index()` is gone. |
| `database/migrations/2027_03_09_event_spotlight_rank.php` | `spotlight_rank` TINYINT UNSIGNED NULL, both schemas. 1–4, validated in PHP. |
| `templates/admin/events/form.twig` | "Spotlight on the events page": Not in the spotlight, Slide 1–4. |
| `templates/pages/events.twig` | Heading · spotlight (carousel 600+, its own scroll-snap markup on a phone) · sticky toolbar · Browse by type · rows · Watch again · hosting band · or one results grid. |
| `public/assets/css/components/events-index.css` | The page's own sheet, so the detail and alert pages are not charged its colour. |
| `public/assets/js/events-index.js` | Carousel (the bar's `animationend` is the clock), row arrows, phone dots, Place auto-submit. An upgrade: the page works without it. |
| `src/Services/EventSales.php` `LOW_PLACES` | 25 — "N left" here and "Only N places left" on the event card read one number. |
| `CoverKind::resolve()` `tone` | One override to another of the five tones, for an event live now. |

## Decisions and deviations — owner to confirm

| # | What | Why |
|---|---|---|
| EV-1 | **No ranked slides → the next four upcoming events.** | An index opening on nothing because nobody set a rank reads as an empty calendar; the 4 Oct page always led with the next event. |
| EV-2 | **No attendee faces.** "N going" is shown only when above zero (confirmed tickets + RSVPs, `attendingForEvent`). | §Data: faces only of attendees "who chose to be shown publicly" — nobody has been asked, so none qualify. |
| EV-3 | **No save heart.** | Nothing on the platform could read a save back (no saved list, no account view). A heart that stores into nowhere is a mechanism with no route out. Owner: build "Saved events" (device or account), or drop it? |
| EV-4 | **No "Selling fast".** "N left" (≤ `LOW_PLACES`), "Early bird", "Waiting list", plus the 4 Oct "Coming soon" and "Sold out". | No rule could compute "selling fast" honestly. |
| EV-5 | **Copy changed where it was untrue here.** Heading: "…community days." (not "in 41 countries" — events store no country). Online row: "Watch from anywhere" (not "with captions"). Hosting band: "Sell tickets, run a waiting list and stream it live. Tell us what you are planning and we will set it up with you." (not cedis/shillings/CFA, captions, "free to list"). Empty state drops "follow a host". | Each named a feature or a figure this platform does not have. |
| EV-6 | **"Host an event" and "Create an event" go to `/support`; no "How hosting works".** | There is no self-serve event builder and no hosting article. |
| EV-7 | **"N left" and "Live now" are live-ink words on a neutral chip**, not the live wash. | With the green CTA and the gold chip, a live-wash field is a third colour event on a tier-2 page (ColourBudgetTest). |
| EV-8 | **Chips are 44px at every width** (DC: 40 on desktop); the tablet slide title is 30px (DC 31). | House target floor; no 31 on the type ladder. |
| EV-9 | **Filters are links and a GET form**, and `?f=upcoming|soon|live|past` still answer as results grids with no chip. "Watch again" carries "All past events". | Deep links, no-JS, and the owner's 4 Oct coming-soon and past views kept one address away. |
| EV-10 | **"Learn something" is workshops, training and conferences**; webinars are their own tile and are not in that row. | The DC's row adds webinars while its "See all" target (the Learn tile) excludes them, so "See all N" would count a different set. |
| EV-11 | **Live now needs a stream and an end date still ahead.** | No end date → no duration to invent; a finished event must never read as happening. |
| EV-12 | **Recordings: no duration chip**; the play disc opens the recording link. | Duration is not stored. |
| EV-13 | Under reduced motion the **Pause/Play button is not offered**. | Nothing ever advances then, so the button would claim a state the page cannot enter; Previous, Next and the bars still work. |

## Verification

`EventsPagesTest` (19): order of blocks, operator ranking and a past event refused a slide, sandbox
kept out (index and search), `?f=soon`/`?f=past`, search/type/place and how chips keep each other,
Live now, "N left" on the shared threshold, the carousel's markup, clock and targets. Four planted
mutations (live without an end date, a looser low-places rule, ignoring ranks, a widened live
query) each fail it. Carousel driven in Chromium: advances at 6.5s, holds on hover and focus,
pauses, Previous/Next, nothing advances under reduced motion.
