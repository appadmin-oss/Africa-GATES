# Redesign Phase 3 — Gee and celebrations

**`design_handoff_africa_gates/phases/PHASE-3-gee-celebrations.md`.** Two agents built this phase side by side on
3 Oct 2026: the Gee section (guide + help desk, §7.7/§8.22) and the Celebrations section (§7.8/§9.3). Each owns its
own section below. Paths are relative to the repo root. Nothing is committed.

---

# Celebrations — §7.8, §9.3 (3 Oct 2026)

`design/Celebration.dc.html` (kind × size × layout), `design/assets/celebration/*` (**ship verbatim**), SKILL §24,
`screenshots/phase-3/celebration-*.png`. Screenshots are under `docs/handoff/shots/phase-3/celebration/`.

No page celebrates yet, by design: every page that did was destroyed on 3 Oct 2026, and their rebuilds belong to
Phases 5, 7 and 8. This phase ships the engine, the partial, the boot, the decision those pages must ask, the guards,
and a dev showcase that draws every combination through the real partial.

## 1. Files

### Created

| File | What |
|---|---|
| `public/assets/js/celebration.js` | The handoff's engine, **byte-identical** (sha256 `903d690b6c85f0a8bb9c3837f1a58356035f767084a026db5f0251c7a145f3c8`). |
| `public/assets/css/components/celebration.css` | The engine's sheet, **byte-identical** (sha256 `1e00d2fb90ed58234e9812d8e10384af6016c4cd632619d3ec1e4b016eada06a`). |
| `templates/partials/celebration.twig` | The DC's card. Args `kind, size, layout, kicker, title, sub, stats[], primary, secondary, seen_key` (§7.8) plus `first` (the win headline), `ticket` (the stub's `{label, when}`, the DC's `ticketLabel`/`ticketWhen`) and `context` (what the decision is asked). **Draws nothing unless `celebration_allowed(kind, context)`.** Empty stage `<div class="ag-cel__stage" data-agc-kind data-agc-size data-agc-seen-key>`; stats at their final value server-side (`<b data-agc-count="18402">18,402</b>`, Twig's `number_format`, the house formatter — 306 uses); the §7.8 headline and button table; every string `|trans`; scripts `defer` + nonce. |
| `public/assets/css/components/celebration-card.css` | Every DC inline style as a rule, colour from Accent token names, type from the ladder in rem, `.ag-rm` (the site's own Reduce motion) mirrored for the stage. Linked by the partial, so only a page that celebrates pays for it. |
| `public/assets/js/celebration-boot.js` | §7.8's boot (nonced, deferred, idempotent) plus: the seen key handed over only with Preferences (`data-ag-keep`), haptics only after a tap and never under `html.ag-rm`, no count-up/replay under `ag-rm`, the ticket stub's two words HTML-escaped and never empty (so the engine's demo defaults cannot render), "Celebrate again" removed when there is nothing to replay. |
| `src/Services/Celebration.php` | `allowed()`/`refusal()` — the decision; `seenKey()` — the one play-once resolver (`{kind}-{edition_slug}-{subject_slug}[-{user_id}]`); `style()` — the kind's palette family as three inline custom properties (the `Accent::tileStyle()` pattern). |
| `templates/pages/dev-celebration.twig`, `public/assets/css/dev-celebration.css` | `/_dev/celebration` — dev-only (404 in production), one combination per load (`?kind=&size=&layout=`), an index of all twenty without. `win` asks the real decision against the newest released award in the database; it is never a specimen. |
| `tests/Support/VerbatimAssets.php` | The two verbatim files, by path **and** sha256 — the exemption is lost the moment either is edited. |

### Changed

| File | Change |
|---|---|
| `config/container.php`, `tests/Support/AppTwig.php` | Twig functions `celebration_allowed`, `celebration_seen_key`, `celebration_style`. |
| `src/routes.php` | `GET /_dev/celebration` beside `/_dev/ui`, gated the same way. |
| `src/Support/CookieRegistry.php` | Storage row `ag-cel-` (Preferences, local). The stale `ag-celebrated:` row had already gone with the cookie rebuild. |
| `public/assets/css/tokens.css` | Display steps `--ag-fs-20/22/24/34/48` (celebration title 34 → 48 is §6.2's own row; 22 the inline title; 20 → 24 a stat). |
| `tests/Unit/CelebrationTest.php` | **Destroyed and rebuilt**: 22 new tests on the partial, the boot, the engine and the decision; the 4 `backedWinners()` tests kept as they were. |
| `tests/Unit/ColourLiteralTest.php` | Skips a file only while `VerbatimAssets::is()` — the verbatim sheet, by name and hash. |
| `tests/Unit/ColourBudgetTest.php` | A celebration is charged its KIND's family, read from the include's literal `kind:` (its colours arrive inline, so the field sweep would never have charged it); planted-text test added. |

**Exemptions, exactly.** Only `ColourLiteralTest` is exempted from anything, because the verbatim sheet is the only
thing a guard fails (two literals: `#fff`, `rgba(255,255,255,.8)`). `TypeScaleTest`, `MonoAndCaseTest` and
`DeadTokenTest` find nothing in `celebration.css` and read no JavaScript, so they were **not** given an exemption —
one that excuses nothing is a hole waiting to be used. What they cannot see in the script is listed in §5.

## 2. Who wires each moment

| Moment (`context.moment`) | Kind | Page | Phase | Extra context the decision needs |
|---|---|---|---|---|
| `award_won` | win | the nominee page / award result | 5 | `category_id`, `nominee_id` — refused when delayed, unreleased, held (no quorum or community dark), sandbox, or not the winner |
| `edition_won` | win | the overall edition result | 5 | `edition` (slug), `nominee_id` — also refused while the overall is provisional or a dead heat |
| `vote_cast` | vote | vote done | 5 | `confirmed: true` |
| `nomination_sent` | nominate | `/nominate/success` | 8 | — |
| `gift_confirmed` | give | `/giving/success` | 7 | `confirmed: true` |
| `ticket_confirmed` | ticket | the ticket page after checkout | 7 | `confirmed: true`, and `ticket: {label, when}` and `primary.href` (the .ics) |
| `draft_saved`, `settings_saved`, `signed_in`, `added_to_cart`, `subscribed` | — | never | — | refused by name for every kind |

Each page also passes `context.edition` and `context.subject` (slugs) for the seen key, or a `seen_key` from
`celebration_seen_key()` — `CelebrationTest` fails on any other spelling and on any template but the partial that
mounts a stage.

## 3. Every prop combination → screenshot

Layout follows the viewport (≥1024 desktop, the boot's own query), so the phone layout is the 390/834 shots and the
desktop layout the 1024/1440 shots; `layout=phone|desktop` forces one (used for the DC comparison). Shots at 2.3s
(after the count-up).

| Kind · size | 390 · 834 (phone) | 1024 · 1440 (desktop) | RTL 390 | Burst mid-flight |
|---|---|---|---|---|
| win · full | `win-full-390-phone.png`, `win-full-834-phone.png` | `win-full-1024-desktop.png`, `win-full-1440-desktop.png` | `rtl-win-full-390.png` | `burst-win-full-{390,1440}-450ms.png` |
| win · inline | `win-inline-{390,834}-phone.png` | `win-inline-{1024,1440}-desktop.png` | `rtl-win-inline-390.png` | — |
| vote · full / inline | `vote-{full,inline}-{390,834}-phone.png` | `vote-{full,inline}-{1024,1440}-desktop.png` | `rtl-vote-{full,inline}-390.png` | `burst-vote-full-*` |
| nominate · full / inline | `nominate-…` (same pattern) | `nominate-…` | `rtl-nominate-…` | `burst-nominate-full-*` |
| give · full / inline | `give-…` | `give-…` | `rtl-give-…` | `burst-give-full-*` |
| ticket · full / inline | `ticket-…` | `ticket-…` | `rtl-ticket-…` | `burst-ticket-full-*` |

**Against the DC** (REFERENCE §17), the DC rendered from variants of `Celebration.dc.html` with only the prop defaults
changed, at the same card width (phone 390, desktop 1040): `compare-dc-<kind>-<size>-<layout>.png`,
`compare-build-…`, `overlay-…` (DC at 50% over the build) for all twenty, under reduced motion so the card is compared
rather than where a spark happens to be; and under normal motion at 6s, `motion-dc-…-6s.png` / `motion-build-…-6s.png`
for the ten full cards. **Measured card sizes are identical to the sub-pixel in all twenty** under normal motion
(e.g. win phone 390 × 749.11 both, win desktop 1040 × 444.52 both); the inline cards are identical under reduced motion
too. The reduced-motion full overlays sit 27–29px apart vertically for one reason only: deviation 2. The handoff's
own screenshots are copied in as `ref-celebration-*.png` (they were captured at t≈0, so their figures read 0 and
"0st"; ours are after the count-up).

**Play once** (`once-*.png`, `report-once.json`): with Preferences allowed, view 1 has 72 burst pieces and the
first figure counting ("0" at 350ms), and stores `ag-cel-win-creative-2025-achieng-otieno`; view 2 has **0** burst
pieces and "18,402" at 350ms — straight to the ambient loop. With Preferences refused, nothing is stored and both views
burst (see Q-C1).

**Reduced motion** (`reduced-{os,site}-<kind>-full-390-120ms.png`, `report-rm.json`), for the OS setting and for the
site's own Reduce motion (`html.ag-rm`): at 120ms, for every kind — ambient and burst layers not displayed, every figure
already final, the title fully opaque, "Celebrate again" removed.

**Node budget** (`report-budget.json`; ambient only, 8s sampled every 250ms after the burst layer was emptied at
5.6s; "visible" = effective opacity > .05 and inside the stage):

| | DOM (≤40) | Max visible (≤12) |
|---|---|---|
| win full (phone, desktop) | 39, 39 | **26, 26** |
| nominate full (phone, desktop) | 15, 17 | 11, **16** |
| vote / give / ticket full | 8 / 7 / 5 | 7 / 7 / 4 |
| every inline | 1–7 | 1–6 |
| burst layer after cleanup | 0 everywhere | |

The DOM counts are exactly §9.3's ("win 39, nominate 15–17, the others ≤8"). **The "≤12 visible at once" half is not
met by the verbatim file** for win (26) and desktop nominate (16): win keeps seven twinkling sparks, the beam, the
spinning rays and twelve-spark loops on screen together. §9.3 says the file passes and not to optimise it, so it is
reported, not changed — Q-C3.

**Keyboard and screen reader.** Tab order inside a full card: primary (share button, or the calendar link) →
secondary link → "Celebrate again"; inline: primary → secondary. The stage holds only `aria-hidden` pieces and
takes no focus; the title is an `<h2>`; one polite `role="status"` region reads "Title. Sub" (one full stop — the DC
concatenates "Achieng won.."). VoiceOver/TalkBack are not available in this container.

## 4. Deviations (target 0) — each awaiting the owner

| # | What | Why |
|---|---|---|
| 1 | The card's tint per kind is the family's wash token: win `--ag-gold-wash` (DC `#fff4d6`), nominate `--ag-green-wash` (DC `#f1f8ec`), give `--ag-live-wash` (DC `#fdf0f3`), ticket `--ag-info-wash` (DC `#edf4f9`). Vote's `#effaf0` is the token exactly. | The four DC hexes are not in §6.1, and "no new colours" (§4.6) and "colour from Accent only" (CLAUDE.md) forbid typing them. Q-C2. |
| 2 | "Celebrate again" is removed under reduced motion. | The DC draws it always and its handler does nothing when reduced — a dead control. |
| 3 | A stat that is not a number (`1st`) is printed as it is, not counted. | The DC counts it up through "0st" (visible in the handoff's own screenshot); a number is counted, an ordinal is a word. |
| 4 | Primary is a share button (the chrome's `data-ag-share`: system sheet, else copy, with an announced confirmation) unless an `href` is given; "Add to calendar" is always a link and is not drawn without one; a secondary is not drawn without an `href`. | The DC's buttons call demo handlers. Reuses the one share behaviour the site has rather than a second. |
| 5 | The live region reads "Achieng won. Musician…" | The DC's `title + '. ' + sub` reads "won.." |
| 6 | No haptics until the person has tapped the page; none under `html.ag-rm`. | Chromium refuses `vibrate()` before a user gesture and logs it — every celebration reached by redirect logged one. |

## 5. The verbatim files against the house rules — for the owner (Q7)

Shipped exactly; nothing below was edited.

- **Colour.** `celebration.js`: 32 lines of hex, 22 distinct values, and six `rgba()`. Off §6.1: `#fbd46a` (6×),
  `#f4789c` (4×), `#7fb6d9` (3×), `#d9c7a3` (3×), `#e8a800`, `#fff3c4`, `#c9dbe8`, `#d8e6f0` (the last is §6.5's
  ticket shadow, named there and nowhere in §6.1); `rgba(251,212,106,…)` ×2 (the off-palette `#fbd46a`); `#000` ×6 are
  mask stops, not colour. On-palette but typed: `#f3b416` gold, `#237b22`, `#7fc87c`, `#e0245e`, `#1f6fa3`, `#effaf0`,
  `#7a5600`, `#10292c`, `#fff8df`, `#fdecef`, `#e8f1f7`, `#626a6e`, `#fff`, `rgba(243,180,22,…)` ×2,
  `rgba(31,111,163,.55)`, `rgba(255,255,255,.88)`. `celebration.css`: `#fff` (the badge ring) and
  `rgba(255,255,255,.8)` (the glint). The engine takes **no colour option and reads no custom property**, so nothing
  can feed it Accent values from outside.
- **Type.** In the ticket stub (`celebration.js:148-152`): "Admit one" `11px` (not a rung — the ladder has no 11),
  the date `11px`, the stamp `12px`, the event name `px(tw/11)` (18.18 / 21.82px); all px, so the Display & reading
  text size does not reach them. The stub sets `font-family:'Playfair Display',Georgia,serif` by literal.
- **Case.** "CONFIRMED" is typed capitals with `letter-spacing:.08em` — the one capital §18.4 allows (the stamp).
- **Words.** "Admit one" is English inside the script and cannot pass through `|trans`. The demo fallbacks
  `'KCEA Ceremony'` / `'Sat 6 Dec · 18:00'` are neutralised by the boot (deviation-free: a real page always passes its
  own, and an empty one becomes a no-break space).
- **CSP.** The engine writes `style="…"` attributes through `innerHTML` (the ticket stub, the star orbit, the hearts),
  so the public policy's `style-src-attr 'unsafe-inline'` is load-bearing for it; `CelebrationTest` now fails if that
  is tightened without the engine changing.
- **Storage.** It writes `localStorage['ag-cel-'+key]` unconditionally (Q-C1).
- **Budget.** ≤12 visible is exceeded by win and desktop nominate (§3 above; Q-C3).
- `--ag-gold-wash` now has a reader (win's tint, through `Celebration::style()`), but a dynamic one that
  `DeadTokenTest` cannot see, so its `AWAITING_REBUILD` line is left as it was.

## 6. Guards — each watched failing first

`p3/mut.py` (scratch): 21 mutations, 21 caught. The first run caught 20: removing the "held" refusal was missed,
because a result with no quorum has no winner and the next line refused it anyway — so a test for the OTHER held
reason (every panel finished, no organic vote counted: a winner exists and the page withholds it) was added, and caught it.

| Mutation | Caught by |
|---|---|
| delayed check removed · NEVER list ignored · winner not compared · confirmation ignored · held not refused · provisional edition celebrated | `CelebrationTest` (by reason: `R_DELAYED`, `R_NEVER`, `R_NOT_THE_WINNER`, `R_UNCONFIRMED`, `R_HELD`) |
| partial skips the decision · an ordinal counted · the boot ignores Preferences · stops escaping the stub · writes the page · `.ag-rm` hiding dropped · scripts not deferred · showcase reachable in production | `CelebrationTest` |
| the engine edited by one byte | `CelebrationTest` (bytes, exemption) + `ColourLiteralTest` |
| the exemption made name-only · the verbatim skip removed | `CelebrationTest` / `ColourLiteralTest` |
| the celebration charge removed from the budget · three kinds in one tier-2 page | `ColourBudgetTest` |
| the storage row removed · declared Essential | `CookieRegistryTest` (both directions) + `CelebrationTest` |

## 7. Blocked questions — not guessed

- **Q7 (open, unchanged).** Accept `celebration.js`/`.css` as verbatim with everything in §5, or amend them?
- **Q-C1. Play once vs consent.** The engine stores unconditionally, so it is given a key only with Preferences; with
  Preferences refused (or a GPC/DNT signal) the burst plays on every view. Accept, or amend the engine to take a
  storage (e.g. `sessionStorage` for the tab, as `ag-a11y` does)? That is an edit to a verbatim file (Q7).
- **Q-C2. The four DC tints** (deviation 1): the family washes, or add the DC's four hexes to the palette?
- **Q-C3. ≤12 visible** is exceeded by win (26) and desktop nominate (16) as shipped; §9.3 says not to optimise.
  Accept the measurement, or amend?
- Deviations 2–6: approve or reject each.
- Translations: the partial's strings go through `|trans`, no catalogue entries were written (who writes them is
  §8c item 9).

## 8. Runs

No `.env`; `var/data` lock files cleared. PHP 8.3 container.

- Filter `Celebrat|Cookie|Colour|TypeScale|MonoAndCase|DeadToken|Translator|Csp|Shell|DevUi|AssetBundle|Twig|NestedForm|TemplateContext|PublicIa|RouteTable|SecurityHeaders|Accent|SlotFloor`:
  **OK, 361 tests**.
- Full suite: **6,633 tests, 9 failures** — `PasskeyTest` ×8 (environmental: PHP 8.3) and
  `FormErrorStateTest::test_novalidate_and_the_validator_are_never_separated` on `partials/gee.twig` (the Gee half,
  in flight). None in a celebration file.
- The count adds up: test methods 6,476 at `HEAD` → 6,526 = `CelebrationTest` 4 → 26 (+22),
  `ColourBudgetTest` 10 → 11 (+1), `GeeTest` 0 → 27 (+27, the Gee half).

---

# Gee — §7.7 guide, §8.22 help desk (3 Oct 2026)

`design/Gee.dc.html` (mode guide/support × signedIn × phone/desktop × open/closed, and its Check now → work card →
result → No → handoff → Pass flow), `screenshots/phase-3/gee-*.png`, `snippets/js/bottom-ui.js`,
`snippets/php/redirects.php`, SKILL §9/§16. Screenshots, video and measurements are under
`docs/handoff/shots/phase-3/gee/`. Nothing is committed.

**Not staging.** "A real stuck payment fixed in staging, with a recording" cannot be done here. The flow was driven
end to end against the dev server (`php -S`, a scratch SQLite database) with a **seeded pending paid-vote order** and
a **stubbed gateway** (`verify()` answers success for the order's own amount after a 1.5 s pause, set into the
container in the scratch front controller only). Everything after the stub is the real code: the support chat
endpoint, `SupportContext::fix_payment`, `PaymentReconciler::reclaim()`'s conditional UPDATE, `PaidVoteService::mint()`
(10 votes landed on the order), the receipt, `SupportWork`, the escalation and the ticket. Recorded as screenshots
(`flow-*`) and video (`video/flow-390-member.webm`, `flow-1440-member.webm`, `flow-390-guest.webm`).

## G1. Files

**Destroyed and written again** (inventory first: `docs/handoff/inventory/_scripts.md`, "Phase 3 destroy — Gee";
`gee.css` and the `gates.twig` mount were already destroyed and inventoried):

| File | Now |
|---|---|
| `public/assets/js/gee.js` | One classic deferred script, `window.AGGee = {open({mode, q}), close()}`. Guide mode → `/api/guide`; support mode keeps the retired page's `supportDesk()` store without Alpine (transcript per mode in `sessionStorage` `ag-gee-chat:{mode}`, the remembered reference and its × "forget", `?q=`/`?ref=`/`?topic=`/`?ask=` read only beside `gee=support` then taken off the URL, "ask, do not file"). Opens through `AGChrome.openSheet` → `AGShell.openSheet` (one history entry, focus trap, Esc, focus back). Every word arrives as a `|trans`-rendered `data-msg-*`. Keeps the old widget's escape-first linkifier (`ROUTE_RE`/`HELP_RE`, read by `GeeSupportsTest`). |

**Created**

| File | Why |
|---|---|
| `templates/partials/gee.twig` | The launcher (hidden until the script runs) and the panel in §7.7's order; both empty states (guide: privacy note, greeting, 4 starters; support: greeting, From your account / the reference card, 2×2 quick fixes, privacy note); the pill composer with the attach button; both footers. |
| `public/assets/css/components/gee.css` | Every DC value, Accent tokens only, `--ag-fs-*` in rem, logical properties, 44px targets, reduced motion. Clearance is the formula and nothing else. |
| `src/Services/SupportWork.php` | The work card from what the repair RECORDED (`found`, `asked`, votes minted): steps `done`/`failed`, a step that did not run not drawn, "Fixed" only when the order is put right; the result card's facts, the receipt address only when it is the signed-in member's own, no sandbox nominee named. |
| `src/Services/SupportDesk.php` | "From your account": the member's most recent unresolved (pending, not refunded, not expired, not sandbox) order, the newest open support ticket and whether a PERSON spoke last; the tickets dot. |
| `tests/Unit/GeeTest.php` | 27 tests (§G6). |

**Changed**

| File | Change |
|---|---|
| `templates/layout/shell.twig` | `_gee_off` (template scope): `hide_chrome`, `gates_page` ∈ {pulse, support}, `tab == 'pulse'`. Otherwise the `gee` block includes the partial, links `gee.css`, loads `gee.js`. |
| `src/Controllers/SupportController.php` | `page()` deleted (its template was destroyed; the route is a 301). New `desk()` — `GET /api/support/desk` (identity from the session: `can_see_payments`, `ai_on`, `sla_hours` from `NominationFeedbackService::slaHours()`, the member's own email, payment, ticket, replied). `chat()`: `check=<ref>` runs exactly `fix_payment` (shape-checked, same context and allowance) and every turn carries `work` (SupportWork). `escalate()`: screenshots (images only, ≤5 MB, decided on the bytes) and the address it will reply to. Takes the container's `PaymentService`. |
| `src/routes.php` | `/support/assistant` → **301** `/help?gee=support`, keeping `q` (and `ref`/`topic`/`ask` — §G4 deviation 21); `GET /api/support/desk`; `/_dev/ui?sticky=1` (dev-only mock sticky bar). |
| `src/Services/SupportContext.php` | `withPayments()` (a copy with a given gateway client — the factory's pinned signature is untouched), `ownsEmail()`, `fix_payment` returns `reference`/`found`/`asked`, and our reference in the wrong case finds its order (MySQL's collation forgave it; SQLite did not). |
| `src/Services/PaymentReconciler.php` | `reclaim()` records `found` and the providers actually `asked`; a **vote** order whose money the gateway confirms but whose mint is refused is now `MINT_REFUSED` (ok:false) like the branch above it, not "Your payment is now confirmed" — the work card found it drawing "Fixed" over votes that were not on the tally. |
| `src/Services/PaymentService.php` | `label()` — the providers' one table of names, static. |
| `src/Services/SupportAttachmentService.php` | A screenshot profile (`SCREENSHOT_TYPES`, `SCREENSHOT_MAX_BYTES`): the same `store()`, a narrower argument. |
| `public/assets/js/shell.js` | `trackBottomUI()`: the figure is how far up the screen the bottom UI REACHES (bars stack — the cookie notice sits above the tab bar), and a bar mounted or removed later is seen (childList); always runs. |
| `templates/partials/cookie-consent.twig` | The notice and the "saved" line carry `data-bottom-ui`. |
| `src/Support/CookieRegistry.php` | `ag-gee-chat:` (Essential, session) and `ag-gee-privacy` (Preferences, local-or-session). **Legal-page text — for the owner.** |
| `public/assets/css/tokens.css` | `--ag-fs-25` (the greeting, Playfair 25) and `--ag-z-gee:60`, each with its reader. |
| `src/Support/AssetBundle.php`, `tests/Unit/ShellLayoutTest.php` | `components/gee.css` last in the cascade. |
| `templates/pages/dev-ui.twig`, `public/assets/css/dev-ui.css` | The mock bottom action bar (skill §8), which removes and remounts itself. |
| `config/container.php` | `SupportController` gets `PaymentService`. |
| `tests/Unit/DeadTokenTest.php` | `--ag-green-edge`, `--ag-info-wash`, `--ag-green-light` left `AWAITING_REBUILD` — they have readers. |
| `tests/Unit/SupportTicketNamingTest.php` | The dead `SCRIPT_COPY` constant (no reader) removed; Gee's copy is held by `GeeTest`. |
| Docs | `GAPS.md` §3.10, the table's row 10, Q19, §8c item 12; `inventory/_scripts.md`. |

## G2. The live work card — what is real

`supportDesk()`'s chat is one request and one response, and a repair runs to the end inside it. So the card is drawn
at exactly two moments: **while the request is out**, the first step active and the others pending (true — the
server starts by reading the order, nothing has come back); **when it answers**, exactly the steps the server says
RAN, from `PaymentReconciler::reclaim()`'s own record (`found`, the providers `asked`, votes minted) through
`Services\SupportWork`: `done`, or `failed` (a red mark and the words "did not complete"); a step that did not run —
no gateway asked about an already-confirmed order — **is not drawn**. No timers anywhere in the client (`GeeTest`
holds it; the DC's 700/1500/2300 ms are a demo). "Asking {provider}" names the provider recorded on the order while
in flight and the providers actually asked once it answers. Check now / Check / "Check a payment" post `check=<ref>`
and run exactly the repair; a free-text turn that the agent answers with `fix_payment` gets the same card, drawn
already answered.

## G3. Every DC prop combination → screenshot (`docs/handoff/shots/phase-3/gee/`)

| DC prop | 390 | 834 · 1024 · 1440 | DC (same size) |
|---|---|---|---|
| guide · signed in · closed / open | `guide-signedin-390-{closed,open}.png` | `guide-signedin-{834,1024,1440}-{closed,open}.png` | `dc-guide-signedin-{phone,desktop}-{closed,open}.png` |
| guide · signed out | `guide-signedout-390-*.png` | `guide-signedout-{w}-*.png` | `dc-guide-signedout-*` |
| support · signed in (From your account) | `support-signedin-390-*.png` | `support-signedin-{w}-*.png` | `dc-support-signedin-*` |
| support · signed out (reference card) | `support-signedout-390-*.png` | `support-signedout-{w}-*.png` | `dc-support-signedout-*` |
| Check now → working → fixed → No → handoff → Pass → ticket | `flow-390-{1-desk,2-working,3-fixed,4-handoff,5-ticket,6-talk-to-a-person}.png` | `flow-1440-*.png` | `dc-flow-{working,fixed,handoff,ticket}.png` |
| Guest: reference typed in lower case → upper-cased → repaired → Yes | `flow-390-guest-{1..4}-*.png` | — | — |
| Guide answer + help-link chips; Talk to a person | — | `guide-1440-answer.png`, `guide-1440-talk-to-a-person.png` | — |
| Privacy note dismissed (and still dismissed after a reload, Preferences refused → `sessionStorage`) | `guide-390-note-dismissed.png`, `notes.txt` | — | — |
| RTL (Arabic) | `rtl-390-{closed,guide-open,support-open}.png` | — | — |
| Reduced motion (the active step) | `motion-{normal,reduced}-working-{a,b}.png`; computed ring in `notes.txt` (normal `geeSpin 0.9s`, reduced `none`, ink half ring both) | — | — |
| Overlay (DC at 50% over the build) | `overlay-{guide,support}-390.png` | `overlay-{guide,support}-1440.png` | — |
| Video | `video/flow-390-member.webm`, `video/flow-390-guest.webm` | `video/flow-1440-member.webm` | — |

**Clearance, measured** (`clearance.json`, `clearance-{w}-{plain,cookie-notice,sticky-bar,…}.png`): at 390, 834, 1024
and 1440, the gap between the launcher and the top of the highest bottom-fixed element is **16px in every case**:
above the tab bar (390: bar 81px → launcher 97px up), above the cookie notice (390: notice + tab bar → 333px up;
≥600: 226px up), above the mock sticky bar (93px → 109px up), and with no bar 16px. Removing the sticky bar drops the
launcher to 16px and remounting it lifts it back. The open panel clears the same elements by 16px. (The spec's 96 and
108 are "the bar + 16"; this tab bar is 81px and the mock bar 93px, so 97 and 109.)

**Overlays.** The panels line up in position and width; vertical offsets inside come from: the header buttons at
44px (DC 40), the support status line (no AI key on the dev server, so the spec's longer `ai_on` fallback sentence
wraps to three lines), the privacy note's place (§7.7 order) and real data (references are 22 characters, the DC's 15).

**Keyboard** (`notes.txt`): Enter on the launcher opens the dialog with focus in the composer (where a keyboard is
attached); Tab ×30 never leaves the panel (order: composer → footer Privacy → Talk to a person → New conversation →
Close → note Privacy → Dismiss → the four starters → composer); Esc closes, **focus returns to the launcher**,
`aria-expanded=false`; the browser Back closes it and stays on the page. **Screen reader** (by role and name; no
VoiceOver/TalkBack here): the launcher is a button with `aria-haspopup="dialog"`/`aria-controls`/`aria-expanded`;
the panel a modal `dialog` named "Gee"/"Gee · Help desk"; the transcript a polite `log`; the work card a `status`; a
failed step says "did not complete" in words; the tickets link says "a person has replied" in words beside the dot;
the privacy note a `note`.

## G4. Deviations — what, why, approved by (target 0)

| # | What | Why | Approved by |
|---|---|---|---|
| 1 | Guide: privacy note second, under the header (DC: after the starters) | §7.7's panel order | REFERENCE §0 |
| 2 | Privacy sentence: "What you type here stays in this tab until you close it; passed to a person, it is kept with your support ticket. Never share card numbers or passwords." (DC: "Chats are kept for 30 days, then deleted.") | No 30-day mechanism exists; the sentence is the registry's own `where` (GeeTest) | **Needs approval** (§G5.2) |
| 3 | 44px targets: header buttons (DC 40), Check now (40), Yes/No (40), help-link chips (34), result pills (38), composer buttons (36, the disc stays 36), note dismiss (28, the × stays) | §6.7/§13 | REFERENCE §0 |
| 4 | Composer input 16px (DC 15) | §6.2/§9.5 inputs ≥16 | REFERENCE §0 |
| 5 | `#f4faf4` → `--ag-green-wash`; `#f6f7f6` → `--ag-bar`; pending text `#8b9295` → `--ag-soft` | not in the palette; `mute` is never a word | Owner Q1/Q2 — **confirm the two mappings** |
| 6 | The active ring is line-2 with an ink half; the DC turns a uniform ring (no visible motion) | §8.22's "static half ring" under reduced motion | REFERENCE §0 |
| 7 | A `failed` step state (error mark + words); steps that did not run are not drawn (DC always draws three) | the steps must be the real ones (§8.22) | **Needs approval** |
| 8 | "Support ticket AGS-… · subject", "Support ticket AGS-… opened" (DC "Ticket #4821") | the repo's naming rule (Gee floats over event pages); references, not row ids | **Needs approval** |
| 9 | Guide sub-line "…, event tickets or a payment" (DC "tickets") | same rule | **Needs approval** |
| 10 | Guest handoff card has an email field | a ticket nobody can answer is a promise to reply to nothing; the server already took `email` | **Needs approval** |
| 11 | A reference chip with × (forget) and a screenshot chip above the composer | the retired desk's "forget"; an attachment needs a visible state | **Needs approval** |
| 12 | Typing dots while a reply is coming | §9.4 asks a visible loading state; the DC draws none | **Needs approval** |
| 13 | Guide footer has "Privacy" | §7.7's footer text (the DC omits it) | REFERENCE §0 |
| 14 | 600–1023px uses the desktop panel and pill (DC: phone/desktop only; snippet's 24px at ≥1024) | the chrome's own breakpoint is 600 | **Needs approval** |
| 15 | Desktop panel 580 high (DC 600) | §7.7 | REFERENCE §0 |
| 16 | On a touch screen the composer is not focused on open | the on-screen keyboard would cover the panel before anybody chose to type | **Needs approval** |
| 17 | Dropped from the old widget: tool-label row, Copy/Ask again, resize/drag, attention pulse, page-aware greetings, the second set of chips | not in the DC (inventory lists each) | **Needs approval** |
| 18 | `/support/assistant` also keeps `ref`, `topic`, `ask` | the repair button that sends a reference and asks for the repair to RUN (the retired desk's rule) | **Needs approval** |
| 19 | Help-link chips are the server's Help Centre answers (≤3, `/help/{slug}` only) | real data in the DC's slot | — |
| 20 | Dots drawn at the DC's rendered size (content-box: 14, 15, 12) under the repo's border-box | the DC's pixels | REFERENCE §5 |

## G5. Blocked — not guessed

1. **Q19.** Applied from §7.7 (every shell page but Pulse, still off the support pages and chromeless pages) —
   recorded as applied, not owner-answered. **Confirm.**
2. **Retention.** Build a 30-day server-side retention for Gee conversations (so the DC's sentence becomes true), or
   accept the derived sentence? Also for the owner: `gates_ai_calls` keeps 300-character excerpts of model replies
   (not what people type — that is hashed) and has no pruner.
3. **The reply promise.** The handoff card reads the one SLA the platform has, `review_sla_hours` (48h by default,
   the review/complaint acknowledgement window, `NominationFeedbackService::slaHours()`), as directed. The DC says
   "within 2 hours", and two server strings still type "usually within a working day" (`SupportController::escalate`,
   `SupportAgentService::finish`). One support SLA setting, or this one?
4. **"A person sees every unresolved message"** — the spec's footer; a chat turn reaches a person only when it is
   escalated (by the agent's rule or by "Pass this to a person"). Keep the sentence?
5. **"No bar: 40px"** — the spec's own formula gives 16px + the safe area with no bar (16px on desktop). Built to the
   formula.
6. **`/help` 500s** (`pages/help.twig` destroyed; Phase 9). The 301 lands there until then; `?gee=support` opens the
   desk on any page, so it will work the day `/help` does. A temporary target was not guessed.
7. **Translations** — every string is `|trans`; no catalogue entries written (§8c item 9).
8. Not possible here: staging and a real payment, VoiceOver/TalkBack, Lighthouse, a 2 GB Android. PHP 8.3 container.

## G6. Guards — each watched failing first

`GeeTest` (27): mount and the Pulse/support/chromeless suppressions; the clearance formula and no typed offset, no
`vh`; the tracker's reach, mounts and the notice's `data-bottom-ui`; the privacy dismissal kept only with Preferences;
the note's sentence agreeing with the storage; every `data-msg` read and every `M()` handed over; no bare "ticket";
no capitals, mono 16 reference upper-cased by value; reduced motion; the work card (all three steps, a step not
drawn, failed steps, no card when nothing ran, the receipt address, upper-cased references, the sandbox name, no fake
timers); the desk (own rows, refunded/expired/sandbox excluded, a person vs the assistant, guest endpoint, the check
button's shape rule); screenshots by bytes; ask before filing; the 301 and every kept parameter read and bounded;
`AGGee`.

**38 mutations, 38 caught** (`scratchpad/gee/mut.py`, run with `-d upload_max_filesize=10M` so the 5 MB branch is
distinguishable from this container's 2 MB server ceiling): mount removed · Pulse allowed · a typed 96px · the panel
in vh · the tracker back to height · blind to mounts · the notice not bottom UI · the dismissal always local · the
transcript on the device · the note saying 30 days · a word missing · a word unread · a bare ticket · capitals · the
reference not upper-cased · motion kept · the provider step always drawn · confirmed-without-votes (reconciler) · a
refused mint drawn as nothing · the receipt address leaked · a case-sensitive reference · a sandbox name printed ·
fake timers · the desk showing sandbox / expired orders · the assistant counted as a person · a typed SLA · an email
to a guest · any text to the check · any file type / 8 MB as a screenshot · an empty ticket filed · the redirect
dropping `q` / as a 302 · `q` read off any page / uncapped · `ask` only drafting · no `AGGee`. Three of the first
versions passed their mutation and were fixed, not excused: the sandbox test used a vote order whose mint the
sandbox refused (so no result card existed to carry a name), the 5 MB test could not see past a 2 MB server, and a
second "fixed without votes" guard in `SupportWork` was unreachable once the reconciler answered honestly — deleted.
The word sweep caught a real fault on its first run (`attach-remove`: handed over, never read).

## G7. Runs

No `.env`; `var/data` lock files cleared. PHP 8.3 container.

- Filter `Gee|Support|Cookie|Shell|Colour|TypeScale|MonoAndCase|DeadToken|Translator|TargetSize|Csp|RouteTable|PublicIa|Sandbox|Payment|Reclaim|Reconcil|Chrome|DevUi|AssetBundle|TemplateContext|TwigBlock|NestedForm|Accent|SlotFloor|Legal|Attachment|Celebration`:
  **OK, 944 tests**.
- Full suite: **6,633 tests, 8 failures — `PasskeyTest` ×8 (environmental: PHP 8.3).** None elsewhere.
- The count adds up: test methods 6,472 at `HEAD` → 6,522 = `GeeTest` 0 → 27 (+27), and the celebrations half's
  `CelebrationTest` 4 → 26 (+22) and `ColourBudgetTest` 10 → 11 (+1).
- `public/assets/js/gee.js` shows in `git status` as a staged deletion (`git rm`) plus an untracked file of the same
  name: the destroy, then the rebuild.
