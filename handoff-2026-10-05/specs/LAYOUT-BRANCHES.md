# Phone, tablet, desktop: strict layout-branch spec

Read this before building ANY screen. It overrides every older note about breakpoints. When a DC and this file disagree on *which markup renders where*, this file wins; for values (sizes, copy, colours), the DC wins.

## 1. The three layouts

| Layout | Width (of the page container, not the device) | Chrome | Bottom |
|---|---|---|---|
| **phone** | < 600px | `partials/app-bar.twig` (root or child) | `partials/tab-bar.twig` on tab destinations; an action bar on flow pages; never both |
| **tablet** | 600–1023px | `partials/site-header.twig` | none (no tab bar) |
| **desktop** | ≥ 1024px | `partials/site-header.twig` | none |

- 3-column desktop layouts start at **≥ 1200px**. From 1024 to 1199 they drop to two columns (the third column's content moves under the second).
- **Wrong today:** `site-header.twig` says "hidden below 768px". It must hide below **600px**; the app bar and tab bar show **only** below 600px. Between 600 and 767px the site currently has no defined chrome.
- Every `@media` in `components/*.css` is **min-width, mobile-first**, using only these values: `(min-width:600px)`, `(min-width:1024px)`, `(min-width:1200px)`. No `max-width` queries, and no 640/700/767/768/900/991/992. `main.css` uses all of those; it's legacy and must not be loaded by `layout/shell.twig`. Add a guard test that fails on any other breakpoint value in `components/*.css`.
- Components that live in boxes (covers, cards, the ticket card) use **container queries**, never media queries.

## 2. How to read a DC for its branches

Each DC computes `L` = phone | tablet | desktop from its own measured width (`ResizeObserver`), or from the `layout` prop.

1. **`<sc-if value="{{ isPhone }}">` = phone-only markup.** It is a separate branch: different elements, different order, often different copy. Build it as its own markup block. Don't restyle the desktop markup to look like it.
2. **`isDesktop` / `isWide` = tablet AND desktop** (everything ≥ 600px), unless the DC also has `isTablet`.
3. **Holes like `{{ cols }}` with a ternary** (`isPhone ? … : isTablet ? … : …`) = the same markup with different values. Build one block and switch the values in CSS at the breakpoints above.
4. **`Object.assign(o, {…})` when `L==='tablet'`** = tablet overrides of rule 3. They win for 600–1023px.
5. Page-specific flags (`phoneBar`, `itemPhone`, `idxWide`, `rowNarrow`…) follow the same rule: `phone*` / `*Phone` / `narrow` are < 600 only; `*Wide` / `wide` are ≥ 600.

## 3. How to build a branch in Twig (no duplicated forms)

The server can't know the width, so both branches ship in the HTML and CSS shows one:

```css
.ag-only-phone{display:block}  .ag-only-wide{display:none}
@media (min-width:600px){ .ag-only-phone{display:none} .ag-only-wide{display:block} }
```
(Use the element's real display value instead of `block`: `flex`, `grid`, `contents`.)

Strict rules:
1. **Never duplicate a form, an input, an `id` or a live region across branches.** If both layouts contain the same form (ballot, ticket checkout, search, nomination steps), render it **once** and move it with CSS: `order`, `grid-area`, `display:contents` on wrappers. The events page does this already: the ticket rail is `display:contents` on phone so its card can sit between the hero and About.
2. Duplicate only presentational blocks (heroes, app bars, floating buttons, galleries). Mark the hidden one with `.ag-only-phone` or `.ag-only-wide`. `display:none` removes it from the accessibility tree; don't also add `aria-hidden`.
3. A phone branch never contains desktop blocks squeezed into one column (see CLAUDE.md). If the DC has no phone branch for a block, the block is the same markup with phone values.
4. JS that reads layout uses `matchMedia('(min-width:600px)')` (or 1024 / 1200), never `window.innerWidth` comparisons with other numbers.

## 4. Per-DC map

"Branch flags" lists the `sc-if` values that switch markup. "—" means one markup with value switches only (rule 3).

| DC | Branch flags | Tablet values | Measures itself |
|---|---|---|---|
| AppBar | — one markup; values switch | — | no |
| AwardsPage | `isPhone`, `isDesktop` | yes | yes |
| BlogPage | `isPhone`, `isDesktop` | yes | yes |
| Celebration | — one markup; values switch | — | no |
| CookieConsent | — one markup; values switch | — | no |
| DiscoverPage | `isPhone`, `isDesktop` | yes | yes |
| DisplayReading | — one markup; values switch | — | no |
| DocPage | `isPhone`, `isWide` | yes | yes |
| EventsPage | `isPhone`, `phoneIdxBar`, `isDesktop`, `phoneBar` | yes | yes |
| Gee | — one markup; values switch | — | no |
| GivingPage | `isPhone`, `isDesktop`, `phoneGiveBar` | yes | yes |
| HelpCentre | `isPhone`, `isWide` | yes | yes |
| HomePageV3 | `isPhone`, `isDesktop` | yes | yes |
| Leaderboard | `isPhone`, `isWide` | yes | yes |
| LegacyVault | `isPhone`, `phoneIdxBar`, `isWide` | yes | yes |
| MobileMenu | — one markup; values switch | — | no |
| Nominate | — one markup; values switch | — | no |
| NominateHub | `isPhone`, `isDesktop` | yes | yes |
| NominationFlow | `isTablet`, `isDesktop`, `isPhone` | yes | yes |
| NomineePage | `isPhone`, `isDesktop` | yes | yes |
| ProfilePage | `isPhone`, `isDesktop` | yes | yes |
| PulsePage | `isPhone` | yes | yes |
| ResultsPage | `isPhone`, `isDesktop` | yes | yes |
| ShopPage | `isPhone`, `phoneTopCart`, `isDesktop`, `idxPhone`, `hasPhonePills`, `idxWide`, `itemPhone`, `itemWide`, `phoneBuyBar` | yes | yes |
| SignIn | `isPhone`, `byPhone` | yes | yes |
| SiteHeader | — one markup; values switch | — | no |
| StatusPageV2 | `isPhone`, `isDesktop` | yes | yes |
| TicketPage | — one markup; values switch | — | no |
| VoteBallot | — one markup; values switch | — | no |
| VoteHub | `isPhone`, `isDesktop` | yes | yes |
| VotePage | `isPhone`, `isDesktop`, `wide`, `narrow`, `rowNarrow`, `rowWide`, `phoneBallot` | yes | yes |
| WeAreAfrica | — one markup; values switch | — | yes |
| DefaultCover | — (container queries, §DEFAULT-GRAPHICS 6) | — | yes |
| DefaultGraphics | — (documentation board) | — | no |
| AccountPage | see the DC | yes | yes |
| ChallengePage | see the DC | yes | yes |

## 5. Shared chrome by layout (exact)

| Element | phone | tablet | desktop |
|---|---|---|---|
| Site header (logo · Participate ▾ · Explore ▾ · tools · account) | ✕ | ✓ | ✓ |
| Mega panels | ✕ | ✓ (2 columns) | ✓ (3 columns) |
| App bar | ✓ | ✕ | ✕ |
| Tab bar (Home · Discover · Nominate · Pulse · Menu) | tab destinations only | ✕ | ✕ |
| Menu sheet | from the tab bar | ✕ | ✕ |
| Quick settings sheet (account, language, display) | from the root app-bar avatar | ✕ | ✕ |
| Aa and language popovers | inside Quick settings | header toolbar | header toolbar |
| Action bars (vote, tickets, add to cart) | ✓ sticky bottom | ✕ (the rail card shows) | ✕ |
| Gee launcher | bottom-end, 16px above the bottom UI | 24px from the corner | 24px from the corner |
| Footer | ✓ | ✓ | ✓ |

## 6. Acceptance (in addition to REFERENCE §17)

At 599 and 600px, and 1023 and 1024px, take a screenshot each side of the line: exactly one chrome set is visible, and no element appears in both. Run axe at 390 and 1440: zero duplicate-id and zero duplicate-landmark errors.
