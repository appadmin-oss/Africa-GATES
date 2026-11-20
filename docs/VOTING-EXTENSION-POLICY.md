# Voting extension policy

**Owner:** Programmes lead. **Applies to:** every award edition (`gates_award_cycles`) with a
public vote. **Code:** `src/Services/VotingExtension.php`, the "Extend voting" card on
`/admin/programmes/{id}/cycle`, table `gates_voting_extensions`
(`database/migrations/2027_06_03_voting_extensions.php`).

An extension moves the moment a public vote ends. Done quietly, it looks like the platform
moving the finish line to change a result or to sell more paid votes. This policy sets out
when that is allowed and how to do it so the record shows it was fair.

## When an extension is allowed

Only for a **documented cause** that stopped people voting or made the vote unfair, such as:

- **Outage:** the site, the ballot or the database was down or unusable for a material part
  of the window.
- **Payment or verification failure:** OTP codes were not delivered (an email or SMS provider
  outage), or the payment gateway refused or lost checkouts.
- **Late nominee notification:** nominees were told they were on the ballot too late to
  campaign (for example, the voting letter went out late).
- **Data error fixed late:** a nominee was missing, mis-categorised or shown wrongly and the
  fix landed close to the close.

Keep the evidence for the cause (the incident, the mail log, the gateway report) and name it
in the public reason.

## When it is never allowed

- **Never for leaderboard reasons.** That includes a close race, a nominee or sponsor asking
  for more time, low turnout, or a hope that a particular nominee catches up.
- **Never to raise money.** That is why paid votes stop at the previous close by default.
- **Never after results are released.** The form refuses this.
- **Never past the results date.** Move the results date first, under its own rules, or don't
  extend. The form refuses a new close at or after the results date.

## Limits

- **Length:** 72 hours at most. The form warns above 72 hours. A longer extension needs the
  Programmes lead's written sign-off, and the reason must say why.
- **Count:** one extension per edition. A second needs its own documented cause, and the
  operator must tick "This is a further extension". The public notice then lists every reason.
- **Decide before the final-day counts.** Decide on the extension because of the cause, before
  anyone has looked at the closing-day standings. The standings are captured automatically when
  the extension is recorded (`snapshot_ref`), so the timing can be checked later.
- **All categories.** An extension always covers the whole edition: `voting_close` belongs to
  the cycle, so every category and every nominee gets the same new date. Extending one
  category is not possible and not allowed.
- **Paid cutoff by default.** Paid checkout stops at the previous close, so the extra time is
  free voting only. Paid orders started before the cutoff still complete and count, under the
  normal late-confirmation grace. Untick the box only with the Programmes lead's sign-off, for
  example when the cause was the payment gateway itself.
- **Free voting must be on.** If the award takes contributed votes only, a paid cutoff would
  end voting altogether, so the form refuses that combination.

## What the public sees

On the vote hub, the award's vote page, each nominee's ballot, the award page and the open
results page:

> Voting extended from {previous} to {new}. {reason} Every vote already cast stands. Rules
> and prices are unchanged. The new date applies to every nominee in this edition. Paid voting
> closed at {cutoff}; free voting stays open.

After the cutoff, the paid option is removed from the ballot and replaced by: "Paid voting for
this edition closed on {cutoff} when voting was extended; free voting stays open until {new}."

## Procedure (admin)

1. **Confirm the cause** and that it is on the allowed list. Record the evidence (incident
   link, mail log, gateway report). Get the Programmes lead's approval.
2. Open **Admin → Programmes → (programme) → Cycle**, make sure the right edition is selected
   (the Editions panel marks the one you are editing), and scroll to **Extend voting**.
3. **Previous close:** the close before the extension. It defaults to the current voting close.
4. **New close:** the new end of voting, no more than 72 hours later and before the results
   date.
   - **To record an extension already made** by editing "Voting close" directly, leave **New
     close** at the current close and set **Previous close** to the date it was before. The
     date is not changed; the record, snapshot, audit entry and public notice are added. If
     paid checkout kept selling after the old close, the paid cutoff is set to the moment you
     record (not the old close), so the public notice stays true. Those paid votes stand.
5. **Public reason** (20–1,000 characters): what happened, in plain words, without blaming a
   named person. It is published as written.
6. Leave **Stop paid votes at the previous close** ticked unless the lead has approved
   otherwise.
7. For a second extension, tick **This is a further extension** and make sure the cause is
   documented separately.
8. Press **Extend voting** and confirm. The platform then:
   - refuses if the rules above are broken, and changes nothing;
   - captures the standings (`snapshot_ref`; if the capture fails the extension still goes
     ahead and the screen says there is no snapshot);
   - writes the extension row and an audit entry `cycle.voting_extended` with before/after;
   - moves `voting_close` (unless only recording) and clears the public award caches.
9. **Check** the public vote page for the notice, and the extension table on the cycle screen.
10. **Do not** use the cycle form's "Voting close" field to extend voting. It records nothing.
    If it was used by mistake, record the extension straight away using step 4.
    Since 20 Nov 2026 the cycle form **refuses** a later close once voting has opened and
    points to "Extend voting" instead: two awards were moved that way and their public pages
    showed a new closing date with no notice.
11. **Several awards at once:** tick them under "Also extend these editions". Each gets its own
    row, snapshot, paid cutoff, audit entry and public notice, with the same new close and
    reason. An edition already moved to the new close by editing the date is *recorded*, using
    the previous close typed on the form.

### How the public sees it

Since 20 Nov 2026 the extension is **a thin ticker in the notice strip** at the top of every
public page (the same strip as Settings → Announcement): "**Voting extended** · Until the end
of Sun 29 Nov · the awards · the reason", scrolling on one line, with pause and ✕. It used to be
a card inside every voting, award and results page. It pauses on hover, on keyboard focus and
by its own button (WCAG 2.2.2), stands still for readers who turned motion off (the line then
swipes sideways), and screen readers hear the full sentence once. Awards extended to the same
close for the same reason share one ticker. It goes by itself when the extended voting ends,
and comes back for somebody who hid it if a further extension is recorded.

- A close at **00:00** is written as *the end of* the day before ("the end of Sun 29 Nov 2026
  (midnight WAT)"), because "Mon 30 Nov, 00:00" reads as a whole Monday of voting.
- The three reassurances (votes cast stand · rules and prices unchanged · same date for every
  nominee) are shown **only where the reason does not already say them**, so they are never
  said twice.

## Review

The Programmes lead reviews every extension after the edition closes: the cause and its
evidence, the length, whether the standings at `snapshot_ref` moved the decision, and whether
paid votes were taken after the cutoff (there should be none, apart from orders started before
it). This policy is reviewed at least once every 12 months and whenever the extension feature
changes.
