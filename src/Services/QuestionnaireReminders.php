<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The message that goes out BEFORE a nominee loses their place.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see QuestionnairePolicy::enforce()} disqualifies every nominee in an armed cycle who
 * has not submitted by the deadline plus its grace, and it runs unattended out of
 * {@see \AfricaGates\Support\Maintenance} at 06:00. There is no shell on this host, so
 * nobody watches it happen.
 *
 * Until now the ONLY message a nominee ever got was the invitation. One email, months
 * earlier, naming a date. Then silence, then removal.
 *
 * That email can fail in half a dozen ordinary ways that are nobody's fault: the address
 * on the nomination was typed by the NOMINATOR and may be wrong, it may have gone to spam,
 * the person may have changed job or phone, the link may have been opened once and lost.
 * A rule that takes an award nomination away from somebody who never knew they had one is
 * the single most damaging thing this platform can do quietly.
 *
 * `gates_nominee_submissions.reminded_at` has been in the schema since the questionnaire
 * migration, waiting for this. Nothing wrote it, nothing read it, and the sentence in
 * docs/CODEBASE-INDEX.md §17 about a declared field with no reader was written about
 * exactly this shape of fault. Here the field's absence was not a dormant feature — it was
 * the reason the warning did not exist.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO HALVES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see sweep()} sends the warnings and stamps the column. {@see QuestionnairePolicy::enforce()}
 * now refuses to take a nominee the column says was never warned. Either half alone is
 * worth little: warnings nobody acts on, or a guard with nothing to read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A CYCLE WITHOUT AUTO-DISQUALIFICATION IS STILL REMINDED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because the deadline is real either way — the invitation named it — and a nominee who
 * misses it has still missed their chance to speak to the panel in their own words. What
 * changes is the sentence: an armed cycle says what will happen, an unarmed one does not
 * threaten a consequence that does not exist. Copy that promises removal where none is
 * configured is the same dishonesty as an unannounced removal, pointed the other way.
 */
final class QuestionnaireReminders
{
    /**
     * Days before the enforcement moment at which a warning goes out.
     *
     * Three, and spread. One is not a warning — somebody who reads it on the day has no
     * time to write anything worth reading. A daily countdown is nagging, and this is a
     * message about something the recipient may find upsetting.
     */
    public const DEFAULT_MARKS = [14, 5, 1];

    /**
     * Rows per tick. Same ceiling and same reason as {@see InviteReminders::CAP}: a shared
     * host's max_execution_time is the real limit on an unattended run, and a cycle with
     * six hundred silent nominees must not take the whole maintenance pass down with it.
     */
    public const CAP = 40;

    /** @var array<string,string>|null */
    private static ?array $settings = null;

    // ══ schedule ═════════════════════════════════════════════════════════════

    /**
     * The marks, nearest-first-capped, largest first.
     *
     * @return list<int>
     */
    public static function marks(): array
    {
        $raw = trim((string) (self::settings()['questionnaire_reminder_days'] ?? ''));
        if ($raw === '') return self::DEFAULT_MARKS;

        $out = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $piece) {
            if ($piece === '') continue;
            $n = (int) $piece;
            if ($n >= 1 && $n <= 365) $out[$n] = true;
        }
        if ($out === []) return self::DEFAULT_MARKS;

        $marks = array_keys($out);

        // Four, and the four NEAREST the deadline — same reasoning as the event reminders.
        // Trimming from the far end keeps the last warning, which is the one that matters
        // most to somebody who is about to lose their place.
        sort($marks);
        $marks = array_slice($marks, 0, 4);
        rsort($marks);

        return array_values($marks);
    }

    /**
     * Which warning is in play with $daysLeft to go, or null if none is yet.
     *
     * The SMALLEST mark still ahead of the day — with marks [14, 5, 1] and nine days left,
     * the fourteen-day warning has opened and the five-day one has not, so it is the
     * fourteen. Picking the largest passed mark instead would re-open a window that had
     * already been served and send the same nominee the same message twice.
     */
    public static function dueMark(int $daysLeft, ?array $marks = null): ?int
    {
        $best = null;
        foreach ($marks ?? self::marks() as $m) {
            if ($m >= $daysLeft && ($best === null || $m < $best)) $best = $m;
        }

        return $best;
    }

    /**
     * Whole days from now until $at, negative once it is behind us.
     *
     * Measured from the START of today rather than this instant, because a deadline is a
     * date to the person reading it: at 23:00 the night before, "tomorrow" is the honest
     * answer and "in 0 days" is not.
     */
    public static function daysUntil(string $at, ?Carbon $now = null): ?int
    {
        $at = trim($at);
        if ($at === '') return null;

        try {
            $now    = ($now ?? Carbon::now())->copy()->startOfDay();
            $target = Carbon::parse($at)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return (int) round($now->diffInDays($target, false));
    }

    // ══ the sweep ════════════════════════════════════════════════════════════

    /**
     * Send the warnings that are due. Returns how many went, or TASK_FAILED semantics are
     * left to the caller: 0 means "ran, nothing to warn about".
     */
    public static function sweep(?OtpService $mailer = null, ?int $cap = null): int
    {
        // Without the column there is no way to send one warning rather than one a day, and
        // a service that mails a distressing message daily is worse than one that is off.
        // The migration adds it; a database that has not run it simply does not warn yet.
        if (!OptionalColumn::on('gates_nominee_submissions', 'reminded_at')) {
            error_log('[questionnaire-remind] gates_nominee_submissions.reminded_at is absent — '
                    . 'run the migrations; nobody is being warned before disqualification');
            return 0;
        }

        $cap ??= self::CAP;
        $marks = self::marks();
        $now   = Carbon::now();
        $sent  = 0;

        foreach (self::cycles() as $cycleId) {
            if ($sent >= $cap) break;

            $p = QuestionnairePolicy::forCycle($cycleId);
            $at = (string) ($p['enforce_at'] ?? '');
            if ($at === '') continue;

            $left = self::daysUntil($at, $now);
            // Past it already: the warning window is closed. Sending "you have -3 days"
            // helps nobody, and enforce() has its own guard for anybody who was missed.
            if ($left === null || $left < 0) continue;

            $mark = self::dueMark($left, $marks);
            if ($mark === null) continue;

            // When this mark's window opened. A row reminded at or after that moment has
            // already had this warning — which is how one mark sends one message.
            try {
                $opened = Carbon::parse($at)->startOfDay()->subDays($mark)->toDateTimeString();
            } catch (\Throwable) {
                continue;
            }

            foreach (self::silent($cycleId, $opened, $cap - $sent) as $row) {
                if (self::warn($row, $p, $left, $mailer)) {
                    DB::table('gates_nominee_submissions')->where('id', (int) $row->id)
                        ->update(['reminded_at' => $now->toDateTimeString(),
                                  'updated_at'  => $now->toDateTimeString()]);
                    $sent++;
                }
                if ($sent >= $cap) break;
            }
        }

        return $sent;
    }

    /**
     * Submissions in $cycleId that have not answered and have not had THIS warning.
     *
     * @return list<object>
     */
    private static function silent(int $cycleId, string $openedAt, int $limit): array
    {
        try {
            return DB::table('gates_nominee_submissions AS s')
                ->leftJoin('gates_nominees AS n', 'n.id', '=', 's.nominee_id')
                ->where('s.cycle_id', $cycleId)
                // Same three exclusions enforce() uses, for the same reasons: draft and
                // withdrawn both mean "did not submit", and a test row has nobody behind it.
                ->whereIn('s.status', ['draft', 'withdrawn'])
                ->where(fn ($q) => $q->whereNull('s.is_test')->orWhere('s.is_test', 0))
                // Never invited is not silence, it is an invitation that has not gone yet.
                // Warning somebody about a deadline for a questionnaire they have not been
                // asked to fill in is how a platform teaches people to ignore its mail.
                ->whereNotNull('s.invited_at')
                ->where(fn ($q) => $q->whereNull('s.reminded_at')->orWhere('s.reminded_at', '<', $openedAt))
                ->orderBy('s.id')
                ->limit(max(0, $limit))
                ->get(['s.id', 's.nominee_id', 's.cycle_id', 's.invite_token', 'n.name'])
                ->all();
        } catch (\Throwable $e) {
            error_log('[questionnaire-remind] could not list cycle ' . $cycleId . ': ' . $e->getMessage());
            return [];
        }
    }

    /** True when a message actually left. */
    private static function warn(object $row, array $p, int $daysLeft, ?OtpService $mailer): bool
    {
        $mailer ??= self::mailer();
        if ($mailer === null) return false;

        $name = trim((string) ($row->name ?? ''));
        $link = QuestionnaireService::url((int) $row->id);
        $body = self::body($name, $link, $p, $daysLeft);

        try {
            foreach (ClaimIndependence::contactsFor((int) $row->nominee_id) as $c) {
                if (($c['channel'] ?? '') !== 'email') continue;
                $to = (string) ($c['value'] ?? '');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;

                $mailer->sendCustom($to, self::subject($daysLeft), $body);

                return true;
            }
        } catch (\Throwable $e) {
            error_log('[questionnaire-remind] submission ' . $row->id . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * The subject line.
     *
     * The countdown first and the ask second, because a phone truncates at roughly forty
     * characters and "your questionnaire" is the half a nominee already knows.
     */
    public static function subject(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0 => 'Last day to tell the Africa GATES judges about your work',
            $daysLeft === 1 => 'Tomorrow: your Africa GATES questionnaire',
            default => $daysLeft . ' days left to tell the Africa GATES judges about your work',
        };
    }

    /**
     * The warning itself.
     *
     * ── WHAT IT SAYS AND WHY ─────────────────────────────────────────────────
     *
     * It names the consequence plainly where there is one, and does not invent one where
     * there is not. It repeats the link, because the commonest reason a questionnaire is
     * unanswered is that the first email was never found. And it repeats the two sentences
     * from the invitation that matter most — nothing costs money, and an honest answer
     * about what has not worked has never cost anybody an award — because this is the
     * message most likely to be the FIRST one a nominee actually reads.
     */
    public static function body(string $name, string $link, array $p, int $daysLeft): string
    {
        $when = trim((string) ($p['deadline_at'] ?? ''));
        try { $whenText = $when !== '' ? Carbon::parse($when)->format('j F Y') : ''; }
        catch (\Throwable) { $whenText = $when; }

        $countdown = match (true) {
            $daysLeft <= 0  => 'today',
            $daysLeft === 1 => 'tomorrow',
            default         => 'in ' . $daysLeft . ' days',
        };

        $stake = $p['autodisqualify']
            // Said once, plainly, without dressing. Somebody about to lose a nomination is
            // owed the sentence rather than an inference from a firm tone.
            ? "If nothing arrives by then, the nomination is closed and the judges will not "
            . "see your side of it. That is not a decision anybody makes about you — it is "
            . "a deadline, and it is the only one.\n\n"
            : "After that the panel begins reading, and anything that arrives later may not "
            . "reach them in time.\n\n";

        return 'Dear ' . ($name !== '' ? $name : 'Nominee') . ",\n\n"
             . "You were nominated for an Africa GATES award, and we have not heard from you "
             . "yet. The judges still have only what the person who nominated you wrote.\n\n"
             . "YOUR PAGE: " . $link . "\n\n"
             . ($whenText !== ''
                 ? "It closes " . $countdown . " — " . $whenText
                   . ((int) ($p['grace_days'] ?? 0) > 0
                       ? ', with ' . (int) $p['grace_days'] . " days' grace after it"
                       : '')
                   . ".\n\n"
                 : "It closes " . $countdown . ".\n\n")
             . $stake
             . "It is short, it saves as you go, and the link keeps working — you do not have "
             . "to finish it in one sitting. If you would rather answer it as a conversation, "
             . "the same page will do that.\n\n"
             . "Two things worth saying again:\n"
             . "  - Nothing here costs money. We will never ask you to pay for a nomination, an "
             . "interview, a result or an award. If anybody does, it is not us.\n"
             . "  - Answering honestly about what has NOT worked has never cost anybody an "
             . "award. The judges are looking for real work, not a perfect record.\n\n"
             . "If you would rather not take part at all, you can say so on that page and we "
             . "will stop writing.\n";
    }

    // ══ plumbing ═════════════════════════════════════════════════════════════

    /** Cycles with a questionnaire deadline worth warning about. @return list<int> */
    private static function cycles(): array
    {
        try {
            // Every cycle that has submissions waiting, not only the armed ones — the
            // deadline is real whether or not a consequence is attached to it.
            return DB::table('gates_nominee_submissions')
                ->whereIn('status', ['draft', 'withdrawn'])
                ->whereNotNull('cycle_id')
                ->distinct()->pluck('cycle_id')
                ->map(fn ($v) => (int) $v)->filter(fn (int $v) => $v > 0)->values()->all();
        } catch (\Throwable $e) {
            error_log('[questionnaire-remind] could not list cycles: ' . $e->getMessage());
            return [];
        }
    }

    private static function mailer(): ?OtpService
    {
        try { return OtpService::boot(); }
        catch (\Throwable $e) {
            error_log('[questionnaire-remind] no mailer: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<string,string> */
    private static function settings(): array
    {
        if (self::$settings !== null) return self::$settings;
        try {
            return self::$settings = DB::table('gates_settings')->pluck('value', 'key_name')
                ->map(fn ($v) => (string) $v)->all();
        } catch (\Throwable) {
            return self::$settings = [];
        }
    }

    /** Tests and the settings screen both change these underneath us. */
    public static function forget(): void
    {
        self::$settings = null;
    }
}
