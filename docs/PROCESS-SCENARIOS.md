# Voting & Nomination — Process Scenarios

Updated 2026-07-05 (batch 3). This is the operator's reference for how each flow behaves in every configuration. Settings live under **Admin → Settings** unless noted.

---

## 1. Nomination

**Entry points:** `/nominate` (4-step wizard), `POST /api/v1/nominations` (API), or a share link `/nominate?share=<token>`.

### Scenario A — nominator provides the nominee's EMAIL only
1. Wizard validates (nominee needs email OR phone — email given, so it proceeds).
2. On submit: rate limit (5/24h per nominator), spam gate (heuristics → AI on borderline), device-fingerprint dedupe (one nomination per person per device per cycle), then the row is written with reference **AGN-YYYY-XXXXXX-C** (checksummed, collision-free).
3. Notifications: operators get a review brief; the **nominator** gets a branded confirmation email; the **nominee** gets a "You've been nominated" email with a claim-profile link.
4. Webhook `nomination.submitted` fires; the success page offers a **share link** so others can second the nomination.

### Scenario B — nominee's PHONE only (no email)
Same as A, except the nominee's number is validated and stored as **E.164** (`+234…`, resolved from their country when typed nationally, e.g. `0803…`). The nominee is notified by **SMS (Twilio)** and/or **WhatsApp** — whichever channels the admin has configured under Settings → Messaging:
- Only SMS configured → SMS.
- Only WhatsApp configured → WhatsApp.
- Both → SMS first, then WhatsApp.
- Neither → nothing sends (the nomination itself is unaffected); delivery failures are audited in `gates_messages` (numbers hashed + masked) and retried automatically via the job queue.

### Scenario C — BOTH email and phone
The nominee gets **email + SMS**, and **WhatsApp always sends too when configured**. Anything the nominator typed must validate — a garbled phone is a hard error, never silently dropped.

### Scenario D — signed-in member nominates
Step 4 shows a **"Use my details"** chip (name/email/phone from their account — one click, one-click undo, everything editable). The nomination then appears under **"Your nominations"** on their dashboard with its status and reference.

### Scenario E — nomination via share link
Opening `/nominate?share=<token>` prefills the nominee's details (name, contact, location, programme/category) — all editable. The opener submits **their own** nomination through the full pipeline (spam gate, rate limits, device dedupe all still apply). Links expire after 30 days, count their opens, and never carry the original nominator's identity.

### After submission — review outcomes
- **Approved** (admin console): nominee goes live; `nomination.approved` webhook.
- **Rejected**: nominator is emailed the outcome; `nomination.rejected` webhook.
- The optional **eligibility rule** (Settings) can require nominations from N distinct locations before approval is allowed.

---

## 2. Voting

**Entry point:** `/vote` → programme → nominee ballot page. What the voter sees depends on configuration:

### Scenario 1 — FREE voting (the default)
1. Voter enters name, phone, email (members can one-click autofill).
2. A 6-digit OTP goes to their email (10 min expiry, rate-limited, bot-checked).
3. On confirmation the vote is recorded: **one vote per email per category**, enforced by a DB unique key. The email is stored only as a SHA-256 hash.
4. Vote counts toward BOTH the public tally (`vote_count`) and the **organic count** — the community signal worth 45% of the CPI.
5. Voter gets a confirmation email; `vote.cast` webhook fires; members see it in their dashboard history.

### Scenario 2 — member redeems voting points
Members earn points from shop orders, tickets and donations (rates in Settings → Voting points). On the ballot, a member with enough points can redeem instantly — **no OTP**. This mints a `bonus` vote that bumps `vote_count`, which the community half of the CPI normalises over — so a redeemed vote counts like any other. A cap (default: 50% of a nominee's **non-bonus** votes) bounds how much of a tally may be granted rather than cast; it read *organic* votes until that column became structurally zero wherever free voting is disabled. Nothing here reaches the judging half.

### Scenario 3 — donation bonus votes
When enabled, donors receive bonus votes per ₦1,000 given, redeemable on any open nominee — same rules as Scenario 2: capped against non-bonus support, counted in the community half like any other vote, and never in the judging half.

### Scenario 4 — PAID voting enabled, free voting still on
Settings → Paid voting → **Enable paid voting**. The ballot now leads with **"Buy votes"**:
1. Supporter picks a quantity; price is computed **server-side**: the cheaper of (votes × per-vote price) or (full ₦1,000 bundles + per-vote remainder). Example with ₦150/vote and 10 votes/₦1000: 1 vote = ₦150, 10 votes = ₦1,000, 11 votes = ₦1,150.
2. Checkout via Paystack/Flutterwave; the charge is verified server-to-server (amount must match) before anything counts.
3. On confirmation, the votes are **minted once** (idempotent — gateway webhook and browser return can both land safely) as a weighted `paid` vote: the public tally rises immediately, `vote.paid` webhook fires.
4. **The judging half is never touched by money.** Judges are not shown a vote count. The community half reads a nominee's full tally, so a bought vote counts exactly as a free one does — with the composition published beside every result. (This point used to read "the organic CPI signal is never touched by money"; that rule zeroed the community half entirely wherever free voting was switched off.)

### Scenario 5 — paid voting with FREE VOTING DISABLED ("paid by default")
Tick **"Disable free voting"** as well. The OTP flow disappears from the ballot and the API rejects free-vote attempts (`PAID_VOTING_ONLY`). All voting is purchased. Consequence to understand: with no organic votes accruing, the CPI's 45% community component stays flat, so **ranking is effectively decided by the independent jury (55%)** plus paid-vote public tallies for display. The audited vote pipeline is untouched — the switch closes the gate at the API boundary only, and unticking it restores free voting instantly.

### What counts where (summary)

| Vote type            | Public tally | Organic (CPI 45%) | Jury (CPI 55%) |
|----------------------|:---:|:---:|:---:|
| Free OTP vote        | ✓ | ✓ | — |
| Points redemption    | ✓ | ✗ | — |
| Donation bonus vote  | ✓ | ✗ | — |
| **Paid vote**        | ✓ | ✗ | — |
| Jury scoring         | — | — | ✓ |

Money can make a nominee **look** popular; it can never buy their Cultural Power Index.
