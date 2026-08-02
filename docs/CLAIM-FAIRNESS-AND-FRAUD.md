# Claiming: fair to everyone, stealable by no one

**The verification design.** Companion to `NOMINEE-CLAIMING-PLAN.md`, which
covers what claiming *is*; this covers how somebody proves they are who they say.

Status: decided, not built · Date: August 2026

---

## 0. What changed, and why this document got shorter

The earlier draft put a heavy evidence ledger — points, video calls, NIN — at the
**claim** door, because I had split claiming from CRI enrolment and assumed the
claim released money.

**It does not.** Claiming is enrolment; enrolment is not payment. Money leaves
the platform at **payout**, weeks later, and that is where identity has to be
proven. So the design is now two bars in two places:

| | When | Bar | Cost to the nominee |
|---|---|---|---|
| **Claim** | Before voting | Prove you can be reached at an address the nominator did not control | A code. Two screens. |
| **Payout** | When money moves | Prove you are the person, and that the account is yours | Bank name match, or a fallback |

That split is what makes this both fair and safe. A stolen claim gets you a page
you can edit under a name that is not yours — and **the money still cannot move**,
because it moves on a name-matched bank account you do not have.

> **Put the friction where the money is, not where the photo upload is.**

---

## 1. The fact that breaks the obvious claim flow

> **The email on a nomination was typed by the NOMINATOR. It is a claim about the
> nominee, not proof from them.**

Every first-draft claim flow sends an OTP to it and calls the job done.

I nominate a well-known choral director and, in the nominee email field, type
**my own address**. The nomination is genuine, a moderator approves it, her page
goes live. Then I "claim" it with a code sent to my own inbox. I have proved that
I can read my own email; the system records it as proof of her identity.

No amount of OTP rigour fixes an input the attacker supplied. Three consequences:

1. An OTP to the nomination address counts **only when that address is
   independent of every nominator on the row** — free to check, because
   `nominee_email` and `nominator_email` are in the same row.
2. Same for the phone, and same for the device: `gates_nominations` already
   stores `ip_hash` and `device_fp`. A claimant whose device fingerprint matches
   the nominator's is the case above, caught deterministically.
3. **The most important control is not the gate.** It is telling the real person,
   on every channel we hold, that somebody just claimed their page.

---

## 2. The claim bar

Deliberately light, because it does not release money.

**Pass if:** an OTP is confirmed at an address or number on the nomination
**that is independent of every nominator** on that nomination.

Independence fails when any of these match a nominator on the row:
* the same email address, or the same local-part on the same domain;
* the same phone number;
* the same `device_fp` or `ip_hash` at claim time.

**When independence fails, the claim is not refused — it is *held*** and routed to
the assisted path. That distinction matters: the person whose nominator typed
their own address by mistake is the *most* likely to be the real nominee, and
telling them "no" would be exactly backwards.

### The assisted path

A held or impossible claim goes to a human, through the ticket system that
already exists, with a checklist rather than a conversation:

* nominator vouch — the nominator confirms from their own verified address (void
  when the nominator *is* the claimant);
* organisation vouch — the school, church or troupe named on the nomination;
* a verified social account posting a one-time code;
* a short video call, on the number on file.

Any one of these clears a held claim. **A person with no email, no document and a
borrowed phone can still claim** — that is the floor, and it is deliberate.

---

## 3. The payout bar

This is where the earlier draft's rigour belongs.

**Required before any money moves:**

1. **Bank account name match.** Resolve the NUBAN through the gateway and compare
   to the nominee's name. Near-universal in Nigeria, no document, ~10 seconds.
2. **The Integrity Gate** from the reputation-infrastructure plan — collusion
   findings, snapshot-chain integrity, reconciliation.
3. **Cooling-off.** No money on a claim less than 7 days old.

**If the bank name does not match** — a married name, a stage name, a joint
account — it goes to review, with NIN/BVN or a video call as the fallback. Not a
refusal.

**Never**: payment to an account in someone else's name, including a manager's.
A represented claim gets the admin work done and grants no access to the money.

---

## 4. Threat model

| # | Attacker | Motive | Stopped by |
|---|---|---|---|
| 1 | **The nominator** | Controls the address they typed | Independence check (§1) — deterministic, free |
| 2 | **Opportunist** | Sees a page with a payout | Independence check + notification |
| 3 | **Someone who knows them** — bandmate, ex-manager, relative | Knows the address, may hold photos | Notification on channels they do not control; **name-matched payout** |
| 4 | **Rival** | Claim to sabotage or lock the real person out | Reversibility; a rejected claim never blocks a later one |
| 5 | **Farmer** | Many claims across a cycle | Velocity limits (`RateLimitService`) |
| 6 | **Coercion** | Real nominee pressured to hand over the payout | Payout to their own account only; cooling-off |

Attacker 1 is the *most likely* and the one most designs miss — they are already
engaged, already know the page exists, and had legitimate access to the form.

Attacker 3 is the hardest, and the honest answer is that no remote check reliably
stops someone who knows their victim. What stops them is that the theft is
**loud** (§5) and **the money will not move** (§3).

---

## 5. The control that matters more than the gate

> **When a claim is made, tell the nominee on EVERY channel on file — including
> the ones the claimant did not use.**

A thief who claims via an email they control still sets the victim's **phone**
ringing: *"Someone has claimed your Africa GATES page. If this was not you, press
here."* One tap opens a dispute. No account needed.

`OtpService` and `SmsService` (SMS **and** WhatsApp) both exist, so this is close
to free — and it is the only thing that works against attacker 3.

Paired with it:

| Control | Cost |
|---|---|
| 7-day cooling-off before any money moves | free |
| Device/IP correlation against the nominator | free — columns already stored |
| Velocity limits on multiple claims | free — `RateLimitService` |
| Name-matched payout only | with §3 |
| Revocable claims; accrual **held**, not paid, during a dispute | free |

**Detection, notification and a slow payout beat a hard gate — and they cost
nobody their claim.**

---

## 6. Four people, four roads

**Adaeze — choir director, 34, Lagos.** Nomination carries her own Gmail. OTP →
**claimed in ninety seconds, enrolled in the CRI.** Weeks later, at payout, her
bank name matches. Paid.

**Baba Sule — master weaver, 61.** His customer nominated him and typed *her own*
email. Independence fails → the claim is **held**, not refused. His own number is
also on the nomination: SMS OTP clears it. **Claimed and enrolled, no document,
no email address.** At payout his account name matches. Paid.

**Chidi — musician, managed.** His manager claims; the page is labelled
*represented*. At payout the account must resolve to **Chidi**. The manager did
the admin and cannot touch the money.

**The impostor.** Nominated the director, typed his own address. Independence
fails on the first check; his device fingerprint also matches the nomination.
Held, reviewed, rejected. Even had he passed, her phone would have rung within
seconds — and the payout would have died at a bank account in the wrong name.

---

## 7. Fairness safeguards

Anti-fraud without these is exclusion with a security justification.

1. **Never charge to claim or verify.** A fee makes the bar a proxy for wealth.
2. **A held claim is not a refused claim.** The wording, the email and the UI must
   all say *"we need one more thing"*, never *"denied"*.
3. **A human route always exists**, without an account, with a stated turnaround.
4. **Assisted claiming is legitimate.** A daughter or a cyber-café operator
   helping someone claim is normal here. Allow it, log it, keep the payout tied
   to the nominee's own account.
5. **Plain, translatable language.** This is the one flow that must read simply
   and be ready for Yoruba, Hausa, Igbo and Pidgin.
6. **A rejected claim never locks the page.** The real person must always be able
   to try again.
7. **Refusals carry a reason and an appeal**, decided by someone other than the
   original decider.
8. **Publish the numbers** (§8).

---

## 8. The numbers to publish

Per cycle, on the public integrity page — including the ones that look bad:

* claims made · activated · held · median time to claim
* **claim rate by state and LGA** — a system that works in Lagos and fails in Kano
  is broken, and this is the only way anyone finds out
* route mix — OTP, vouch, video call
* refusals, appeals, **appeals upheld**
* disputes raised and claims revoked
* claims caught by the independence check
* **payout failures by cause** — name mismatch is the one to watch, because a
  high rate means the bar is catching honest people, not thieves

Nobody publishes this. That is the point.

---

## 9. Build order

| # | Step | Size |
|---|---|---|
| 1 | **Independence check** — email, phone, `device_fp`, `ip_hash` vs every nominator | S |
| 2 | **Notification fan-out** on claim, every channel on file | S |
| 3 | **Claim by OTP**, with *held* as a first-class outcome | M |
| 4 | **Assisted path** — checklist, vouch, review queue | M |
| 5 | **Dispute, revoke, cooling-off** | M |
| 6 | **Payout bar** — bank name match, then NIN/video only on failure | L |
| 7 | **Published fairness metrics** | S |

Steps 1–3 are the first release: most nominees claim in ninety seconds, the two
commonest attacks are closed, and nobody is asked for a document.

---

## 10. What I would refuse to build

* **A claim that ignores independence.** One OTP to a nominator-supplied address
  is not proof of anything.
* **A paid fast lane.** Verification money can shorten is not verification.
* **Silent claiming.** No claim without the fan-out in §5.
* **Auto-transfer between claimants.** A second claim on an active page goes to a
  human, always.
* **Storing raw NINs or account numbers** once the check has passed. Store that it
  matched, not the thing itself.
* **An AI decision on identity.** Models may triage the queue. A person's claim on
  their own name is decided by a person.
