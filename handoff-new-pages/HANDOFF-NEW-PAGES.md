# Africa GATES · Handoff: Account, Challenges, Challenge banner

Scope: ONLY these screens. Everything else follows `HANDOFF-CLAUDE-CODE.md` (§0 contract, §2 tokens, §3 chrome) and `skills/mobile-native-ux/SKILL.md`.
Stack: PHP 8.4 · Slim 4 · Twig 3 · vanilla JS · nonce CSP · SQLite (dev) / MySQL (prod).

## 0. Contract

1. The `.dc.html` files in `designs/` are the spec. Match order, copy, sizes (±1px), radii, colours and states at **390, 834 and 1440**. Open every Tweaks prop (`layout`, `tab`, `challenge`, `state`, `signedIn`, `placement`) before writing code.
2. Reuse first: `pages/account/dashboard.twig` (the `me_tabs`, `:target` tabs, search, points chart, referral logic, vendor stall, security keys), `partials/viz.twig`, `partials/gee`, the nomination services.
3. Build anything missing end to end: migration (both schema files) → service → route → Twig → CSS → test. No stubs.
4. No inline styles. BEM classes in `public/assets/css/components/`. No new colours beyond §2 and the 4 challenge theme presets.
5. One PR per screen, with screenshots at 390/834/1440 + RTL 390, keyboard and VoiceOver notes, and a deviations list (target 0).

## 1. Files

| Design (`designs/`) | Build as | Route |
|---|---|---|
| `AccountPage.dc.html` | `pages/account/dashboard.twig` (rework) | `/account`, `/account#me-<tab>` (auth) |
| `ChallengePage.dc.html` | `pages/challenges/show.twig` (new) | `/challenges/{slug}` (public) |
| `ChallengeBanner.dc.html` | `partials/promo-carousel.twig` (new) | partial, `placement=` |
| `Celebrate Nigeria Flier.dc.html` | marketing asset (PNG export) | — |
| Shared (already specced): `AppBar`, `SiteHeader`, `MobileMenu`, `Gee`, `DisplayReading` | existing partials | — |

## 2. Account (`/account`)

**Tabs, in this order:** overview · points · referral · challenges · purchases · activity · saved · security · settings, plus `vendor` only for linked stalls.
- Generate the tab list ONCE at template scope (`me_tabs`) and derive the rail, the `:target` reveal rules and the highlight rules from it. The codebase comment explains why; keep it.

**Desktop (≥1024):**
- 250px sticky rail: profile link, a "Search your account" pill (filters every list on the page), tab rows with 28px tinted icon tiles and mono counts.
- Main column: Playfair H1 34px + 15.5px lead + the right-aligned page action (Points → Download CSV).

**Phone (<600):**
- Overview is a hub:
  - Greeting row (44px avatar, "Hi, {first}", Verified badge, search and settings icons).
  - Then the balance card → the 4×2 quick actions → the promo banner → cards → a "Your account" list (56px rows → each tab).
- Every other tab is a **pushed child view**: 56px bar with back + title, a 14.5px lead, then content. There is no chip strip of tabs.
- Tablet (600–1023): 210px rail.

**Overview:**
- **Balance card:**
  - Points 46/40px 800, tabular. "Worth N votes · X more for the next".
  - Eye toggle hides every balance: `aria-pressed`, persisted in `localStorage.ag-hide-bal`.
  - Redeem for votes (green) + Earn more, a divider, then Referral earnings "₦X ready" + Withdraw.
- **Quick actions:** Vote, Nominate, Tickets, Refer & earn, Challenges, Orders, Giving, Help. 46px tiles (radius 15) with a tint, ≥76px targets, an optional red count badge.
- **Promo banner:** §4.
- **Cards:**
  - "Someone you backed won" (existing `me-won`) → challenge progress (10 segments: verified green / checking #7fb3d6 / needs gold / empty outline, plus a text legend) → Recent activity (mixed ledger, signed mono amounts).
  - Then "Finish setting up" (meter + checklist; hidden at 100%) and the next ticket.
  - Desktop: 2 columns (1.3fr / 1fr).

**Subpages (all):** the cards are white, border #e8e5dd, radius 20. Desktop lists are **tables built as grid rows** with a header row (12.5px #626a6e). Phone rows collapse to 2–3 columns, with the secondary data moved under the title.
- **Points:**
  - Balance card: range chips 30 days / 90 days / 1 year, a 150px area chart (`viz.chart`), and 3 mini facts (Earned, Spent, Next vote).
  - Right column: "Turn points into votes" (green wash) + "Ways to earn" (rate per source).
  - History: All / Earned / Spent, grouped by month (sticky month header on phone). Columns: Date 110 | Activity | Source | Points 100 (right-aligned, mono, green +, red −).
- **Referrals:**
  - Left: the link card (readonly mono input + Copy → "Copied ✓" for 2s, `aria-live`; the code; share to WhatsApp, X, SMS) and the "Earnings unlocked" card (status pill "7 of 5 paid", 5-segment bar, Paid / Earned / Withdrawn on ONE row of 3, Withdraw + Payout account, the payout rule).
  - Right: How it works (3 steps), People you referred (initials, tickets, earned).
  - Below the threshold, show the existing honest note instead of Withdraw.
- **Challenges:** auto-fill grid of cards (min 280px): icon tile, state pill, title, line, progress bar.
- **Purchases:** filter (All, Tickets, Orders, Giving). Columns: icon | item + order no. | date | amount | status pill | action (View ticket, Track, Details).
- **Activity:** filter (All, Needs you, Checking, Done). Columns: avatar | who + inline fix note | what | status pill | action (Fix/View).
- **Saved:** auto-fill grid (min 220px), 4:3 image cards on desktop; 64px thumbnail rows on phone.
- **Security / Settings:** 2-column grid of grouped row cards on desktop (58px rows, value + chevron). Destructive items are red text. Sign out everywhere else.

## 3. Challenges

**A challenge is data. One template renders all of them.**

`gates_challenges`:
- Identity and look: `id, slug, title, kicker, art_url NULL, icon NULL, flag BOOL`.
- Theme: `theme` (one of 4 presets).
- Rules: `action nominate|vote|refer|give|attend`, `target INT`, `mode first|top|draw`, `cap INT`, `draw_count INT NULL`, `draw_at NULL`.
- Prize: `prize_type cash_each|cash_pool|points|tickets`, `prize_amount`, `prize_currency`, `prize_label`.
- Lifecycle: `starts_at, ends_at, terms_version, status draft|upcoming|open|full|ended`.
- Ops: `created_by`.

Other tables:
- `gates_challenge_scopes`: `challenge_id, scope_type award_cycle|category|event, scope_id`. An action counts ONLY inside a scope.
- `gates_challenge_entries`: `challenge_id, user_id UNIQUE, joined_at, progress INT, qualified_at NULL, rank NULL, payout_status none|pending|paid|failed, payout_ref`.
- `gates_nominations` gets `challenge_entry_id NULL` and `nominee_identity_hash` (sha256 of E.164 phone + normalised email).

**Copy is generated** by `ChallengeCopy::for($challenge)`. Port `derive()` from `ChallengePage.dc.html` exactly:
- Promise sentence per mode.
- 3 steps per action.
- Rules = base (verified phone, one entry) + per action + the per-mode ordering rule.
- Prize big/unit, meter title, CTA per state.

Admins may append rules; they can never remove the generated base.

**Page layout:**
- Hero band on the theme wash, full width: art (or a 200px icon tile) + kicker + state pill + H1 56/42/34 + promise + a 4-fact strip (Prize · Winners · To qualify · Ends).
- Below it, the main column + a **360px sticky rail** on desktop: prize, meter (only for mode=first), your progress, CTA (`nowrap`), Share, Get the flier.
- Phone: a meter card, then sections, then a fixed bottom bar (prize + CTA 54px); Gee sits 108px up.
- **Sections:** How to take part · What counts (as verified) · Winners (first: ordered by `qualified_at`; top: "Leading now", hourly; draw: an empty state until `draw_at`, then the seeded draw) · Included (scoped awards and events) · Questions · Terms vN · More challenges.
- **CTA per state:**
  - upcoming → Remind me (one SMS);
  - open, signed out → Sign in to join;
  - joined → Add a nominee / Get my link;
  - full → See the awards (outline);
  - ended → See the results.

**Server rules (never trust the client):**
1. A phone-verified account; one entry per user and per phone.
2. Nominations count if they're unique by `nominee_identity_hash` within the entry, not the entrant, complete (reason ≥40 chars), confirmed by the nominee (SMS/WhatsApp, 7-day token, max 3 resends) and moderator-approved.
3. Rank by `qualified_at`. Assign it in a transaction with `SELECT … FOR UPDATE` on the challenge row. When the cap is reached, set status `full`.
4. Rate limits. Cross-entry duplicate nominees are flagged for review. Fraud disqualifies the whole entry, with a logged reason.
5. Payout within 7 days to an account in the entrant's name (name lookup). Store the reference.
6. Public winners show first name + initial, area and time only.

**Placement:** the compact challenge strip on scoped award, event and nominee pages; the banner (§4); "Challenges" under Participate in the mega menu while ≥1 is open; the account Challenges tab.

## 4. Promo banner (`partials/promo-carousel.twig`)

- `gates_promos`: `placement`, `kicker`, `title`, `sub`, `cta`, `href`, `theme`, `art_url`, `challenge_id NULL` (state chip computed live), `priority`, `starts_at/ends_at`, `audience all|signed_in|signed_out`.
- **Placements:** account (under quick actions), **nominate (Nominate hub, above "Closing soon")**, home, vote, events. 0 promos means it isn't rendered. 1 promo means no dots and no auto-advance.
- **Layout:** radius 22, light wash. Desktop has the art on the right (180×156, contain); phone has no art. Min height 188/176. CTA pill in the theme accent.
- **Motion:**
  - Track `translateX`, 600ms `cubic-bezier(.2,.8,.2,1)`.
  - Auto-advance 5s; it pauses on hover, focus-within, the pause button (WCAG 2.2.2) and `prefers-reduced-motion`.
  - Dots: 32px targets; the inner pill is 8px, and 28px when active, with a 5s linear `scaleX` fill that restarts per slide.
  - Decorative layer (`aria-hidden`): a dashed 220px ring rotating 40s at 0.28, a 120px disc drifting 9s at 0.10, a 12px dot drifting 7s at 0.35.
- **A11y:** `aria-roledescription="carousel"`/`"slide"`, `aria-label="n of N: title"`; inactive slides are `aria-hidden` with `tabindex=-1`.

## 5. Admin challenge builder (notes only; build in the existing admin, no designs)

Challenges → New: a 5-step form with a live preview of the challenge page.
1. **Basics:** title, kicker, slug, art or icon, theme preset, terms version.
2. **What counts:** action, target, scopes (multi-select awards → editions → categories, and events), unique-person rule.
3. **Who wins:** mode, cap or draw count + date, prize type/amount/currency.
4. **Schedule & audience:** dates (zone shown), eligibility, 1 entry per person.
5. **Promote:** auto-create promos (nominate + account + scoped pages checked), an optional SMS/email announce, a flier export.

Publish validation: ≥1 scope, cap > 0 unless mode=draw, ends > starts, a prize amount, terms published. After publish, only copy, art, an end-date extension and placements can be edited; the rules are locked.
Queues: verification, entries (disqualify with a reason), payouts (mark paid + reference), draw (seeded, published), CSV export.

## 6. Done means

- [ ] All props and states match the DCs at 390/834/1440 and RTL.
- [ ] Every tab is deep-linkable, the back button works and the highlight is correct with JS off.
- [ ] Hide balances persists.
- [ ] The carousel passes 2.2.2; dots ≥32px.
- [ ] Challenge copy comes only from `ChallengeCopy`; the 3 demo configs (nigeria, teachers, gala) render correctly.
- [ ] The rank race condition has a test (11 concurrent qualifiers → ranks 1–11, then status `full`).
- [ ] The payout name-match has a test.
