# Vendor stands at Africa GATES events

### A specification for how stands are sold, allocated and traded from

| | |
|---|---|
| **Document** | Vendor stands — operating specification |
| **Version** | 2.0 · supersedes `VENDOR-STANDS-BRIEF.md` |
| **Status** | For decision. Section 12 lists what must be settled before build begins. |
| **Audience** | Africa GATES leadership; event organisers; prospective vendors; counsel |
| **Scope** | Vendors, exhibitors and food traders taking a stand at an Africa GATES event, and selling goods from it |

---

## Contents

1. [Executive summary](#1--executive-summary)
2. [What a vendor is, and the choice that governs everything](#2--what-a-vendor-is-and-the-choice-that-governs-everything)
3. [The lifecycle](#3--the-lifecycle)
4. [What the platform already does](#4--what-the-platform-already-does)
5. [Allocation: who gets a stand](#5--allocation-who-gets-a-stand)
6. [Conduct: trading once you are in](#6--conduct-trading-once-you-are-in)
7. [Money](#7--money)
8. [Eligibility, documents and the vendor account](#8--eligibility-documents-and-the-vendor-account)
   - [8.0 A vendor can be a person](#80-a-vendor-can-be-a-person)
9. [Event day](#9--event-day)
10. [**Worked scenarios**](#10--worked-scenarios)
11. [Risks](#11--risks)
12. [Phasing and decisions required](#12--phasing-and-decisions-required)

---

## 1 · Executive summary

Africa GATES events draw traders who want to sell to the people attending. Selling them
stand space is straightforward. **Deciding which of them gets one is not**, and neither is
what happens once they are trading — and those two things are where a stands programme
either builds the organisation's reputation or costs it.

This specification takes three positions.

**First, a vendor's business is selling goods, not renting a table.** The stand fee is the
smaller half of the relationship. Every serious question — what they may sell, who is liable
when a product harms somebody, whose customers they are — sits on the goods side.

**Second, the platform should never hold a vendor's takings.** Money splits at the payment
gateway into an account in the vendor's own registered name. This is now built and running
for donation partners; §4 lists what the vendor path inherits. It removes an entire class of
regulatory exposure and an entire class of dispute, at no cost to the vendor experience.

**Third, allocation must be decided by a published rule fixed before anybody applies.** There
will be more applicants than pitches. The moment curation exists, so does the possibility
that somebody's cousin got a stand — and the only defence is a rule that was written down
before anybody knew who was asking.

The recommended starting point is **Model 2 in §2**: sell the stand, list what vendors sell
for discovery, and let the transaction happen at the stall. It gives vendors real value
without the platform taking custody of money or standing behind goods it has not seen.

---

## 2 · What a vendor is, and the choice that governs everything

A ticket buyer is a **customer of the event**. A vendor is a **business trading at the
event**. The relationship has two legs:

| Leg | Who sells | Who buys | Nature |
|---|---|---|---|
| **A — the stand** | Organiser | Vendor | Limited inventory sold at a price. Resembles ticketing. |
| **B — the goods** | Vendor | Attendee | A marketplace: many sellers, own stock, own money, own liability. |

Leg A is a solved shape. Leg B is where the work is, and how far into it Africa GATES goes is
the first decision:

| | Model | The attendee's experience | Platform revenue | Platform liability for goods |
|---|---|---|---|---|
| **1** | **Stand fee only** | Pays the vendor directly — cash or the vendor's own POS | Stand fee | None |
| **2** | **Listed catalogue** *(recommended)* | Browses vendor items on the event page; buys at the stall | Stand fee, optional listing fee | Thin — but it is publishing claims about goods |
| **3** | **Full marketplace** | Buys vendor items through the platform | Stand fee + commission | Real, and largely non-negotiable |

**Model 2 is recommended to start.** It gives vendors the thing they actually want — being
findable before the doors open — without the platform holding money for goods it cannot
inspect or handling disputes about items it never saw. Model 3 remains available later; §4
explains why it is now a smaller step than it first appeared.

---

## 3 · The lifecycle

| # | Stage | Owner | What happens |
|---|---|---|---|
| 1 | Publish the call | Organiser | Criteria, prices, stand count, category quotas and closing date are locked and published |
| 2 | Applications | Vendor | Form, documents, and what they intend to sell. Editable until close. |
| 3 | Eligibility | Event admin | Objective pass/fail against published requirements |
| 4 | Selection | Panel of two | Scored against published criteria; rationale recorded |
| 5 | Offers | Platform | Offer with a stated hold window; declines fall to the waiting list |
| 6 | Account | Vendor | Settlement account resolved and verified; subaccount created (§8) |
| 7 | Payment | Vendor | Stand fee, full cost shown before commitment |
| 8 | Assignment | Event admin | Pitch number, load-in slot, staff passes |
| 9 | Catalogue *(2–3)* | Vendor | Items listed; reviewed before publication |
| 10 | Event day | Door team | Vendor passes scanned like tickets |
| 11 | Settlement | Platform | Statement per vendor |

### The gate that matters: eligibility is not selection

- **Eligibility is objective.** Right category, documents present and in date, insurance
  valid, not previously removed for cause. A rejection here is explainable in one sentence.
- **Selection is judgement.** Quotas, quality and mix — and therefore the only stage that
  needs a recorded rationale.

Collapsing them into a single "we reviewed your application" is what makes rejections feel
arbitrary, because an applicant cannot tell whether they failed a rule or a preference.

---

## 4 · What the platform already does

Verified against the codebase. This section replaces speculation with an inventory.

**Reusable for the stand itself:**

| Requirement | Existing mechanism |
|---|---|
| Stand types with price and limit | Ticket tiers |
| Colour-coding stand categories | `EventTierPalette`, derived from the event accent |
| More applicants than pitches | Event waiting list with timed offers |
| Cancellation and refunds | `EventRefundPolicy` + the refunds queue |
| Staff at the gate with no account | `EventScanPass` door passes |
| Discounts for returning vendors | Discount codes |

**Built for donation partners and directly reusable** — this was speculative in v1.0 and has
since shipped:

| Requirement | Where it now lives |
|---|---|
| An account in the seller's own registered name, created automatically | `PartnerOrg::attachSubaccount()` |
| Bank-name resolution as an anti-impersonation check | `PaymentService::resolveAccount()` |
| Money split at the gateway; the platform holds nothing | `PaymentDestination::initFieldsForPartner()` |
| A transfer recipient created without storing an account number | `PaymentService::createTransferRecipient()` |
| A seller dashboard scoped to their own rows | `/org` + `OrgAuth` |
| Withdrawal requests on the gateway's own transfer state machine | `OrgPayout` |
| Certificates with expiry tracking | `gates_org_documents` |
| Vetting states, with suspension distinct from rejection | `PartnerOrg::STATUSES` |
| Registry checks that never claim more than was actually done | `RegistryCheck` |
| Publishing a seller's own public page after review | `OrgCampaign` |

**Genuinely new for Model 3:**

- `gates_products` has no owner column. Every product is currently the platform's own.
- `gates_orders` is one basket, one payment — nowhere to record that ₦8,000 of a ₦20,000
  order belongs to vendor A.
- `ShopShipping` quotes delivery by region; goods bought at a stand are collected.

> **Correction carried forward from v1.0.** That draft stated one Paystack transaction can
> settle to at most one subaccount, and concluded that a multi-vendor basket requires a
> ledger and therefore custody of other people's money. **That was wrong.** Paystack's
> Transaction Splits API settles a single transaction across the main account and one or
> more subaccounts. Model 3 is a rebuild of the *order* model, not the *money* model.

---

## 5 · Allocation: who gets a stand

**The governing principle: the rule is fixed and published before anybody knows who applied.**
Everything below enforces it.

**5.1 Published before the window opens, unchangeable after.** Criteria, weights, stands per
category, price per stand type, closing date. Changing any of them after applications open
means reopening the window.

**5.2 Category quotas as published numbers.** "6 food, 10 craft, 4 fashion, 2 services."
This prevents a monoculture without a private hand on the scale: the constraint is the
published number, not a preference applied later.

**5.3 Two independent scorers, then reconciliation.** Neither sees the other's score first; a
gap beyond a stated threshold goes to a third. One person's score is an opinion; two agreeing
is evidence.

**5.4 Ties break on earliest complete application.** Not earliest submission — otherwise the
fastest incomplete form beats the careful one.

**5.5 Conflicts declared and recorded.** Anyone connected to an applicant declares it, does
not score that application, and the abstention forms part of the record. Connections are not
disqualifying; concealment is.

**5.6 Selection completes before payment.** Chosen, then invited to pay. At the moment of
decision, nobody has paid anything — this is the structural block on pay-to-jump.

> A premium pitch — corner, main entrance, power included — may legitimately cost more. That
> is a product difference within a stand type, priced and published like any other. It is
> never a different chance of being selected, and the two must not be bundled.

**5.7 Every applicant receives an outcome with a reason** drawn from the published criteria.
Unsuccessful applicants may request their score.

**5.8 Accessible pitches are inventory, not a favour.** A stated number of step-free stands,
marked on the map and requestable on the form.

**5.9 The SIZE is a published term, locked with the price and the quota.** A vendor who
applied for 3 × 3 m and arrives to find 2 × 2 m was sold something else, and no amount of
goodwill on the morning fixes it. Sizes are chosen from a short list of stock parts — the
hired gazebo and multiples of it — because a market is built from what can actually be
ordered, and a free pair of numbers invites 2.8 m, which fits nothing and is discovered on
build day. Custom remains available for the converted warehouse with odd corners.

### 5.10 The floor budget

**The sum that nobody does.** Forty pitches at 3 × 3 and twelve at 6 × 3 is 576 m² of stands.
In a 500 m² hall with a third of the floor given to aisles, that is not tight — it is
impossible. It is discovered on build morning, by which point every remaining option is a
broken promise: pitches shrunk without notice, or vendors turned away who hold an accepted
offer, or a room the fire officer closes.

The arithmetic takes one line. It does not get done because it lives in a different head from
the one typing quotas, so the platform does it on the same screen, at the moment the quota is
typed:

| | |
|---|---|
| Hall | width × depth, recorded against the call |
| Aisle allowance | default **35%** — circulation, fire lanes, the queue at a servery |
| Sellable floor | hall less the allowance |
| Committed | Σ (quota × pitch area) across every stand type |

Over the sellable floor, the screen says so in square metres and refuses to be quietly
optimistic about it. An unmeasured venue reports as *unmeasured* — never as a comfortable
fit, which would be the most dangerous thing this feature could do.

**The venue is exempt from the lock, and that distinction is the point.** The lock exists to
stop the *rules* changing once you know who applied. How wide the hall is, is not a rule; it
is a fact somebody may measure more carefully next week, or a room the venue may reassign.
Refusing a better measurement protects no applicant — it only guarantees the plan on the
screen stays wrong.

**The block layout is not a site plan, and says so wherever it appears.** It packs rows of
pitches into a plain rectangle. It knows nothing about fire exits, columns, power drops, the
servery, the stage, or which wall the loading door is on. That crudeness is the honest part: a
diagram that *looks* like a fire-safety document while knowing nothing about fire is worse
than no diagram, because somebody forwards it to the venue. It answers one question — is this
plausibly the right order of magnitude, or obviously impossible — and pitches it could not
place are counted rather than dropped.

---

## 6 · Conduct: trading once you are in

Applies to Models 2 and 3. These rules decide whether vendors trust the platform *after*
they have been let in — which is what determines whether they return.

**6.1 The default order of any vendor list is published and neutral.** Random per session,
alphabetical, or category-grouped. Pick one, say which, hold it. An unexplained ranking is
indistinguishable from a sold one.

**6.2 Paid promotion, if offered, is labelled and capped.** Selling visibility is legitimate;
selling it invisibly is not.

**6.3 Africa GATES merchandise does not compete in the vendor list.** The platform sells its
own goods. The moment platform items and vendor items appear in one ranked list, any ordering
that favours the platform is self-preferencing. Platform merchandise sits in its own labelled
section.

**6.4 Commission is one published rate per category**, not negotiated per vendor. A
differential is acceptable if it is a published rule anyone can qualify for.

**6.5 Category exclusivity is a product, and a dangerous one.** "Only coffee seller at the
event" is sellable and sometimes right, but it converts an allocation decision into a
permanent commercial advantage. If offered: publish that it exists, publish the price, and
never grant it retroactively to a vendor already selected on mixed criteria.

**6.6 The vendor owns their catalogue; the platform owns the standard.** Vendors set their
own prices and stock. The platform may refuse a listing against published rules — prohibited
goods, missing certification, misleading claims — and must say which rule was applied.

**6.7 Consumer redress has a named owner before launch.** In Model 3 the platform took the
money, so in practice the platform refunds. The vendor agreement therefore needs a chargeback
and returns clause, and the commission must price that risk.

**6.8 Vendors receive their own sales data.** A vendor who cannot reconcile their own takings
does not come back.

---

## 7 · Money

**7.1 The stand fee.** Full cost shown before commitment — stand fee, power, table hire and
platform fee, itemised on one screen. Hidden add-ons discovered at payment are the most
reliable way to lose a booking.

**7.2 Deposit now, balance later** for events far out. The deposit is what makes a no-show
cost something; without it the waiting list is theatre.

**7.3 A published cancellation ladder, symmetric.** Whatever window applies to the vendor
applies to the organiser: **if the organiser cancels a stand, the vendor is refunded in full
regardless of date.** A one-sided cancellation policy is the clearest signal that a
marketplace is not even-handed.

**7.4 Item sales split at the gateway, never through a platform balance.** The vendor's share
settles into their own subaccount; the platform's commission splits off at source. The
platform is never a custodian of a vendor's takings.

**7.5 A held-back window, published.** Paying out the instant an order is marked paid means
paying out before the return window closes. A short hold is normal and must be stated,
because to a vendor an unexplained delay in their money reads as theft.

**7.6 Commission recorded per line, not per order**, or category rates become unreconcilable.

---

## 8 · Eligibility, documents and the vendor account

Collected at application, verified before the offer is confirmed — **never on the day**, which
is when a missing certificate becomes the organiser's problem instead of the vendor's.

| Requirement | Who | Notes |
|---|---|---|
| Public liability insurance | All | Expiry stored; a lapsed policy blocks confirmation |
| Food hygiene / handler certificate | Food and drink | A gate, not a scoring criterion |
| CAC registration | **Registered businesses only** | Not asked of individuals — see 8.0 |
| Pitch size accepted as published | Every vendor | Locked with the price and quota — see 5.9 |
| Government photo ID | **Individuals and sole traders** | Replaces CAC; also what the pitch is checked against on the day |
| NAFDAC registration | Packaged food, drink, cosmetics, supplements | Attaches per product line, not per vendor |
| SON conformity | Regulated manufactured goods | Where applicable to the category |
| **Settlement account in the vendor's own registered name** | **Every vendor to be paid** | **Resolved at the gateway before acceptance — see 8.1** |
| Power requirement | Any vendor drawing power | Drives pitch assignment |

**A published prohibited-goods list.** Counterfeits, unlicensed medicines, anything the venue
bans. Cheap to write now; extremely expensive to improvise on the day.

### 8.0 A vendor can be a person

**Most of the people who will actually trade at an Africa GATES market are not companies.**
They are one woman with a jollof stall, one man who prints t-shirts, a pair who make leather
sandals. Requiring a CAC registration from all of them would not raise the standard of the
market. It would do two things, both bad: hand every pitch to whoever already has a lawyer,
and push everybody else to borrow somebody else's registration number — which is strictly
worse than having none, because it puts the wrong name on the paperwork at exactly the moment
the paperwork matters.

So an applicant declares themselves a **registered business** or an **individual**, and the
requirements branch on that and only on that. The settlement account, the subaccount, the
dashboard, the document expiries and the vetting states are identical for both.

| | Registered business | Individual / sole trader |
|---|---|---|
| Identity | CAC registration number and certificate | Full legal name and a government photo ID |
| Insurance | Public liability | Public liability |
| Settlement account | In the registered name | In their own name |
| Name comparison | String similarity, legal-form suffixes normalised | **Part-by-part, order-insensitive** — see below |

**There is deliberately no NIN field.** The instinct is to collect a National Identification
Number, and it is resisted for the same reason bank account numbers are not stored: a table of
Nigerians' NINs is a permanent liability under the Nigeria Data Protection Act 2023 and buys
nothing the platform does not already have. Opening a Nigerian bank account requires a BVN,
which requires identity documents — so when the gateway answers *"this account belongs to
NGOZI OKAFOR"*, a regulated institution has already done the identity check, more recently and
better evidenced than a number typed into a form. **That answer is the identity control**, and
approving an individual is refused until it exists.

**The photo ID is asked for anyway, for an operational reason rather than a regulatory one.**
On the morning of the market somebody has to check that the person standing at the pitch is
the person it was allocated to. A registration number does not help with that.

**Why the name comparison had to change.** A Nigerian bank returns `OKAFOR NGOZI CHIOMA`; the
same woman writes *Ngozi Okafor*. Under the organisation rule — character similarity — that
pair scores around 0.5, squarely inside the range the platform treats as *somebody is
collecting into a stranger's account*. Applied across a vendor list it would flag nearly every
honest sole trader, and a warning that fires on everybody is a warning reviewers learn to
click past. That is how it stops working for the one case it exists for.

So a person's name is compared as a **set of parts**: order is irrelevant, an extra middle name
on the bank's side is expected, a single initial matches the part it abbreviates (`OKAFOR N C`
is a real thing a bank returns), and titles are stripped (`MRS` is not a name). The score
weights recall over precision — every part the applicant claimed must appear on the account,
while parts the account carries that they did not mention cost comparatively little, because
claiming a name that is not on the account is the suspicious direction. A shared surname alone
does not clear the bar: two brothers, or somebody using a relative's account, is precisely the
case a reviewer must still be shown.

### 8.1 The vendor account, created automatically

Reuses `PartnerOrg::attachSubaccount()` verbatim. On acceptance:

1. **The account number is resolved before anything is created.** The gateway returns the
   name the bank holds for that number, which is compared with the registered business name
   and stored with the comparison score.
2. **A subaccount is created in the vendor's own name**, with the platform's commission as
   its percentage charge, so money splits at source.
3. **A transfer recipient is created in the same request** — the only moment the account
   number is legitimately in hand — and only its code is retained.
4. **The account number is never stored.** Bank code, last four digits and the resolved name
   are enough for a human to recognise the account.
5. **A dashboard login is issued**: `owner` can move money, `viewer` can only read.

**Why this matters more for vendors than for donation partners.** A donation partner receives
money the platform promised nothing about. A vendor receives money an attendee paid for
goods, so the account must be correct before the first sale rather than before the first
payout. There is no window in which to fix it quietly. The gateway's own KYC does part of the
work: a subaccount requires an account in the business's own name, so a vendor who cannot
produce one is filtered out before any vetting code runs.

### 8.2 The platform's limit, stated out loud

Africa GATES is a **record-keeper and a gatekeeper, not an auditor or an underwriter**. It
verifies that documents exist, are in date, and name the applicant. It does not verify
authenticity, and it does not inspect goods. This appears in the vendor agreement and on the
public page in those words.

---

## 9 · Event day

- **Vendor staff receive door passes, not admin accounts.** Time-boxed, revocable instantly,
  cannot see the guest list or the money.
- **Load-in slots staggered by pitch number**, published with the offer. Every vendor
  arriving at once is the default failure of every market ever run.
- **A stand checks in like a ticket** — admit, already in, refuse — so the door team learns
  one interaction rather than two.
- **No-shows release at a stated hour**, and the waiting list is offered the space if there
  is time to use it.
- **Collection, not delivery** (Models 2–3): an item bought online is collected at the stall
  against a code the vendor verifies. The ticket QR mechanism covers this.
- **Assume the venue network fails.** A vendor whose till is the platform cannot trade when
  the signal drops. This alone argues for Model 2 at a first event.

---

## 10 · Worked scenarios

These are the situations that decide whether the rules above hold under pressure. Each states
the situation, what the specification requires, and why.

### 10.1 The oversubscribed category

> *Applications close for a 40-stand event. Craft is a published quota of 10 and has 34
> applicants, several of them excellent. Food is a quota of 6 and has 4 applicants.*

**What happens.** Craft is scored and the top 10 are offered; the remaining 24 go onto the
waiting list in scored order. The two unfilled food places **do not** become craft places.

**Why.** The quota was published before anybody applied, and it is what makes the mix a
constraint rather than a preference. Reallocating it mid-round retroactively changes the rule
34 people applied under. If food is repeatedly undersubscribed, the quota is wrong for next
time — which is a decision to take in public, before the next window opens.

### 10.2 The organiser's cousin

> *An organiser's cousin applies. Their application is genuinely strong.*

**What happens.** The organiser declares the connection, does not score that application, and
the abstention is recorded. The remaining two scorers proceed normally. If selected, the
cousin gets a stand and the record shows exactly how.

**Why.** Connections are not disqualifying — a small sector means everybody knows everybody,
and a rule that excluded relatives would exclude half of Lagos. Concealment is the problem.
The recorded abstention is what turns "your cousin got a stand" from an accusation into a
documented, answerable fact.

### 10.3 The account in the wrong name

> *"Adaeze Foods" is accepted. Their settlement account resolves to "OKAFOR CHINEDU JOSEPH".*

**What happens.** Onboarding creates the subaccount and stores the name comparison, which
scores low and flags for review. The vendor is not rejected — they are asked. Two answers are
common and both are fine: a sole trader banking personally, or a typo. A third answer — the
account belongs to somebody unconnected to the business — stops the acceptance.

**Why.** A weak name match is a question, not a verdict. Refusing outright would push
legitimate sole traders to supply somebody else's account details, which is worse. But money
must never settle to an account nobody has explained.

**Since 8.0 this scenario is rarer, on purpose.** "A sole trader banking personally" is no
longer an anomaly the reviewer has to guess at — the applicant said which they were on the
form, and an individual's account is compared part-by-part against their own name, so
`OKAFOR CHINEDU JOSEPH` against *Chinedu Okafor* now scores strong and never reaches a human.
What still reaches one is the case worth reaching one: a name on the account that the
applicant never claimed.

### 10.4 The certificate that lapsed last month

> *A food vendor's hygiene certificate expired three weeks before the event. Nobody noticed.*

**What happens.** It does not reach the day. `gates_org_documents` stores the expiry, and a
lapsed document blocks confirmation — the vendor is told at the point the offer is confirmed,
which leaves weeks to renew.

**Why.** A missing certificate discovered on the morning is the organiser's crisis, not the
vendor's: the stall is built, the attendees are arriving, and the only options are to let it
trade uncertified or leave a hole in the room. Expiry tracking exists to move that decision
back by a month.

### 10.5 One basket, two vendors, one refund *(Model 3)*

> *An attendee buys a ₦12,000 bag from vendor A and ₦8,000 of soap from vendor B in one
> checkout. The bag arrives damaged.*

**What happens.** The order carries per-vendor lines, so the refund is against vendor A's
₦12,000 only. The platform refunds the attendee, reverses its commission on that line, and
recovers the vendor's share from their next settlement. Vendor B is untouched and never
learns of it.

**Why.** This is the scenario that makes per-vendor order lines non-optional in Model 3. A
single `subtotal_naira` cannot express a partial refund across two sellers, and the workaround
— refunding the whole basket — punishes vendor B for vendor A's packaging.

### 10.6 The vendor who does not turn up

> *A vendor with a paid stand does not arrive. Load-in closed two hours ago.*

**What happens.** The pitch releases at the stated hour and the waiting list is offered it if
there is time to use it. The vendor's deposit is not refunded, per the published ladder.

**Why.** The deposit is the only thing that makes a no-show cost something. Without it, the
waiting list is theatre and the room has a hole in it that every attendee can see.

### 10.7 Suspended mid-event

> *At 2pm the CAC restricts a vendor's trustees. The event runs until 9pm.*

**What happens.** Suspension takes effect on the next request: their listing stops accepting
orders at the gateway call, not at the next page render. Sales already completed stand. Their
settlement is held pending advice.

**Why.** `PartnerOrg::canReceive()` is checked at the moment money moves, precisely so a
suspension applies within seconds rather than whenever a cache expires. Holding settled money
is a separate decision and should involve counsel — the specification's job is to make
stopping instant, not to decide the disposal.

### 10.8 Counterfeit goods on a stall

> *An attendee reports that a vendor is selling counterfeit branded trainers.*

**What happens.** Counterfeits are on the published prohibited-goods list, so this is a rule
breach rather than a judgement call. The listing comes down, the vendor is suspended, and the
stall is closed by the organiser under the vendor agreement. Attendees who bought are pointed
to the refund path in 6.7.

**Why.** The published list is what makes the decision fast. Without it, the argument at 3pm
on a Saturday is about whether counterfeits were ever prohibited, which is not an argument to
have while a queue forms.

### 10.9 The event is cancelled

> *Two days out, the venue floods. The event does not happen.*

**What happens.** Every vendor is refunded in full regardless of the cancellation ladder,
because the ladder is symmetric and the organiser cancelled. Refunds go through the same
queue as ticket refunds, and failures are visible there.

**Why.** 7.3 exists for exactly this. A cancellation policy that binds only the vendor is the
clearest possible signal that a marketplace is not even-handed, and it is noticed precisely
when goodwill matters most.

### 10.10 A vendor asks why they were rejected

> *An unsuccessful applicant writes: "we have traded at every event for three years."*

**What happens.** They receive their score against the published criteria, and the category
quota that was published before applications opened. If they scored 11th of 34 in a category
of 10, that is what the letter says.

**Why.** This is the entire purpose of §5. A rejection that can be explained with a number
and a published rule is a disappointment. One that cannot is a story about favouritism, and
it travels.

---

## 11 · Risks

| Risk | Consequence | Mitigation |
|---|---|---|
| **Curation reads as favouritism** | A public story that outlives the event | The §5 record: published criteria, two scorers, declared conflicts, scores on request |
| **Self-preferencing** | Vendors conclude the deck is stacked | 6.3 — platform merchandise never competes in the vendor list |
| **The platform is read as endorsing vendors** | Liability for goods it never saw | 8.2, in the agreement and on the public page |
| **Scoring costs organiser time** | Two scorers × 80 applications is real work | If unaffordable, run first-come with category caps and *say so* — not curation performed casually and described as a panel |
| **Model 3 obligations arrive by increment** | A marketplace nobody decided to become | Choose the model deliberately at §12.1 |
| **Deposits and item refunds create volume** | Vendor money is chased harder than ticket money | Existing refunds queue; published hold window |
| **Quotas are contestable** | Argument about why six food stands | Better to have that argument in public, before applications, than in private afterwards |

---

## 12 · Phasing and decisions required

### Phasing

| Phase | Scope | Notes |
|---|---|---|
| **1** | Sell stands (Model 1) | Stand types as tiers, application form, manual selection, existing payment path, vendor door passes. Ships quickly. |
| **2** | List what vendors sell (Model 2) | Discovery, not checkout. Adds an owner column and a vendor editor; reuses products and variants. Best value per unit of risk. |
| **3** | Make selection defensible | Scoring, quotas, conflict declaration, decision record, outcome letters. Earns the word "fair" rather than asserting it. |
| **4** | Operational layer | Stand map, pitch assignment, document expiry, load-in scheduling, settlement statements. |
| **5** | Sell vendors' items (Model 3) | Only if volume justifies it. Per-vendor order lines, split money, disputes, collection codes. |

### Decisions required before build

1. **Model 1, 2 or 3?** Everything follows from this.
2. **Curated or first-come?** Both are defensible; only one is affordable at scale. Determines whether Phase 3 exists.
3. **Who scores?** Organiser staff or an independent pair. Independence costs money and buys the answer to the favouritism question.
4. **Deposit or full payment for the stand?**
5. **Stand-fee share, sales commission, or flat listing fee?** These have materially different incentives.
6. **What is the cancellation ladder**, and is the organiser held to the symmetric version?
7. **Who refunds an attendee who bought a faulty item?** Needs an answer before the first sale, not after the first dispute.
8. **Is category exclusivity offered at all?** (6.5)

---

*Prepared for Africa GATES. Sections 4, 5.9–5.10, 8.0 and 8.1 describe code that exists and is under test;
everything else is proposal.*
