# Vendor stands — how it would work, and how it would be managed fairly

**Status:** brief for decision. Nothing built yet.
**Scope:** vendors, exhibitors and food traders taking a stand at an Africa GATES event.

---

## 1 · What this is

An event sells tickets to people who come to *watch*. A vendor stand sells space to people who
come to *trade*. They are different products with different money, different obligations and a
different failure mode — an attendee who does not turn up costs nobody anything, while a vendor
who does not turn up leaves a hole in the room that everyone can see.

The platform already runs the harder half of this: limited inventory, a waiting list, gateway
payments split to an organiser's subaccount, an enforceable refund policy, and time-boxed door
passes for people with no account. What is genuinely new is **selection** — deciding *who* gets
a stand when there are more applicants than pitches — and that is the part where fairness is
either designed in or absent.

---

## 2 · The core fairness problem, stated plainly

Ticketing is fair by construction: the rule is "first to pay, while seats last", everyone can
see it, and nobody has to trust anybody. Stands are not like that. There are usually more
applicants than pitches, and an organiser has a legitimate interest in the *mix* — twelve
jewellery stalls and no food is a worse event than eight and four, even if the twelve applied
first.

So the moment you allow curation, you have created a place where somebody's cousin can get a
stand. Every rule below exists to make that visible if it happens.

**The single most important property: the rule is fixed and published before anybody knows who
applied.** Everything else is enforcement of that one idea.

---

## 3 · The lifecycle

| # | Stage | Who owns it | What the platform does |
|---|---|---|---|
| 1 | **Publish the call** | Organiser | Locks criteria, prices, stand count and closing date. Public page. |
| 2 | **Applications** | Vendor | Form + documents. Acknowledged automatically. Editable until close. |
| 3 | **Eligibility check** | Event admin | Objective pass/fail. Documents present and in date. |
| 4 | **Selection** | Panel of 2 | Scored against the published criteria. Recorded. |
| 5 | **Offers** | Platform | Offer with a stated hold window. Declines fall to the waiting list. |
| 6 | **Payment** | Vendor | Full cost shown before commitment. Split to the organiser's subaccount. |
| 7 | **Assignment** | Event admin | Pitch number, load-in slot, staff passes. |
| 8 | **The day** | Door team | Vendor passes scanned like tickets. |
| 9 | **Settlement** | Platform | Statement per vendor. Refunds queue handles anything that failed. |

### The gate that matters: eligibility ≠ selection

These are deliberately two stages with two different characters.

- **Eligibility is objective and checkable.** Right category, documents present, insurance in
  date, not previously removed for cause. A machine could do it, and a rejection here is
  explainable in one sentence.
- **Selection is judgement.** It is where quotas, quality and mix get applied — and therefore
  the only stage that needs a recorded rationale.

Collapsing them into one "we reviewed your application" decision is what makes rejections feel
arbitrary, because a vendor cannot tell whether they failed a rule or a taste.

---

## 4 · How selection stays fair

**a. Published before the window opens, unchangeable after.** Criteria, weights, the number of
stands per category, the price of each stand type, and the closing date. Changing any of them
after applications open means reopening the window.

**b. Category quotas, published as numbers.** "6 food, 10 craft, 4 fashion, 2 services." This
is what prevents a monoculture *without* needing a secret hand on the scale — the constraint is
the published number, not a preference applied later.

**c. Two independent scorers per application, then reconcile.** Neither sees the other's score
first. A gap beyond a threshold goes to a third. This is the same shape as the platform's
existing judging, and for the same reason: one person's score is an opinion, two agreeing is
evidence.

**d. Ties break on earliest *complete* application.** Not earliest submission — completeness
matters, otherwise the fastest incomplete form beats the careful one. This keeps a
first-come element as the neutral tiebreak while stopping it from driving the whole outcome.

**e. Conflicts declared and recorded.** Anyone connected to an applicant — organiser, staff,
scorer, judge — declares it, does not score that application, and the abstention is part of the
record. The point is not that connections are disqualifying; it is that they are *visible*.

**f. Selection completes before payment is taken.** A vendor is chosen, then invited to pay.
Not the reverse. This is the structural block on pay-to-jump: at the moment of the decision,
nobody has paid anything.

> A premium pitch — corner, main entrance, power included — may legitimately cost more. That is
> a **product difference within a stand type**, priced and published like any other. It is not a
> different chance of being selected, and the two must never be bundled.

**g. Every applicant gets an outcome with a reason.** Drawn from the published criteria, not
free text. Unsuccessful applicants can request their score. Silence is the thing that makes
people assume the worst, and it is free to avoid.

**h. A real waiting list, offered in order.** The platform already does this for tickets:
ordered queue, timed offer, unclaimed offers return to the front. Same mechanism, same hold
window.

**i. Accessible pitches are inventory, not a favour.** A stated number of step-free stands with
level access to the pitch, marked on the map and requestable in the form.

---

## 5 · Money

Reuses the ticketing money path exactly, which is the main argument for building it here at all.

- **One payment, split at the gateway.** The stand fee goes to the organiser's Paystack
  subaccount with the platform's share split at source. Nobody invoices anybody afterwards,
  which is where this kind of arrangement normally goes wrong.
- **The full cost is shown before commitment.** Stand fee, power, table hire, and the platform
  fee, itemised on one screen. Hidden add-ons discovered at payment are the single most
  reliable way to lose a booking, and they are also simply not honest.
- **Deposit now, balance later** for events far out. The deposit is what makes a no-show cost
  something; without it the waiting list is theatre.
- **A published cancellation ladder, symmetric.** Whatever window applies to the vendor applies
  to the organiser: *if the organiser cancels a stand, the vendor is refunded in full regardless
  of the date.* A one-sided cancellation policy is the clearest signal that a marketplace is not
  even-handed, and regulators increasingly treat obstructed or asymmetric cancellation as an
  enforcement matter rather than a commercial choice.
- **Failed refunds are already visible.** The refunds queue on the event's tickets screen shows
  what did not go through and who is owed money; vendor refunds join the same list rather than
  becoming a second thing nobody watches.

---

## 6 · Obligations and documents

Collected at application, checked before the offer is confirmed — **not on the day**, which is
when a missing certificate becomes the organiser's problem instead of the vendor's.

| Document | Who needs it | Notes |
|---|---|---|
| Public liability insurance | All | Expiry stored; an expired policy blocks confirmation |
| Food hygiene / handler certificate | Food and drink | Non-negotiable gate, not a scoring criterion |
| CAC registration or equivalent | All trading vendors | Sole traders: identity + address |
| Power requirement | Any vendor drawing power | Drives pitch assignment, not price alone |

The platform stores expiry dates and refuses confirmation on a lapsed document. It does **not**
verify authenticity, and the brief should say so out loud — the platform is a record-keeper here,
not an underwriter, and pretending otherwise creates a liability nobody priced.

---

## 7 · The day

- **Vendor staff get door passes, not admin accounts.** The existing time-boxed scan-pass
  mechanism already covers exactly this: works between two times for one event, revocable
  instantly, cannot see the guest list or the money.
- **Load-in slots staggered by pitch number**, published with the offer. Every vendor arriving
  at once is the default failure of every market ever run.
- **A stand is checked in like a ticket.** Same three verdicts — admit, already in, refuse — so
  the door team learns one interaction, not two.
- **No-shows release at a stated hour**, and the waiting list is offered the space if there is
  time to use it.

---

## 8 · What this reuses vs what is new

**Reused almost as-is** — the reason this is a moderate build rather than a large one:

| Need | Existing mechanism |
|---|---|
| Stand types with their own price and limit | Ticket tiers (price, capacity, sale window, access code) |
| Colour-coding stand categories | Tier colour palette, derived from the event's accent |
| More applicants than pitches | Event waiting list with timed offers |
| Payment, split to the organiser | `PaymentService` + Paystack subaccounts |
| Cancellation and refunds | `EventRefundPolicy` + the refunds queue |
| Staff at the gate with no account | `EventScanPass` door passes |
| Discounts for returning vendors | Discount codes |

**Genuinely new:**

1. An application form with document upload and expiry tracking.
2. A scoring screen — two scorers, published criteria, conflict declaration, reconciliation.
3. A decision record per applicant, and the outcome email that quotes it.
4. Stand inventory with pitch numbers, accessibility flags, power, and a map.
5. A vendor-facing view of their own application, offer and stand.

---

## 9 · Honest risks

- **Curation is a reputational surface.** A rejection that looks like favouritism is a public
  story in a way a sold-out ticket never is. The decision record is not bureaucracy; it is the
  only thing that answers the accusation.
- **The platform will be read as endorsing its vendors.** Food safety in particular. Document
  collection must be described as record-keeping, in the vendor terms and on the public page.
- **Deposits create refund volume.** More refunds means more failed refunds. The queue exists,
  but vendor refunds are larger than ticket refunds and will be chased harder.
- **Quotas are contestable.** Publishing "6 food stands" invites argument about why six — which
  is a much better argument to have in public, before applications, than in private afterwards.
- **Scoring costs organiser time.** Two scorers per application on 80 applications is real work.
  If that is not affordable, the honest answer is first-come with category caps, published as
  such — *not* curation performed casually and described as a panel.

---

## 10 · Suggested phasing

**Phase 1 — sell stands.** Stand types as tiers, an application form, manual selection by the
organiser, payment through the existing gateway path, vendor door passes. Fairness rules
published and followed by hand. Ships quickly because most of it exists.

**Phase 2 — make selection defensible.** Scoring, quotas, conflict declaration, the decision
record, and the outcome email that quotes the criteria. This is the phase that earns the word
"fair" rather than asserting it.

**Phase 3 — the operational layer.** Stand map and pitch assignment, document expiry tracking,
load-in scheduling, vendor self-service portal, settlement statements.

---

## 11 · Decisions needed before any of this starts

1. **Curated or first-come?** Both are defensible; only one is affordable at scale. This
   determines whether Phase 2 exists at all.
2. **Who scores?** Organiser staff, or an independent pair? Independence costs money and buys
   the answer to the favouritism question.
3. **Deposit or full payment up front?**
4. **Does the platform take a share of stand fees, or charge the organiser a flat listing fee?**
   The first aligns incentives with filling the room; the second is easier to explain to vendors.
5. **What is the cancellation ladder**, and is the organiser held to the symmetric version?
