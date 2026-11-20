# Prompt for Claude Code: Africa GATES Challenges (full feature)

You are implementing **Challenges** on Africa GATES (PHP 8.4 · Slim 4 · Twig 3 · vanilla JS · nonce CSP · SQLite dev / MySQL prod).

Before writing code, read in this order:
1. `handoff-new-pages/HANDOFF-NEW-PAGES.md`
2. `HANDOFF-CLAUDE-CODE.md` §0–§3
3. `skills/mobile-native-ux/SKILL.md`
4. The repo `CLAUDE.md`
5. `templates/pages/account/dashboard.twig` and the nomination services.

The designs in `handoff-new-pages/designs/` are the spec:
- `ChallengePage.dc.html`: props challenge, state, signedIn, layout.
- `ChallengeBanner.dc.html`: props placement, layout.
- `AccountPage.dc.html`: tab = challenges, activity, overview.
- `Celebrate Nigeria Flier.dc.html`: the marketing asset.

Open each one and cycle every prop before you start.

---

## 1. What a challenge is

A challenge is a **time-boxed, rule-based campaign that rewards people for taking real actions on Africa GATES**. Examples:
- Nominating people.
- Voting.
- Bringing ticket buyers to an event.
- Giving.

Challenges are created by admins, run inside one or more **awards, editions, categories or events**, and are shown across the site.

**The launch challenge, which must work exactly like this:**
> **Celebrate Nigeria (Independence Day).** The first **11 people** to get **10 unique nominees verified** each win **₦6,000**. To take part you must first **sign in or create an Africa GATES account** (phone verified). Every nomination must have **complete details** (full name, phone, email, category, reason ≥40 characters), and the nominee must be **real and verified**: they confirm by SMS or WhatsApp and our team approves it. Fake, copied, duplicate or self-nominations don't count, and fraud disqualifies the whole entry. Scope: Alimosho Awards 2026 (Choral, Business, Impact). Ends 15 Oct, 23:59 WAT.

**Challenges must vary without new code.** Everything below is configuration:

| Dimension | Options |
|---|---|
| Action | `nominate` · `vote` · `refer` (paid tickets via your link) · `give` (donations) · `attend` (checked-in tickets) |
| Target | any N (e.g. 10 nominees, 5 categories voted, 5 paid tickets) |
| Winning mode | `first` (first N to finish) · `top` (most by the close) · `draw` (seeded random draw among everyone who finishes) |
| Prize | `cash_each` · `cash_pool` (split) · `points` · `tickets` (e.g. gala tickets) · any currency (₦, KSh, GH₵, R…) |
| Scope | one or many award cycles, categories and events (mixed allowed) |
| Eligibility | verified phone (always) · optional country/state/LGA · optional new members only · optional age 18+ |
| Theme | 4 presets (green, blue, gold, rose) + optional art + optional flag |
| Lifecycle | `draft → upcoming → open → full → ended` (and `cancelled`, with a public reason) |

---

## 2. Data model (migrations in BOTH schema files; mind the SQLite/MySQL traps in CLAUDE.md)

```
gates_challenges
  id, slug UNIQUE, title, kicker, summary NULL
  action ENUM(nominate,vote,refer,give,attend)
  target INT, mode ENUM(first,top,draw), cap INT NULL, draw_count INT NULL, draw_at DATETIME NULL, draw_seed VARCHAR NULL
  prize_type ENUM(cash_each,cash_pool,points,tickets), prize_amount INT, prize_currency CHAR(3) NULL, prize_label VARCHAR NULL
  theme ENUM(green,blue,gold,rose), art_url NULL, icon NULL, flag BOOL
  eligibility JSON  -- {country:[], state:[], lga:[], new_members_only:bool, min_age:int}
  extra_rules JSON  -- admin-appended lines; never replaces generated rules
  starts_at, ends_at, timezone, terms_version
  status ENUM(draft,upcoming,open,full,ended,cancelled), cancel_reason NULL
  created_by, published_at NULL, created_at, updated_at

gates_challenge_scopes
  challenge_id, scope_type ENUM(award_cycle,category,event), scope_id   -- PK(challenge_id,scope_type,scope_id)

gates_challenge_entries
  id, challenge_id, user_id, phone_hash, joined_at
  progress INT DEFAULT 0, verified INT DEFAULT 0, checking INT DEFAULT 0, needs_details INT DEFAULT 0
  qualified_at NULL, rank NULL, status ENUM(active,qualified,won,disqualified,withdrawn)
  disqualify_reason NULL, payout_status ENUM(none,pending,paid,failed), payout_ref NULL, payout_at NULL
  UNIQUE(challenge_id,user_id), UNIQUE(challenge_id,phone_hash)

gates_challenge_events      -- append-only audit trail
  id, entry_id, kind, ref_type, ref_id, meta JSON, created_at

gates_nominations  (+ columns)
  challenge_entry_id NULL, nominee_identity_hash  -- sha256(E.164 phone) + normalised email hash
  nominee_confirm_token, nominee_confirmed_at NULL, confirm_sends TINYINT
  status ENUM(draft,submitted,checking,verified,needs_details,rejected)

gates_promos
  id, placement ENUM(account,nominate,home,vote,events,award,event), kicker, title, sub, cta, href
  theme, art_url NULL, challenge_id NULL, priority INT, audience ENUM(all,signed_in,signed_out)
  starts_at, ends_at, active BOOL
```

---

## 3. Rules engine (server-side only; never trust the client)

`ChallengeService::record(Action $a)` is called from the nomination, vote, ticket, donation and check-in services. It:
1. Finds open challenges whose **scope contains** the action (cycle, category or event) and whose action type matches.
2. Finds the user's entry (or ignores the action if they haven't joined).
3. Applies the action-specific counting rule:
   - **nominate:** counts when the nomination reaches `verified`.
     - Unique by `nominee_identity_hash` within the entry.
     - Not the entrant's own phone or email.
     - All fields complete and the reason ≥40 chars.
     - The nominee confirmed via the SMS/WhatsApp link or a "YES" reply (token valid 7 days, max 3 resends, after which the nominator can edit the phone number once).
     - A moderator approved it.
   - **vote:** counts distinct categories voted (email OTP verified) in scope.
   - **refer:** counts paid tickets bought through the entrant's link or code; refunds decrement; self-purchase is excluded (same phone, email or card fingerprint).
   - **give:** counts confirmed gifts ≥ the minimum amount (configurable in `extra_rules`).
   - **attend:** counts tickets checked in at the door.
4. Updates the counters (`verified`, `checking`, `needs_details`) for the 10-segment progress bar.
5. **Qualification:** when `verified >= target`, set `qualified_at = the moment the last counting item was approved` (NOT submit time).
6. **Ranking:**
   - **first:** in a DB transaction, `SELECT … FOR UPDATE` on the challenge row; if `count(won) < cap`, set `rank = count+1, status = won`; when `count == cap`, set the challenge to `full`. Later qualifiers stay `qualified` (thanked, no prize).
   - **top:** rank by `verified` at `ends_at`; ties go to the earlier `qualified_at`. Show a live "Leading now" (cached hourly).
   - **draw:** at `draw_at`, a seeded shuffle over qualified entries (publish `draw_seed` + the algorithm), take `draw_count`.
7. **Fraud:**
   - Rate-limit nominations per entrant per hour.
   - Flag the same nominee phone across entries (allowed, but reviewed).
   - Flag device and IP clusters.
   - Disqualify an entry with a reason (logged in `gates_challenge_events`, emailed to the entrant).
   - Disqualification is appealable once.
8. **Payout** (cash): within 7 days, to the mobile money wallet or bank account **in the entrant's name** (provider name-lookup must match the profile name). Store `payout_ref`. Points prizes credit the ledger (`earn.challenge`). Ticket prizes issue tickets in the account.

`ChallengeCopy::for($c)` generates ALL public copy. Port `derive()` from `ChallengePage.dc.html` exactly:
- the promise sentence per mode;
- 3 steps per action;
- rules = base (verified phone; one entry per person/phone) + per action + per-mode ordering + `extra_rules`;
- prize big/unit, meter title, CTA per state, empty states.

Unit-test it against the 3 demo configs (nigeria, teachers, gala).

---

## 4. Where challenges appear (visitors and members must SEE them)

1. **Challenge page** `/challenges/{slug}` (`ChallengePage.dc.html`):
   - **Hero band** on the theme wash: art or icon + kicker + state pill + H1 + promise + 4 facts (Prize · Winners · To qualify · Ends).
   - **Body:** How to take part · What counts as verified · Winners (first: in qualified order; top: Leading now; draw: an empty state → published draw) · **Included** (scoped awards, editions, categories and events, linked) · Questions · Terms vN · More challenges.
   - **Desktop:** a 360px sticky rail with the prize, the claim meter (first mode: N spots, filled = claimed), your progress, CTA, Share, Get the flier.
   - **Phone:** a meter card + a fixed bottom bar; Gee sits at 108px.
   - **CTA by state:**
     - upcoming → Remind me (one SMS);
     - open, signed out → **Sign in to join** (returns to the challenge after sign-up and phone verification);
     - joined → Add a nominee / Get my link / Vote now;
     - full → See the awards;
     - ended → See the results;
     - cancelled → the reason.
2. **Challenges index** `/challenges`: open first, then upcoming, then ended. Filter by action and country. Cards show the prize, progress of claims and time left.
3. **Promo banner** (`ChallengeBanner.dc.html` → `partials/promo-carousel.twig`): on the **Nominate hub (above "Closing soon")**, the Account overview, Home "Happening now", the Vote hub and the Events index. Every scoped award, edition, category and event page gets a compact one-line strip ("Part of Celebrate Nigeria · 4 of 11 prizes left · Details").
4. **Nomination flow:** when the chosen category is in scope, show "Counts toward Celebrate Nigeria (6 of 10)" under the category, and a completeness checklist before submit. After submit: "Ask {nominee} to reply YES to the SMS" with Resend and WhatsApp.
5. **Account** (`AccountPage.dc.html`):
   - **Overview:** a challenge progress card (10 segments: verified / checking / needs details / empty) with "Fix N nominations".
   - **Challenges tab:** active, upcoming (Remind me) and past (result: "₦6,000 paid" / "Didn't qualify").
   - **Activity tab:** per-nomination status with an inline fix note.
   - **Finish setting up:** a payout account is required before a cash prize can be paid.
6. **Navigation:** "Challenges" in the Participate mega menu and the phone Menu while ≥1 is open (with a live count).
7. **Pulse/Discover:** an automatic public post when someone wins ("Aisha B. from Ikotun won the Celebrate Nigeria challenge"), using first name + initial only and only with the winner's opt-in.
8. **Notifications** (SMS + email + in-app):
   - to the entrant: joined, each nomination status change, "2 more to go", qualified (rank), won, payout sent, disqualified (reason), challenge almost full;
   - to the nominee: the confirmation request.
   
   Every message carries an opt-out.
9. **Fliers:** the "Get the flier" button renders the challenge's share image (1080×1080) from the theme, art and prize. It's server-rendered so it works offline in WhatsApp.

Privacy: public lists show first name + initial, area and time only. Never phones, emails or nominee details.

---

## 5. Admin (notes only; build inside the existing admin UI; there are no designs)

**Challenges → New**, a 5-step form with a live preview of the public page:
1. **Basics:** title, kicker, slug, summary, art upload or icon, theme preset, flag, terms version.
2. **What counts:** action, target, and **scopes (multi-select: awards → editions → categories, and events)**. Shows the per-action counting rule in plain words. Unique-person rule (locked on for nominate).
3. **Who wins:** mode, cap or draw count + draw date, prize type, amount, currency, label. A live sentence preview ("The first 11 people to get 10 different nominees verified each win ₦6,000").
4. **Schedule & audience:** starts/ends with the timezone shown, eligibility (country/state/LGA, new members only, 18+), one entry per person (fixed).
5. **Promote:** auto-create promos (Nominate hub + Account + scoped pages pre-checked; Home optional), an optional SMS/email announcement to a segment, and a flier export.

**Publish validation:** ≥1 scope; cap > 0 unless mode=draw; draw_at ≥ ends_at for draw; ends > starts; a prize amount; terms published; a budget shown (cap × prize).
**After publish:** only copy, art, an end-date extension, promo placements and extra rules (append-only) can be edited. Mode, target, prize and scopes are locked. Cancelling requires a public reason.

**Ops queues:**
- **Verification:** nominations in `checking` (side-by-side nominee details + confirmation status + duplicates).
- **Entries:** progress, rank, flags, disqualify with a reason, appeal.
- **Payouts:** name-match result, mark paid with a reference, retry failed.
- **Draw:** run (seeded), then publish.
- **Exports:** CSV of entries, winners and payouts.

Every admin action is written to `gates_challenge_events`.

The nomination category assistant stays admin/judge-only and is never mentioned publicly.

---

## 6. Build order (one PR each, with screenshots at 390/834/1440 + RTL 390 beside the DC)

1. Migrations + models + seed of the 3 demo challenges.
2. `ChallengeCopy` + tests.
3. `ChallengeService` (record, qualify, rank, draw, fraud flags) + tests:
   - 11 concurrent qualifiers → ranks 1–11, then `full`;
   - a duplicate nominee doesn't count;
   - a self-nomination is rejected;
   - a refund decrements a refer challenge;
   - the draw is reproducible from its seed.
4. Nominee confirmation (SMS/WhatsApp link + YES reply, resend limits).
5. `/challenges/{slug}` + `/challenges` + scoped strips.
6. Promo carousel partial + `/api/promos`, mounted on the Nominate hub, Account, Home, the Vote hub and Events.
7. Account integration (overview card, Challenges tab, Activity statuses, payout setup).
8. Notifications + the winner Pulse post + the flier renderer.
9. Admin builder + queues.

**Rules:** reuse existing services; build anything missing end to end; no inline styles; no new colours (theme presets only); 44px targets; the carousel passes WCAG 2.2.2; deviations list target 0. If anything can't be matched or is ambiguous, **stop and ask**.
