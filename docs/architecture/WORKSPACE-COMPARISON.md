# The volunteer and lead workspace beside Asana, HubSpot, Zoho, Notion and volunteer tools

29 Oct 2026. The owner asked for a comparison of the workspace (`/volunteer` and the event
lead's console, consoles v7) with the tools people already use for this kind of work, and for
the gaps to be filled. This is what each tool does that a volunteer or a lead would expect,
what we already have, and what was built or deferred.

Sources were the vendors' own pages where they exist (listed at the end); review sites were
used only to confirm a feature is common, never for a figure.

---

## 1. What each tool is for, in one line

| Tool | What it is | The pattern worth taking |
|---|---|---|
| **Asana** | Work management for teams | **My Tasks** sorted into sections, rules that move work by date, approvals (Approve, Request changes, Reject), **Workload** showing who is overloaded, calendar and timeline views, an Inbox of updates. |
| **HubSpot** | CRM with a sales workspace | **Task queues**: work the next item without going back to a list; every record has a **timeline** of what happened to it; sequences remind on a schedule. |
| **Zoho Projects / People** | Projects, timesheets, HR | **Timesheets with approval rules**, Blueprint (a process with named steps and who moves each), **resource utilisation** before assigning, feeds and forums per project. |
| **Notion** | Docs and databases | **One record, many views** (table, board, calendar, timeline), saved filters and sorts, templates, a wiki beside the work. |
| **Better Impact, VolunteerHub, Rosterfy** | Volunteer management | **Reminders before each shift**, **self check-in** (kiosk or own phone), hours logged from check-in, **credential expiry reminders**, waitlists and swaps, a volunteer app. |

---

## 2. Feature by feature

✓ have · ◐ partly · ✗ missing · — not for us

| Capability | Asana | HubSpot | Zoho | Notion | Vol. tools | **Africa GATES before** | **Now** |
|---|---|---|---|---|---|---|---|
| A personal "what's next" page | ✓ My Tasks | ✓ | ✓ | ◐ | ✓ | ✓ Today, Needs you | ✓ |
| Updates inbox / bell | ✓ Inbox | ✓ | ✓ feeds | ◐ | ◐ | ✓ the bell, grouped by what it asks | ✓ |
| Find across everything | ✓ | ✓ | ✓ | ✓ | ◐ | ✓ ⌘K Find | ✓ |
| Reminder before a shift or due date | ✓ | ✓ sequences | ✓ | ◐ | ✓ | ✗ | **✓ built** |
| Credential / training expiry reminder | — | — | ◐ | — | ✓ Rosterfy | ◐ shown in the app only | **✓ built** |
| Waitlist when a shift is full | — | — | — | — | ✓ | ✗ "That shift has just filled" | **✓ built** |
| Add to my calendar | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | **✓ built** (.ics) |
| Work a queue item after item | ◐ | ✓ queues | ◐ | — | — | ✗ back to the list each time | **✓ built** |
| Who is overloaded (workload) | ✓ Workload | — | ✓ utilisation | — | ◐ | ✗ | **✓ built** (lead's team) |
| Approvals with a reason | ✓ | ◐ | ✓ Blueprint | — | ◐ | ✓ Requests, notes required | ✓ |
| Timesheet approval | ◐ | — | ✓ | — | ✓ | ✓ hours by hand confirmed by a lead | ✓ |
| Hours from check-in | — | — | ◐ timers | — | ✓ | ◐ staff mark "Came" | ✗ G1 (next) |
| Self check-in at the venue | — | — | — | — | ✓ kiosk / phone | ✗ | ✗ G1 (next) |
| Shift swap between volunteers | — | — | — | — | ◐ | ◐ a request to the lead | ◐ + waitlist fills a drop |
| Comments on a task or shift | ✓ | ✓ notes | ✓ | ✓ | ◐ | ✗ | ✗ (deferred, §4) |
| Saved views of a list | ✓ | ✓ | ✓ | ✓ | ◐ | ✓ in the admin console only | ✗ (deferred) |
| Recurring shifts / tasks | ✓ | ✓ | ✓ | ◐ | ✓ | ✗ | ✗ (deferred) |
| A record's timeline | ◐ | ✓ | ✓ | ◐ | ◐ | ✓ requests, hours, audit | ✓ |
| Masked data by role | ◐ | ◐ | ◐ | ◐ | ◐ | ✓ server-side, tested | ✓ |
| Access that ends on its own | — | — | — | — | — | ✓ every grant has an end | ✓ |

The last two rows are where this platform is ahead of every tool above: none of them makes
access end by default, and none masks a record on the server by role as a rule. Keep them.

---

## Shifts removed (owner, 29 Oct 2026)

The owner asked for shifts to be removed from volunteering completely. There is no Shifts page,
no joining, no waitlist, no shift calendar file, no shift reminders, no clash or fill counts, and
nothing in the lead console posts shifts. A volunteer's role at an event or an award is a grant
the volunteer team gives (`/admin/volunteers`), which ends by itself; work is tasks; the level is
verified hours (and the lead module for Level 3). The `gates_vol_shifts` and `gates_vol_signups`
tables are left in place, unread, because hours already verified point at them (`source =
signup`); nothing writes them. Items 2, 3 and 5 below, and the shift half of item 1, describe
what existed before this.

## Hours and effectiveness after shifts (owner, 30 Oct 2026)

"The hours should still be calculated. We need to be able to know how effective the volunteers
are." Shifts carried the only observed hours, so removing them left tasks and hand-logged claims.

- **Time on site** (`Volunteer\Attendance`, `gates_vol_attendance`): a volunteer with a role at
  an event checks in and out from the event's page, or the event lead does it from People ›
  Volunteers. Open from six hours before the event to six hours after it. Closing a visit writes
  one hours row (`source = scan`, `ref_kind = attend`): a lead's tap at either end makes it count
  at once; the volunteer's own two taps wait for a lead, who confirms them on the same page. Over
  16 hours is capped and always reviewed.
- **Effectiveness** (`Volunteer\Effect`, one resolver): over six months, verified hours and the
  weeks they came in, tasks finished, handed back and past due, on time, and events turned up to.
  Reliability = (finished + turned up) ÷ (everything taken on), shown only after three. Hours are
  not in it. Shown on the volunteer's record, the lead's team and event pages, and the staff
  roster (sortable, weakest first).
- **An account comes first**: `/volunteer/apply` is open signed-out and says so, with a button
  to make an account (back to the form after); an unverified email is told to verify; `apply()`
  refuses both.

## 3. What was built (29 Oct 2026)

Each is one rule, held by a test, and draws nothing the database did not record.

1. **Reminders** (Better Impact, VolunteerHub, Rosterfy). `Volunteer\Reminders::sweep()` on
   every maintenance tick, by email:
   - the day before a shift: what, where, what to bring, who leads it;
   - a place opening from a waitlist: "You're on it";
   - training that lapses within seven days, naming the shifts it keeps.
   Each is claimed in `gates_broadcast_log` before it is sent, so overlapping ticks never
   mail anybody twice, and none is sent for a shift that has started or a person on a break.
   The Notifications page states them, and that they go by email.
2. **Waitlist** (volunteer tools). A full shift offers "Join the waitlist" instead of a
   refusal. When somebody drops, the first person waiting who can still take it (no clash,
   training held, not on a break) is moved on, gets the shift's role like anybody joining,
   and is told. The order is the order people joined. Nobody is moved on to a shift that has
   started.
3. **Add to calendar** (all of them). `/volunteer/shifts.ics`: the person's own coming shifts,
   one event each, with the place and the brief. It carries no other volunteer's name.
4. **Send and open next** (HubSpot queues). A nomination check goes straight to the next one
   waiting, so a checker works the queue without returning to the list.
5. **Your team's load this week** (Asana Workload, Zoho utilisation). On the lead's My team:
   each person's booked hours this week against the limit they set in Availability. Over the
   limit is said in words, never only in colour. It is the person's own number, never a quota.

6. **Meetings** (owner, 29 Oct 2026; ported from the Afrovanguard site's `lib/Meetings.php`).
   `Volunteer\Meetings`, at `/volunteer/meetings`. A volunteer who has done some work and is
   18 or over schedules a meeting with up to twelve people they already volunteer with (a
   shift or a grant at one of their own places, never a directory of strangers). Online takes
   a Google Meet link where the calendar integration is set up, or a pasted link to a known
   meeting service, checked by host; in person takes a place. It can repeat weekly, every two
   weeks or monthly (the 31st falls on a short month's last day). Each person is emailed once,
   answers "going" or "can't come", gets a reminder the day before each meeting, and can add
   the series to their calendar (.ics with a repeat rule). The organiser writes notes
   afterwards; calling it off tells everybody who hadn't said no. Only the people in a
   meeting can see it. A child is invited only by a team lead and never organises.
   Not ported: transcripts and the AI notetaker, which need their own consent rules.

## 4. No broadcast (owner, 29 Oct 2026)

Every tool above lets a user message a group: Asana's project messages, HubSpot's sequences,
Zoho's feeds, Rosterfy's push to a roster. **The workspace does not, by the owner's decision.**
Every message it sends goes to one person about their own shift, place, training or a meeting
they are in, and is sent by the platform. A meeting invitation is the nearest thing to a
volunteer writing to several people, so it is capped at twelve people the organiser already
volunteers with, carries no text the organiser typed beyond the title and agenda, and links
to the meeting page rather than carrying the call link. `Volunteer\Reminders` says so in its
docblock; a feature that lets a person write once to many is out of scope here.

## 5. Accessibility, for blind users first (owner, 29 Oct 2026)

The owner asked for the workspace to be "extremely accessible, especially for the blind". axe
(WCAG 2.2 AA and best practice) now reports nothing on the fifteen workspace pages at phone and
desktop width. That is the floor; what a screen-reader user actually needed was beyond it:

- **What just happened is heard.** The outcome of an action leads the page title ("Not done:
  …" for an error), which a screen reader reads when the page loads, and it is repeated into
  an empty live region after load, because a region filled at load is often silent. An error
  goes to an alert region and stays on screen until dismissed (WCAG 2.2.1); a confirmation
  leaves after eight seconds, never while hovered or focused.
- **Counts are words.** A badge's number is hidden from the reader and replaced by ", 3
  waiting"; `aria-label` on a plain span (what it used before) is ignored by most readers.
- **Every button says what it acts on.** "Check, N-12 · Halima Bello", "Leave the waitlist,
  Door, Gate B", never "Check, Check, Check" (WCAG 2.4.4, 2.5.3).
- **No colour alone.** A status dot carries a hidden word (Fine, Check, Problem), and a full
  shift says "Full · waitlist open".
- **Rail groups label their lists**, so "Your work, list, 3 items" is announced.
- **Find announces its matches** ("5 matches. Arrow down to choose.") once typing pauses.
- **Contrast.** The shell's words no longer use the console's `faint` grey (2.85:1 on the
  rail); labels use `ink-3`, other words `soft`. `prefers-contrast: more` darkens every grey,
  and Windows high contrast keeps badges, chips and the current page outlined.
- **Work that suits a screen reader**: a check opens the next nomination straight away, so a
  queue is worked without returning to a list; shifts go into the person's own calendar app,
  which their phone already reads aloud; reminders arrive by email with the facts in the order
  they are needed and the link last.

`VolunteerWorkspaceGapsTest` holds the title, the live regions, the badge words and the button
context on a rendered page.

## 6. Deferred, and why

- **Hours from check-in, self check-in** (G1): the door already scans ticket passes; a
  volunteer's own pass needs its own design (a volunteer is never an admin actor) and the
  offline rules in D25. Next slice.
- **Comments on tasks and shifts**: a conversation needs moderation rules, notification rules
  and a retention rule. Requests already carry a note per step, which covers asking.
- **Saved views**: the workspace lists are short by design (§8 "less data"); saved views earn
  their place in the admin console, which has them.
- **Recurring shifts**: staff create shifts in batches today; a series needs a decision on
  how a change to one occurrence affects the others.
- **SMS and WhatsApp reminders**: the Channel setting exists; until a sender is wired for
  volunteers, reminders go by email and the page says so.

---

## Sources

- Asana My Tasks: https://asana.com/features/project-management/my-tasks
- Asana Workload: https://asana.com/inside-asana/new-workload-resource-management
- Asana approvals: https://asana.com/resources/asana-tips-intake-request-deliverable
- HubSpot task queues: https://knowledge.hubspot.com/tasks/use-task-queues
- HubSpot sales workspace: https://knowledge.hubspot.com/prospecting/review-sales-activity-in-the-sales-workspace
- Zoho Projects features: https://www.zoho.com/projects/features.html
- Notion database views: https://www.notion.com/help/guides/using-database-views
- Rosterfy: https://www.rosterfy.com/compare/
- VolunteerHub and Better Impact: https://volunteerhub.com/blog/volunteerhub-vs-better-impact
