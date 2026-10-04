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
| Apple, `UISheetPresentationController` (iOS 15+) — [WWDC21 "Customize and resize sheets in UIKit"](https://developer.apple.com/videos/play/wwdc2021/10063/), [sarunw.com](https://sarunw.com/posts/bottom-sheet-in-ios-15-with-uisheetpresentationcontroller/), [Donny Wals](https://www.donnywals.com/presenting-a-bottom-sheet-in-uikit-with-uisheetpresentationcontroller/) | Two system detents: `.medium` ≈ **half** the screen, `.large` = full height. With both, the person swipes between them. | Open detent 50%, full detent |
| Apple, [`prefersScrollingExpandsWhenScrolledToEdge`](https://developer.apple.com/documentation/uikit/uisheetpresentationcontroller/prefersscrollingexpandswhenscrolledtoedge) — default **true** ([sarunw.com](https://sarunw.com/posts/bottom-sheet-in-ios-15-with-uisheetpresentationcontroller/)) | Scrolling up on content at the medium detent **expands the sheet** instead of scrolling; content scrolls only once the sheet is at its largest. | **Expand first, then scroll** (§3 scenarios 2–3) |
| Apple, [WWDC18 "Designing Fluid Interfaces"](https://developer.apple.com/videos/play/wwdc2018/803/) | Release targets are chosen from the **projected** position: `(v / 1000) × r / (1 − r)`, `r` = deceleration rate (0.998 normal, 0.99 fast). Rubber-banding at an edge resists progressively. | Release projection |
| UIScrollView rubber band — [the formula as reverse-engineered](https://gist.github.com/originell/6961057) | `b = (1 − 1 / (x·c/d + 1)) · d`, **c = 0.55**, `d` the dimension. Tends to `d`, never reaches it. | Rubber band above full |
| Material, [`BottomSheetBehavior` docs](https://github.com/material-components/material-components-android/blob/master/docs/components/BottomSheet.md), [API reference](https://developer.android.com/reference/com/google/android/material/bottomsheet/BottomSheetBehavior) | `halfExpandedRatio` **0.5**; `significantVelocityThreshold` **500 px/s**; modal sheets hideable; `draggable` true; the drag handle (`BottomSheetDragHandleView`) takes accessibility expand/collapse actions. | 50% again; flick velocity; grabber is a control |
| vaul (Emil Kowalski) — [constants.ts](https://github.com/emilkowalski/vaul/blob/main/src/constants.ts), ["Building a drawer component"](https://emilkowal.ski/ui/building-a-drawer-component), [snap points](https://vaul.emilkowal.ski/snap-points) | `VELOCITY_THRESHOLD 0.4` (px/ms), `CLOSE_THRESHOLD 0.25` (fraction of height), `SCROLL_LOCK_TIMEOUT 100`, curve `cubic-bezier(0.32, 0.72, 0, 1)` (from Ionic, "to match iOS"), 500ms; `transform: translateY` set directly, never a CSS variable per frame; damping when dragged past the top; drag only once the inner list is at its top. | Close threshold, curve, transform-only |
| [pure-web-bottom-sheet](https://github.com/viliket/pure-web-bottom-sheet) (viliket) | CSS scroll-snap implementation; `content-height` (fit to content) and **`expand-to-scroll`** ("content can only be scrolled after the sheet has been expanded to its full height") modes. Needs Safari 18.2+ for initial snap. | Fit-to-content medium; names the mode |
| Instagram comments, as reverse-engineered in [a public PR](https://github.com/SamandarAlimov/socialalsamos/pull/137) | Content owns vertical movement while it has scroll offset; at `scrollTop = 0` a continued downward pull is handed to the sheet **in the same gesture**, collapsing to the compact detent and then translating off-screen. | Same-gesture handoff (scenario 4) |
| Facebook "Your shortcuts" — [Dummies](https://www.dummies.com/article/technology/social-media/facebook/use-shortcuts-section-facebook-252513/), [WERSM](https://wersm.com/facebook-is-personalising-your-navigation-bar-shortcuts-according-to-what-you-do-most/) | Ranked by what you use most and most recently; each can be **Pinned**, **Auto** or **Hidden**. | Most-used ranking; pin/hide → question (§7) |
| Firefox frecency — [Mozilla source docs](https://firefox-source-docs.mozilla.org/browser/urlbar/ranking.html), [MDN archive](http://www.devdoc.net/web/developer.mozilla.org/en-US/docs/The_Places_frecency_algorithm.html) | Frequency × recency with **exponential decay, half-life one month** (λ = ln 2 / 30 days). | Decayed-counter frecency |
| NN/g, [Bottom sheets](https://www.nngroup.com/articles/bottom-sheet/); WCAG 2.2 SC 2.5.7 | A sheet needs a visible close; every drag needs a single-pointer alternative. | Close button, grabber button, Esc, Back |

**The 50% open height is Apple's `.medium` (and Material's `halfExpandedRatio`), which Meta's
iOS sheets inherit natively. It is an owner-confirmable number, not a measurement of Meta.**

## 2. The numbers chosen

| Quantity | Value | Source / reason |
|---|---|---|
| Open (medium) detent | **50% of the visual viewport**, or the content's own height when that is shorter (fit) | Apple `.medium`, Material 0.5, pure-web `content-height` |
| Full detent | the visual viewport **minus the top safe area minus 10px** | Apple `.large` leaves the status bar and a sliver above the sheet. **Deviation:** the DC pins the sheet from `top:52px`; see §6 |
| Drag slop | **8px** before anything moves; the axis is decided then — mostly horizontal never drags | platform touch slop (Android 8dp) |
| Flick | **0.5 px/ms** (500 px/s) | Material `significantVelocityThreshold`; vaul uses 0.4 |
| Close line | **25% of the medium height** below the medium detent | vaul `CLOSE_THRESHOLD` |
| Release projection | `y + v × 99` (px, `v` in px/ms) — Apple's formula at `r = 0.99` | WWDC18; 0.998 overshoots a sheet with three positions |
| Rubber band above full | `(1 − 1/(x·0.55/d + 1))·d`, `d` = viewport height | UIScrollView |
| Settle / open / close | **`--ag-dur-2` (260ms)** on `cubic-bezier(.32,.72,0,1)` (`--ag-ease-sheet`) | vaul/Ionic iOS curve; the house sheet duration (§6.5) instead of vaul's 500ms |
| List momentum (JS-driven, see §4) | velocity × 0.998 per ms, stops under 0.02 px/ms | UIScrollView normal deceleration |
| Scrim | opacity = visible height ÷ medium height, clamped 0–1 — tracks closed→medium, holds above | coordinator scenario 10 |
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
| 1 | closed | tap Menu | opens at **medium** (50% of the visual viewport, or content height if shorter); full = viewport − top safe area − 10px |
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
