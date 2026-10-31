# Africa GATES — what the product is, and what it should become

Written 2026-09-15. This is a decision document, not a vision deck. Everything in it is
grounded in what is actually in this repository on the day it was written, and where a
claim rests on a measurement the measurement is printed.

---

## 1. The thing that is easy to get wrong

Africa GATES looks like an awards site. Awards sites are a crowded, low-trust category:
most of them sell votes, most of them are decided by whoever campaigns hardest on
WhatsApp, and most people know it. A reasonable observer's first assumption about any
platform that takes money for votes is that the result is for sale.

This platform has spent an enormous amount of engineering specifically on not being that,
and the evidence is in the codebase rather than in the marketing:

- **One scorer.** `NomineeScoringService::editionScale()` computes the denominator once per
  cycle. There is no second implementation anywhere.
- **Money buys the tally and never the reach.** 70% of the community half counts distinct
  *people*, resolved through `VoterReach::personKey()`, which deliberately collapses a
  buyer's thousand split orders into one person.
- **A result is the one that was announced.** `ReleasedStanding` lays the sealed figures
  back over the page, so a released standing does not move when the rules change. The seal
  is gated on the announcement actually having gone out.
- **A published method with a live worked example.** `/integrity` reads its figures from the
  scoring engine, so the page cannot describe arithmetic the platform does not perform.
- **An appeals route, an audit log, and a hash chain** over published results.

> **The moat is not the awards. It is the apparatus that makes a result survive being
> questioned.** That apparatus took years, cannot be retrofitted credibly, and is the only
> part a competitor cannot copy in a weekend.

Everything below follows from taking that seriously.

---

## 2. What is already built, measured

| | |
|---|---|
| GET routes | 399 |
| Distinct top-level URL segments | 131 |
| Static public pages | 139 |
| Public paths linked from any template | 56 |
| Help articles / categories | 33 / 6 |
| Help articles addressed to an organisation or a judge | **0** |
| Public IA completeness tests | **0** (the admin console has six) |
| Partner-organisation console | 1 template, 1,365 lines, 7 hash-tabbed sections |

**The tenancy already exists and is more serious than it looks.** `gates_partner_orgs`
carries a CAC number, a SCUML registration, a vetting workflow with a named vetter, a
Paystack subaccount, a settlement bank, a settlement schedule, `platform_fee_bps`, and a
suspension reason. That is not a contact form — it is a vetted financial counterparty with a
platform take rate.

**And the scoring engine is already multi-tenant.** `RuleEngine::effective($programmeId,
$cycleId)` resolves community/judge weights, the judging quorum, fraud thresholds and the
paid-vote ceiling through a layered override chain, and `provenance()` reports which layer
set each value. A programme can already run under its own rules, and a screen can already
say whose rules they are.

So the gap is not the engine.

> **The engine is multi-tenant. The partner's experience is not.**

---

## 3. The proposal

> **Corrected 2026-09-15, and the correction matters more than most of what it replaced.**
> The first draft of this section had Africa GATES *selling* recognition infrastructure,
> and §4 priced it in tiers. That is wrong about what this organisation is. Africa GATES
> is a project of a Nigerian non-profit — `PlatformTip`'s own docblock says so in as many
> words, two lines above the rule that makes its funding model defensible — and the
> engineering below it was built on that premise throughout. A strategy document that
> contradicts a fact the code states is the §19 shape one level up: prose outliving, or in
> this case never matching, the rule it describes. What follows is the same analysis with
> the right noun.

### Africa GATES offers recognition infrastructure to institutions, as a partnership.

Universities, professional bodies, state ministries, media houses, diaspora associations
and church networks run recognition programmes constantly. Almost all of them run on a
Google Form for nominations, WhatsApp for campaigning, a spreadsheet for judging, and a
press release for the result. The result is always contested, because there is no method
anybody outside the room can check.

What a partner gets is not software. It is **a result they can announce and not be argued
with**: a published method, a denominator a journalist can check, a sealed standing with a
verification hash, and an appeals route that is on the record.

What Africa GATES gets is not a licence fee. It is **reach, legitimacy and funding for the
mission** — each programme that runs here publishes a page demonstrating what the platform
does, brings its own constituency onto the register, and contributes through the cost
recovery in §4.3.

### Why this is an extension and not a pivot

Every structural piece exists:

| Needed for the partner programme | Already in this repo |
|---|---|
| Per-partner scoring rules | `RuleEngine` layered overrides + `provenance()` |
| Per-partner branding on public pages | `OrgBrand` (accent, logo, nine content blocks) |
| Vetted partner with settlement | `gates_partner_orgs` (CAC, SCUML, subaccount, fee bps) |
| Programme → cycle → category → nominee | The core domain |
| Panel scoring with quorum | `JudgeRubric`, `min_judges_per_nominee` |
| Sealed, citable results | `SnapshotService`, `ReleasedStanding`, hash chain |
| Appeals and audit | `/integrity`, `gates_audit_log`, the appeals route |

What is missing is the partner's *experience*: a front door, onboarding, self-service
programme setup, a console that is not one hash-tabbed page, and a public trust surface
per programme.

---

## 4. Four decisions that should be made deliberately

### 4.1 The unit of partnership is the cycle, not the seat

This survives the correction above unchanged, because it was never really an argument
about money — it is an argument about what a recognition programme *is*.

A programme is an **event**, not a daily tool. It has three administrators in January,
thirty judges in October, and nobody in between. Any arrangement metered per account
punishes exactly the month the partner is getting value, and — this is the part that
actually matters — **it would make a partner ration judge accounts.** Rationing judges
directly degrades the quorum (`min_judges_per_nominee`), and the quorum is load-bearing in
the integrity apparatus of §1. An arrangement that gives a partner a reason to appoint
fewer judges is an arrangement that attacks the thing being sold.

So: agree a partnership **per cycle**. A cycle is the unit of value, it is already the unit
the domain models, and it is the unit an institution's own finance officer can approve.

### 4.2 Three levels of partnership, drawn on what each costs Africa GATES to support

Not price tiers. The distinction is **operational load**, which is a real and finite
constraint for a non-profit, and the commitment asked for rises with it.

| Level | The partner gets | What it costs us | What we ask back |
|---|---|---|---|
| **Hosted** | Their programme on `africagates.org`, our brand, our method | Nothing new — this is what exists | Their constituency, and the published result |
| **Programme** | Their brand on the public result pages (`OrgBrand`), their own settlement account, their own rubric and weights (`RuleEngine` overrides), their own cycle calendar | Support, and a vetting pass per cycle | A partnership agreement, a named programme owner, and the cost recovery in §4.3 |
| **Institution** | Their own domain, SSO, a full data export, an independent audit statement per cycle | Real operational load — only ever agreed by conversation | A funding commitment sized to that load, and a named counterpart |

The middle level is the one to build for. It is almost entirely built already; what it
lacks is the self-service path to switch it on.

### 4.3 The money that actually moves, and the money that does not

Worth stating precisely, because "partnership" is the kind of word that hides a balance
sheet. Three mechanisms exist in the code today and there is no fourth:

- **`platform_fee_bps`** on `gates_partner_orgs` — an agreed share of gifts the platform
  settles for a partner. It comes **out** of the gift, so it is disclosed, and it
  **defaults to `0`**: a partner pays nothing unless something was agreed with them.
- **`PlatformTip`** — a donor's voluntary contribution to Africa GATES on top of what they
  gave the partner. **Added, never taken** (a flat `transaction_charge`, not a subaccount
  percentage, so the partner receives exactly the figure the donor typed), and
  `DEFAULT_PCT = 0` — never pre-ticked, because a default the user did not choose is not a
  choice under the NDPA 2023 or the GDPR line on consent.
- **The shop, tickets and stands.**

There is **no licence fee, no seat charge and no subscription**, and nothing in this
document proposes introducing one. Where a partnership needs to be funded beyond the
above, that is a grant, a sponsorship or a funding commitment negotiated as such — a
conversation, not a pricing page. The honest name for §4.2's third column is cost
recovery, and it should be stated to a partner in exactly those terms.

### 4.4 The public result page is what the partnership produces

Not the dashboard. The dashboard is what the partner uses; **the result page is what the
partnership produced.** It is the thing they link in a press release, the thing a sceptical
alumnus opens, and the thing that either survives scrutiny or does not.

That argues for investment in the *public* trust surface per programme — a page that states
the method, the denominator, the panel size, the quorum, the seal, and the appeals record —
ahead of investment in partner-facing features. It is also the best possible outreach: every
programme that runs here publishes a page that demonstrates what the platform does.

---

## 5. What the UX has to fix first

These are the findings from the audit that ran alongside this document. Severity is
Nielsen's 0–4 scale.

### [4] The partner has no front door

`/org` is linked only from pages a partner reaches *after* an action — the application
success page, the stands offer, the member dashboard. There is **no route to it from the
site's own navigation**. An organisation that closes the tab has to find an old email.

This is not solved by putting organisation sign-in on the member sign-in page:
`templates/pages/account/login.twig` documents a deliberate refusal — *"separate trust
domains on their own routes, and a shared public entry point is the thing that makes one of
them a target."* That reasoning is correct and stays.

The fix is a **findable public destination for organisations** — from `/partner`, where
organisations are told about the platform, and from the footer — which reaches the
organisation's own sign-in route without merging the forms. This is exactly what the Google
reference does: "Google Account" is a distinct linked destination in the help header, not a
merged login.

### [3] Two front doors to one job

`/help` ("What has gone wrong?") and `/support` ("We're here to help") both exist as
entry points for a stuck person, and they cross-link: `/support`'s first step is "Search the
Help Centre → Browse the answers", which lands on `/help`, which is itself a search-first
page. A stuck person hits a router that routes them to another router.

The Google reference solves this with **one surface and persistent sub-navigation** — Help
Center / Community / Improve your Google Account — under one search field and one identity.

### [3] The help centre does not know who is asking

Six categories, 33 articles, and no audience dimension. A voter, a nominee, a judge and a
partner organisation have almost disjoint problems, and there is **not one article for an
organisation or a judge**. For a platform serving institutions this is the gap that
matters most: the customer's own staff have nowhere to go.

The Google reference is scoped — the header says *"Google Account Help"*, not *"Google
Help"* — so a reader always knows which product's answers they are reading.

### [3] Nothing checks that a public page is reachable

`AdminIaTest` asks *"is every admin page reachable without typing a URL?"* for the operator
console. The public site — 139 static pages, real customers — has no equivalent. Measured:
16 genuinely orphaned public paths after subtracting 63 redirect aliases and the payment
callbacks, including the partner console, the platform status page, and `/opportunities`.

### [2] `/support` uses the pattern `/help` documented as abandoned

`templates/pages/help.twig` records, at length, why the centred hero over identical rounded
cards with tinted icon squares was removed. `/support` still has it. One of the two pages is
wrong, and the one with the reasoning written down is the one that is right.

---

## 6. What this is not

**Not a pivot to white-label software.** The brand and the method are the product; a partner
who wants the software without the method wants a different thing entirely, and giving it to
them would destroy the only asset here.

**Not a marketplace.** Africa GATES vets partners. `gates_partner_orgs.status`, the CAC and
SCUML fields and the suspension reason exist because an unvetted organisation taking money
through this platform is a liability the platform cannot survive. Volume is not the goal.

**Not a SaaS product with a price list.** See §3's correction and §4.3. This is a non-profit
offering infrastructure in partnership; the only money that moves is disclosed cost recovery
on funds already flowing, and a voluntary donor tip that is added rather than taken.

**Not metered per seat, per §4.1** — and that one is a safety rule, not a commercial
preference.

**Not a general polling tool.** The moment a result here can be bought outright, the
apparatus in §1 is worthless and so is the company.

---

## 7. Sequence

1. **Fix the front door and the help IA.** The partner can find their console; the stuck
   person has one surface; help knows who is asking. *(This session.)*
2. **Public IA completeness test**, so orphaned pages are a failing build rather than a
   discovery. *(This session.)*
3. **One way in to the register.** A person can find a nominee, an award, a school or a
   partner organisation from the homepage, in the words they would use — and nothing
   unannounced is reachable that way. *(This session.)*
4. **Partner console on real routes.** Seven hash-tabbed sections become seven URLs — an
   architecture fix that unlocks per-section loading, linking and pagination.
5. **Programme trust page.** What §4.4 argues the partnership actually produces, per programme.
6. **Self-service cycle setup** for the Programme level, using the `RuleEngine` overrides
   that already exist.
7. **A partnership agreement surface** — what was agreed with this partner, what the fee
   share is, and what the platform committed to, readable by both sides. Cost recovery
   that a partner cannot look up is cost recovery they will dispute.

Steps 1–3 are done and are recorded in `docs/CODEBASE-INDEX.md` §27 and §28. Steps 4–7 are
named here so the order is a decision rather than whatever gets picked up next.

> **One note on step 3, because it changed what steps 4–7 are for.** Building the finder
> turned up a live fault rather than a missing feature: the site search was publishing
> winners from cycles that had not been announced. The lesson generalises to everything
> below. Each of the remaining steps puts a new surface in front of data this platform
> already holds, and every one of them is a chance to publish something the integrity
> apparatus was relying on nobody looking at. **Ask of each new surface what gate it
> inherits — and if the answer is "none, but the page it links to has one", that is this
> exact bug.**
