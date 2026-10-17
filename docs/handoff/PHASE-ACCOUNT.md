# Member account — desktop frame (8 Oct 2026)

**Owner report:** "Some displays that should be [desktop] are displaying in mobile style, like the
my account page." Measured every shell page at 1280: public pages all use the desktop width; the
four account pages (`/account`, `/account/points`, `/account/notifications`, `/account/display`)
drew a 656px column — Phase 8 built them without a DC (GAPS Q16) as one 720px column at every width.

Rebuilt to `handoff-2026-10-05/design/AccountPage.dc.html`, checked at 390, 800 and 1280.

## What changed

| File | What |
|---|---|
| `templates/pages/account/_frame.twig` | The frame every account page extends: rail (avatar, name, the nine sections with counts) and content from 600 (210px rail, 250px from 1024, 36 apart, 1200 wide); on a phone no rail, the child app bar instead. |
| `src/Services/AccountRail.php` | The nine sections (`TABS` in the DC): order, address, glyph, the number beside each, titles and ledes. The rail and the phone's list both read it. |
| `templates/pages/account/dashboard.twig` | `/account?tab=` — Overview (points balance beside the quick actions; then backed winner, challenge progress, recent activity beside "Finish setting up" and the next ticket), Referrals, Challenges, Purchases, Saved, Security, Settings. Every Phase 8 section kept, moved to its tab. |
| `points.twig`, `notifications.twig`, `display.twig` | On the frame. Points is a balance/redeem split; Activity (`/account/notifications`) now also holds your nominations, votes and shared links. |
| `AccountController` | `railFor()`; redirects that landed on `#me-referral`, `#me-security`, `#profile` now carry their tab. |
| `MenuShortcuts`, `ChallengeCopy`, `MemberActivityService`, event detail | Links to the old anchors point at the tab; the menu's Notifications row pointed at `/account#notifications`, an anchor that never existed (a test pinned it), now `/account/notifications`. |
| `tests/Unit/AccountFrameTest.php` | Rail on every account page with its own section current; every section reachable; anchors on their tab; unknown tab → overview; the 210/250 grid and no 720 cap. |

## Deviations — owner to confirm

| # | What | Why |
|---|---|---|
| AC-1 | Every section is an **address** (`?tab=`, or the page it already had), not in-page state. | Back, reload, a mail link and the phone's pushed screens all work, with no script. |
| AC-2 | **Section and quick-action tiles are neutral, the glyph in its colour.** | The DC's four tinted washes are four colour events on a tier-2 page (ColourBudgetTest). |
| AC-3 | Rail rows are 44px (DC 42). | House target floor. |
| AC-4 | Not drawn: "View public profile" (members have none), "Search your account" (a filter over nine links), the balance chart and range chips, "Ways to earn", the challenge banner inside the overview, Saved beyond conversations. | Either no data behind them yet or a separate piece of work; nothing typed in their place. |
| AC-5 | Quick actions: **Giving → Events**. | `/donate`'s giving page is destroyed and awaiting its phase; a tile to a dead page is worse than none. |

Holiday banner (HOLIDAY-THEMES) sits at the top of this frame and lands in its own commit.
