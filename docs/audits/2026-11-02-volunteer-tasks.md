# Volunteer tasks — audit, 2 Nov 2026

Tests: `tests/Unit/VolunteerTasksAuditTest.php` (all nine fail on 0d0ac398 and pass after).
Migration: `database/migrations/2027_06_09_vol_task_handin.php`.

## Found

1. HIGH — a volunteer could not say a task was finished; only staff ended a take (`Tasks::accept`).
   `Effect` counted a take still `taken` past its due date as late, and measured on time by
   `done_at`, the day staff accepted it.
2. HIGH — a task's "What to do" (`body`) and `checked_note` were stored and shown on no volunteer
   screen.
3. MEDIUM — `Tasks::take()` checked `status = 'open'` only; the place page listed past-due tasks
   with "Take it" while the library hid them.
4. MEDIUM — `Tasks::progress()` had no caller, so every progress bar read "0 of N" until
   acceptance; nothing wrote `closed`; a task could not be edited or closed.
5. MEDIUM — "We tell you when one is posted" and the saved `notify_fit` setting had no reader.
6. LOW-MEDIUM — `accept()`'s UPDATE had no status predicate; `Hours::fromTask()` checked then
   inserted; `idx_volhours_ref` was a plain index under a comment saying "never counted twice".
7. LOW — taking a task already `done` returned ok; the place page offered "Take it" on it.

## Fixed

1. Volunteer "Hand it in" (`do=handin` on `/volunteer/act`, CSRF, plain POST on the Mine row) →
   take `handed_in`, `handed_in_at` stamped. Never late; on time measured by the hand-in (falls
   back to `done_at` for old rows). Staff: "Handed in, waiting for you" on Volunteers › Tasks, the
   tab's count, and a Home "needs a person" card. Every status reader goes through
   `Tasks::ON_THE_GO`. — `test_a_volunteer_hands_work_in_and_staff_see_it_waiting`,
   `test_handed_in_work_is_never_late_and_on_time_is_measured_by_the_hand_in`
2. Body (escaped, line breaks kept) and the check note on the library, Mine, place-page and
   Getting-started rows; Done says how it was checked. —
   `test_what_to_do_and_how_it_is_checked_reach_every_row_that_offers_the_task`
3. `take()` refuses a past-due task; `Tasks::offered()` is the one "may still be taken" query for
   the library, the place page and the new-task notice. —
   `test_a_past_due_or_finished_task_is_refused_and_never_offered`
4. Tasks tab: record progress, change (name, what to do, check note, target, due) and close. A
   closed task is not offered; anybody part-way through can still hand it in and be accepted. —
   `test_staff_record_progress_edit_and_close_a_task`
5. Implemented in `Volunteer\Reminders` (the existing claimed-once mechanism, run by Maintenance):
   `posted` hears about each fitting task once while it is new (7 days), `weekly` gets one email
   on Monday about the week before, `never` nothing; "fits" is `Tasks::fits()` (trained, and
   from-home work only to those who take it). Plus an always-on reminder the day before a held,
   not-handed-in task is due. — `test_new_tasks_that_fit_are_told_as_each_person_chose_and_a_held_task_is_reminded`
6. `accept()` is a compare-and-set; `fromTask()` inserts-or-ignores against a new UNIQUE
   `uq_volhours_ref (ref_kind, ref_id)`. The repair migration first resolves duplicates, keeping
   the EARLIEST row: later copies are detached and declined ("Counted twice …"), not deleted, so
   `Rewards::sync()` takes their points back. — `test_an_accepted_take_is_one_hours_row_however_often_it_is_accepted`,
   `test_the_accept_update_carries_the_status_it_expects`,
   `test_the_repair_keeps_the_earliest_of_a_duplicate_and_declines_the_rest`
7. Taking a finished task again is refused; the place page shows "Done" and no button. — as 3.

## Still open

- `gates_vol_tasks.points` has no payer: rewards are per verified hour (`Rewards::perHour`). It is
  deliberately NOT shown to volunteers nor offered in the edit form, because either would be a
  promise nothing keeps. Owner decision: pay task points, or retire the column.
- `/admin/volunteers` is still unmapped in `Permissions::PATH_SECTIONS` (superadmin only), so the
  new Home card reaches superadmins only — an access change for the owner, not this fix.
- Volunteer screens print a task's due date with `Workspace::day()`, which reads the stored (UTC)
  value without converting to the display zone — shared by every workspace date, so left for a
  workspace-wide change. (Creating and editing a task now converts the typed time through
  `DisplayTime::toStored()` and fills the field with `|when_input`; `Tasks::create()` used to store
  the typed WAT time as if it were UTC.)
- A take on a task closed with work outstanding can still turn "late" on its due date if never
  handed in; that is the volunteer's commitment and is left counted.

## Addendum, 19 Nov 2026 — on the newest line, and getting work finished

The fixes above were written on a branch none of the other lines contained. An audit of
`afv/shop-upgrade` (the newest line, which holds `claude/ai-assistance-judges-features`) found all
of it missing there: no hand-in (the Mine row offered only "Drop"), no "What to do", past-due tasks
takeable, no staff edit/close/progress, no task notices although the page promised them, and
`Effect` judging "on time" by staff acceptance. This branch now merges `afv/shop-upgrade` and
`afv/affectionate-babbage-k2ztoz` (not contained in the shop line) and carries every fix above on
top of what they changed:

- The shop line's event-team rule (an event's task only for that event's team) is kept in
  `library()` and `take()`, and now also in `Tasks::fits()`, so the new-task notice does not tell
  anybody outside the team. Its own `accept()` bounds and transaction are kept, with the
  compare-and-set over `ON_THE_GO` so handed-in work can be accepted; the event lead's console
  (`EventPages::volTasks`, `acceptForEvent`, `forEvent`) lists and accepts handed-in work too.
- The migration was renamed `2027_05_03_vol_task_handin` → `2027_06_09_vol_task_handin` to sort
  after every merged line's migrations (the shop line runs to `2027_06_08`); it was never deployed.

### Achievement on the Tasks tab

Found: staff could accept handed-in work and edit or close a task, but no take showed its due
date, nothing said overdue, the list could not be narrowed to what was waiting or late, and the
only remedies for somebody behind were to wait or close the whole task.

Fixed (`tests/Unit/VolunteerTaskAchievementTest.php`, seven cases, all failing before the change):

1. "Who is working on what": every take on the go with its due date, overdue first, and
   **Overdue** in words. `Tasks::takes()` is the one definition — held, not handed in, past the
   task's due date — the same rule `Effect` counts as late.
2. Filters "All on the go · Handed in, waiting · Overdue" with counts (`Tasks::takeCounts()`); an
   unknown filter falls back to everything.
3. **Nudge**: one email to the holder about that task, with an optional line from staff, through
   `Reminders::deliver()` — the volunteer messages' one mail path, now shared by the sweep
   (`Reminders::sender()`/`person()` extracted, no second sender). Recorded on the take
   (`gates_vol_task_takes.nudged_at`, added in the same migration) and in the broadcast log; at most
   once in 24 hours by a compare-and-set on the stamp; a failed send puts the stamp back. Only a
   take still `taken` can be nudged.
4. **Release** (`Tasks::RELEASED`, not `dropped`): the task is offered again and the release
   counts against nobody on `Effect`; the person may take it again. **Give to somebody else**
   (`Tasks::reassign`): refuses the same person, anybody not approved, untrained, or off an event
   task's team, and anybody who already holds or finished it; the first holder's take is released
   in the same transaction. Both people are told, through the same mail path.
5. All three sit behind the page's existing `canWrite` gate, are audited as `volunteer_take`, and
   are not drawn for a role that cannot write.

Still open: the overdue list reads the stored (UTC) due date through `|when`, but the volunteer
screens still use `Workspace::day()` (see above).
