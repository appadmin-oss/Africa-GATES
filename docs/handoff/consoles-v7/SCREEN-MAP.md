# Screen → code map (build from these, keep every feature)

Repo: `appadmin-oss/Africa-GATES`, branch `claude/ai-assistance-judges-features-1ka4oz`. Read the named files before building each screen. **Where the code and the DC disagree on a rule, the code wins**; where they disagree on layout, the DC wins.

| Screen in the DC | Build from | Rules the screen must keep |
|---|---|---|
| Shell, rail, scopes, Find, bell | `src/Admin/Support/{AdminNav,ConsoleShell,Permissions}.php` | The rail never offers a page `Permissions::canOpen` refuses. `ConsolePins::MAX` = 12 pins. |
| Home | `Admin/Services/{HomeBoard,HomeBlocks,AttentionBoard}.php` | Counts come from one read, so the rail and Home agree. `HomeBoard::LATE_MINUTES` = 240. |
| Nominations › Review (table + record) | `Admin/Services/ReviewQueue.php`, `Admin/Controllers/NominationsController.php` | See §A. |
| Nominations › Review desk | `Admin/Services/{ReviewDesk,ReviewConflict}.php` | `ReviewDesk::REASONS` are the reject and return reasons. `ReviewConflict::GUARDED`: decide, approve, ask, reject, pending, merge, apart, contact (two moderators on one row get the conflict notice). |
| Duplicates | `Admin/Controllers/DuplicatesController.php`, `Services/MergeService` | The merge preview prints which value the survivor keeps per field. A merge can be rolled back. |
| Nominees and finalist record | `Admin/Controllers/NomineesController.php`, `Admin/Services/NomineePeople.php` | Statuses: pending, approved, winner, runner_up. Identity matching: EXACT 0.94, POSSIBLE 0.72. |
| Judging (score matrix, finalist scores) | `Admin/Controllers/{JudgesController,JudgingAuditController}.php` | Judges never see vote totals or each other's scores. |
| Voting (race, integrity) | `VoteDeliveryController`, `VoteRecoveryController`, `Admin/Services/VoterPeople.php` | `VoterPeople::PHONE_CAP` = 3 per phone. Recovery credits once and is logged. |
| Results release | `Admin/Controllers/ResultReleaseController.php` | One sealed act. The overall winner shows first. |
| Payments › Gateway check, Ledger, payment record | `Admin/Controllers/PaymentsTriageController.php`, `Admin/Services/FinanceService.php` | Sources: donation, paid-vote, shop, event, other (`FinanceService::SOURCES`). |
| Refunds, Disputes | `Admin/Controllers/RefundsController.php` | |
| Events (Today, Plan, Money, Sponsors, People, Night, Devices) | `Admin/Controllers/EventsController.php` (87 KB; split per ADMIN-RESTRUCTURE §3.4), `EventTicketService::checkIn()` | Door check-in is decided in the service. A device token is shown once. Ticket codes are upper-cased on save. |
| Alerts and the bell | `Admin/Services/ConsoleAlerts.php`, `AlertsController.php` | HIGH → **Affects production**, MEDIUM → **May affect production**, LOW → Everything else. |
| Data and audit › Audit log | `Admin/Services/AuditService.php`, `AuditController.php` | 60 per page. Defaults to the last 30 days. CSV uses the same filters and is capped at 50,000 rows. |
| Analytics | `AnalyticsController.php`, `AnalyticsService.php` | `MAX_DAYS` = 365. Comment moderation reads `quarantined`, not `pending`. |
| Calendar | `ConsoleCalendar.php` | Areas: Awards, Events, Challenges. |
| Audience › Contacts | `Admin/Services/Contacts.php` | Use its KINDS, TABS and CONSENT; voter, donor, nominator and ticket are always included. |
| Ask pane | `AssistantController.php`, `AssistantThreads.php` | Threads are kept 90 days. It is read-only; repairs run only from Operations scripts. |
| Settings forms | `SettingsService::get/set` (writes log the admin) | Every saved field writes an audit entry. |
| Volunteer workspace | No volunteer area exists yet. Build it per README §6 + AUDIT-AND-PLAN §7.2–7.3. Volunteers are not admin actors. | |

## A. Nominations review (from `ReviewQueue.php`)

- **Tabs (stored status):** To review (`pending`) · Asked (`needs_details`) · Approved (`approved` + `verified`) · Rejected. To review and Asked are the queue; the others are the record.
- **Quick views** (queue tabs only, hidden when 0 unless selected): All · Ready to approve · Needs a check · Possible duplicate · Category flagged. Rules are in `inView()`.
- **Checks on each row:** Duplicate? (red) · No evidence · Needs N more places · one signal text or "N things to check" · All clear (green).
- **Facets**, built from the data and sorted by count: Category · Checks · Nominated by (relation) · Country. A selected value stays visible at 0.
- **Search:** nominee, nominator, nominator email, reference, category, organisation. A search that names a facet value offers **Filter instead**.
- **Sorts:** Nominee, Category, Received (off → ↑ → ↓ → off). Default is newest first.
- **Paging:** 50 per page. The queue is read in full up to 2,000 rows and says so past that. Export CSV uses the same filters, or only the selected rows from the bulk bar.
- **Received:** "12m", "3h", "2d", then the date.
- **The record (the peek):**
  - the case per category (`gates_nomination_categories.reason`);
  - evidence, re-checked as http(s) on the way out;
  - the check list spelled out, e.g. "Nominated from 1 of the 3 places needed to approve";
  - the reading: "Reads specific and backed" ≥70, "Reads general in places" ≥40, "Reads thin" otherwise. It says when it's stale (older than the newest evidence). Admins and judges only;
  - history from the audit log, plus team notes (`gates_nomination_notes`, up to 2,000 characters).
- **Approve is blocked** while the eligibility rule says too few places. The DC shows this as "Approve (needs N more places)", explains it when pressed, and links to Settings.
- **Actions:** approve · ask the nominator (writes `asked_message`, then the status is `needs_details`) · reject · move back to review · merge · rule apart ("Not a duplicate", sets `not_duplicate`) · contact.
