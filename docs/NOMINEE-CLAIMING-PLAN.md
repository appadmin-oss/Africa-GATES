# Nominee profile claiming

**Analysis and implementation plan — how a nominee takes ownership of their
page, and how the words of the people who nominated them are shown.**

Status: proposal, nothing built · Date: August 2026
Companion to `REPUTATION-INFRASTRUCTURE-PLAN.md`

---

## 0. Verdict

**Viable, and unusually cheap for what it unlocks — but only if the
verification bar is set by the PAYOUT and not by the edit.**

The platform is most of the way there without knowing it: `gates_nominees`
already carries a `profile_id` slot, `gates_profiles` already has a
`verification_tier` with exactly the four levels this needs, and `OtpService`
already does verified-email proof (it is what makes a vote a vote). The claim
flow is a token, a landing page and an edit form on top of machinery that
exists.

The part that is **not** cheap, and that must not be waved through, is what
claiming *means* once the Community Return is attached to it. Today a claim
would let somebody edit a bio. Under the CRI it lets somebody **receive money in
another person's name.** Those are not the same feature and they cannot share a
verification bar.

There is also one genuinely new thing here, and it is the best idea in the
brief: **showing what the nominators wrote.** That turns a nominee page from a
bio into a record with provenance — which is the whole reputation-infrastructure
thesis, arriving a phase early and almost free.

---

## 1. The insight everything else follows from

> Claiming is cheap today and expensive under the CRI. So the claim must be a
> LADDER, not a gate.

Consider the attack. Adaeze Okonkwo is nominated for Choral Excellence. Her page
is on the ballot, being voted for, accruing a Community Return. Somebody who is
not Adaeze claims it, edits the brief, and — if claiming alone unlocked the CRI
— receives her money.

Every mitigation people reach for first is wrong:

* *"Require the nominee's email."* The email on a nomination was typed **by the
  nominator**. It is a claim about Adaeze, not proof from her. It is often a
  typo, sometimes a manager's address, sometimes absent entirely.
* *"Require a document up front."* Then almost nobody claims, the pages stay
  stubs through the whole cycle, and the feature fails by being unused.
* *"Have staff approve every claim."* At Alimosho scale that is fine. At Phase 2
  it is a queue nobody can clear, and queues that cannot be cleared get
  rubber-stamped.

The resolution is to notice that **editing a page and receiving a payout are two
different privileges**, and to price them separately.

---

## 2. The claim ladder

And the pleasing part: `gates_profiles.verification_tier` is already
`none | basic | verified | premium`. **No new column.** These four levels have
been sitting unused; they are exactly the ladder.

| Tier | How you get there | What it unlocks | Badge |
|---|---|---|---|
| `none` | Nominated by somebody else | Nothing. The page shows name, category and testimony. | — |
| `basic` | **Claim** — email OTP to the address on the nomination, or a support-verified alternative | Edit brief, photo, links, socials. Respond to testimony. | ID verified |
| `verified` | **Verify** — ID/KYC + bank account name match | **CRI enrolment and payout.** | Verified nominee |
| `premium` | Operator-designated | Official accounts, partners, institutions | Official |

Three rules that make the ladder hold:

1. **`basic` never touches money.** A claimed page can be edited and cannot be
   paid. That single line removes the entire attack above: taking over a page
   gets you a bio you can edit, under a name that is not yours, watched by the
   people who nominated the real person.
2. **`verified` is a payout control, so it is checked at payout time too.** The
   tier is necessary, not sufficient — the Integrity Gate from the companion plan
   still runs, and the bank account must resolve to a matching name.
3. **Going up a tier is a new decision, never an upgrade by accumulation.** Time
   on the platform, number of posts, follower count — none of these ever move
   somebody from `basic` to `verified`.

---

## 3. "The details from those that nominated them" — the design

This is the part the brief marked *not sure how yet*, and it is the most
valuable thing in it. `gates_nominations.reason` — why somebody put this person
forward — has been collected since day one and **has never been shown
anywhere.** It is the best content on the platform, sitting in a table.

### The shape

Two voices, never blended, always labelled:

```
┌──────────────────────────────────────────────────────┐
│  ADAEZE OKONKWO                    ✓ Verified nominee │
│  Choral Excellence · 2026                             │
├──────────────────────────────────────────────────────┤
│  IN HER WORDS                                         │
│  I have run the St Mary's intake programme for six    │
│  years…                          ← she writes this,   │
│                                    only after a claim │
├──────────────────────────────────────────────────────┤
│  WHY SHE WAS NOMINATED            3 nominations       │
│                                                       │
│  "She stayed after every rehearsal for a term to      │
│   teach the harmony line to people who had never      │
│   read music."                                        │
│                      — Chidi O., Lagos · Feb 2026     │
│                                                       │
│  "…"                 — Blessing A., Ogun · Feb 2026   │
│                                                       │
│  + 1 more                                             │
└──────────────────────────────────────────────────────┘
```

Before a claim, the first panel is simply absent and the second carries the
page. **A page with no claim is not empty — it is testimony.** That is a better
unclaimed state than any competitor has, and it is also the strongest possible
reason to claim: *"Three people wrote this about you. Add your side."*

### The rules that make it safe

| Rule | Why |
|---|---|
| Only from **approved** nominations | An unapproved nomination is an unreviewed allegation about a named person. |
| **Moderated before publication**, through the existing filter | `SpamService` and the AI moderation path already do this for comments; testimony is the same risk class with a higher blast radius because it names someone. |
| Attribution is **first name + last initial + state**, opt-in | Exactly the `gates_votes.show_name` precedent already on the ballot. Default private. |
| The nominator's contact details are **never** rendered | They are in the row; they are not the reader's business. |
| The nominee may **respond**, never edit or delete | A testimony the subject can delete is worthless as evidence. Responding is the honest remedy. |
| The nominee may **flag** it for review | Defamation, factual error and privacy all need a route, and it should end at a human. |
| Reasons are shown **verbatim or not at all** | No AI summarising. The moment a model paraphrases what a supporter wrote about a real person, the quote stops being a quote. |

### Why this is the trust product in miniature

The companion plan's Verified Community Record is: *this many real people backed
this person, and here is proof.* The testimony panel is the same claim in
human-readable form, a phase early, using data already collected. When the record
ships, the panel is already its front end.

---

## 4. Consent, and the unclaimed page

This needs saying plainly because it is a legal exposure, not a preference.

Nigeria's Data Protection Act 2023 has been operational under the NDPC's
**General Application and Implementation Directive (GAID) 2025** since 19
September 2025. A nomination is one person submitting **another identifiable
person's** name, photograph, phone number, employer and a written character
assessment — third-party personal data, processed and then published, without
the subject having been asked.

That is defensible for some of it and not for all of it:

| Field | Publish before a claim? | Reasoning |
|---|---|---|
| Name, category, cycle | **Yes** | Public recognition is the purpose of the programme, and the nominee is told. |
| Nomination reason (moderated, attributed) | **Yes** | Same purpose. This is the point of the award. |
| **Photograph** supplied by the nominator | **No — hold it** | A likeness is different in kind, and the nominator has no right to publish it. Show a monogram until the nominee claims and approves it, or a moderator does for a public figure. |
| Phone, email, employer, address | **Never** | Operational data. It has no place on a public page at any tier. |

Two things follow that are not optional:

1. **Tell the nominee.** The moment a nomination is approved, email the address on
   it: *you have been nominated, here is your page, here is how to claim it, and
   here is how to be removed.* Right now the platform can put somebody on a
   public ballot and never tell them. That is the gap to close first, and it is
   also the entire acquisition funnel for claiming.
2. **A removal route that works.** *"This isn't me"* and *"take me off"* must
   both resolve to a human, be answerable without an account, and be linked from
   the page itself. Under GAID this is a right, not a courtesy — and a platform
   that honours it loudly is more trusted, not less.

---

## 5. Timing — why "before voting commences" is the wrong gate

The brief says claiming happens before voting starts. I would open it there and
**never close it**, for a reason that costs nothing to accept and a lot to
ignore.

Nominees find out late. Somebody who hears on day two of voting, under a hard
cutoff, can never claim — so their page stays a stub with no photo and no brief
**while people are actively voting for them.** That is the worst outcome
available: worst for them, worst for their supporters, and worst for a platform
whose product is credibility.

So:

| Window | What opens |
|---|---|
| Nominations approved / shortlist set | **Claim opens.** Notification email goes out. |
| Claim → voting opens | The intended run: claim, write the brief, add the photo, enrol in the CRI. |
| During voting | Claim stays open. Editing stays open. **Enrolment stays open.** |
| After the cycle | Claim stays open forever. The record outlives the cycle. |

**And accrual back-credits.** Under the bracketed scheme in the companion plan
the Community Return is accrued per vote as it lands, so a nominee who claims in
week three is owed exactly what their supporters already generated — the
supporters were real regardless of when the paperwork was signed. Paying from the
start is both the fair answer and the cheap one, and it deletes an entire class
of support ticket.

One thing *is* hard-gated: **the brief and photo freeze at a published point**
before voting opens, and edits after that are versioned and visible. Otherwise a
nominee can rewrite their pitch mid-race and the ballot people voted on is not
the ballot that exists. Freeze the claim, not the claiming.

---

## 6. Contested claims, and the failure cases

The cases that will actually happen, and what each needs:

| Case | Handling |
|---|---|
| No email on the nomination | Cannot OTP. Route to a support-assisted claim: the assistant already exists and already refuses to disclose; this is a ticket with a documented evidence checklist. |
| Email is wrong / a typo | Same route. **The support path must never be a soft option** — it is where an attacker will aim once OTP is closed, so it ends at a human with a checklist, not at a chat. |
| Two people claim one nominee | **First verified claim holds.** The second becomes a dispute ticket. Never auto-transfer; never let a later claim silently displace an earlier one. |
| A manager claims for an artist | Allow it explicitly — `profile_type` already distinguishes individual / business / organisation — but record it as a *represented* claim, and never pay a represented claim to the representative's account. |
| Nominee says "this isn't me" | Removal route, §4. Their name comes off the ballot; the nomination stays in the audit trail. |
| Nominee claims, then wants out of the CRI | Enrolment is opt-in and revocable. Accrued return releases back to the pool, and the release is published like everything else. |
| Duplicate nominee rows for one person | Already solved — `MergeService`, `ProfileMergeService` and `MergeJournal` exist with an undo journal. Claiming must reuse them, not invent a second merge path. |

---

## 7. The flow, screen by screen

Designed for a phone on mobile data, which is the only device that matters here.

**1 · The email.** *"Adaeze — you have been nominated for Choral Excellence."*
One button: **See your page.** One line underneath: *not you? tell us.* No
account required to read either.

**2 · The page, unclaimed.** Name, category, the testimony panel, and a single
persistent bar: **"Is this you? Claim this page."** The testimony is the
argument; nothing else needs to sell it.

**3 · Claim.** One field — the email on the nomination, masked
(`a•••@gmail.com`) so it can be recognised without being disclosed. Send code →
6-digit OTP → done. Two screens, no password, no account creation as a separate
step. *If the masked address is wrong,* one link: **that isn't my address** →
support-assisted route.

**4 · Set up, in three short steps with a progress bar.** Photo → brief → links.
Every step skippable; the page is live from the first one. `completeness_pct`
already exists on profiles and should drive the bar.

**5 · The CRI invitation, and only now.** *"You can earn a Community Return as
supporters back you. Here is how it works, here is what you would need to
verify."* Then the ID/bank step, which is the only heavy screen in the flow and
is reached by people who have already decided they want it.

**6 · The dashboard afterwards.** Distinct supporters (not votes — see the
companion plan), the next milestone, accrued return, and what is still needed to
be paid. This is the page they come back to daily, and it is the one that makes
milestone-chasing feel like progress rather than gambling.

The whole path from email to a live claimed page is **two taps and a code**. The
heavy verification sits behind the money, where it belongs.

---

## 8. Data model

Small, because most of it exists.

**New — one table:**

```
gates_nominee_claims
  id, nominee_id, profile_id, user_id
  method        otp | support | admin
  claimed_email                     -- the address actually proven
  status        pending | held | active | rejected | superseded
  represented   0|1                 -- manager/label claiming for a person
  evidence_ref                      -- support ticket reference, when method=support
  claimed_at, verified_at, released_at
  UNIQUE (nominee_id) WHERE status='active'
```

The partial-unique is the invariant: **one active claim per nominee, in the
database, not in application logic.** MySQL has no partial index, so this is a
generated column or an `active_nominee_id` nullable-unique trick — worth doing
properly, because "two people own this page" is not a bug you want to discover
from a payout.

**Reused, unchanged:**

* `gates_nominees.profile_id` — the link is already modelled.
* `gates_profiles.verification_tier` — the ladder, already four levels.
* `gates_profiles.completeness_pct` — the setup progress bar.
* `gates_nominations.reason`, `nominator_name`, `nominator_state` — the testimony.
* `OtpService::generate()` — the proof.
* `MergeService` / `MergeJournal` — duplicates.
* `SupportTicketService` — the assisted route, with threads and an audit trail.

**Two small additions:**

* `gates_nominations.show_nominator` (default 0) — the attribution opt-in, mirroring
  `gates_votes.show_name` exactly.
* `gates_nominations.reason_status` (`pending|approved|hidden`) — testimony moderation,
  mirroring `gates_comments.status`.

---

## 9. Build order and honest sizing

Each step ships on its own and is useful before the next exists.

| # | Step | Size | Standalone value |
|---|---|---|---|
| 1 | **Tell nominees they were nominated** — email on approval, with a link to their page and a removal route | S | Closes the consent gap. Needed whether or not claiming ever ships. |
| 2 | **Testimony panel** — moderation status, attribution opt-in, render on the nominee page | M | The best content on the platform, finally visible. Independent of claiming. |
| 3 | **Photo hold** — nominator-supplied photos stop publishing until approved | S | Legal exposure closed. |
| 4 | **Claim by OTP** — token, landing, verify, link nominee→profile, set tier `basic` | M | Nominees own their pages. |
| 5 | **Self-service profile edit** — `ProfileService` is read-only today except `register()`; this is the real work | **L** | Every claimed page becomes maintainable. |
| 6 | **Support-assisted claim** — evidence checklist, dispute handling, contested-claim queue | M | The edge cases stop being unhandled. |
| 7 | **Freeze + versioned edits** at ballot publication | S | The ballot people voted on stays the ballot that exists. |
| 8 | **CRI enrolment + ID/bank verification** → tier `verified` | **L** | Gated on the CRI decisions in the companion plan. |
| 9 | **Nominee dashboard** — supporters, milestone, accrual | M | The habit loop. |

Steps 1–4 are the coherent first release and carry most of the value. Step 5 is
the honest surprise: **there is no self-service profile editing anywhere on this
platform today** — `ProfileService` can create and read, and that is all. Do not
let that be discovered mid-sprint.

Step 8 must not start before the CRI questions in the companion plan are settled,
because it hard-codes the answers.

---

## 10. What I would push back on

* **Do not let claiming alone unlock money.** If one thing survives from this
  document, that is it. `basic` edits, `verified` earns.
* **Do not close claiming when voting opens.** It costs nothing to leave open and
  strands real nominees to close.
* **Do not publish nominator-supplied photographs before a claim.** Names yes,
  likenesses no.
* **Do not let AI summarise nomination reasons.** Verbatim or absent. A
  paraphrase of what a supporter wrote about a named person is no longer
  testimony, and the moment one paraphrase is wrong the panel is worthless.
* **Do not build a second merge path.** The duplicate machinery exists and has an
  undo journal.
* **Do not ship claiming before nominees are told they were nominated.** Right now
  a person can be on a public ballot and never know. Fixing that is step 1 for a
  reason.

---

## 11. Open questions — I need your call on these

1. **Represented claims.** Should a manager or label be able to claim on behalf
   of an artist? It is common and legitimate in this sector, and it doubles the
   fraud surface. My recommendation: allow, label visibly, never pay to the
   representative.
2. **Rejected nominations.** If a nomination is rejected, does the nominee ever
   learn it existed? Silence is simpler; a rejection with a reason is kinder and
   generates appeals.
3. **How many testimonies to show.** All of them, or the three most recent with a
   "see all"? More is better for trust, worse for the fold.
4. **Unclaimed at close.** A nominee wins and never claimed. Does the prize and
   any accrued return wait indefinitely, or expire into the pool after a stated
   window? This needs an answer *before* it happens, in public, in the framework.
5. **Existing nominees.** There is a live cycle. Do we backfill claim invitations
   to everybody already on a ballot, or start with the next cycle? Backfilling is
   right and is also the largest single email send this platform will have done.

---

## Sources

* [NDPC issues GAID — key compliance insights (DLA Piper)](https://privacymatters.dlapiper.com/2025/06/nigeria-ndpc-issues-gaid-key-compliance-insights/)
* [The NDPA General Application and Implementation Directive 2025 becomes effective (Afriwise)](https://www.afriwise.com/blog/www-afriwise-com-blog-the-nigeria-data-protection-act-general-application-and-implementation-directive-2025-becomes-effective-today)
* [NDPC issues the NDPA GAID 2025 (Templars)](https://www.templars-law.com/knowledge-centre/ndpc-issues-the-nigeria-data-protection-act-general-application-and-implementation-directive-2025/)
* [Nigeria Data Protection Act GAID 2025 — full text (NDPC)](https://ndpc.gov.ng/wp-content/uploads/2025/07/NDP-ACT-GAID-2025-MARCH-20TH.pdf)

Data-protection points are research, not legal advice. The consent position in §4
should be confirmed by a Nigerian practitioner before the first notification
email goes out.
