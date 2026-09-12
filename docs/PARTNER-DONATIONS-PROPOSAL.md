# Opening donations to partner organisations — a proposal

**Status:** proposal for decision. Nothing built yet.
**Ask:** let organisations other than Africa GATES collect donations through the platform.
**Recommendation:** yes, on the split-at-source model in §5, gated behind the vetting in §6.

---

## 1 · What actually changes

Today `/donate` is a single-recipient page. Money is given to Africa GATES, funds Africa
GATES' own child leadership programmes, and settles into an Africa GATES account. Every
part of the design assumes one recipient, and one of those assumptions is a promise
printed on the page.

Opening it to other organisations changes three things at once, and they are worth
separating because only one of them is hard:

| | Change | Difficulty |
|---|---|---|
| **A** | A donation needs a **recipient** | Small — one column, one lookup |
| **B** | The money must **reach that recipient** | Medium, and the shape of the answer decides everything else |
| **C** | The platform is now **vouching for** the recipient | This is the whole product |

A is an afternoon. B is a solved problem at the gateway, once you pick a model. **C is
what you are actually building**, and the rest of this document is mostly about it: the
moment Africa GATES lists an organisation on its donation page, a donor's trust in
Africa GATES is being lent to a third party, and the platform owns whatever that party
does with it.

---

## 2 · The promise on the page breaks first

`templates/pages/donate.twig` currently says, in two places:

> "A one-time gift — **100% funds the programmes**."
> "Add {{ processing_fee_pct }}% to cover processing so **100% of my gift reaches the cause**."

That is true today because the recipient is Africa GATES and the fee cover is calculated
server-side to offset the gateway's cut. **It stops being true the moment a platform fee
is deducted from a third-party donation**, and the second line stops being true even
without a platform fee, because a donor covering "processing" would reasonably assume
they had covered *everything*.

This is not a copy problem to fix at the end. It is a decision to make at the start:

- **If the platform takes nothing** from partner donations, the promise survives verbatim
  and becomes a genuine differentiator — very few Nigerian platforms can say it honestly.
- **If the platform takes a fee**, the page must say what it is, per recipient, before the
  donor commits. A percentage discovered afterwards is the single fastest way to lose a
  donor permanently, and it is the kind of thing the FCCPC treats as a disclosure matter
  rather than a commercial choice.

There is no third option where the wording stays as it is and a fee exists.

---

## 3 · What exists today, verified against the code

**Reusable almost as-is:**

| Need | Existing mechanism |
|---|---|
| Amount chosen by donor, clamped server-side | `DonationController` — min ₦200, max ₦5,000,000, integer-cast |
| Pending row before the gateway, confirm on verified amount | `gates_donations` + idempotent pending→confirmed |
| Gateway identifiers captured for donor-facing receipts | `gateway_txn_id`, `gateway_ref` |
| Refunds, claim-stamped so two workers cannot double-refund | `RefundService` + the refunds queue |
| Per-payment destination recorded on the row, not derived later | `gates_payment_destinations` |
| Fee-cover maths | `cover_fees` → `ceil(amount × 1.015)` server-side |

**The gaps, precisely:**

- **`gates_donations` has no recipient column.** Every donation in the system is
  implicitly Africa GATES'. This is the same shape as the gap in `gates_products`.
- **There is no organisation entity.** `gates_partner_enquiries` is a contact form, not a
  party that can be paid. `gates_nominees.nominee_org` is a free-text string on a nominee,
  not an organisation record.
- **Money routes by *kind*, not by *recipient*.** `PaymentDestination::STREAMS` is three
  fixed platform-wide streams — `events`, `shop`, `votes` (donations ride `votes`) — each
  with one subaccount set in admin settings.

**The good news, and it is better than I previously assessed.** The routing seam is
already the right shape. `PaymentService::initializePaystack()` merges whatever
`PaymentDestination::initFields()` returns into the payload, and `rememberDestination()`
writes the attribution onto the payment row. Supporting per-recipient donations means
resolving that subaccount **from the recipient instead of from the stream** — the same
return shape, the same merge, the same attribution row. The block is already best-effort
with an explicit fail-open, so a routing failure sends the payment unrouted rather than
blocking a donor.

> **Correction to the vendor stands brief.** That brief states "one Paystack transaction
> settles to at most one subaccount", and uses it to argue that multi-party money needs a
> ledger and a payout run. **That is wrong.** Paystack's Transaction Splits / multi-split
> API settles a single transaction across the main account and one or more subaccounts,
> with no documented maximum, using a split code created via the API. This materially
> improves that document's model 3 as well — the "platform must hold other people's money"
> conclusion does not follow. **Corrected**, and the brief has since been superseded by
> `docs/VENDOR-STANDS-SPEC.md`, which carries the correction visibly rather than silently
> repairing it.

---

## 4 · The decision that determines everything else: who holds the money

| | Model | Who is paid | Platform holds funds? | Verdict |
|---|---|---|---|---|
| **1** | **Signpost** | The org, on its own site | No — no money touches the platform | Safe, and barely a product |
| **2** | **Split at source** | The org's own subaccount, at the gateway | **No** | **Recommended** |
| **3** | **Collect and remit** | Platform account, forwarded later | **Yes** | Avoid |

**Model 3 is the one to avoid, and the reason is not technical.** Holding donations
belonging to a third party makes Africa GATES a custodian of other people's charitable
funds. That is a regulatory posture — with AML obligations, segregation-of-funds
questions and insolvency implications — adopted in exchange for nothing a split cannot
give you. Every failure mode is also worse: a delayed payout looks like theft, a disputed
payout is a lawsuit, and an org that collapses mid-campaign leaves the platform holding
money it must decide what to do with.

**Model 2 gives the same donor experience with none of that.** The donor pays once on an
Africa GATES page, the gateway settles the org's share directly into the org's own
Paystack subaccount — which is tied to the org's own bank account in the org's own name —
and the platform's share, if any, splits off at source. **At no point does Africa GATES
hold money that is not its own.** That single sentence is the whole regulatory argument,
and it should be stated plainly in the partner agreement and on the public page.

One useful consequence: because a Paystack subaccount requires a settlement bank account
in the organisation's name, **the gateway performs a KYC step you would otherwise have to
build**. An organisation that cannot produce a bank account in its own registered name
cannot be paid, and that filters out a whole class of impersonation before you write a
line of vetting code.

---

## 5 · How a partner donation would work

1. **Organisation applies.** Documents in §6. Nothing is public yet.
2. **Vetting.** Two reviewers, recorded, against published criteria.
3. **Subaccount.** The org creates (or is helped to create) a Paystack subaccount in its
   own name. The platform stores the subaccount code, never bank details.
4. **Campaign or general fund.** The org publishes what it is raising for, with a
   plain-language description of what the money does. Reviewed before it goes live.
5. **Donor gives** on `/donate/<org-slug>` — the same amount picker, the same fee-cover
   logic, the same clamps.
6. **One payment, split at source.** Org's share to the org's subaccount, platform share
   (if any) to the platform, both at the gateway.
7. **Receipt names the recipient.** Not Africa GATES. The donor must never be confused
   about who received their money.
8. **The org sees its own donations** — amounts, dates, donor names where consented — and
   nothing about anybody else's.
9. **Public reporting.** Total raised per campaign, visible to everyone.

### What the page must show before a donor commits

- **Who the recipient is**, with its CAC registration number, and that it is not Africa GATES.
- **What the platform takes**, in naira, on the amount being given — not a footnote.
- **What the gateway takes**, if the donor declines the fee cover.
- **When the money reaches the organisation**, honestly. "Settles to the organisation
  within N working days" beats silence.
- **That Africa GATES vets but does not guarantee** — see §8.

---

## 6 · Vetting: the actual product

A fraudulent charity collecting through a trusted platform is the failure that ends the
platform. Everything here exists to make that hard, and to make it evidenced when it is
attempted.

### Hard gates — objective, documentary, no judgement

| Requirement | Why it is non-negotiable |
|---|---|
| **CAC Incorporated Trustees certificate** (Part F, ss.825–829 CAMA 2020) | The only structure under which a Nigerian nonprofit can lawfully hold property and contract in its own name |
| **SCUML registration** | Registration with the EFCC's Special Control Unit against Money Laundering is **mandatory for NGOs** under the Money Laundering (Prevention and Prohibition) Act 2022, and operating without it is a criminal offence. An organisation that cannot produce it is asking a platform to help it break the law |
| **Paystack subaccount with a settlement account in the organisation's registered name** | Money can only ever land in the org's own account. Does the KYC for you |
| **Trustee identities**, matched against the CAC filing | The people, not just the paperwork |
| **A named, reachable human** with a role at the organisation | Somebody who answers when a donor complains |

Note that CAMA 2020 gives the CAC expanded powers over incorporated trustees — including
investigating their affairs, obtaining court-ordered suspension of trustees, appointing
interim managers and **restricting their financial transactions**. A partner whose
trustees have been suspended is a partner whose campaign must come down that day, which
is an argument for storing the registration number in a queryable field rather than as an
uploaded image nobody ever looks at again.

### Judgement gates — recorded, with a reason

- Does the stated purpose match the registered objects?
- Is the campaign description specific enough for a donor to know what they are funding?
- Any prior removal from the platform, or unresolved donor complaints?

### And the honest limit

The platform is a **record-keeper and a gatekeeper, not an auditor**. It verifies that
documents exist, are in date and match the applicant. It does not verify that the money
is spent as promised, and it must say so in those words — on the public page and in the
partner agreement. Claiming more creates a liability nobody priced and a promise nobody
can keep.

**Donation-based collection is outside the SEC crowdfunding regime.** The SEC Rules on
Crowdfunding 2021 govern *investment* crowdfunding — equity, debt, securities — not
donation or reward-based raising, so this does not require a crowdfunding portal licence.
That is a genuine simplification and worth confirming with counsel rather than taking
from this document.

---

## 7 · Money, stated plainly

- **Platform fee: one published rate**, the same for every partner in a tier, never
  negotiated per organisation. A differential (a lower rate for small orgs, say) is fine
  if it is a published rule anyone can qualify for.
- **Split at source, always.** No invoicing, no remittance, no holding.
- **The fee-cover checkbox must be re-derived.** Today it covers ~1.5% of gateway cost. If
  a platform fee exists, the checkbox must either cover both — and say so — or be removed.
  Leaving it saying "100% of my gift reaches the cause" while a platform fee is deducted
  is a false statement, not a rounding error.
- **Refunds are rare but must exist.** A donation taken for a campaign that turns out to
  be fraudulent should be refundable, and after a split the platform can only refund its
  own share. Say what happens in that case *before* it happens: the realistic answer is
  that the platform refunds its fee, suspends the org, and supports the donor's claim
  against the recipient. Pretending the platform can claw back settled funds it never
  held is the promise that will be quoted back at you.
- **Settlement timing is the org's, not the platform's.** Paystack settles subaccounts on
  its own schedule; the platform should display that schedule rather than implying it
  controls it.

---

## 8 · What the platform is representing, and how to bound it

Listing an organisation is a representation. The bounding language should appear in three
places, in the same words:

> Africa GATES verifies that this organisation is registered with the CAC and with SCUML,
> and that donations settle to a bank account in its own name. We do not audit how the
> money is spent.

- **On the campaign page**, near the give button — not in a footer.
- **In the receipt**, which is the document a donor keeps.
- **In the partner agreement**, alongside the org's warranty that its documents are
  genuine and its undertaking to notify the platform of any CAC action against it.

**Ranking is the second representation, and it is easy to miss.** Once there is more than
one organisation, whatever order they appear in is a commercial act. Publish the rule —
random per session, alphabetical, or by campaign end date — and hold it. If promoted
placement is ever sold, label it and cap it. And Africa GATES' own programmes must not
outrank partners on a page that presents itself as neutral; the cleanest rule is that
Africa GATES' own fund sits in its own labelled section and does not compete in the
partner list.

---

## 9 · Honest risks

- **One fraudulent partner damages every partner**, and Africa GATES most of all. The
  vetting record is not bureaucracy; it is the only thing that answers "how did they get
  on your site?"
- **Vetting costs real time.** Two reviewers per applicant does not scale to hundreds. If
  that is unaffordable, the honest answer is a small, deliberately curated partner list —
  not a large one vetted casually.
- **A suspended or deregistered partner needs a same-day takedown path**, including
  pausing an in-flight campaign. Build the off switch before the on switch.
- **Donor data crosses an organisational boundary.** Sharing donor names and emails with a
  partner is a disclosure under the Nigeria Data Protection Act 2023 and needs explicit,
  unbundled consent — the existing `show_name` flag is a good precedent but covers public
  display, not disclosure to a third party. Default to sharing nothing but the amount.
- **The `/donate` page's identity gets diluted.** It currently converts because it is
  specific. A directory of causes is a different, usually worse-converting page. Keep the
  Africa GATES programmes page as its own destination.
- **Success creates a payout support burden** that lands on Africa GATES regardless of who
  holds the money, because the platform is where the donor paid.

---

## 10 · Phasing

**Phase 1 — one partner, hand-run.** Recipient column on `gates_donations`, an
organisation record, per-recipient subaccount routing, `/donate/<slug>`, receipt naming
the recipient. Vetting done by hand against the §6 list. Proves the money path end to end
with one trusted organisation and no directory.

**Phase 2 — make it a product.** Partner application form with document upload and expiry
tracking, the two-reviewer record, campaign create/review, the partner's own view of
their donations, the takedown switch.

**Phase 3 — the directory.** Multiple partners, the published ranking rule, campaign
progress, public per-campaign totals.

**Phase 4 — reporting.** Per-partner statements, donor receipts as downloadable
documents, and — if it is ever wanted — an outcomes field partners fill in and donors can
read, clearly marked as the partner's own claim rather than a verified fact.

---

## 11 · Decisions needed before any of this starts

1. **Does the platform take a fee from partner donations?** This decides whether the
   "100%" promise survives, and it is the first thing to settle because the page copy,
   the fee-cover checkbox and the partner agreement all follow from it.
2. **Model 2 confirmed?** I recommend split-at-source and would push back hard on
   collect-and-remit. Worth an explicit yes.
3. **Open applications, or invitation only?** Curated is far cheaper and far safer to
   start; it can open later. The reverse is very hard.
4. **Who vets?** This is recurring operational work, not a one-off build.
5. **What is shared with partners about donors** — nothing, amount only, or name and
   email with explicit consent?
6. **Does Africa GATES' own fund appear in the partner list, or in its own section?**
   §8 argues its own section.
7. **What is the takedown SLA** when the CAC acts against a partner mid-campaign?

---

## Sources

- [SCUML registration requirements for NGOs under the Money Laundering (Prevention and Prohibition) Act 2022](https://scuml.efcc.gov.ng/laws-regulations/)
- [SCUML registration in Nigeria — scope and penalties](https://lexpraxisng.com/scuml-registration-everything-you-need-to-know/)
- [Registering and managing NGOs in Nigeria under CAMA 2020 (Part F, ss.825–829)](https://1stattorneys.com/articles/2024/11/04/how-to-register-and-manage-ngos-in-nigeria-under-cama-2020/)
- [SEC guidance on crowdfunding platforms in Nigeria — investment-based scope](https://lexpraxisng.com/sec-guidelines-for-setting-up-a-crowdfunding-platform-in-nigeria/)
- [Paystack multi-split payments](https://paystack.com/docs/payments/multi-split-payments/)
- [Paystack Transaction Split API](https://paystack.com/docs/api/split/)
