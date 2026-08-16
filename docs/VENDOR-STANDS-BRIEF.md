# Vendor stands — how it would work, and how it would be managed fairly

**Status:** brief for decision. Nothing built yet.
**Scope:** vendors, exhibitors and food traders taking a stand at an Africa GATES event —
and **selling goods from it**.

> **Revision note.** The first draft of this brief modelled a stand as *inventory the organiser
> sells*, reusing the ticket-tier machinery, and largely stopped at the stand fee. That was the
> wrong centre of gravity. **A vendor's business is selling items.** The stand fee is the
> smaller half of the relationship and the easier half to build; the item sales are where the
> money, the fairness questions and the legal exposure actually live. This revision reorganises
> the brief around that, and corrects a factual error flagged in §4.

---

## 1 · What a vendor actually is

A ticket buyer is a **customer of the event**. A vendor is a **business trading at the event**.
The platform's relationship with a vendor has two distinct legs, and conflating them is what
produced the first draft's error:

| Leg | Who sells | Who buys | What the platform does today |
|---|---|---|---|
| **A — the stand** | Organiser | Vendor | Nothing yet, but closely resembles ticketing |
| **B — the goods** | Vendor | Attendee | **Nothing, and nothing that resembles it** |

Leg A is a solved shape: limited inventory, a price, a waiting list, a payment, a pass at the
door. Leg B is a **marketplace** — many sellers, each with their own catalogue, stock, money and
liability. The platform has a shop, but it is a single-seller shop selling Africa GATES' own
merchandise. That distinction is the whole engineering story, and §4 sets out exactly where it
bites.

**The first question is therefore not "how do we allocate stands" but "does the platform touch
item sales at all".** Three defensible answers:

| | Model | What the attendee does | Platform's cut | Platform's liability for goods |
|---|---|---|---|---|
| **1** | **Stand fee only** | Pays the vendor directly — cash, vendor's own POS | Stand fee only | None |
| **2** | **Listed catalogue** | Browses vendor items on the event page, buys at the stand | Stand fee (+ optional listing fee) | Thin — but it is publishing claims about goods |
| **3** | **Full marketplace** | Buys vendor items through the platform | Stand fee + commission on sales | Real, and mostly non-negotiable |

Model 1 is a weekend of work on top of the existing event machinery. Model 3 is a different
company. Model 2 is the interesting middle and is where I would start — it gives vendors real
value (discovery, being findable before the event) without the platform taking custody of
other people's money or standing behind other people's goods.

**Everything downstream of this brief depends on which of the three is chosen.** The rest of the
document is written to be read as: §5 applies to all three; §6 applies to 2 and 3; §4 and §7
explain why 3 is much larger than it looks.

---

## 2 · The core fairness problem, stated plainly

Ticketing is fair by construction: the rule is "first to pay, while seats last", everyone can
see it, and nobody has to trust anybody. Stands are not like that. There are usually more
applicants than pitches, and an organiser has a legitimate interest in the *mix* — twelve
jewellery stalls and no food is a worse event than eight and four, even if the twelve applied
first.

So the moment you allow curation, you have created a place where somebody's cousin can get a
stand. Every rule in §5 exists to make that visible if it happens.

**The single most important property: the rule is fixed and published before anybody knows who
applied.**

But once vendors are *selling items* there is a second fairness problem, and it does not go away
after allocation:

**Whoever controls the ordering of a vendor list controls who earns money.** Allocation is a
one-off decision with a small number of losers who know they lost. Ranking is a decision the
platform re-makes every time a page loads, silently, for the whole event. It is the larger
fairness surface and the first draft did not mention it at all. §6 is about that.

---

## 3 · The lifecycle

| # | Stage | Who owns it | What the platform does |
|---|---|---|---|
| 1 | **Publish the call** | Organiser | Locks criteria, prices, stand count and closing date. Public page. |
| 2 | **Applications** | Vendor | Form + documents + **what they intend to sell**. Editable until close. |
| 3 | **Eligibility check** | Event admin | Objective pass/fail. Documents present and in date. |
| 4 | **Selection** | Panel of 2 | Scored against the published criteria. Recorded. |
| 5 | **Offers** | Platform | Offer with a stated hold window. Declines fall to the waiting list. |
| 6 | **Payment** | Vendor | Stand fee. Full cost shown before commitment. |
| 7 | **Assignment** | Event admin | Pitch number, load-in slot, staff passes. |
| 8 | **Catalogue** *(models 2–3)* | Vendor | Lists items: name, price, photo, stock. Reviewed before publish. |
| 9 | **The day** | Door team | Vendor passes scanned like tickets. |
| 10 | **Sales** *(model 3)* | Platform | Orders, per-vendor money, refunds, disputes. |
| 11 | **Settlement** | Platform | Statement per vendor: stand fee, commission, payout. |

### The gate that matters: eligibility ≠ selection

- **Eligibility is objective and checkable.** Right category, documents present, insurance in
  date, not previously removed for cause. A machine could do it, and a rejection here is
  explainable in one sentence.
- **Selection is judgement.** Quotas, quality and mix — and therefore the only stage that needs
  a recorded rationale.

Collapsing them into one "we reviewed your application" decision is what makes rejections feel
arbitrary, because a vendor cannot tell whether they failed a rule or a taste.

---

## 4 · What the code actually supports today

This section exists because the first draft asserted reuse that does not exist. Verified against
the repository:

**Correction to the first draft.** It claimed stand fees would be "split to the organiser's
Paystack subaccount". **There is no per-organiser subaccount.** `PaymentDestination::STREAMS` is
three fixed, platform-wide streams — `events`, `shop`, `votes` — each with *one* subaccount set
in admin settings. Money is routed by *what kind of thing was sold*, never by *who sold it*.
Per-organiser and per-vendor routing are both new work, and the second is the harder one.

**What genuinely reuses, for leg A (the stand):**

| Need | Existing mechanism | Fit |
|---|---|---|
| Stand types with price and limit | Ticket tiers | Good |
| Colour-coding stand categories | `EventTierPalette` (accent-derived) | Good |
| More applicants than pitches | Event waiting list, timed offers | Good |
| Cancellation and refunds | `EventRefundPolicy` + refunds queue | Good |
| Staff at the gate with no account | `EventScanPass` door passes | Good |
| Discounts for returning vendors | Discount codes | Good |

**What does not reuse, for leg B (the goods):**

- **`gates_products` has no owner column.** Slug, name, category, price, stock, cover, delivery
  regions — and no seller. Every product in the system is the platform's own. Multi-seller means
  an owner on the product, and an ownership check on every read and write path that touches it.
- **`gates_orders` is one basket, one payment.** An `items_json` blob, a single
  `subtotal_naira`, a single `provider_ref`. There is nowhere to record that ₦8,000 of a
  ₦20,000 order belongs to vendor A and ₦12,000 to vendor B. Per-vendor order lines are new.
- **Money cannot be split per seller.** One Paystack transaction settles to at most one
  subaccount. A basket spanning three vendors is either three separate payments (three
  checkouts, three failure modes, an attendee who can abandon after paying vendor A) or one
  payment into a platform account plus a **ledger and a payout run** — which means holding other
  people's money, with everything that implies.
- **Shipping assumes delivery, not collection.** `ShopShipping` quotes by region against
  `delivery_regions`. Items bought at a stand are collected there; items bought online before an
  event may be collected *at* the event. Collection is a fulfilment mode that does not exist.
- **Variants and stock do reuse well.** `gates_product_variants` (label, axis, price delta,
  per-variant stock) and `gates_stock_alerts` are seller-agnostic and would carry over almost
  unchanged. This is the one part of leg B that is genuinely nearly free.

**The honest summary:** model 3 is not "the shop, but for vendors". The catalogue half is
reusable; the money half is a rebuild, and it is the largest single item in this brief —
larger than the whole selection-and-fairness apparatus in §5.

---

## 5 · Fairness in allocation — who gets a stand

**a. Published before the window opens, unchangeable after.** Criteria, weights, stands per
category, price of each stand type, closing date. Changing any of them after applications open
means reopening the window.

**b. Category quotas, published as numbers.** "6 food, 10 craft, 4 fashion, 2 services." This
prevents a monoculture *without* a secret hand on the scale — the constraint is the published
number, not a preference applied later.

**c. Two independent scorers per application, then reconcile.** Neither sees the other's score
first; a gap beyond a threshold goes to a third. Same shape as the platform's existing judging,
for the same reason: one person's score is an opinion, two agreeing is evidence.

**d. Ties break on earliest *complete* application.** Not earliest submission — otherwise the
fastest incomplete form beats the careful one.

**e. Conflicts declared and recorded.** Anyone connected to an applicant declares it, does not
score that application, and the abstention is part of the record. The point is not that
connections are disqualifying; it is that they are *visible*.

**f. Selection completes before payment is taken.** Chosen, then invited to pay — not the
reverse. This is the structural block on pay-to-jump: at the moment of decision, nobody has paid.

> A premium pitch — corner, main entrance, power included — may legitimately cost more. That is
> a **product difference within a stand type**, priced and published like any other. It is not a
> different chance of being selected, and the two must never be bundled.

**g. Every applicant gets an outcome with a reason**, drawn from the published criteria. Silence
is what makes people assume the worst, and it is free to avoid.

**h. A real waiting list, offered in order** — ordered queue, timed offer, unclaimed offers
return to the front.

**i. Accessible pitches are inventory, not a favour.** A stated number of step-free stands,
marked on the map and requestable in the form.

---

## 6 · Fairness in trading — the part the "items" correction adds

Applies to models 2 and 3. These are the rules that decide whether vendors trust the platform
*after* they have been let in.

**a. The default order of any vendor or item list is published and neutral.** Pick one — random
per session, alphabetical, or category-grouped — say which, and hold it. An unexplained ranking
is indistinguishable from a sold one.

**b. Paid promotion, if it exists, is labelled and capped.** Selling visibility is legitimate;
selling it invisibly is not. If a vendor paid to be at the top, the attendee should be able to
see that, and there should be a stated maximum number of promoted slots so the organic list is
not decoration.

**c. The platform must not rank its own shop above its vendors.** Africa GATES sells its own
merchandise. The moment platform items and vendor items appear in one list, any ranking that
favours the platform is self-preferencing — the single most reliable way for a marketplace to
lose the room, and increasingly an enforcement matter for the FCCPC rather than a commercial
choice. Simplest safe rule: **platform merchandise sits in its own clearly-labelled section and
never competes in the vendor list.**

**d. Commission is one published rate per category.** Not negotiated per vendor. If a
differential exists (a lower rate for first-time vendors, say) it is a published rule anyone can
qualify for, not a deal.

**e. Category exclusivity is a product, and a dangerous one.** "Only coffee seller at the event"
is sellable and sometimes right — but it converts an allocation decision into a permanent
commercial advantage. If it is offered: publish that it exists, publish the price, and never
grant it retroactively to a vendor already selected on mixed criteria.

**f. The vendor owns their catalogue; the platform owns the standard.** Vendors set their own
prices and stock. The platform may refuse a listing against published rules (prohibited goods,
missing certification, misleading claims) and must say which rule was applied.

**g. Consumer redress has a named owner *before* launch.** If an attendee buys a faulty item
through the platform, someone refunds them. In model 3 that will in practice be the platform,
because the platform took the money — so the vendor agreement needs a chargeback and
returns clause, and the commission needs to price the risk. Deciding this after the first
dispute means deciding it badly.

**h. Vendors get their own sales data.** Their orders, their stock, their payouts. A vendor who
cannot reconcile their own takings will not come back.

---

## 7 · Money

**Leg A — the stand fee.** Reuses the ticketing path, with the §4 correction: routing today is
the platform-wide `events` stream, so paying organisers their share is either a new per-organiser
subaccount or a manual settlement.

- **The full cost is shown before commitment** — stand fee, power, table hire, platform fee,
  itemised on one screen. Hidden add-ons discovered at payment are the most reliable way to lose
  a booking, and are simply not honest.
- **Deposit now, balance later** for events far out. The deposit is what makes a no-show cost
  something; without it the waiting list is theatre.
- **A published cancellation ladder, symmetric.** Whatever window applies to the vendor applies
  to the organiser: *if the organiser cancels a stand, the vendor is refunded in full regardless
  of date.* A one-sided cancellation policy is the clearest signal that a marketplace is not
  even-handed.
- **Failed refunds are already visible** in the refunds queue on the event's tickets screen;
  vendor refunds join the same list rather than becoming a second thing nobody watches.

**Leg B — item sales (model 3 only).** The genuinely new money, and the reason to think hard
before choosing model 3:

- **One basket, many sellers** needs either split payments or a ledger. A ledger means the
  platform holds funds it does not own, which is a regulatory posture, not a feature.
- **A payout run** — schedule, minimum, failure handling, and a statement per vendor.
- **A held-back window.** Paying out the instant an order is marked paid means paying out before
  the refund window closes. A short hold is normal and must be published, because to a vendor an
  unexplained delay in their money is the platform stealing.
- **Commission recorded per line, not per order**, or category rates become unreconcilable.

---

## 8 · Obligations and documents

Collected at application, checked before the offer is confirmed — **not on the day**, which is
when a missing certificate becomes the organiser's problem instead of the vendor's.

| Document | Who needs it | Notes |
|---|---|---|
| Public liability insurance | All | Expiry stored; an expired policy blocks confirmation |
| Food hygiene / handler certificate | Food and drink | Non-negotiable gate, not a scoring criterion |
| CAC registration or equivalent | All trading vendors | Sole traders: identity + address |
| **NAFDAC registration** | **Packaged food, drink, cosmetics, supplements** | **Follows directly from selling items rather than services — applies per product, not per vendor** |
| **SON conformity** | **Regulated manufactured goods** | **Where applicable to the category** |
| Power requirement | Any vendor drawing power | Drives pitch assignment, not price alone |

The NAFDAC and SON rows are what the "items" correction adds. A vendor *providing a service*
needs to be insured; a vendor *selling packaged consumables* is subject to product regulation
that attaches to each product line. In models 2 and 3 the platform is publishing those product
listings, which is what makes this the platform's problem and not only the organiser's.

**A prohibited-goods list, published.** Counterfeits, unlicensed medicines, anything the venue
bans. Cheap to write now, extremely expensive to improvise on the day.

The platform stores expiry dates and refuses confirmation on a lapsed document. It does **not**
verify authenticity, and the brief says so out loud — the platform is a record-keeper here, not
an underwriter, and pretending otherwise creates a liability nobody priced.

---

## 9 · The day

- **Vendor staff get door passes, not admin accounts.** The existing time-boxed scan-pass
  mechanism covers exactly this: works between two times for one event, revocable instantly,
  cannot see the guest list or the money.
- **Load-in slots staggered by pitch number**, published with the offer. Every vendor arriving
  at once is the default failure of every market ever run.
- **A stand is checked in like a ticket.** Same three verdicts — admit, already in, refuse — so
  the door team learns one interaction, not two.
- **No-shows release at a stated hour**, and the waiting list is offered the space if there is
  time to use it.
- **Collection, not delivery** *(models 2–3)*. An item bought online before the event is
  collected at the stand, which needs a collection code the vendor can verify — the ticket QR
  mechanism is the obvious reuse, and the only part of leg B's fulfilment that is nearly free.
- **Assume the venue wifi fails.** A vendor whose till is the platform cannot trade when the
  network drops. This alone is a strong argument for model 2 over model 3 for a first event.

---

## 10 · Honest risks

- **Model 3 is a marketplace, with a marketplace's obligations.** Holding other people's money,
  standing behind other people's goods, and handling other people's disputes. It is buildable,
  but it should be chosen deliberately and not arrived at by increment.
- **Self-preferencing is the reputational landmine.** The platform sells merchandise and would be
  ranking competitors. §6c is not optional politeness.
- **Curation is a reputational surface.** A rejection that looks like favouritism is a public
  story in a way a sold-out ticket never is. The decision record is the only thing that answers
  the accusation.
- **The platform will be read as endorsing its vendors**, food and cosmetics especially. Document
  collection must be described as record-keeping, in the vendor terms and on the public page.
- **Deposits and item refunds create refund volume.** Vendor money is larger than ticket money
  and will be chased harder.
- **Quotas are contestable.** Publishing "6 food stands" invites argument about why six — a much
  better argument to have in public, before applications, than in private afterwards.
- **Scoring costs organiser time.** Two scorers on 80 applications is real work. If that is not
  affordable, the honest answer is first-come with category caps, published as such — *not*
  curation performed casually and described as a panel.

---

## 11 · Suggested phasing

**Phase 1 — sell stands (model 1).** Stand types as tiers, an application form, manual selection,
payment through the existing gateway path, vendor door passes. Fairness rules published and
followed by hand. Ships quickly because most of it exists.

**Phase 2 — list what vendors sell (model 2).** A vendor catalogue that is a *discovery* surface,
not a checkout: items, photos, prices, which stand to find them at. Reuses products and variants;
adds an owner column and a vendor-facing editor. Publishes the ranking rule from §6a. This is the
best value-per-unit-of-risk in the whole brief.

**Phase 3 — make selection defensible.** Scoring, quotas, conflict declaration, the decision
record, the outcome email quoting the criteria. Earns the word "fair" rather than asserting it.

**Phase 4 — the operational layer.** Stand map and pitch assignment, document expiry tracking,
load-in scheduling, vendor self-service portal, settlement statements.

**Phase 5 — sell vendors' items (model 3),** *only if the volume justifies it.* Per-vendor order
lines, split or ledgered money, payout runs, disputes, collection codes.

---

## 12 · Decisions needed before any of this starts

1. **Model 1, 2 or 3** — stand fee only, listed catalogue, or full marketplace? Everything else
   follows from this, and it is the decision the "vendors sell items" correction actually poses.
2. **Curated or first-come?** Both are defensible; only one is affordable at scale. Determines
   whether Phase 3 exists.
3. **If model 3: does the platform hold vendor funds**, or does each vendor get their own
   Paystack subaccount and their own checkout? The second is far less powerful and far less
   dangerous.
4. **Who scores?** Organiser staff, or an independent pair? Independence costs money and buys the
   answer to the favouritism question.
5. **Deposit or full payment up front for the stand?**
6. **Does the platform take a share of stand fees, a commission on item sales, or a flat listing
   fee?** These have very different incentives: the first fills the room, the second aligns the
   platform with vendors actually trading well, the third is easiest to explain.
7. **What is the cancellation ladder**, and is the organiser held to the symmetric version?
8. **Who refunds an attendee who bought a faulty item?** Needs an answer before the first sale,
   not after the first dispute.
