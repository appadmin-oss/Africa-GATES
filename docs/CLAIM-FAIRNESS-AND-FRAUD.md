# Claiming a nominee page: fair to everyone, stealable by no one

**The verification design.** Companion to `NOMINEE-CLAIMING-PLAN.md`, which
covers what claiming unlocks; this covers how somebody proves they are who they
say, and how a rural weaver with no email gets there as surely as a Lagos artist
with a bank app.

Status: proposal, nothing built · Date: August 2026

---

## 0. The principle

> **Fairness comes from many independent routes to ONE bar — never from lowering
> the bar for some people.**

Every naive design picks a side and loses. Demand a NIN and a bank app and you
exclude the 61-year-old master weaver in Alimosho who is exactly the person this
programme exists to recognise. Accept an email OTP and anybody who can read one
inbox can take a page and, under the CRI, the money attached to it.

The way out is to stop thinking of verification as a *gate* and start treating it
as an **evidence ledger**: several independent signals, each worth something,
accumulating to a threshold. Different people reach the same threshold by
different roads. Nobody is asked for a document they do not have, and nobody gets
in on one weak signal.

---

## 1. The fact that breaks the obvious design

> **The email on a nomination was typed by the NOMINATOR. It is a claim about the
> nominee, not proof from them.**

This is worth stating on its own because every first-draft claim flow in the
world sends an OTP to it and calls the job done.

Consider: I nominate a well-known choral director and, in the nominee email
field, I type **my own address**. The nomination is genuine — she really is
excellent, a moderator approves it, her page goes live and starts accruing a
Community Return. Then I "claim" it with an OTP sent to my own inbox. I have
proved that I can read my own email. The system records that as proof of her
identity.

There is no clever hardening of that flow. The address is attacker-controlled at
the point of entry, and no amount of OTP rigour fixes an input the attacker
supplied.

**Three consequences, all of which the design below is built around:**

1. An OTP to the nomination address is worth something only when that address is
   **independent of the nominator** — and the platform can check this for free,
   because `nominee_email` and `nominator_email` sit in the same row.
2. Independence has degrees. Two unrelated nominators typing the same nominee
   address is strong. One nominator typing an address one character from their
   own is not.
3. The most important control is not the gate at all. It is **telling the real
   person, on every channel we hold, that somebody just claimed their page** —
   including the channels the claimant did not use.

---

## 2. Threat model

Who actually attacks this, ordered by how likely they are.

| # | Attacker | Motive | What defeats them |
|---|---|---|---|
| 1 | **The nominator** | They control the email they typed; the page now has money on it | Independence check, nominator-vouch disqualification, device correlation |
| 2 | **Opportunist** | Sees a page with a payout, tries an address they own | Independence check + second signal |
| 3 | **Someone who knows the nominee** — bandmate, ex-manager, relative | Can answer questions, may hold photos, may know the address | Notification fan-out to channels they do not control; bank name match |
| 4 | **Rival** | Claim to sabotage, or to lock the real person out | Reversibility; a rejected claim never blocks a later one |
| 5 | **Farmer** | Claims many nominees across a cycle to aggregate payouts | Velocity limits; one-active-claim-per-person review |
| 6 | **Coerced claim** | Real nominee pressured to claim and hand over the payout | Payout to a name-matched account only; cooling-off |

Attacker 1 is the one most designs miss, and it is the *most likely* — because
they are already engaged, already know the page exists, and had legitimate access
to the form.

Attacker 3 is the hardest, and the honest answer is that no remote verification
fully stops a determined person who knows their victim. What stops them is that
the theft is **loud** (§5) and the **money will not move** (§6).

---

## 3. The signals

Each is independent, each is verifiable, **none is mandatory.** Weight is worth
in points; the thresholds are in §4.

| Signal | Pts | Who can reach it | Rail |
|---|---:|---|---|
| Email OTP to the nomination address, **independent of every nominator on file** | 2 | Most | `OtpService` ✓ |
| Email OTP where **two or more unrelated nominations** name the same address | 4 | Nominees with several nominations | free, in data |
| **SMS OTP** to the nomination phone, independent of the nominator's | 2 | Most — a phone is near-universal | `SmsService` ✓ |
| **WhatsApp OTP** to that number | 2 | Very high reach in Nigeria | `SmsService` ✓ (Meta Cloud) |
| **Nominator vouch** — the nominator confirms "yes, that is them" from their own verified address | 2 | Everyone. Costs nothing | `OtpService` ✓ |
| **Organisation vouch** — the school, church, employer or troupe named on the nomination confirms | 4 | Institutional nominees | email + review |
| **Bank account name match** — NUBAN resolve, name compared to nominee name | 4 | Anyone with an account | gateway API — **new** |
| **NIN / BVN** name + date-of-birth match | 4 | Most adults | provider — **new** |
| **Verified social account** posts a one-time code | 2 | Anyone with public reach | manual check |
| **Live video call** with an operator, on the phone number on file | 4 | **Everyone** | scheduled, human |
| **Existing platform history** — a profile at that address, or votes cast from it before the nomination | 1 | Some | free, in data |

Two rules that keep the ledger honest:

* **Same-rail signals do not stack.** Email OTP and "two nominations agree" are
  one email fact; take the higher, never the sum. Likewise SMS and WhatsApp to
  the same number are one phone fact.
* **The nominator vouch is void when the nominator is the claimant** — same
  address, same device fingerprint, or same IP. Otherwise attacker 1 vouches for
  themselves.

---

## 4. The two thresholds

| | Points | Must include | Unlocks |
|---|---:|---|---|
| **Claim** → tier `basic` | **4** | at least two *different rails* | Edit the page: brief, photo, links. Respond to testimony. **No money, ever.** |
| **Verify** → tier `verified` | **8** | at least one identity-grade signal (bank, NIN/BVN, org vouch, or video call) | CRI enrolment and payout |

Why 4-with-two-rails rather than one strong signal: a single rail is a single
point of failure, and the failure is somebody else's identity. Two rails means
an attacker needs the victim's inbox **and** their phone, or their inbox and
their nominator's cooperation.

Why the payout bar names identity-grade signals explicitly: money leaving the
platform in a person's name needs something that ties to *that person*, not to a
device they hold. Note that **four different signals qualify**, one of which — the
video call — needs no document at all.

---

## 5. The control that matters more than the gate

> **When a claim is made, tell the nominee on EVERY channel on file — including
> the ones the claimant did not use.**

If a thief claims via an email they control, the nominee's **phone** still gets an
SMS and a WhatsApp message: *"Someone has claimed your Africa GATES page. If this
was not you, press here."* One tap opens a dispute; no account needed.

This is the highest-value control in the entire design, and it is close to free
because `SmsService` and `OtpService` already exist. It works against attacker 3,
whom no gate reliably stops, and it converts a silent theft into a loud one.

Paired with it:

| Control | What it does | Cost |
|---|---|---|
| **7-day cooling-off** before any money moves on a new claim | A thief needs the victim to not notice for a week, across three channels | free |
| **Device/IP correlation** — claimant fingerprint vs the nominator's on the nomination row | Attacker 1, caught deterministically. `device_fp` and `ip_hash` are already stored | free ✓ |
| **Velocity limits** — one person claiming several nominees goes to review | Attacker 5 | `RateLimitService` ✓ |
| **Name-matched payout only** | Even a successful impersonation cannot be cashed | with §3 bank signal |
| **Reversibility** — a claim can be revoked and accrual is held, not paid, during a dispute | Makes every mistake recoverable | free |

**Detection, notification and a slow payout beat a hard gate — and they cost
nobody their claim.**

---

## 6. Four people, four roads to the same bar

The fairness argument is only real if it survives contact with actual people.

**Adaeze — choir director, 34, Alimosho.** Nomination carries her Gmail (not her
nominator's) and her phone. Email OTP **2** + WhatsApp OTP **2** = **4. Claimed**,
in about ninety seconds, no documents. Later, for the CRI, her bank name match
**4** takes her to 8. **Verified.**

**Baba Sule — master weaver, 61.** His customer nominated him and typed *her own*
email by mistake. He has a phone and no email at all.
The independence check **fails** on the email — correctly, it is the nominator's.
SMS OTP to his own number **2** + nominator vouch (she confirms from her address;
she is not the claimant, so it counts) **2** = **4. Claimed.**
For the CRI: a video call **4** takes him to 8. **Verified without a single
document or an email address.** He is not a second-class case; he took a
different road to the same bar.

**Chidi — musician, managed.** His manager claims. Represented claim, labelled on
the page. Manager reaches 4 on email + phone. Payout later resolves to **Chidi's**
name-matched account, never the manager's — so representation gets the admin work
done and grants no access to the money.

**The impostor.** Nominated the director, typed his own address. Independence
check **fails immediately** — `nominee_email` matches `nominator_email`. He
switches to the device route; `device_fp` on the nomination matches his claim
device. **Zero points, review flagged.** Even had he slipped through, the real
director's phone would have rung within seconds, and the payout would have failed
at a bank account in the wrong name.

---

## 7. Fairness safeguards — the other half of the word

Anti-fraud without these is just exclusion with a security justification.

1. **Never charge to claim or verify.** A fee is a tax on the poorest nominees
   and would make the verification bar a proxy for wealth.
2. **No deadline that strands anyone.** Claiming opens at shortlist and never
   closes; accrual back-credits. (Established in the companion plan.)
3. **A human route always exists**, reachable without an account, with a stated
   turnaround. The support assistant already refuses to disclose and already
   opens threaded tickets — this is a checklist on top of it, ending at a person.
4. **Assisted claiming is legitimate.** A daughter, a choirmaster, a cyber-café
   operator helping somebody claim is normal here. Allow it; log that it happened;
   keep the payout tied to the nominee's own verified account.
5. **Plain language, translatable.** This is the one flow that must read at a
   primary-school level and be ready for Yoruba, Hausa, Igbo and Pidgin.
6. **A rejected claim never locks the page.** The real person must always be able
   to try again. A page locked by an attacker's failed attempt is the attacker
   winning anyway.
7. **Refusals carry a reason and an appeal**, and the appeal goes to somebody who
   did not make the original decision.
8. **Publish the fairness numbers** (§9). A verification system nobody audits
   drifts, and it drifts against the people with the least recourse.

---

## 8. Data model

Two tables. Everything else already exists.

```
gates_nominee_claims
  id, nominee_id, profile_id, user_id
  status        pending | active | held | rejected | revoked | superseded
  points        -- cached sum of active evidence
  represented   0|1
  cooling_until -- no money moves before this
  device_fp, ip_hash          -- the claimant's, for the correlation check
  claimed_at, activated_at, revoked_at, revoked_reason

gates_claim_evidence
  id, claim_id
  rail          email | phone | vouch | org | bank | nin | social | video | history
  signal        -- the specific test that passed
  points
  detail_hash   -- what was proven, hashed. Never the raw NIN or account number
  verified_at, verified_by     -- 'system' or an operator id
```

Design notes worth keeping:

* **The evidence table is append-only.** How somebody was verified is an audit
  record; if a decision is later challenged, "we no longer store why" is not an
  answer a trust platform can give.
* **`detail_hash`, never the raw value.** A NIN or an account number verified once
  should not sit in a database afterwards. Store proof that it matched, not the
  thing itself.
* **One active claim per nominee, enforced in the database**, not in application
  code. MySQL has no partial unique index, so use a nullable `active_nominee_id`
  column with a UNIQUE on it — "two people own this page" must be impossible, not
  merely unlikely.
* **Points are cached but recomputable.** The evidence rows are the truth.

---

## 9. The numbers to publish

Per cycle, on the public integrity page — including the ones that look bad:

* claims made · claims activated · median time to claim
* claim rate by **state and LGA** — a verification system that works in Lagos and
  fails in Kano is broken, and this is the only way anyone finds out
* route mix — how many reached the bar by phone, by vouch, by video call
* refusals, appeals, and **appeals upheld**
* disputed claims, and how many were revoked
* claims caught by the device-correlation check

If the LGA spread is uneven, that is a fairness bug and publishing it is what
forces the fix. Nobody publishes this. That is the point.

---

## 10. Build order

| # | Step | Size | Why here |
|---|---|---|---|
| 1 | **Independence check** — compare `nominee_email`/`phone`/`device_fp` against every nominator on the row | S | The whole design rests on it, and the data is already stored |
| 2 | **Notification fan-out** on claim, to every channel on file | S | Highest-value control; both rails exist |
| 3 | **Evidence ledger** — the two tables, scoring, thresholds | M | The spine |
| 4 | **Email + SMS + WhatsApp OTP rails** | M | Gets most nominees to 4 |
| 5 | **Nominator vouch** | S | The rail that makes Baba Sule's road work |
| 6 | **Dispute + revoke + cooling-off** | M | Makes every mistake recoverable |
| 7 | **Operator queue** — review flags, video-call scheduling, appeals | M | The human floor under everything |
| 8 | **Bank name match** (gateway resolve) and **NIN/BVN** | L | Only needed for the payout bar |
| 9 | **Published fairness metrics** | S | Ships with the first real cycle |

Steps 1–5 are a coherent first release: most nominees can claim, the two commonest
attacks are closed, and nobody is asked for a document.

---

## 11. Decisions I need from you

1. **Points and thresholds.** 4 to claim, 8 to be paid, with the weights in §3 —
   are those the right dials? They are deliberately conservative and easy to
   loosen once real refusal rates exist.
2. **Video call as the universal floor.** It is the thing that makes this fair for
   people with no documents, and it costs operator time per nominee. Are you
   willing to staff it? If not, the honest answer is that some nominees cannot
   reach the payout bar, and that must be said publicly rather than discovered.
3. **Organisation vouch.** Strong signal, and it hands a school or church power
   over an individual's claim. Worth it for institutional categories; risky where
   the nominee and the institution have fallen out.
4. **NIN.** Highest assurance, real friction, and a meaningful data-protection
   obligation the moment it is collected. My recommendation: bank name match
   first, NIN only where the bank route fails.
5. **Cooling-off length.** Seven days is my proposal. Shorter is friendlier to
   honest nominees; longer is safer against a theft nobody notices over a
   weekend.

---

## 12. What I would refuse to build

* **A single-signal claim.** One email OTP is not proof of anything when the
  nominator typed the address.
* **A paid fast lane.** Verification that money can shorten is not verification.
* **Silent claiming.** No claim without the fan-out in §5.
* **Auto-transfer between claimants.** A second claim on a held page goes to a
  human, always. Never let a later claim silently displace an earlier one.
* **Storing raw NINs or account numbers** after the check has passed.
* **An AI decision on identity.** Models can triage a queue and prioritise a
  reviewer's day. A person's claim on their own name is decided by a person.
