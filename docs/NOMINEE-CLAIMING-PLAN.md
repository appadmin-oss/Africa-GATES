# Nominee profile claiming

**How a nominee takes ownership of their page and enters the Community Return.**

Status: decided, not built · Date: August 2026
Companion: `CLAIM-FAIRNESS-AND-FRAUD.md` (verification) · `REPUTATION-INFRASTRUCTURE-PLAN.md` (the CRI)

---

## 0. Decisions taken

These were chosen, not assumed. Everything below follows from them.

| # | Decision |
|---|---|
| 1 | Claiming gives the nominee **their voting profile AND a permanent registry page** — and where they already have an account, it **links to that** rather than creating a second one. |
| 2 | The ballot page shows **everything the nominator submitted** *and* **the reason, quoted and attributed** — beneath the nominee's own words. |
| 3 | **Claiming IS enrolment in the CRI.** One act. Identity and bank checks move to **payout** time, not the door. |

---

## 1. The finding that makes this cheap

**The ballot page already renders from a linked registry profile.** This is not
something to build; it is something to switch on.

`VoteController::nominee()` reads `gates_nominees.profile_id` and, when it is
set, loads the `gates_profiles` row. `vote-nominee.twig` then already:

* prefers `profile.avatar_path` over `n.photo_path` for the portrait;
* shows `profile.cpi_tier` and `profile.cpi_score`;
* leads with the brief (`n.tagline`) and follows with `profile.bio` — the
  two-layer idea is **already implemented**, with a comment explaining why the
  bio joins the brief rather than replacing it;
* links to `/registry/{profile.slug}` when a profile exists.

So the whole "nominee edits their page" mechanism exists. What is missing is
narrow and specific:

1. **The link** — nothing ever sets `profile_id` from the nominee's side.
2. **The edit surface** — `ProfileService` can `register()` and read. There is no
   self-service editing anywhere on this platform.
3. **The nominator band** — the nomination payload has never been displayed.

That is the build. Three things, not a system.

---

## 2. What claiming does

One act, in this order:

```
prove you are this person
        ↓
link or create the registry profile      ← decision 1
        ↓
gates_nominees.profile_id is set
        ↓
the ballot page starts rendering YOUR words
        ↓
you are enrolled in the Community Return  ← decision 3
```

**Link or create** is not a preference — `gates_profiles.email` is UNIQUE, so a
person who already has a profile *cannot* be given a second one. The schema
enforces the right behaviour:

* email already has an approved profile → **link** it, and the nominee inherits
  the CPI, bio and avatar they already had;
* no profile at that address → **create** one, `status = 'approved'`,
  `verification_tier = 'basic'`, seeded from the nomination (name, organisation,
  photo if the nominee approves it);
* email belongs to somebody else's profile → this is a *conflict*, not a link.
  It goes to a human. See the fairness doc.

---

## 3. The ballot page, in three bands

Decision 2. Order matters: the nominee speaks first, and the record backs them.

```
┌────────────────────────────────────────────────────────┐
│  ADAEZE OKONKWO                     ✓ Verified nominee  │
│  Choral Excellence · 2026        CPI 412 · Gold         │
├────────────────────────────────────────────────────────┤
│  IN HER WORDS                                           │
│  Brief (n.tagline) — the short line on the ballot.       │
│  Bio (profile.bio) — the fuller thing, if she wrote it.  │
│                          ← both already render today     │
├────────────────────────────────────────────────────────┤
│  FROM HER NOMINATION                    3 nominations   │
│  Organisation   St Mary's Choral Society                │
│  Role           Director of the intake programme        │
│  References     2 links ↗                               │
│                          ← never displayed before        │
├────────────────────────────────────────────────────────┤
│  WHY SHE WAS NOMINATED                                  │
│  "She stayed after every rehearsal for a term to teach  │
│   the harmony line to people who had never read music." │
│                        — Chidi O., Lagos · Feb 2026     │
│  "…"                   — Blessing A., Ogun · Feb 2026   │
│                                            + 1 more     │
└────────────────────────────────────────────────────────┘
```

**Before a claim** the first band is simply absent and the other two carry the
page. An unclaimed page is therefore not empty — it is a record of what other
people said. That is a better unclaimed state than any competitor has, and it is
also the strongest reason to claim: *three people wrote this about you; add your
side.*

**The nominee never overwrites the nominator's bands.** They add theirs on top.
That is the whole trust proposition of the page: you can see what was claimed
about someone and what they say themselves, and tell them apart at a glance.

### Rules on the nominator bands

| Rule | Why |
|---|---|
| Only from **approved** nominations | An unapproved nomination is an unreviewed allegation about a named person. |
| Reasons **moderated before publication** | Same risk class as comments, higher blast radius — it names someone. `SpamService` and the AI path already exist. |
| Attribution is **first name + last initial + state**, opt-in | Exactly the `gates_votes.show_name` precedent already on the ballot. Default private → "A supporter, Lagos". |
| Nominator contact details **never render** | They are in the row; they are not the reader's business. |
| Nominee may **respond**, never edit or delete | Testimony the subject can delete is worthless as evidence. Responding is the honest remedy. |
| Nominee may **flag** for review | Defamation, factual error and privacy each need a route that ends at a human. |
| Reasons shown **verbatim or not at all** | No AI summarising. A paraphrase of what a supporter wrote about a real person is no longer testimony. |
| Nominator-supplied **photographs are held** until the nominee approves them | A likeness is different in kind from a name. See §6. |

---

## 4. Timing

Claiming opens when the shortlist is set and **closes when voting opens** — your
original instruction, and I now think it is right for a reason I talked past the
first time:

> If claiming stays open during voting, an early claimer has a richer page than a
> late one **while votes are being cast**. The ballot people vote on should be
> the same ballot for everyone.

So:

| Window | State |
|---|---|
| Nominations approved | **Claim opens.** Notification goes out on every channel on file. |
| Claim window → voting opens | Claim, write, upload, enrol. Reminders at 7 days, 48 hours, and the morning of the close. |
| Voting opens | **Ballot content freezes.** No brief, bio or photo edits. |
| During voting | Late claims are still accepted, and they **enrol and accrue** — but they do not change the ballot page until the cycle closes. |
| After the cycle | Editing reopens. The registry profile is permanent. |

That last row is what stops the cutoff being cruel. Somebody who hears on day two
of voting still gets their page, still gets their Community Return, still gets
back-credited from the first vote — because the supporters were real whenever the
paperwork got signed. What they do not get is to change the ballot mid-race.
**Freeze the page, not the person.**

---

## 5. The flow

Built for a phone on mobile data.

1. **The email / SMS / WhatsApp.** *"Adaeze — you have been nominated for Choral
   Excellence."* One button: **See your page.** One line under it: *not you? tell
   us.* No account needed to read either.
2. **The page, unclaimed.** The nominator bands, and one persistent bar:
   **"Is this you? Claim your page."** The testimony is the argument.
3. **Claim.** The masked address on the nomination (`a•••@gmail.com`), a code,
   done. Two screens, no password. *If the address is wrong* → one link to the
   assisted route.
4. **Set up — three steps, a progress bar, every step skippable.** Photo → brief
   → details. The page is live from the first one. `completeness_pct` exists on
   profiles already and should drive the bar.
5. **You are in the Community Return.** Stated plainly at the end of setup, with
   what it means and what will be needed *later* to be paid. Not a second signup.
6. **The dashboard.** Distinct supporters, next milestone, accrued return, and the
   one outstanding thing — verify your bank details — surfaced when it starts to
   matter, not at the door.

Email to a live claimed page: **two taps and a code.**

---

## 6. Consent, and the unclaimed page

Nigeria's NDPA 2023 has been operational under the NDPC's **GAID 2025** since 19
September 2025. A nomination is one person submitting **another identifiable
person's** name, photograph, phone number, employer and a written character
assessment. Third-party personal data, published, without the subject asked.

| Field | Publish before a claim? | Reasoning |
|---|---|---|
| Name, category, cycle | **Yes** | Public recognition is the purpose, and the nominee is told. |
| Organisation, role, reason (moderated) | **Yes** | Same purpose. This is the award. |
| **Photograph** from the nominator | **No — hold** | A likeness is not the nominator's to publish. Monogram until the nominee or a moderator approves it. |
| Phone, email, address | **Never** | Operational data. No place on a public page at any tier. |

Two consequences that are not optional:

1. **Tell the nominee.** Today a person can be on a public ballot and never know.
   That is the gap to close *before* claiming ships — and it is also the entire
   acquisition funnel for it.
2. **A removal route that works.** *"This isn't me"* and *"take me off"* both
   resolve to a human, work without an account, and are linked from the page.
   Under GAID this is a right, not a courtesy.

---

## 7. Data model

```
gates_nominee_claims
  id, nominee_id, profile_id, user_id
  status        pending | active | held | rejected | revoked
  method        otp | assisted | admin
  represented   0|1              -- a manager claiming for an artist
  device_fp, ip_hash             -- the claimant's, for the independence check
  claimed_at, activated_at, revoked_at, revoked_reason
  active_nominee_id              -- nominee_id when active, NULL otherwise; UNIQUE
```

`active_nominee_id` is the invariant: **one active claim per nominee, enforced by
the database.** MySQL has no partial unique index, so the nullable-unique column
is the portable way. "Two people own this page" must be impossible, not unlikely.

**Two small additions to `gates_nominations`:**

* `show_nominator` (default 0) — attribution opt-in, mirroring `gates_votes.show_name`.
* `reason_status` (`pending|approved|hidden`) — moderation, mirroring `gates_comments.status`.

**Everything else is reuse:** `gates_nominees.profile_id`,
`gates_profiles.verification_tier` / `completeness_pct` / `status`,
`OtpService`, `SmsService`, `SupportTicketService`, `MergeService` for duplicates.

---

## 8. Build order

| # | Step | Size | Value on its own |
|---|---|---|---|
| 1 | **Notify nominees they were nominated** — email + SMS + WhatsApp, with the page link and a removal route | S | Closes the consent gap. Needed regardless. |
| 2 | **Nominator bands** on the ballot page — facts + moderated, attributed reasons | M | The best content on the platform, finally visible. No claiming required. |
| 3 | **Hold nominator photos** until approved | S | Legal exposure closed. |
| 4 | **Claim** — OTP, independence check, link-or-create profile, set `profile_id` | M | The page becomes theirs; the ballot already renders it. |
| 5 | **Self-service profile editing** — `ProfileService` is read-only today | **L** | The honest big one. Every claimed page becomes maintainable. |
| 6 | **Freeze at ballot publication** | S | The ballot voted on stays the ballot that exists. |
| 7 | **Assisted claim + disputes + revoke** | M | The edge cases stop being unhandled. |
| 8 | **Nominee dashboard** — supporters, milestone, accrual | M | The habit loop. |
| 9 | **Payout verification** — bank name match, then NIN only if that fails | L | Gated on the CRI decisions in the companion plan. |

Steps 1–5 are the coherent first release. Step 5 is the one to plan properly:
**there is no self-service profile editing anywhere on this platform today.**

---

## 9. Still open

1. **Represented claims.** Managers claiming for artists is common and legitimate
   here, and doubles the fraud surface. Recommendation: allow, label on the page,
   never pay to the representative's account.
2. **Rejected nominations.** Does the nominee ever learn one existed? Silence is
   simpler; a reason is kinder and generates appeals.
3. **How many reasons to show** before "see all". More is better for trust, worse
   for the fold.
4. **Unclaimed at close.** A nominee wins, or accrues a return, and never claimed.
   Does it wait indefinitely or expire into the pool after a stated window? This
   needs a public answer *before* it happens.
5. **Backfill.** There is a live cycle. Do we send claim invitations to everyone
   already on a ballot? Right, and the largest single send this platform has done.
