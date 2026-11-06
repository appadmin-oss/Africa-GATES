# Reply to Feyisayo Amoo — AFG-PVOTE-5fa76fb70246

**Send from:** gates@afrovanguard.org.ng
**Subject:** Re: AFG-PVOTE-5fa76fb70246 — we are checking your payment now

---

## Before you send it: do this first (30 seconds)

Open **`/admin/vote-delivery`** and paste `AFG-PVOTE-5fa76fb70246` into
*"Look up one order"*. It tells you which of three things is true, and the reply
below has a version for each. **Do not send a version you have not checked** —
this is the second time she has been told something that turned out not to match
her bank, and a third would be the one she tells people about.

| What the lookup says | Send |
|---|---|
| `awaiting_delivery` / `claimed_but_missing` / `partial` | **Version A** — we have her money, votes are being added |
| `delivered` | **Version B** — the votes are already on |
| `pending` / `not_paid` | **Version C** — the honest hard one |

---

## Version A — the money is with us (most likely)

> Good morning Feyisayo,
>
> Thank you for your patience, and I am sorry you have had to chase this twice.
>
> I have checked reference **AFG-PVOTE-5fa76fb70246** myself. Your payment of
> **₦9,600** did reach us — the reminder email you received was wrong, and it went
> out because our record had not caught up with your bank. That is our fault, not
> anything you did.
>
> Your **50 votes for Oluwagbemiga Dorcas** in TEACHERS' CHOICE are being added
> now. You can see them yourself here, and this page reads our live records rather
> than repeating what I have told you:
>
> **https://afg.afrovanguard.org.ng/vote/verify?ref=AFG-PVOTE-5fa76fb70246**
>
> Nothing further is needed from you. Please do not pay again.
>
> Thank you for backing Dorcas, and I am sorry for the worry.
>
> — Africa GATES Support

## Version B — already delivered

> Good morning Feyisayo,
>
> Thank you for your patience, and I am sorry for the confusing email.
>
> I have checked reference **AFG-PVOTE-5fa76fb70246**. Your **50 votes for
> Oluwagbemiga Dorcas** are already counted — they went on after that reminder was
> sent, which is why the two messages contradicted each other. The reminder should
> never have reached you and we have fixed the cause.
>
> You can see the votes and the exact time each one was recorded here:
>
> **https://afg.afrovanguard.org.ng/vote/verify?ref=AFG-PVOTE-5fa76fb70246**
>
> Nothing is needed from you, and please do not pay again.
>
> — Africa GATES Support

## Version C — the gateway shows no completed payment

Send this **only** after the lookup says `pending` or `not_paid`. It has to be
honest without calling her a liar, because the likeliest explanation is that she
is right about her bank and wrong about what the charge means.

> Good morning Feyisayo,
>
> Thank you for your patience, and I am sorry you have had to chase this twice.
>
> I have checked reference **AFG-PVOTE-5fa76fb70246** directly with the payment
> provider. It shows the checkout was started but **no completed payment reached
> us**, which is why your votes have not been added.
>
> I believe you that your bank showed a deduction, and those two things are not a
> contradiction. When a card payment does not complete, most Nigerian banks still
> place a **pending authorisation** — the money leaves your available balance and
> looks exactly like a charge, then reverses itself, usually within 3–5 working
> days and sometimes up to 10. Nothing has been settled to us, so there is nothing
> for us to refund, and if we sent you money we would be sending you money we were
> never paid.
>
> Two things, please:
>
> 1. **Check whether it has reversed.** In your bank app, look for the entry
>    against your *available* balance rather than your statement. If it is gone,
>    that is the reversal.
> 2. **If it is still showing after 5 working days**, send me a screenshot of the
>    entry with the date and amount, and I will take it up with the payment
>    provider directly on your behalf. If they confirm it settled to us, you will
>    have your votes or your money back the same day — my word on that.
>
> In the meantime the ballot is still open, so if you want the votes on Dorcas now
> you can buy them here, and I will watch this reference personally so you are
> never charged twice:
>
> **https://afg.afrovanguard.org.ng/vote/verify?ref=AFG-PVOTE-5fa76fb70246**
>
> I am sorry this has taken three messages.
>
> — Africa GATES Support

---

## Then fix the thing that caused this

She was sent a *"payment was never completed"* email for a payment she had made.
Two causes, and both are worth ten minutes:

1. **The abandoned-cart email fired on a live payment.** `CheckoutMailer` has a
   `completedElsewhere()` guard, but it only catches orders that reached
   `confirmed` — not one still settling. The nudge window is now derived from
   `PaymentService::IN_FLIGHT_MINUTES` (2 h), which helps, but a bank transfer
   settling at hour three still gets this email. Consider re-verifying with the
   gateway before sending, for paid-vote orders only, where the cost of being
   wrong is this exact conversation.

2. **"Reply to this email quoting the reference" is not a working instruction.**
   She did it, twice, and told us it "isn't working" — because replying puts the
   work in a human queue with no visible progress. The email should point at the
   assistant, which re-asks the bank and credits on the spot without anybody
   waking up. `docs/email-templates/votes-not-minted.html` already leads with
   that; the abandoned-cart template does not.
