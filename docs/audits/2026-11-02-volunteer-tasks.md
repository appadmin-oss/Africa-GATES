# Volunteer tasks — audit, 2 Nov 2026

Tests: `tests/Unit/VolunteerTasksAuditTest.php` (all nine fail on 0d0ac398 and pass after).
Migration: `database/migrations/2027_05_03_vol_task_handin.php`.

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
