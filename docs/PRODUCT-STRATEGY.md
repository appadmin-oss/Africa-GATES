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
suspension reason. That is not a contact form — it is a vetted financial tenant with a
platform take rate.

**And the scoring engine is already multi-tenant.** `RuleEngine::effective($programmeId,
$cycleId)` resolves community/judge weights, the judging quorum, fraud thresholds and the
paid-vote ceiling through a layered override chain, and `provenance()` reports which layer
set each value. A programme can already run under its own rules, and a screen can already
say whose rules they are.

So the gap is not the engine.

> **The engine is multi-tenant. The tenant's product is not.**

---

## 3. The proposal

### Africa GATES sells recognition infrastructure to institutions.

Universities, professional bodies, state ministries, media houses, diaspora associations
and church networks run recognition programmes constantly. Almost all of them run on a
Google Form for nominations, WhatsApp for campaigning, a spreadsheet for judging, and a
press release for the result. The result is always contested, because there is no method
anybody outside the room can check.

What they buy from Africa GATES is not software. It is **a result they can announce and not
be argued with**: a published method, a denominator a journalist can check, a sealed
standing with a verification hash, and an appeals route that is on the record.

### Why this is an extension and not a pivot

Every structural piece exists:

| Needed for the SaaS product | Already in this repo |
|---|---|
| Per-tenant scoring rules | `RuleEngine` layered overrides + `provenance()` |
| Per-tenant branding on public pages | `OrgBrand` (accent, logo, nine content blocks) |
| Vetted tenant with settlement | `gates_partner_orgs` (CAC, SCUML, subaccount, fee bps) |
| Programme → cycle → category → nominee | The core domain |
| Panel scoring with quorum | `JudgeRubric`, `min_judges_per_nominee` |
| Sealed, citable results | `SnapshotService`, `ReleasedStanding`, hash chain |
| Appeals and audit | `/integrity`, `gates_audit_log`, the appeals route |

What is missing is the tenant's *experience*: a front door, onboarding, self-service
programme setup, a console that is not one hash-tabbed page, billing that is not only a
donation fee, and a public trust surface per programme.

---

## 4. Three decisions that should be made deliberately

### 4.1 Price the cycle, not the seat

A recognition programme is an **event**, not a daily tool. Seat-based pricing is the wrong
shape: a programme has three administrators in January, thirty judges in October, and
nobody in between. Per-seat pricing punishes exactly the month the customer is getting
value, and per-seat metering would make a tenant ration judge accounts — which directly
degrades the quorum the integrity apparatus depends on.

**Price per cycle, with the take rate on paid votes as a second line.** A cycle is the unit
of value, it is already the unit the domain models, and it is the unit a finance officer
can approve.

### 4.2 Three tiers, drawn on what actually costs Africa GATES money

| Tier | The tenant gets | What it costs us |
|---|---|---|
| **Community** | Their programme on `africagates.org`, our brand, our method, revenue share on paid votes | Nothing new — this is what exists |
| **Programme** | Their brand on the public result pages (`OrgBrand`), their own settlement account, their own rubric and weights (`RuleEngine` overrides), their own cycle calendar | Support, and a vetting pass per cycle |
| **Institution** | Their own domain, SSO, a full data export, an independent audit statement per cycle, an SLA | Real operational load — this is the only tier that should be sold by conversation |

The middle tier is the product. It is almost entirely built; what it lacks is the
self-service path to switch it on.

### 4.3 The public result page is the sellable artefact

Not the dashboard. The dashboard is what the customer uses; **the result page is what the
customer bought.** It is the thing they link in a press release, the thing a sceptical
alumnus opens, and the thing that either survives scrutiny or does not.

That argues for investment in the *public* trust surface per programme — a page that states
the method, the denominator, the panel size, the quorum, the seal, and the appeals record —
ahead of investment in tenant-facing features. It is also the best possible marketing: every
programme that runs here publishes a page that demonstrates what the platform does.

---

## 5. What the UX has to fix first

These are the findings from the audit that ran alongside this document. Severity is
Nielsen's 0–4 scale.

### [4] The paying tenant has no front door

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
organisation or a judge**. For a platform selling to institutions this is the gap that
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

**Not a pivot to white-label software.** The brand and the method are the product; a tenant
who wants the software without the method wants a different product, and selling it to them
would destroy the only asset here.

**Not a marketplace.** Africa GATES vets tenants. `gates_partner_orgs.status`, the CAC and
SCUML fields and the suspension reason exist because an unvetted organisation taking money
through this platform is a liability the platform cannot survive. Volume is not the goal.

**Not seat-priced, per §4.1.**

**Not a general polling tool.** The moment a result here can be bought outright, the
apparatus in §1 is worthless and so is the company.

---

## 7. Sequence

1. **Fix the front door and the help IA.** The tenant can find their console; the stuck
   person has one surface; help knows who is asking. *(This session.)*
2. **Public IA completeness test**, so orphaned pages are a failing build rather than a
   discovery. *(This session.)*
3. **Tenant console on real routes.** Seven hash-tabbed sections become seven URLs — an
   architecture fix that unlocks per-section loading, linking and pagination.
4. **Programme trust page.** The sellable artefact of §4.3, per programme.
5. **Self-service cycle setup** for the Programme tier, using the `RuleEngine` overrides
   that already exist.
6. **Cycle billing.**

Steps 1 and 2 are done in this session and are recorded in `docs/CODEBASE-INDEX.md` §27.
Steps 3–6 are named here so the order is a decision rather than whatever gets picked up
next.
