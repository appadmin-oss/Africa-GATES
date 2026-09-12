# Africa GATES → Africa's trusted reputation infrastructure

**A restructuring and implementation plan for the Community Return Initiative,
and for the platform it turns Africa GATES into.**

Status: proposal · Date: August 2026 · Owner: unassigned
Source documents: `Africa_GATES_Community_Return_Initiative.pdf`, plus the
four-phase / four-pillar strategy note.

---

## 0. What this document is

The Community Return Initiative (CRI) proposal says Africa GATES will pay
nominees 5–25% of voting revenue when they cross vote milestones. The strategy
note says the real company is "Africa's trusted reputation infrastructure," that
awards are one product inside it, and that the metric to watch is **trust**, not
revenue.

Those two documents are describing the same thing and do not yet know it. This
plan joins them, and says what to build, in what order, and what to change about
the CRI before a naira is promised.

It is written against the codebase as it stands, not against a whiteboard.
Everything in §4 is a column, table or service that exists today.

---

## 1. The reframe, in one page

Africa GATES currently sells **attention**: pay ₦100, your favourite moves up a
leaderboard. Every paid-voting platform on the continent sells that, and it is
worth nothing the day the cycle closes.

What the platform actually *manufactures* — and does not yet sell, publish, or
even name — is a **verified record of real human support**. Every vote in
`gates_votes` carries an OTP-verified email hash, an IP hash, a device hash, a
risk score, a fraud flag, and a timestamp. `SnapshotService` already hash-chains
the standings so history cannot be rewritten. That is a reputation ledger with
an awards show bolted on the front.

So:

| Layer | What it is | Status |
|---|---|---|
| **The backbone** | Verified support, hash-chained, fraud-scored, auditable | Half-built, unnamed |
| **The credential** | A record a third party can check: "3,412 real people backed this person" | Does not exist |
| **The applications** | Awards. Then grants, bookings, lending, hiring, visas | Awards only |

The CRI is what makes people *want* the credential badly enough to earn it
honestly. It is not a marketing feature; it is the demand generator for the real
product.

**Do not pitch awards. Pitch: the record of who Africa actually backs, and
proof it is real.**

---

## 2. The one change that must happen before launch

> The proposal denominates milestones in **votes**. It must denominate them in
> **distinct verified supporters**.

This is not a detail. It is the difference between a rebate scheme and a trust
product.

**Why.** `gates_votes` is `UNIQUE(voter_email_hash, category_id)` — one row per
person per category — but the row carries a `weight`, and paid votes set weight
to whatever was purchased. "50,000 votes" is therefore a statement about
**money**, not about people. One person with ₦5,000,000 reaches 50,000 votes.
Fifty thousand people reaching 50,000 votes is a completely different fact about
the world, and it is the only one worth paying for, publishing, or being trusted
about.

The proposal already says what it wants:

> *"This approach rewards not only popularity but also the ability to build
> engaged, supportive communities."*

Distinct supporters **is** the engaged community. Vote weight is not. The fix
makes the CRI better on its own stated terms.

**What it changes downstream.**

* A nominee cannot buy their way to Ambassador — they can only buy weight, and
  weight no longer moves the milestone.
* The metric becomes the thing the Trust Index is built from, so the CRI and the
  trust product stop being two projects.
* "3,412 people backed her" is a sentence a bank understands. "341,200 votes"
  is not.
* The headline numbers get smaller and more honest. 50,000 distinct verified
  supporters in one category is a genuinely large number in Nigeria. Say so.

**Recommended reading of the milestone table** (the proposal explicitly leaves
these open — *"final milestones and percentages shall be determined by Africa
GATES"*):

| Milestone | Meaning | Return rate on that band |
|---|---|---|
| 2,500 supporters | Local following | 5% |
| 5,000 | City-wide | 10% |
| 10,000 | State-wide | 15% |
| 25,000 | Regional | 20% |
| 50,000 | National + Ambassador | 25% |

---

## 3. The second change: make the return **marginal**, not a cliff

As written, the return is a cliff: cross 50,000 and 25% applies. That has three
problems, one of which is a solvency event.

1. **The last vote costs a fortune.** Going from 49,999 to 50,000 supporters
   moves the rate from 20% to 25% across the *whole* base. On ₦5m of attributable
   revenue that single supporter costs the platform **₦250,000**.
2. **It creates exactly the incentive we are trying to remove** — an enormous,
   concentrated reason to manufacture the last few hundred supporters.
3. **The liability cannot be reserved exactly.** Until the cycle ends you must
   reserve at the maximum rate against every nominee who *might* cross, which
   means holding 25% of all voting revenue idle.

**Fix: bracket it like income tax.** Each band earns its own rate.

Worked example, a nominee finishing at exactly 50,000 supporters at ₦100 each
(₦5,000,000 attributable):

| Band | Supporters in band | Rate | Return |
|---|---:|---:|---:|
| 1 – 2,499 | 2,499 | 0% | ₦0 |
| 2,500 – 4,999 | 2,500 | 5% | ₦12,500 |
| 5,000 – 9,999 | 5,000 | 10% | ₦50,000 |
| 10,000 – 24,999 | 15,000 | 15% | ₦225,000 |
| 25,000 – 49,999 | 25,000 | 20% | ₦500,000 |
| 50,000 + | 1 | 25% | ₦25 |
| **Total** | **50,000** | **≈15.75% blended** | **≈₦787,525** |

You keep the headline — *"up to 25% back"* is still true — you remove the cliff,
you cut the worst-case liability by roughly a third, and you get the elegant
part:

> **With brackets, the reserve is exactly computable at mint time.** Each
> confirmed paid vote accrues its own marginal rate the moment it lands. No
> estimation, no over-reserving, no end-of-cycle shock. The liability is always
> exactly right.

That is not a compromise for the platform's benefit. It is the only version that
can be reserved honestly, and honest reserving is the whole trust thesis.

---

## 4. What already exists (so the plan is credible)

| Need | Already in the codebase |
|---|---|
| Per-vote identity | `gates_votes.voter_email_hash` (OTP-verified), `ip_hash`, `device_hash` |
| Per-vote risk | `gates_votes.risk_score`, `fraud_flag` |
| Collusion detection | `CollusionService` — device rings (≥3), IP rings (≥6), bursts (≥8 in 10 min) |
| Judge anomalies | `JudgeAnomalyService` |
| Synthesised integrity view | `IntegrityBriefService` |
| Tamper-evident history | `SnapshotService` — SHA-256 hash-chained standings per cycle |
| Money in | `gates_donations` (+ `intent_nominee_id`, `payment_ref`, `status`) |
| Vote ↔ money link | `gates_votes.donation_id` |
| Gateway truth | `PaymentReconciler`, `PaymentService` (Paystack + Flutterwave) |
| Money back | `RefundService` — claim-before-act idempotency, caps, grace window |
| Milestones | `MilestoneService` — already tracks 100…100,000 and notifies |
| Recognition score | `CpiService` |
| Geography | `gates_nominations.nominee_state`, `nominee_lga`, `gates_votes.nominee_country` |

**What is missing is short and specific:**

1. A **ledger** with a liability side. There is money-in and money-back, but no
   double-entry and no concept of an obligation.
2. A **Trust Index** — the signals are all collected and none are combined,
   scored, or published.
3. A **public record** — nothing the platform knows is exportable or checkable
   by an outsider.
4. **Payouts** — no transfer path, no KYC, no bank-account verification.

That is the Phase 1 build. Four things.

---

## 5. The Trust Index

CPI answers *how much recognition does this person have*. Nothing answers *can
that recognition be believed*. The Trust Index (TI) does, and every input
already exists.

| Signal | Computed from | Why it matters |
|---|---|---|
| **Breadth** | count(distinct `voter_email_hash`) | Real people, not weight |
| **Dispersion** | Shannon entropy over supporter LGA/state | Support from one street scores low |
| **Independence** | 1 − share held by largest `device_hash` / `ip_hash` cluster | Ring detection, continuous |
| **Cadence** | penalty for votes clustered in few 10-minute windows | Reuses `CollusionService`'s burst window |
| **Verification depth** | share of supporters who completed OTP | Not all support is equal |
| **Clean rate** | 1 − (`fraud_flag` share) | The obvious one |
| **Chain integrity** | `SnapshotService` verify | **A gate, not a weight** |

Two rules that make it worth something:

* **Chain integrity is a gate.** If the snapshot chain for a cycle does not
  verify, the TI for that cycle is *not published*. Not "published low" —
  withheld, loudly, with the reason.
* **The formula is public and versioned.** `TI v1.0` is documented, the weights
  are in the repo, and a change is a version bump with a published changelog. An
  index nobody can audit is a marketing number.

Publish TI per nominee, per category, and **per cycle in aggregate** — including
the bad numbers. See §11.

---

## 6. The Verified Community Record

The exportable artefact. This is the product that outlives the awards.

A permanent page at `/verify/<record-id>` plus a signed JSON representation:

```
Adaeze Okonkwo — Choral Excellence, 2026 Cycle
3,412 distinct verified supporters
across 27 local government areas in 4 states
Trust Index 87 (v1.0)   ·   Clean-vote rate 99.2%
Community Return disbursed: ₦412,500 on 2026-11-04
Standings snapshot: 0x9f2c…a41b   ·   chain verified 2026-11-04 18:22 WAT
Issued by Africa GATES · verify this record at afg.afrovanguard.org.ng/verify/…
```

Properties that matter:

* **Permanent.** It survives the cycle, the category being retired, and the
  nominee losing.
* **Signed.** Ed25519 over the canonical JSON; the public key published at a
  well-known URL. Later: W3C Verifiable Credential so it works off-platform.
* **Revocable, visibly.** If a category is voided, the record shows *voided* and
  why. It does not disappear. Deleting a bad record is how a registry dies.
* **Free to check.** No login to verify. Charging to verify is the Phase 4
  temptation that would destroy the asset.

This is the thing a lender, a festival booker, a grant committee or an embassy
can act on, and it is why "reputation infrastructure" is not a slide.

---

## 7. The money: ledger, accrual, payout

### 7.1 A real ledger

`gates_ledger_entries`, double-entry, append-only:

```
account                       dr/cr   naira   ref
asset.cash                      dr     100    AFG-PVOTE-…
revenue.votes                   cr      95
liability.community_return      cr       5    nominee 812, band 5%
expense.gateway_fee             dr       1.5
```

Accounts: `asset.cash`, `revenue.votes`, `revenue.shop`,
`liability.community_return`, `liability.refunds`, `expense.gateway_fee`,
`expense.community_return_paid`.

Rules:
* Append-only. Corrections are reversing entries, never updates.
* Every entry references a `payment_ref` or a `payout_ref`.
* A nightly job asserts `sum(dr) == sum(cr)` and screams if not.

### 7.2 Accrue at mint, not at milestone

The moment a paid vote confirms and mints, accrue its **marginal** return rate
into `liability.community_return`, tagged to the nominee. Because the schedule is
bracketed (§3), the rate for vote *n* is known exactly at vote *n*.

Consequences:
* The platform never spends money it has already promised.
* Crossing a milestone is a *notification*, not a financial event.
* A cycle that ends below a milestone releases the accrued band back to revenue —
  and that release is published too.

### 7.3 Payouts

Reuse the pattern that already works in `RefundService`: **claim before act** —
stamp `payout_claimed_at` in a conditional UPDATE before calling the gateway, so
two workers can never pay twice.

Gate every disbursement on:

1. **Integrity gate green** (§8).
2. **KYC complete** — NIN or BVN, name match, bank account resolved and verified
   via the gateway's account-resolution API. Payout goes to the nominee's own
   verified account and nowhere else, ever.
3. **Cycle terminally closed** for that category, plus a dispute window.
4. **Caps** — per-payout and per-day ceilings, as `RefundService` already has.

### 7.4 The strong idea: pay into a purpose wallet, not a bank account

The proposal already lists what Community Returns are *for*: production, studio
time, equipment, branding, business registration, training, education.

Pay the return into a **restricted wallet** redeemable with vetted vendors, with
a cash-out option at a lower rate (say 70%) after the dispute window.

This does four things at once:

* **Removes the money-laundering vector** (§9.4) almost entirely.
* **Simplifies the tax position** — the platform buys a service for the nominee
  rather than remitting cash.
* **Honours the proposal's own intent** rather than hoping people spend well.
* **Becomes Phase 4 revenue.** A vetted vendor marketplace with guaranteed
  demand is a two-sided market. The cost centre turns into the business.

This is, in my view, the single highest-leverage idea in this document.

---

## 8. The Integrity Gate

One function, checked in code, that every payout and every published record
passes through. Not a prompt, not a checklist — a gate.

```
green   → publish record, release payout
amber   → publish record with a caution, hold payout for human review
red     → publish nothing, hold payout, notify the Integrity Council
```

Red conditions (any one):
* Snapshot chain fails to verify for the cycle.
* An open `CollusionService` finding names this nominee.
* Clean-vote rate below threshold.
* Reconciliation shows unmatched money for this nominee.
* KYC name mismatch.

**The gate is owned by the Trust pillar and cannot be overridden by anyone who
owns a revenue target.** That sentence is the entire governance model; everything
in §12 exists to make it true.

---

## 9. Legal and regulatory — read this before the pitch

I researched this rather than recalling it, because the ground moved recently and
it changes the phase plan.

### 9.1 The regulator is the **state**, not the federal commission

In *AG Lagos v AG Federation* (SC/1/2008, 22 Nov 2024) the Supreme Court held
that lotteries, betting and gaming are **residual matters for the states**, and
confined the National Lottery Act 2005 to the FCT. The NLRC is no longer the
primary regulator outside Abuja.

For an initiative operating out of Lagos, the regulator is the **Lagos State
Lotteries and Gaming Authority (LSLGA)**.

### 9.2 A Promotional Competition permit is probably required

LSLGA licenses "Promotional Competitions" — prizes awarded on **skill or chance**
as part of a marketing initiative, where the activity does not amount to a
lottery. The application requires the **competition rules submitted for board
approval**, a CAC certificate, and a Fit-and-Proper assessment of the applicant
and key personnel.

Note the phrase *skill or chance*. The permit regime catches this whether or not
the CRI is a lottery. **Budget for the permit; do not argue your way out of it.**

### 9.3 The CRI makes the legal position *better*, not worse

The classic lottery test is prize + consideration + **chance**. The Community
Return is deterministic: a published schedule, an outcome driven entirely by how
many real people a nominee persuades. There is no chance element in the return at
all, and the award itself is judged. That is a materially stronger position than
"pay to vote, one winner takes a prize."

Do not treat that as a legal opinion. Get one, from a Lagos gaming/promotions
practitioner, **before** the schedule is published — because once published it is
a representation to the public and to the regulator.

### 9.4 Three things to fix in the document itself

1. **Delete the word "investment."** The proposal says *"Every vote becomes an
   act of investment"* and *"It represents investment."* This is a one-word
   problem with real consequence: it edges toward a securities framing, invites
   the question "what is the return on my investment," and is untrue — the
   supporter receives nothing financial. Say **contribution**, **backing**, or
   **support**. Keep the emotional force; lose the legal exposure.
2. **Say who the percentage is *of*.** "A percentage of voting revenue" is
   ambiguous between platform-wide and nominee-attributable. It must read: *a
   percentage of the net revenue attributable to that nominee's own supporters,
   after payment-processing fees.* Ambiguity here is a dispute later.
3. **State the tax position.** Under the Nigeria Tax Act 2025 (in force 1 Jan
   2026) payments of this kind to resident individuals attract withholding at
   around 5% for services-type heads. Nominees must be told, in the published
   framework, whether the quoted percentage is gross or net of WHT. Confirm the
   correct head with a Nigerian tax adviser — the head, not just the rate, is the
   question.

### 9.5 The finding that reshapes Phase 2

Because gaming and promotions are now regulated **state by state**, "expand
across Nigeria" is not one regulatory step. It is potentially **twenty-plus**
separate permits, fee schedules and rule approvals.

That is expensive if you treat Nigeria as one market — and nearly free if you
treat it as chapters. **The regulatory reality argues for exactly the chapter
model the strategy already wants.** Each state chapter obtains its own permit and
operates under the shared backbone. Sequence expansion by regulatory tractability,
not by population.

### 9.6 The risk nobody has named: laundering

A nominee's own money in, 25% back out, clean. It is expensive laundering — you
lose 75–84% — but for some money that is an acceptable price, and one incident
ends the platform.

Mitigations, in order of strength: the purpose wallet (§7.4), payout only to the
nominee's own KYC-verified account, per-nominee and per-cycle ceilings, source-of-
funds flags on large single-payer concentrations (already computable — one
`voter_email_hash` funding a large share of a nominee's supporters is precisely
what the Independence signal measures), and a documented escalation path to the
payment providers' compliance teams.

Write this into the framework before launch. A platform that publishes its own
abuse controls is trusted; one that is asked about them later is not.

---

## 10. The four phases, with exit criteria

Phases end on **evidence**, not dates.

### Phase 1 — Alimosho. Prove the model in one place.
*Goal: trust. Revenue is reported, never targeted.*

Build: bracketed CRI on one programme · the ledger · Trust Index v1 · Verified
Community Record v1 · public money page · Integrity Gate · payouts with KYC.

**Exit when:**
* One full cycle completed with **100% of crossed milestones disbursed**, and the
  median milestone→money latency published and under 14 days.
* Trust Index published for every nominee, formula public.
* Every record verifiable by a stranger with no account.
* At least one **negative** result published — a voided category, a withheld
  payout, a rejected milestone — with the reasoning.

That last one is not a stretch goal. A trust platform with no published failures
has either been lucky or is not looking.

### Phase 2 — Nigeria. Chapters, partners, verification.
Build: chapters as first-class objects (`state`/`lga` already on nominations) ·
per-state permits · sponsor matching pools · AI verification of nomination claims
(`NominationTriageService` is the seam) · Trust Index API (read-only, free).

**Exit when:** three state chapters have run a clean cycle each under their own
permit, and at least one **external** organisation has made a decision citing a
Verified Community Record.

### Phase 3 — Pan-African. Same backbone, local operators.
Build: multi-currency (`CurrencyService` exists) and multi-gateway · per-country
legal wrappers · federated chapter operations with a staked integrity bond ·
localisation.

**Exit when:** a chapter the core team does not operate runs a cycle that passes
the Integrity Gate unaided.

### Phase 4 — Infrastructure. Others build on it.
Build: the vetted-vendor marketplace (§7.4) · portable credentials · reputation
API and embeddable badge · underwriting introductions — a nominee with three
clean cycles of verified audience is a credit-assessable person, and that is the
endgame with real margin.

**Revenue mix shifts** from vote fees to marketplace take-rate, verification and
licensing. Verifying a record stays free forever.

---

## 11. The scoreboard: what "trust is the metric" actually means

**North star — Disbursement Integrity.** Percentage of crossed milestones paid,
on time, and published. Target 100%. Below 100% once, the thesis is damaged;
twice, it is dead. This is the only number that goes on the wall.

Supporting:

| Metric | Why |
|---|---|
| Distinct verified supporters | The real audience. Not votes. |
| Median supporters per nominee | Breadth. A platform carried by three nominees is not a platform. |
| Clean-vote rate, published per cycle | Nobody publishes this. That is the point. |
| Voided categories / withheld payouts | A **good** number when >0 and explained. |
| Median milestone→money latency | Promises kept, measured in days. |
| Repeat-supporter rate, cycle over cycle | People coming back *is* trust, measured. |
| External verify-page fetches | The Phase 4 leading indicator: is anyone checking? |

Revenue, GMV and vote count are reported monthly and **are not targets before
Phase 3**. Write that into the board pack so nobody has to be brave about it
later.

---

## 12. The four pillars → org and code

### Org

| Pillar | Owns | Non-negotiable |
|---|---|---|
| **Technology** | Platform, backbone, API | — |
| **Trust** | Judges, verification, collusion, audit, the published ledger | **Does not report to whoever owns revenue.** Holds veto over publication and payout. |
| **Growth** | Partnerships, chapters, marketing | Cannot commission a record or override the gate |
| **Operations** | Finance, legal, volunteers, support | Runs the payout rail; cannot approve its own gate |

The Trust pillar needs an **Integrity Council** with a published charter, published
minutes, external members, and the standing power to void a category. If Trust
can be overruled by a revenue owner, the index is worth nothing and everyone will
eventually know.

### Code

The four pillars map onto four bounded contexts. `src/Services/` is currently 80+
flat classes where `RefundService`, `CollusionService` and `PulseFeedService` are
neighbours.

```
src/Trust/      CollusionService, FraudService, JudgeAnomalyService,
                SnapshotService, IntegrityBriefService,
                + TrustIndexService, IntegrityGate, RecordService
src/Money/      PaymentService, PaymentReconciler, RefundService, PaidVoteService,
                + Ledger, ReturnAccrual, PayoutService, KycService
src/Awards/     CycleService, CyclePolicy, VoteService, CpiService,
                StandingsService, MilestoneService
src/Community/  CommunityService, PulseFeedService, ProfileService, SupportersService
src/Support/    the assistant (already coherent)
src/Platform/   Cache, Queue, Webhook, Notifier, Ai*, Media, R2
```

**Do not do this as a big-bang rename.** Moving namespaces touches every `use` in
1,523 passing tests for zero behaviour change, and restructuring before you know
the shape is how you get the wrong shape. Instead:

* **New code goes in the new context from day one** — `src/Trust/`, `src/Money/`.
* Old classes move **only when they are being changed anyway**.
* `src/Services/` drains over two or three phases and is deleted when empty.

Low risk, no flag day, and the boundary is enforced by where new work lands.

---

## 13. Brainstorm — the bigger swings, graded

| # | Idea | Grade |
|---|---|---|
| 1 | **Purpose wallet + vetted vendor marketplace** (§7.4). Cost centre → two-sided market. | **Do it.** Phase 1 design, Phase 2 build. |
| 2 | **Publish the anti-fraud numbers.** Aggregate collusion stats, clean-vote rate, voided categories, every cycle. Nobody does this. | **Do it.** Nearly free, loudest possible signal. |
| 3 | **Founding Supporter marks.** Early backers permanently named on the record. Costs nothing, deepens exactly the loop the proposal wants. | **Do it.** Phase 1. |
| 4 | **Sponsor matching pools.** A sponsor matches community returns for a category. Turns sponsorship from logo placement into measurable impact — and adds revenue without touching voting integrity. | **Do it.** Phase 2. |
| 5 | **Verifiable supporter receipts.** Hash-chained, so a supporter can later prove they backed someone before they were famous. | Strong. Phase 2. |
| 6 | **Chapter integrity bonds.** Local operators stake; integrity failures cost them. Makes federation safe. | Strong. Phase 3. |
| 7 | **Portable credentials (W3C VC).** The record works off-platform. | Right for Phase 4; premature before. |
| 8 | **Talent underwriting.** Three clean cycles → introduce to a lender with a verified audience record. | The real endgame. Phase 4. Needs a partner, not code. |
| 9 | **Quadratic supporter weighting** for the milestone metric — 100 people × 1 beats 1 person × 100. | Mostly subsumed by §2. Revisit if gaming appears. |
| 10 | **Trust-as-a-service API with a paid tier.** | Careful: verification must stay free. Charge for *bulk* and *monitoring*, never for a single check. |

---

## 14. Phase 1 build order

Roughly sequenced; each item is shippable and independently valuable.

1. **Ledger** — `gates_ledger_entries`, accounts, the nightly balance assertion.
   No behaviour change; start recording truth immediately.
2. **Supporter metric** — `distinct_supporters` per nominee per cycle, computed
   and cached. Publish it on the nominee page *before* any money depends on it,
   so the number is familiar and boring by the time it matters.
3. **Bracketed accrual** — marginal rate on every confirmed paid vote at mint.
   `MilestoneService` becomes the notifier, not the calculator.
4. **Trust Index v1** — service, published formula, per-nominee score, chain
   integrity as a gate.
5. **Integrity Gate** — one function, allowlist-style, checked in code.
6. **Verified Community Record** — `/verify/<id>`, signed JSON, permanent,
   revocable-visibly.
7. **Public money page** — in, accrued, disbursed, released. Updated live.
8. **KYC + payouts** — account resolution, name match, claim-before-act transfer,
   caps.
9. **Framework publication** — the rules, the schedule, the abuse controls, the
   tax position. Published *before* the cycle opens, as the proposal promises.

Items 1–3 and 7 are worth doing even if the CRI is never launched: they make the
existing platform honest about its own money.

---

## 15. What I would push back on

* **"Every vote is an investment."** Change the word (§9.4). This is the one item
  I would not ship without.
* **Milestones in votes.** As written the CRI is a volume rebate wearing the
  language of community. Denominate in people (§2).
* **The 25% cliff.** Not affordable and not reservable (§3).
* **Ambassador Recognition at 50,000.** A permanent title awarded on a number
  that can be influenced by money should be reviewable and revocable. Write the
  revocation rule into the framework at the same time as the award rule, or it
  will be written during a scandal.
* **"Expand across Nigeria" as one step.** It is twenty-plus regulators now
  (§9.5). Plan chapters, not a national rollout.

---

## Sources

* [Supreme Court Nullifies National Lottery Act — LSLGA](https://lslga.org/supreme-court-nullifies-national-lottery-act-national-lottery-regulatory-commission-nlrc-in-landmark-judgement/)
* [Regulation of Lotteries in Nigeria: Review of the Supreme Court's Decision — Chambers and Partners](https://chambers.com/articles/regulation-of-lotteries-in-nigeria-review-of-the-supreme-court-s-decision-and-its-implications)
* [LSLGA — Licensing Categories and Compliance](https://lslga.org/licensing-categories-and-compliance/)
* [Navigating Nigeria's New Gaming Laws: A 2026 Regulatory Guide — Mondaq](https://www.mondaq.com/nigeria/gaming/1807818/navigating-nigerias-new-gaming-laws-a-2026-regulatory-guide-for-sports-betting-and-casino-operators)
* [The Lagos State Lotteries and Gaming Authority Law, 2021 — G. Elias](https://www.gelias.com/images/The_Lagos_State_Lotteries_and_Gaming_Authority_Law_2021_Top_Ten_Significant_Provisions_-compressed.pdf)
* [Nigeria Tax Act, 2025 has been signed – highlights — EY](https://www.ey.com/en_gl/technical/tax-alerts/nigeria-tax-act-2025-has-been-signed-highlights)
* [Nigeria — Corporate — Withholding taxes — PwC Tax Summaries](https://taxsummaries.pwc.com/nigeria/corporate/withholding-taxes)

Regulatory and tax points are research, not legal advice. Both need a Lagos
practitioner's opinion before the framework is published.
