<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\Ics;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What a judge has been asked to sit on, when, and whether the calendar agrees.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SITTINGS WERE SCHEDULED AND NOBODY COULD SEE THE SCHEDULE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_interviews` holds a `scheduled_at`, a `meet_url`, a `meet_code` and a
 * `calendar_event_id` per sitting, and `panel_json` says which judges are on it. Every
 * screen that reads any of this reads ONE sitting at a time — `/admin/interviews/{id}`.
 * There has never been a view of the round: which judge is expected where, on which day,
 * and with which link.
 *
 * So an operator running a panel of ten across forty entries had the information in the
 * database and no way to answer "what is Tuesday", "does Dr Achebe know about Thursday",
 * or "did the calendar actually take it". The last of those is the one that bites: the
 * Google side is created through an Apps Script deployment where five different
 * misconfigurations look identical from the .env file, and a sitting whose event silently
 * failed to sync is a room nobody joins.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT VERIFIES AGAINST GOOGLE RATHER THAN TRUSTING THE ROW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The calendar is where the meeting actually lives; the row here is a copy, and an
 * organiser who drags the event in Google changes the truth without telling us. {@see
 * verify()} asks the Apps Script what it holds and reports the three states that matter
 * separately — present and agreeing, present and DRIFTED, and gone. A screen that showed
 * only the local time would be confidently wrong precisely when somebody had rescheduled.
 *
 * ── AND IT VERIFIES ON DEMAND, NEVER ON RENDER ───────────────────────────────
 *
 * One round is forty sittings and each verification is a round trip to a Google Apps
 * Script deployment. Doing that while a page loads would make the schedule screen the
 * slowest thing in the console and would spend the script's quota every time somebody
 * glanced at it. The list renders from the rows; the check is a button.
 */
final class JudgeSchedule
{
    /**
     * Sittings that have not happened yet, and the recent past.
     *
     * The past matters on this screen: "was that call yesterday actually held" is the
     * question an operator asks when a transcript has not appeared, and a schedule that
     * only looks forward cannot answer it.
     */
    public const PAST_DAYS = 3;

    /**
     * Statuses whose sitting is still expected to take place — {@see InterviewService::PENDING}.
     *
     * ── WHY THIS IS A REFERENCE AND NOT A LIST ───────────────────────────────
     *
     * It WAS a list: `['scheduled', 'invited', 'confirmed', 'live']`, sitting beside
     * InterviewService::PENDING — `['draft', 'invited', 'confirmed', 'live']` — under a
     * docblock that said the same sentence. Two readers of one fact, which is the thing
     * this codebase has a rule about, and they had drifted in both directions at once:
     *
     *   `scheduled` IS NOT A STATUS. `gates_interviews.status` is an ENUM of exactly
     *   draft, invited, confirmed, declined, live, done, no_show and cancelled, and no
     *   migration has ever widened it. So on production this value matched nothing, ever,
     *   and the list was effectively three items long.
     *
     *   `draft` WAS MISSING. Its own schema comment reads "created, nobody told yet" — a
     *   sitting that exists, has a time, and will be reminded about by InterviewService,
     *   which counts it pending. It never appeared on the schedule screen, whose entire
     *   job is showing sittings. An operator who booked a call and had not yet sent the
     *   invitation could not see it anywhere.
     *
     * Invisible in development, because SQLite declares this column TEXT and takes any
     * string: three tests seeded 'scheduled', it stored happily, and the screen was
     * verified against a state production cannot hold. MySQL rejects the insert outright,
     * which is how it was found.
     */
    private const LIVE_STATUSES = InterviewService::PENDING;

    // ═══════════════════════════════════════════════════════════════════════
    // THE ROUND
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every sitting in the window, soonest first, with its panel resolved.
     *
     * @param  int|null $programmeId narrow to one programme, or null for the whole round
     * @return list<array<string,mixed>>
     */
    public static function upcoming(?int $programmeId = null): array
    {
        $from = Carbon::now()->subDays(self::PAST_DAYS)->toDateTimeString();

        try {
            $q = DB::table('gates_interviews as i')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'i.nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                ->whereNotNull('i.scheduled_at')
                ->where('i.scheduled_at', '>=', $from)
                ->whereIn('i.status', self::LIVE_STATUSES);

            if ($programmeId !== null && $programmeId > 0) {
                $q->where('y.programme_id', $programmeId);
            }

            $rows = $q->orderBy('i.scheduled_at')
                ->select('i.*', 'n.name as nominee', 'c.title as category',
                         'c.id as category_id', 'y.programme_id')
                ->get();
        } catch (\Throwable $e) {
            error_log('[judge-schedule] could not read the round: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = self::shape($r);
        }

        return $out;
    }

    /**
     * One judge's own sittings, for their portal and for the reminder they are sent.
     *
     * Filtered in PHP on `panel_json` rather than joined, because the panel is a JSON array
     * on the sitting and neither MySQL nor SQLite can be relied on to index into it the same
     * way — `JSON_CONTAINS` does not exist on SQLite at all. A round is tens of rows, not
     * millions, so the honest portable filter is the right one here; a query that works on
     * production and returns nothing in every test is not.
     *
     * @return list<array<string,mixed>>
     */
    public static function forJudge(int $judgeId, ?int $programmeId = null): array
    {
        if ($judgeId < 1) return [];

        return array_values(array_filter(
            self::upcoming($programmeId),
            static fn (array $s): bool => in_array($judgeId, $s['panel_ids'], true)
        ));
    }

    /**
     * Judges with at least one sitting in the window, and how many.
     *
     * The list the operator picks from when sending reminders. A judge with nothing
     * scheduled is deliberately absent: a reminder about no meetings is the fastest way to
     * teach a panel to ignore our email.
     *
     * @return list<array{id:int, name:string, email:string, sittings:int, next:string}>
     */
    public static function judgesWithSittings(?int $programmeId = null): array
    {
        $round = self::upcoming($programmeId);

        /** @var array<int,array{sittings:int, next:string}> $tally */
        $tally = [];
        foreach ($round as $s) {
            foreach ($s['panel_ids'] as $jid) {
                $tally[$jid] ??= ['sittings' => 0, 'next' => ''];
                $tally[$jid]['sittings']++;
                if ($tally[$jid]['next'] === '' && $s['when'] !== '') {
                    // The round is already ordered soonest-first, so the first one seen is
                    // the next one.
                    $tally[$jid]['next'] = $s['when'];
                }
            }
        }

        if ($tally === []) return [];

        try {
            $judges = DB::table('gates_judges')->whereIn('id', array_keys($tally))
                ->orderBy('name')->get(['id', 'name', 'email']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($judges as $j) {
            $out[] = [
                'id'       => (int) $j->id,
                'name'     => (string) $j->name,
                'email'    => (string) $j->email,
                'sittings' => (int) $tally[(int) $j->id]['sittings'],
                'next'     => (string) $tally[(int) $j->id]['next'],
            ];
        }

        return $out;
    }

    /** The programmes a round is spread across, for the per-programme send. */
    public static function programmes(): array
    {
        $ids = array_values(array_unique(array_filter(
            array_column(self::upcoming(), 'programme_id')
        )));
        if ($ids === []) return [];

        try {
            return DB::table('gates_award_programmes')->whereIn('id', $ids)
                ->orderBy('sort_order')->orderBy('title')
                ->get(['id', 'title'])->map(fn ($r) => ['id' => (int) $r->id,
                                                        'title' => (string) $r->title])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DOES THE CALENDAR AGREE?
    // ═══════════════════════════════════════════════════════════════════════

    /** The calendar holds this sitting, at the time we think it does. */
    public const SYNC_OK      = 'ok';
    /** The calendar holds it at a DIFFERENT time — somebody moved it in Google. */
    public const SYNC_DRIFTED = 'drifted';
    /** The calendar does not hold it at all. Nobody will be let into that room. */
    public const SYNC_MISSING = 'missing';
    /** We could not ask. Not the same as any of the three above. */
    public const SYNC_UNKNOWN = 'unknown';

    /**
     * Ask Google what it actually holds for one sitting.
     *
     * ── THE DRIFT CASE IS THE WHOLE REASON THIS EXISTS ───────────────────────
     *
     * A missing event is loud: nobody gets an invitation and somebody complains. An event
     * that was DRAGGED in Google to a new time is silent — the invitations already went out
     * with the old time, our reminders keep sending the old time, and the panel arrives an
     * hour after the nominee. Comparing the two timestamps is the only way to see it.
     *
     * @return array{state:string, message:string, calendar_at:string, meet_url:string,
     *                html_link:string}
     */
    public static function verify(int $interviewId): array
    {
        $no = static fn (string $state, string $m): array => [
            'state' => $state, 'message' => $m,
            'calendar_at' => '', 'meet_url' => '', 'html_link' => '',
        ];

        try {
            $iv = DB::table('gates_interviews')->where('id', $interviewId)
                ->first(['id', 'scheduled_at', 'calendar_event_id', 'meet_url']);
        } catch (\Throwable) {
            return $no(self::SYNC_UNKNOWN, 'That sitting could not be read.');
        }
        if (!$iv) return $no(self::SYNC_UNKNOWN, 'That sitting could not be found.');

        $eventId = trim((string) ($iv->calendar_event_id ?? ''));
        if ($eventId === '') {
            // Never synced rather than lost. Different fix: press "create the meeting",
            // not "go and look in Google".
            return $no(self::SYNC_MISSING, 'No calendar event has been created for this sitting yet.');
        }

        $r = GoogleMeetService::boot()->readEvent(['event_id' => $eventId]);

        if (!($r['ok'] ?? false)) {
            // The script did not answer. Reported as UNKNOWN and never as MISSING: telling
            // an operator a sitting is not in the calendar because a deployment is
            // misconfigured would send them to recreate forty events that already exist.
            return $no(self::SYNC_UNKNOWN, (string) ($r['message'] ?? 'The calendar did not answer.'));
        }
        if (!($r['found'] ?? false)) {
            return $no(self::SYNC_MISSING,
                'The calendar no longer holds this event. It was created and has since been deleted.');
        }

        $calAt = trim((string) ($r['start'] ?? ''));
        $ours  = trim((string) ($iv->scheduled_at ?? ''));

        $out = [
            'state'       => self::SYNC_OK,
            'message'     => 'The calendar agrees.',
            'calendar_at' => $calAt,
            'meet_url'    => (string) ($r['meet_url'] ?? ''),
            'html_link'   => (string) ($r['html_link'] ?? ''),
        ];

        // Compared as instants, not as strings. Google answers in ISO-8601 with an offset
        // and we store a naive UTC datetime, so `'2026-09-04T10:00:00+01:00'` and
        // `'2026-09-04 09:00:00'` are the SAME moment and would fail any string test —
        // which is the MySQL/SQLite `T`-separator trap in CLAUDE.md wearing a different hat.
        if ($calAt !== '' && $ours !== '') {
            $a = strtotime($calAt);
            $b = strtotime($ours . ' UTC');
            // A minute of tolerance: Google rounds, and a schedule screen that cried drift
            // over four seconds would be ignored within a day.
            if ($a && $b && abs($a - $b) > 60) {
                $out['state']   = self::SYNC_DRIFTED;
                $out['message'] = 'The calendar has this sitting at a different time. '
                                . 'Somebody moved it in Google — the invitations and reminders '
                                . 'we have already sent carry OUR time.';
            }
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ADD TO CALENDAR
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * A judge's whole schedule as one calendar file.
     *
     * ── ONE FILE WITH MANY EVENTS, NOT MANY FILES ────────────────────────────
     *
     * A judge with six sittings should press one thing. Every calendar client handles a
     * multi-`VEVENT` file, and six separate downloads is six chances to import five.
     *
     * Each event's UID is derived from the sitting id, so re-importing after a reschedule
     * UPDATES the entry rather than adding a second one beside it. That is the whole
     * difference between a calendar file that is useful twice and one that has to be
     * cleaned up by hand.
     *
     * @param  list<array<string,mixed>> $sittings
     */
    public static function icsFor(array $sittings, string $judgeName = ''): string
    {
        $specs = [];
        foreach ($sittings as $s) {
            $specs[] = [
                // Derived from the sitting id, so re-importing after a reschedule UPDATES
                // the entry rather than adding a second one beside it. That is the whole
                // difference between a file that is useful twice and one somebody has to
                // clean up by hand.
                'uid'         => Ics::uid('judging-' . (int) $s['id']),
                'title'       => 'Judging: ' . (string) $s['nominee']
                               . ((string) $s['category'] !== '' ? ' — ' . $s['category'] : ''),
                'starts_at'   => (string) $s['when'],
                'ends_at'     => (string) $s['ends'],
                // The joining link is the location, because that is what a calendar app
                // turns into a tappable "join" on a phone at one minute to the hour.
                'location'    => (string) $s['meet_url'],
                'url'         => (string) $s['meet_url'],
                'description' => self::icsBody($s, $judgeName),
            ];
        }

        // One VCALENDAR with many VEVENTs. Concatenating single-event calendars would give
        // clients six calendars in one file, and none of them handles that as intended.
        return Ics::calendar($specs);
    }

    /** @param array<string,mixed> $s */
    private static function icsBody(array $s, string $judgeName): string
    {
        $lines = [];
        if ($judgeName !== '') $lines[] = 'You are on this panel, ' . $judgeName . '.';
        $lines[] = 'Nominee: ' . (string) $s['nominee'];
        if ((string) $s['category'] !== '') $lines[] = 'Category: ' . (string) $s['category'];
        if ((string) $s['meet_url'] !== '') $lines[] = 'Join: ' . (string) $s['meet_url'];
        if ((int) $s['panel_count'] > 1) {
            $lines[] = 'Panel of ' . (int) $s['panel_count'] . '.';
        }
        $lines[] = 'Your ballot: ' . rtrim(SiteUrl::base(), '/') . '/judge/ballot';
        // Said in the invitation as well as on the ballot, because this is the artefact a
        // judge is looking at ten minutes before the call.
        $lines[] = 'Read the dossier before the call. The map on the ballot is a finding '
                 . 'aid, not a judgement.';

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE REMINDER SOMEBODY SENDS BY HAND
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Remind judges of the sittings they are on, now, because somebody decided to.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A MANUAL SEND EXISTS ALONGSIDE THE AUTOMATIC ONE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `InterviewService::invite()` mails the panel when a SITTING is invited, and
     * `queueReminders()` fires before it. Both are per-sitting and both are automatic, which
     * covers the ordinary case and none of the ones an organiser actually rings about:
     *
     *  · The round moved and everybody needs the new links, today.
     *  · One judge says they never got it.
     *  · A programme's panel is confirmed a week after the rest and needs bringing level.
     *
     * None of those is a sitting-level event, so none of them had a button. Doing it by
     * forwarding forty emails by hand is how a link goes to the wrong judge.
     *
     * ── SCOPE IS A LIST OF JUDGE IDS, RESOLVED BY THE CALLER ─────────────────
     *
     * "Everybody", "this programme" and "just her" are three ways of choosing WHO, and they
     * all reduce to a set of judges. Keeping the choosing out of here means the send has one
     * behaviour to test rather than three, and a fourth scope later is a controller change.
     *
     * ── AND A JUDGE WITH NOTHING SCHEDULED IS NEVER MAILED ───────────────────
     *
     * Not an edge case — it is what happens when somebody presses "remind everyone" on a
     * round that has not been scheduled yet. A reminder about no meetings is the fastest
     * way to teach a panel to ignore our email, so it is skipped and counted rather than
     * sent and apologised for.
     *
     * @param  list<int> $judgeIds
     * @return array{ok:bool, sent:int, skipped:int, failed:int, message:string}
     */
    public static function remind(array $judgeIds, ?int $programmeId = null,
                                  ?OtpService $mailer = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $judgeIds))));
        if ($ids === []) {
            return ['ok' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0,
                    'message' => 'Nobody was selected.'];
        }

        $mailer ??= new OtpService();

        try {
            $judges = DB::table('gates_judges')->whereIn('id', $ids)
                ->where('is_active', 1)->orderBy('name')->get(['id', 'name', 'email']);
        } catch (\Throwable) {
            return ['ok' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0,
                    'message' => 'The judges could not be read.'];
        }

        $sent = $skipped = $failed = 0;

        foreach ($judges as $j) {
            $to = trim((string) $j->email);
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

            $mine = self::forJudge((int) $j->id, $programmeId);
            if ($mine === []) { $skipped++; continue; }

            try {
                $name = (string) $j->name;
                $ics  = self::icsFor($mine, $name);

                $mailer->sendBranded(
                    $to,
                    self::subjectFor($mine),
                    self::reminderHtml($name, $mine),
                    self::reminderText($name, $mine),
                    'Judging',
                    '',
                    '',
                    trim($ics) === '' ? [] : [[
                        'name' => Ics::filename('africa-gates-judging'),
                        'mime' => Ics::MIME,
                        'body' => $ics,
                    ]]
                );
                $sent++;
            } catch (\Throwable $e) {
                // Counted and logged, never thrown. One bad address must not stop the other
                // nine judges hearing about tomorrow.
                error_log('[judge-schedule] reminder to ' . $to . ' failed: ' . $e->getMessage());
                $failed++;
            }
        }

        $parts = [$sent . ' reminder' . ($sent === 1 ? '' : 's') . ' sent'];
        // Said out loud rather than folded into a success count. "Sent 4" on a panel of
        // seven is a number somebody will read as "all of them".
        if ($skipped > 0) $parts[] = $skipped . ' skipped (nothing scheduled, or no address)';
        if ($failed > 0)  $parts[] = $failed . ' failed';

        return ['ok' => $sent > 0, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
                'message' => implode(' · ', $parts) . '.'];
    }

    /** @param list<array<string,mixed>> $mine */
    private static function subjectFor(array $mine): string
    {
        $n = count($mine);
        if ($n === 1) {
            return 'Your judging call: ' . (string) $mine[0]['nominee']
                 . ' — ' . self::whenWords((string) $mine[0]['when']);
        }
        return 'Your judging schedule — ' . $n . ' calls, next '
             . self::whenWords((string) $mine[0]['when']);
    }

    /** In the operator's configured zone, with the abbreviation, because a call has a time. */
    private static function whenWords(string $stored): string
    {
        return $stored === '' ? 'time to be set' : DisplayTime::showZoned($stored, 'D j M, H:i');
    }

    /** @param list<array<string,mixed>> $mine */
    private static function reminderHtml(string $name, array $mine): string
    {
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $out = '<p>' . ($name !== '' ? 'Dear ' . $h($name) . ',' : 'Hello,') . '</p>'
             . '<p>This is where you are expected, and the link for each one. The attached '
             . 'calendar file adds all of them to your own calendar in one go.</p>';

        foreach ($mine as $s) {
            $out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
                  . 'style="width:100%;margin:0 0 14px"><tr><td '
                  . 'style="padding:13px 15px;border:1px solid #dfe4e4;border-radius:10px">'
                  . '<div style="font:600 15px/1.4 Arial,sans-serif;color:#10292c">'
                  . $h((string) $s['nominee'])
                  . ((string) $s['category'] !== ''
                      ? ' <span style="font-weight:400;color:#5a6d6f">· ' . $h((string) $s['category']) . '</span>'
                      : '')
                  . '</div>'
                  . '<div style="font:400 13.5px/1.5 Arial,sans-serif;color:#3c4649;margin-top:4px">'
                  . $h(self::whenWords((string) $s['when']))
                  . ' · ' . (int) $s['minutes'] . ' min</div>';

            if ((string) $s['meet_url'] !== '') {
                // A real anchor, not a bare URL: this is the one thing the message exists to
                // get clicked, and it is clicked on a phone.
                $out .= '<div style="margin-top:10px"><a href="' . $h((string) $s['meet_url'])
                      . '" style="display:inline-block;background:#237b22;color:#fff;'
                      . 'text-decoration:none;font:600 14px/1 Arial,sans-serif;'
                      . 'padding:11px 16px;border-radius:999px">Join the interview</a></div>';
            } else {
                $out .= '<div style="margin-top:8px;font:400 13px/1.5 Arial,sans-serif;color:#7a5600">'
                      . 'The meeting link for this one is not ready yet — we will send it on.</div>';
            }

            $out .= '</td></tr></table>';
        }

        $base = rtrim(SiteUrl::base(), '/');

        return $out
             . '<p style="font:400 13.5px/1.6 Arial,sans-serif;color:#3c4649">'
             . 'Your ballot is at <a href="' . $base . '/judge/ballot">' . $base . '/judge/ballot</a>. '
             . 'Read the dossier before the call — the map at the top of each entry is a '
             . 'finding aid, not a judgement, and nobody has checked it.</p>'
             . '<p style="font:400 13px/1.6 Arial,sans-serif;color:#5a6d6f">'
             . 'If a time does not work, reply to this message rather than declining the '
             . 'calendar invitation — a declined invitation reaches a calendar and not a person.</p>';
    }

    /**
     * The plain part, generated from the same list.
     *
     * Generated and never hand-written: a text part that keeps saying last week's times
     * after the HTML was corrected is worse than a clumsy one, which is the lesson the
     * campaign editor recorded when its plain part was prose somebody maintained by hand.
     *
     * @param list<array<string,mixed>> $mine
     */
    private static function reminderText(string $name, array $mine): string
    {
        $lines = [$name !== '' ? 'Dear ' . $name . ',' : 'Hello,', ''];
        $lines[] = 'Where you are expected, and the link for each one. The attached calendar';
        $lines[] = 'file adds all of them to your own calendar in one go.';
        $lines[] = '';

        foreach ($mine as $s) {
            $lines[] = '* ' . (string) $s['nominee']
                     . ((string) $s['category'] !== '' ? ' (' . (string) $s['category'] . ')' : '');
            $lines[] = '  ' . self::whenWords((string) $s['when']) . ' · ' . (int) $s['minutes'] . ' min';
            $lines[] = (string) $s['meet_url'] !== ''
                ? '  Join: ' . (string) $s['meet_url']
                : '  The meeting link for this one is not ready yet.';
            $lines[] = '';
        }

        $base = rtrim(SiteUrl::base(), '/');
        $lines[] = 'Your ballot: ' . $base . '/judge/ballot';
        $lines[] = 'Read the dossier before the call. The map on each entry is a finding aid,';
        $lines[] = 'not a judgement, and nobody has checked it.';
        $lines[] = '';
        $lines[] = 'If a time does not work, reply to this message rather than declining the';
        $lines[] = 'calendar invitation — a declined invitation reaches a calendar, not a person.';

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SHAPING
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    private static function shape(object $r): array
    {
        $when    = trim((string) ($r->scheduled_at ?? ''));
        $mins    = (int) ($r->duration_mins ?? 30);
        $panelIds = array_values(array_unique(array_map('intval',
            json_decode((string) ($r->panel_json ?? '[]'), true) ?: [])));

        return [
            'id'          => (int) $r->id,
            'nominee'     => (string) ($r->nominee ?? 'Unknown'),
            'category'    => (string) ($r->category ?? ''),
            'category_id' => (int) ($r->category_id ?? 0),
            'programme_id'=> (int) ($r->programme_id ?? 0),
            'status'      => (string) $r->status,
            'when'        => $when,
            'ends'        => $when === '' ? '' :
                Carbon::parse($when)->addMinutes(max(5, $mins))->toDateTimeString(),
            'minutes'     => $mins,
            'day'         => $when === '' ? '' : substr($when, 0, 10),
            'meet_url'    => (string) ($r->meet_url ?? ''),
            'has_event'   => trim((string) ($r->calendar_event_id ?? '')) !== '',
            'panel_ids'   => $panelIds,
            'panel_count' => count($panelIds),
            // Loaded per sitting rather than in one query for the round. A round is tens of
            // rows and this is an admin screen; the readable version is the right trade
            // until somebody measures otherwise.
            'panel'       => $panelIds === [] ? [] : InterviewService::panel((int) $r->id),
            'admin_url'   => '/admin/interviews/' . (int) $r->id,
        ];
    }
}
