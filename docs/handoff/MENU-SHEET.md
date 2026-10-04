# The phone Menu sheet — research, chosen numbers, and the rebuild (4 Oct 2026)

Owner request (GAPS §8e): *"The menu for mobile should be smarter. You cannot currently drag down
to close."* Two asks: (1) a **most used** set where the four Participate tiles are, ranked by
frequency and recency; (2) **drag** that behaves "kinda how Meta does it", including the height the
sheet opens at. This file was written in two passes: §1–§4 (research and the numbers) **before** a
line of the rebuild, §5 onward after it.

---

## 1. What "how Meta does it" actually is

Meta's iOS apps (Instagram comments and share sheets, Facebook's menu sheets, Threads' sheets)
present with the platform sheet, and on Android with the Material bottom sheet behaviour. Their
exact open height **cannot be measured from outside** — there is no published spec, and screen
recordings vary by device and content. What can be cited is the platform model they inherit:

| Source | What it says | Used for |
|---|---|---|
| Apple, `UISheetPresentationController` (iOS 15+) — [WWDC21 "Customize and resize sheets in UIKit"](https://developer.apple.com/videos/play/wwdc2021/10063/), [sarunw.com](https://sarunw.com/posts/bottom-sheet-in-ios-15-with-uisheetpresentationcontroller/), [Donny Wals](https://www.donnywals.com/presenting-a-bottom-sheet-in-uikit-with-uisheetpresentationcontroller/) | Two system detents: `.medium` ≈ **half** the screen, `.large` = full height. With both, the person swipes between them. | The first build's fixed 50% (superseded by the content-aware detent, §2); full detent |
| Apple, [`prefersScrollingExpandsWhenScrolledToEdge`](https://developer.apple.com/documentation/uikit/uisheetpresentationcontroller/prefersscrollingexpandswhenscrolledtoedge) — default **true** ([sarunw.com](https://sarunw.com/posts/bottom-sheet-in-ios-15-with-uisheetpresentationcontroller/)) | Scrolling up on content at the medium detent **expands the sheet** instead of scrolling; content scrolls only once the sheet is at its largest. | **Expand first, then scroll** (§3 scenarios 2–3) |
| Apple, [WWDC18 "Designing Fluid Interfaces"](https://developer.apple.com/videos/play/wwdc2018/803/) | Release targets are chosen from the **projected** position: `(v / 1000) × r / (1 − r)`, `r` = deceleration rate (0.998 normal, 0.99 fast). Rubber-banding at an edge resists progressively. | Release projection |
| UIScrollView rubber band — [the formula as reverse-engineered](https://gist.github.com/originell/6961057) | `b = (1 − 1 / (x·c/d + 1)) · d`, **c = 0.55**, `d` the dimension. Tends to `d`, never reaches it. | Rubber band above full |
| Material, [`BottomSheetBehavior` docs](https://github.com/material-components/material-components-android/blob/master/docs/components/BottomSheet.md), [API reference](https://developer.android.com/reference/com/google/android/material/bottomsheet/BottomSheetBehavior) | `halfExpandedRatio` **0.5**; `significantVelocityThreshold` **500 px/s**; modal sheets hideable; `draggable` true; the drag handle (`BottomSheetDragHandleView`) takes accessibility expand/collapse actions. | The 50% band's lower neighbour; flick velocity; grabber is a control |
| vaul (Emil Kowalski) — [constants.ts](https://github.com/emilkowalski/vaul/blob/main/src/constants.ts), ["Building a drawer component"](https://emilkowal.ski/ui/building-a-drawer-component), [snap points](https://vaul.emilkowal.ski/snap-points) | `VELOCITY_THRESHOLD 0.4` (px/ms), `CLOSE_THRESHOLD 0.25` (fraction of height), `SCROLL_LOCK_TIMEOUT 100`, curve `cubic-bezier(0.32, 0.72, 0, 1)` (from Ionic, "to match iOS"), 500ms; `transform: translateY` set directly, never a CSS variable per frame; damping when dragged past the top; drag only once the inner list is at its top. | Close threshold, curve, transform-only |
| [pure-web-bottom-sheet](https://github.com/viliket/pure-web-bottom-sheet) (viliket) | CSS scroll-snap implementation; `content-height` (fit to content) and **`expand-to-scroll`** ("content can only be scrolled after the sheet has been expanded to its full height") modes. Needs Safari 18.2+ for initial snap. | Fit-to-content medium; names the mode |
| Instagram comments, as reverse-engineered in [a public PR](https://github.com/SamandarAlimov/socialalsamos/pull/137) | Content owns vertical movement while it has scroll offset; at `scrollTop = 0` a continued downward pull is handed to the sheet **in the same gesture**, collapsing to the compact detent and then translating off-screen. | Same-gesture handoff (scenario 4) |
| Facebook "Your shortcuts" — [Dummies](https://www.dummies.com/article/technology/social-media/facebook/use-shortcuts-section-facebook-252513/), [WERSM](https://wersm.com/facebook-is-personalising-your-navigation-bar-shortcuts-according-to-what-you-do-most/) | Ranked by what you use most and most recently; each can be **Pinned**, **Auto** or **Hidden**. | Most-used ranking; pin/hide → question (§7) |
| Firefox frecency — [Mozilla source docs](https://firefox-source-docs.mozilla.org/browser/urlbar/ranking.html), [MDN archive](http://www.devdoc.net/web/developer.mozilla.org/en-US/docs/The_Places_frecency_algorithm.html) | Frequency × recency with **exponential decay, half-life one month** (λ = ln 2 / 30 days). | Decayed-counter frecency |
| NN/g, [Bottom sheets](https://www.nngroup.com/articles/bottom-sheet/); WCAG 2.2 SC 2.5.7 | A sheet needs a visible close; every drag needs a single-pointer alternative. | Close button, grabber button, Esc, Back |

**The open height is content-aware (owner, 4 Oct 2026: "The open height varies sometimes").** The
first build opened at a fixed 50% — Apple's `.medium` and Material's `halfExpandedRatio`. The owner
observed that Meta's sheets do not: they open to what the content needs. Apple has the same idea
in custom detents (iOS 16 `.custom { context in … }`, a resolver that returns a height from the
content), and pure-web-bottom-sheet calls it `content-height`. So the medium detent is now
resolved from the layout, inside a 45–70% band (§2).

## 2. The numbers chosen

| Quantity | Value | Source / reason |
|---|---|---|
| Open (medium) detent | **Content-aware**: tall enough to show the head, the account or join card and the four squares **whole**, ending on the bottom of a whole block or list row (never through one), plus up to 12px of breath that never reaches into the next row; clamped to **45–70% of the visual viewport**; the content's own height when that is shorter. If the squares cannot fit inside 70% (very large text on a short phone), the most that fits whole. Recomputed on every open, resize/rotation and sub-view | owner, 4 Oct 2026; Apple custom detents; pure-web `content-height` |
| Full detent | the visual viewport **minus the top safe area minus 10px** | Apple `.large` leaves the status bar and a sliver above the sheet. **Deviation:** the DC pins the sheet from `top:52px`; see §6 |
| Drag slop | **8px** before anything moves; the axis is decided then — mostly horizontal never drags | platform touch slop (Android 8dp) |
| Flick | **0.5 px/ms** (500 px/s) | Material `significantVelocityThreshold`; vaul uses 0.4 |
| Close line | **25% of the medium height** below the medium detent | vaul `CLOSE_THRESHOLD` |
| Release projection | `y + v × 99` (px, `v` in px/ms) — Apple's formula at `r = 0.99` | WWDC18; 0.998 overshoots a sheet with three positions |
| Rubber band above full | `(1 − 1/(x·0.55/d + 1))·d`, `d` = viewport height | UIScrollView |
| Settle / open / close | **`--ag-dur-2` (260ms)** on `cubic-bezier(.32,.72,0,1)` (`--ag-ease-sheet`) | vaul/Ionic iOS curve; the house sheet duration (§6.5) instead of vaul's 500ms |
| List momentum (JS-driven, see §4) | velocity × 0.998 per ms, stops under 0.02 px/ms | UIScrollView normal deceleration |
| Scrim | opacity = visible height ÷ medium height, clamped 0–1 — tracks closed→medium, holds above | coordinator scenario 10 |
| Close line | 25% of **this** open height below it (it moves with the content-aware detent) | vaul |
| Reduced motion | duration 0 — an instant snap; dragging still follows the finger | skill §13, §17 |

### Most used (frecency)

| Quantity | Value | Reason |
|---|---|---|
| Score | a **decayed counter**: on each open `s ← s·2^(−Δt/H) + 1` | Firefox's double exponential decay, collapsed to one stored number per destination |
| Half-life `H` | **14 days** | Firefox uses 30 for browsing history; a menu's own shortcuts should follow a habit change inside a fortnight (Facebook: "visited in the past week") |
| Qualifies as a shortcut | decayed score **≥ 1.5** (≈ two recent opens) | one stray tap is not a habit |
| Warm-up | **5** recorded menu opens in total before anything is personalised; until then the four Participate tiles exactly | owner: "falls back to today's four until there is enough history" |
| Slots | **4**, ranked first, then the Participate defaults fill what is left, in their own order | the four squares |
| Tie-break | score ↓, then last opened ↓, then catalogue order ↑ | deterministic |
| Never shown | a destination the visitor cannot open (`who`: all / member / guest) | owner |
| Never lost | a Participate default pushed out of the tiles is listed at the head of Explore | a destination must not become unreachable because it was not used |

**Where usage lives.** A signed-in member: `gates_users.menu_use_json`, one bounded JSON document
(the same shape as `display_json` — read once per page for a member already identified by the
session, never filtered or sorted on; no table, no index, nothing for `SchemaIndex` to guard).
It follows them between devices. A guest: `localStorage["ag-menu-use"]` **only** when the visitor
allowed Preferences (`data-ag-keep="1"`, `CookiePrefs`), declared in `CookieRegistry`; otherwise
nothing is stored and the defaults show. What is counted is a destination **opened from the
Menu** — not every page view — because the request was "the items you use most" in the menu.

Recording never holds navigation: a member's open is a `navigator.sendBeacon` (fallback `fetch`
with `keepalive`) to `POST /account/menu-use` carrying the CSRF token in the form body; a guest's
is one `localStorage` write. Without script every link simply works and nothing is counted.

## 3. Scenarios — the behaviour contract

Each row: start → gesture → expected end. §5 records what was measured.

| # | Start | Gesture | Expected |
|---|---|---|---|
| 1 | closed | tap Menu | opens at the **content-aware medium**: head + account/join card + the four squares whole, ending on a whole block/row, within 45–70% of the visual viewport (content height if shorter); full = viewport − top safe area − 10px. Checked on 640/844/932px phones, guest and member, 100% and 150% text |
| 2 | medium | swipe up anywhere, the list included | sheet follows the finger and settles at **full**; the list does **not** scroll at medium |
| 3 | full | swipe up on the list | the list scrolls (with momentum) |
| 4 | full, list scrolled | swipe down | the list scrolls back first; when `scrollTop` reaches 0 **in the same gesture** the rest of the pull moves the sheet |
| 5 | full, list at top | drag down and release | **medium**, or **closed** if the projection passes the close line |
| 6 | medium | drag down | closes past 25% of the medium height, or on a ≥ 0.5 px/ms downward flick; otherwise springs back |
| 7 | any | release | nearest detent (closed only via the close line) to the **projected** position; a flick from full never skips to closed unless the projection passes the close line |
| 8 | full | drag up on the grabber/head | rubber band (c = 0.55), springs back to full |
| 9 | any | tap; horizontal move; tap during momentum | no drag before 8px; a mostly horizontal move never drags; a tap during momentum stops it and does not navigate |
| 10 | any | drag | scrim follows the position closed→medium, holds above; tapping it closes |
| 11 | any | grabber / keyboard | the grabber is a ≥ 44px button: tap toggles medium ↔ full, named "Expand menu"/"Collapse menu"; Esc, the close button and Back close; focus trapped and returned to the Menu tab |
| 12 | medium | open Display & reading / Language | taller than the detent → full; Back to the menu keeps full |
| 13 | medium | focus a form control (the language select) | full |
| 14 | any | resize / rotate / on-screen keyboard | detents recomputed, the named detent kept |
| 15 | reduced motion | any | no spring — instant snap; dragging still follows the finger |
| 16 | iOS Safari | any | the page behind cannot scroll or pull-to-refresh; `touch-action` on the sheet keeps the browser from taking the vertical pan; `overscroll-behavior: contain` on the list |
| 17 | `dir="rtl"` | any | identical vertically |

## 4. How it is built (decided before building)

- **One owner for vertical touch.** The sheet carries `touch-action: pan-x pinch-zoom` and the
  script handles every vertical touch on it — the sheet's position AND the list's `scrollTop`,
  with its own momentum. This is what makes scenario 4 possible: once a browser has started a
  native scroll, the `touchmove`s that follow are uncancelable (Chrome logs "Ignored attempt to
  cancel a touchmove event with cancelable=false"; iOS behaves the same), so a sheet that lets
  the list scroll natively can only hand over on the NEXT gesture — which is vaul's model. The
  cost: list momentum on a touch screen is ours (UIScrollView's 0.998/ms), not the platform's.
  Wheel, keyboard and focus scrolling stay native (`overflow-y: auto` is unchanged).
- Pointer Events, listened on the document while a gesture is live — **never
  `setPointerCapture`** (CLAUDE.md, the globe band: capture eats a child button's click).
  A mouse drags only from the grabber and the head; a finger anywhere on the sheet.
- The sheet is laid out at its **full** height and moved with `transform: translateY(y)`, set
  directly on the element (vaul: not a custom property per frame, which restyles every child).
- After a drag passes the slop, the next `click` is swallowed, so releasing over a row does not
  open it.
- History, Esc, the scrim, the focus trap and focus return stay the shell's: `AGChrome.openSheet`
  → `AGShell.openSheet`. The drag engine calls the same close.

---

## 5. Results (measured 4 Oct 2026, Chromium with touch emulation, CDP touch events)

Driver: `scratchpad/ms/drive.js` (Playwright, `hasTouch`, `isMobile`, 390 × 844 and 834 × 1112; the
gestures are real `Input.dispatchTouchEvent` sequences, not scripted `scrollTop`). **26 of 26 rows
pass.** Evidence in `shots/menu-sheet/`.

| # | Measured | Shot |
|---|---|---|
| 1 | Guest at 390×844: opens with 464 of 844px (55%) — the squares whole, ending 12px past them; scrim 1. The full matrix is below | `open-medium-390.png`, `open-*.png` |
| 2 | A quick swipe up on the list at medium → full (top 10px), list `scrollTop` 0 | `full-390.png` |
| 3 | At full, the same swipe scrolls the list (+momentum) | `drag-sequence-390.webm` |
| 4 | Scrolled list, one continuous pull down: list reached 0, then the sheet moved to top 150 in the same gesture | `handoff-mid-gesture-390.png` |
| 5 | Released slowly from there → a detent (full or medium) | — |
| 6 | Slow drag 32% of the medium height below medium → closed; quick flick from medium → closed, focus back on the Menu tab; a 20px drag held and released → back to medium | `flick-closed-390.png` |
| 7 | Quick flick down from full → **medium**, not closed | — |
| 8 | Drag up on the head at full: stretched to top −78 for 200px of finger travel, springs back to 10 | `rubber-band-390.png` |
| 9 | A 200px horizontal move did nothing; a tap while the list coasted stopped it (`scrollTop` stayed put) and did not navigate | — |
| 10 | Mid-drag 233px showing: scrim 0.55 (= 233 / 422) | `mid-drag-down-390.png` |
| 11 | Grabber tap and keyboard Enter → full, label "Collapse menu"; Esc and browser Back close, focus to "Open menu", URL unchanged | — |
| 12 | Display & reading from medium → full; Back to the menu keeps full | `display-subview-390.png` |
| 13 | Focusing a row below the visible edge at medium → full | — |
| 14 | Rotating 390×844 → 844×390 at medium → medium recomputed (236 of 390px, 61%) | `rotated-medium-390.png` |
| 15 | Reduced motion: open and settle are instant (state final 30ms after the tap); dragging still follows the finger | `reduced-motion-full-390.png` |
| 16 | A drag on the scrim scrolled nothing behind (`main.scrollTop` 0 → 0) | — |
| 17 | `?lang=ar`: identical | `rtl-medium-390.png`, `rtl-full-390.png` |
| 834 | No Menu tab — the Menu is phone chrome, below 600px only (Phase 2); the header serves ≥600 | `no-menu-834.png` |

**Open height across phones, audiences and text size** (`scratchpad/ms/openmatrix.js`; pass =
45–70%, account/join card and squares wholly visible, nothing cut by the edge). **12 of 12 pass.**

| Phone | Visitor | 100% text | 150% text |
|---|---|---|---|
| 390 × 640 | guest (join card) | 373px · 58.3% | 438px · 68.4% |
| 390 × 640 | member (profile card) | 296px · 46.3% | 321px · 50.2% |
| 390 × 844 | guest | 464px · 55.0% | 438px · 51.9% |
| 390 × 844 | member | 387px · 45.9% | 420px · 49.8% |
| 430 × 932 | guest | 443px · 47.5% | 438px · 47.0% |
| 430 × 932 | member | 426px · 45.7% | 420px · 45.1% |

Shots: `open-{390x640,390x844,430x932}-{guest,member}-{100,150}.png`. Found on the way and fixed:
the first cut added its 12px of breath after a list row, which is 12px INTO the next row (rows
touch) — the breath now stops at the next element's top; and at 150% "Nominate" broke mid-word in
its square (`overflow-wrap:anywhere`) — it hyphenates now.

Video of the whole sequence (open → up to full → scroll with momentum → one pull hands over and
returns to medium → flick closed): `shots/menu-sheet/drag-sequence-390.webm`.

**Most used** (`scratchpad/ms/mostused.js`): guest with Preferences allowed — before: Nominate,
Vote, Awards, Events ("Participate"); after seven opens (shop ×3, blog ×2, status, pulse): Shop,
Blog, Nominate, Vote ("Most used"), Awards and Events moved to the head of Explore
(`most-used-guest-{before,after}-390.png`). Guest refusing Preferences: nothing stored
(`localStorage` null), defaults unchanged. Member: eight opens counted by beacon in 26ms total
(navigation never waited), server-ranked to Legacy Vault, Giving, Status, Nominate on the next page
(`most-used-member-{before,after}-390.png`); "Register a profile" is not offered to a member at all.

**Tests.** `MenuSheetTest` rebuilt (17 tests, was 3 — it is a rebuilt file, `M`, not new):
Explore, Status, Cookies/Sign out kept; the four squares default and personalised; displaced
defaults head Explore and nothing leaves the Menu; warm-up, frequency × recency and decay-out;
decay before the add; tie-break score → recency → catalogue; never a shortcut the visitor cannot
open; normalisation (future clocks, junk, the 1024-byte bound); **every destination requested
through the real router as a guest and as a member** (opens for exactly the audience it claims);
**the JS `Rank` run under Node against the PHP on 402 sampled histories** (ties included); the
script types no ranking number and writes nothing without Preferences; the beacon counts the
session's member only (a body `user_id` is ignored, a guest is bounced); the Menu's code is in no
shared chrome file. **14 mutations, each caught** (two were missed on the first run — the
tie-break's recency — and the tests were strengthened until they failed).

## 6. Deviations

| What | Why | Decides |
|---|---|---|
| Full detent at the top safe area + 10px, not the DC's `top:52px` | §7.4's 52px was a fixed sheet; a detented sheet's large detent is Apple's (status bar + a sliver). At full the app bar is covered | owner |
| Open height content-aware, 45–70% (was a fixed 50% in the first build) | owner, 4 Oct 2026: "the open height varies"; the band is the owner's | — |
| Expand-first (a swipe up at medium expands, never scrolls) | Apple's `prefersScrollingExpandsWhenScrolledToEdge` default; coordinator correction | — |
| List momentum on touch is the script's (0.998/ms), not the platform's | The same-gesture hand-over is impossible with native scrolling (uncancelable `touchmove`s once a scroll starts); wheel and keyboard scroll stay native | owner |
| "Register a profile" not shown to a signed-in member (Explore and shortcuts) | `/account/register` sends a member to `/account`: a row that goes somewhere other than it says. The Explore list is otherwise §7.4's seven | owner |
| Settle 260ms (`--ag-dur-2`) on vaul's iOS curve, not vaul's 500ms | the house sheet duration (§6.6); added `--ag-ease-sheet` to `tokens.css` | — |
| The back button is `hidden` on the main view, not `visibility:hidden` | a visibility-hidden control sat in the shell's focus list; the title keeps its column by `grid-column` | — |
| Member usage on `gates_users.menu_use_json` (migration `2027_03_01_member_menu_use.php`, VARCHAR(1024), no index), not a table | one bounded document per member, read once per page; no index means no `SchemaIndex` trap | — |
| Only opens FROM the Menu are counted (not every page view) | "the items you use most" in the menu; counting page views would rank whatever the home page links to | owner |

## 7. Open questions for the owner

1. ~~Confirm the 50% open height~~ — answered: content-aware, 45–70% (built, §2, §5).
2. **Pin / hide a shortcut** (Facebook's Pin · Auto · Hide) — not built: it needs an edit mode the
   DC does not draw. A long-press menu on a tile is the cheap version; approve a design first.
3. Confirm the frecency numbers: half-life 14 days, two recent opens to qualify, five opens before
   anything changes.
4. Should a **member's** device keep a local copy too (so the first menu on a new device is theirs
   offline)? Today the server is the only copy for members.
5. Should "Register a profile" leave Explore for members (done) — or should it point members at the
   registry form instead?
6. On iOS Safari the sheet was **not** measured on a real device (Chromium touch emulation only):
   `touch-action` on both the sheet and its scroller, `overscroll-behavior: contain` and the scrim's
   `touch-action:none` are the standard levers, but iOS rubber-banding of the page behind needs a
   device check.
