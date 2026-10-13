# The phone Menu sheet — feature inventory before its destruction (4 Oct 2026, at `b0520b8`)

Destroyed and rebuilt for the owner's request of 4 Oct 2026 (GAPS §8e, `MENU-SHEET.md`). Three
places held the Menu, and all three were taken out in one change:

- `templates/partials/menu-sheet.twig` (219 lines) — the whole file.
- `public/assets/js/chrome.js` — `bindMenu()` (the opener, the sub-view pusher, `AGChrome.openMenu`).
- `public/assets/css/components/chrome.css` — the `══ Menu · §7.4` block (`.ag-menu*`, ~100 lines).

**Included by:** `templates/layout/shell.twig` (`{% block sheets %}`) · **Opened by:** the tab
bar's fifth tab (`[data-ag-menu]`, `aria-controls="ag-menu"`) and Quick settings' "All display &
reading settings" (`AGChrome.openMenu(opener, 'display')`).

## MUST RESTORE

| # | Behaviour | Where it was |
|---|---|---|
| 1 | `role="dialog" aria-modal="true"`, `aria-labelledby` the title, `id="ag-menu"`, `inert` at rest | twig |
| 2 | Grabber 38×5 `--ag-grabber`; head grid `44px 1fr 44px`: back (sub-views only) · centred title 700 16.5 · close (30px `--ag-tint` circle in a 44px target, "Close menu") | twig + css |
| 3 | Signed in: profile card → `/account` (52px ink avatar with initials from `member_name`, name 700 17, "View your profile", points from `member_points()` in DM Sans tabular, **left out when null**) | twig |
| 4 | Signed out: join card ("Join Africa GATES", the line, 50px ink "Sign in or join" → `/account/login`) | twig |
| 5 | Four tiles `nav[aria-label=Participate]`: Nominate, Vote, Awards, Events (44px tinted icon r14, 13px 600 label, white r18 tile) | twig + css |
| 6 | Explore, §7.4's seven in order: Discover, Pulse, Giving, Shop, Legacy Vault, Blog, Register a profile (Blog, not the DC's Leaderboard — `MenuSheetTest`) | twig |
| 7 | Settings: Display & reading (pushed; value "Standard/Large/Largest" kept in sync by `chrome.js sync()` via `data-ag-size-label` + `data-l0..2`), Language (pushed; value is `lang_name()` with `lang`), Notifications → `/account#notifications` (signed in only) | twig |
| 8 | Help: Help Centre `/help`, Integrity Center `/integrity`, Status `/status` with the **recorded** state from `status_light()` in words + a dot, nothing when stale (`MenuSheetTest`) | twig |
| 9 | Foot: Privacy, Terms, **Cookies** `/cookies#choices` with `data-ag-do="consent-open"` (consent.js opens the sheet in place and hands focus back to the Menu tab); Sign out as a **POST** with `_token` (signed in only); wordmark line in `--ag-soft` | twig |
| 10 | Display & reading sub-view **includes `partials/display-reading.twig` with `uid:'menu'`** (one markup for every surface — `DisplayReadingTest`) | twig |
| 11 | Language sub-view: links (works with scripting off), name in its own language with `lang`/`dir`, English below via `|trans`, `aria-current` + tick on the current one | twig |
| 12 | Sub-views push inside the sheet (no accordion); title swaps from `data-title-*`; back returns focus to the row that pushed; focus moves to the first control of the new view; the body scrolls to the top on a push | js |
| 13 | One history entry per sheet interaction (Back closes, hand-over keeps the entry); Esc, scrim, focus trap and focus return are `AGShell.openSheet`'s | js (shared `open()` stays in chrome.js) |
| 14 | Every colour a token; tile tones through `.ag-tone--*` (shared marks, stay in chrome.css); type from `--ag-fs-*`; logical properties only (RTL); `.ag-ico-dir` mirrors | css |
| 15 | `body.ag-sheet-open` while open; `--ag-bottom-ui` untouched by the sheet (it is not a bottom bar) | js |

## Not carried

- **Activity** — there was no Activity row in the Phase 2 menu; the feed is retired to
  `/discover?tab=live` and the rebuild adds none.
- `top:52px` as the sheet's only position — replaced by detents (`MENU-SHEET.md` §2, §6).
- Quick settings borrowing `.ag-menu__av` / `.ag-menu__who` from the Menu's CSS — it has its own
  classes now, so the Menu's sheet can be destroyed without taking another sheet's avatar with it.
