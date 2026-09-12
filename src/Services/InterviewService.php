<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Services\GoogleMeetService;
use AfricaGates\Support\Env;
use AfricaGates\Support\Ics;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The interview, from "we should talk to this person" to a transcript a panel can read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS ACTUALLY MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominee_interviews` has existed since 2026_08_15. {@see EvidenceService} reads
 * it, the judge ballot renders it with a heading that says "Interview — the nominee's own
 * words", and {@see EvidenceService::coverage()} prints "no interview on file" when it is
 * empty. It has never had a writer. Not an admin form, not an importer, not a route.
 *
 * So every dossier the panel has ever seen carried that line, and the 55% of a nominee's
 * CPI that is supposed to come from expert judgement has been formed from a nominator's
 * paragraph and a photograph. This file is the missing half.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ABOUT THE AI, AND WHAT IT HONESTLY CANNOT DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The interview runs on Google Meet, and the model is used throughout: it writes the
 * question pack from the dossier ({@see InterviewBrief}), it drives the sitting from the
 * console, and it reads the transcript afterwards ({@see InterviewReview}).
 *
 * What it does NOT do is join the call. A speaking participant in Meet needs a
 * persistent WebRTC client holding a media session — Google's Meet Media API — and this
 * platform is PHP-FPM on shared cPanel hosting with no long-running process and no
 * shell. Claiming otherwise in a comment would be the same class of lie as the cooling-off
 * constant that was written into an email and read nowhere else.
 *
 * So the AI speaks through the room rather than into it: the panel has the console open,
 * the next question is on screen with the criterion it is testing, and the follow-ups are
 * generated from what was just answered. A human reads it aloud. The intelligence is real
 * and the voice is borrowed — and afterwards the model reads every word of the transcript,
 * which is the half a human panel is worst at.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE RULES THIS FILE ENFORCES AND WILL NOT BEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. NOTHING REACHES THE PANEL WITHOUT THE NOMINEE'S OWN CONSENT. Not staff ticking a
 *    box on their behalf — the nominee opens their own link and presses the button, and
 *    {@see publish()} refuses without `consent_at`. These are their words, recorded,
 *    machine-transcribed, read by people deciding an award, and kept as long as the
 *    result can be questioned. The evidence table already treats that as serious; this
 *    is where the seriousness is actually enforced.
 *
 * 2. A MACHINE TRANSCRIPT IS LABELLED AS ONE. `transcript_source` is set to 'machine'
 *    when it came from Meet's transcription, because a model mishears proper nouns and
 *    numbers, which are precisely the load-bearing facts in an impact claim. A judge
 *    reading "we reached about fifty schools" is entitled to know whether the hedge is
 *    the nominee's or the transcriber's.
 *
 * 3. NO SCORE IS EVER WRITTEN FROM HERE. Not from the brief, not from the review, not
 *    from a "suggested band". `gates_judge_criteria_scores` is written by judges only.
 *    An AI that could move the expert 55% would make the 45/55 weighting a fiction, and
 *    would do it invisibly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE INVITATION IS A TOKEN AND NOT A LOGIN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A nominee has no account and cannot be made to create one to attend their own
 * interview; the platform already learned what a login wall does to attendance on the
 * claim path. The token is the whole credential and opens exactly one sitting: confirm,
 * consent, decline, and read the Meet link. It authorises nothing else and reveals no
 * other nominee.
 *
 * The address it is sent to is NEVER chosen by the requester. It resolves through
 * {@see ClaimIndependence::contactsFor()} — the contacts already on an approved
 * nomination — for the same reason the claim path does it: an endpoint that accepted
 * "send the interview link to X" would let anybody be interviewed as anybody.
 */
final class InterviewService
{
    /** Reminder jobs, both queued the moment a sitting is scheduled. */
    public const JOB_REMIND = 'interview.remind';

    /** How far ahead the two reminders go out. */
    public const REMIND_HOURS = [24, 2];

    /** Statuses in which a sitting is still expected to happen. */
    public const PENDING = ['draft', 'invited', 'confirmed', 'live'];

    // ══ 1. create and schedule ═══════════════════════════════════════════════

    /**
     * Open a sitting for a nominee. Does not tell anybody — {@see invite()} does that.
     *
     * @param array{scheduled_at?:string,duration_mins?:int,timezone?:string,
     *              meet_url?:string,panel?:list<int>,language?:string} $opts
     * @return array{ok:bool, id?:int, token?:string, message:string}
     */
    public static function create(int $nomineeId, array $opts = [], ?int $adminId = null): array
    {
        $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
        if (!$nominee) return ['ok' => false, 'message' => 'That nominee could not be found.'];
        if (!empty($nominee->merged_into)) {
            return ['ok' => false, 'message' => 'That nominee has been merged into another profile.'];
        }

        // One open sitting per nominee. A second appointment while the first is still
        // expected is nearly always a mistake, and two live links to two Meet rooms is
        // how a nominee joins the wrong one.
        $open = DB::table('gates_interviews')->where('nominee_id', $nomineeId)
            ->whereIn('status', self::PENDING)->first();
        if ($open) {
            return ['ok' => false, 'id' => (int) $open->id,
                    'message' => 'This nominee already has an interview waiting (#' . $open->id
                               . '). Reschedule or cancel that one first.'];
        }

        $now   = Carbon::now()->toDateTimeString();
        // The zone FIRST, because the time is read in it. Resolving them the other way
        // round reads an operator's local afternoon in whatever the server default is.
        $zone  = self::normaliseZone((string) ($opts['timezone'] ?? ''));
        $when  = self::normaliseWhen((string) ($opts['scheduled_at'] ?? ''), $zone);
        $meet  = trim((string) ($opts['meet_url'] ?? ''));
        $panel = array_values(array_unique(array_map('intval', $opts['panel'] ?? [])));

        try {
            $id = (int) DB::table('gates_interviews')->insertGetId([
                'nominee_id'    => $nomineeId,
                'category_id'   => (int) ($nominee->category_id ?? 0) ?: null,
                'cycle_id'      => self::cycleFor((int) ($nominee->category_id ?? 0)),
                'status'        => 'draft',
                'scheduled_at'  => $when,
                'duration_mins' => max(10, min(180, (int) ($opts['duration_mins'] ?? 30))),
                'timezone'      => $zone,
                'meet_url'      => $meet !== '' ? mb_substr($meet, 0, 400) : null,
                'meet_code'     => $meet !== '' ? self::meetCode($meet) : null,
                'panel_json'    => $panel ? json_encode($panel) : null,
                'invite_token'  => self::mintToken(),
                'language'      => mb_substr((string) ($opts['language'] ?? 'en'), 0, 12),
                'created_by'    => $adminId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[interview] could not open a sitting for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The interview could not be created just now.'];
        }

        // The question pack is built off the request: it reads the whole dossier and may
        // call a model. Nobody should wait for that on a form submit.
        InterviewBrief::queue($id);

        return ['ok' => true, 'id' => $id, 'token' => (string) self::tokenFor($id),
                'message' => 'Interview #' . $id . ' opened.'];
    }

    /**
     * Move or set the appointment. Re-queues the reminders and clears a stale
     * confirmation, because "yes" was given for the old time.
     *
     * @return array{ok:bool, message:string, moved?:bool}
     */
    public static function reschedule(int $id, string $when, ?int $mins = null,
                                      string $timezone = '', string $meetUrl = ''): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];
        if (in_array($iv->status, ['done', 'cancelled'], true)) {
            return ['ok' => false, 'message' => 'A ' . $iv->status . ' interview cannot be moved. Open a new one.'];
        }

        // The zone the time was typed in: the one being set now, else the sitting's own.
        // Falling back to a server default here would move a sitting scheduled in Nairobi
        // by an hour every time somebody edited its duration.
        $zone = self::normaliseZone($timezone !== '' ? $timezone : (string) ($iv->timezone ?? ''));
        $at   = self::normaliseWhen($when, $zone);
        if ($at === null) return ['ok' => false, 'message' => 'That date and time could not be read.'];

        $was  = (string) ($iv->scheduled_at ?? '');
        $meet = trim($meetUrl);
        $set  = [
            'scheduled_at' => $at,
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ];
        if ($mins !== null)     $set['duration_mins'] = max(10, min(180, $mins));
        if ($timezone !== '')   $set['timezone']      = $zone;
        if ($meet !== '') {
            $set['meet_url']  = mb_substr($meet, 0, 400);
            $set['meet_code'] = self::meetCode($meet);
        }
        // A confirmation is for a time, not for a sitting. Moving it makes the nominee's
        // "yes" answer a question nobody asked, so it goes back to invited and they are
        // asked again. Consent is NOT cleared — that was given for being recorded, which
        // the new time does not change.
        if ($was !== '' && $was !== $at && $iv->status === 'confirmed') {
            $set['status']       = 'invited';
            $set['confirmed_at'] = null;
        }

        DB::table('gates_interviews')->where('id', $id)->update($set);
        self::queueReminders($id, $at);

        return ['ok' => true, 'moved' => $was !== '' && $was !== $at,
                'message' => 'Interview #' . $id . ' is set for ' . $at . '.'];
    }

    /** Attach or replace the Meet link. Kept separate: it is the one field that arrives late. */
    public static function setMeetUrl(int $id, string $url): array
    {
        $url = trim($url);
        if ($url === '') return ['ok' => false, 'message' => 'Paste the Meet link first.'];
        if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'message' => 'That does not look like a link.'];
        }
        $code = self::meetCode($url);
        DB::table('gates_interviews')->where('id', $id)->update([
            'meet_url'   => mb_substr($url, 0, 400),
            'meet_code'  => $code,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'code' => $code,
                'message' => $code !== ''
                    ? 'Meet link saved (' . $code . ').'
                    : 'Link saved. It is not a meet.google.com URL, so the transcript cannot be '
                    . 'matched to it automatically — you can still paste the transcript in by hand.'];
    }

    /**
     * The bare conference code out of a Meet URL: abc-defg-hij.
     *
     * Extracted once at save time rather than re-parsed at every comparison, because the
     * stored URL may carry ?authuser=, a hs= parameter, or be a calendar shortlink, and
     * Google's transcript artefacts are keyed by the conference rather than by whatever
     * somebody pasted.
     */
    public static function meetCode(string $url): string
    {
        if (preg_match('~meet\.google\.com/(?:lookup/)?([a-z]{3}-[a-z]{4}-[a-z]{3})~i', $url, $m)) {
            return strtolower($m[1]);
        }
        // A bare code, typed rather than pasted.
        if (preg_match('~^\s*([a-z]{3}-[a-z]{4}-[a-z]{3})\s*$~i', $url, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    // ══ 2. tell people ═══════════════════════════════════════════════════════

    /**
     * Send the nominee their link, and the panel theirs.
     *
     * Returns which channels were actually reached, because "invited" with nobody
     * emailed is the failure this whole platform keeps finding.
     *
     * @return array{ok:bool, message:string, sent?:list<string>, missing?:bool}
     */
    public static function invite(int $id, ?OtpService $mailer = null, ?SmsService $sms = null): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];
        if (empty($iv->scheduled_at)) {
            return ['ok' => false, 'message' => 'Set a date and time before inviting anybody.'];
        }
        $nominee = DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->first();
        if (!$nominee) return ['ok' => false, 'message' => 'That nominee could not be found.'];

        $link  = self::url($id);
        $sent  = [];

        // ── the nominee ───────────────────────────────────────────────────────
        // Address resolved from the nomination, never supplied by the caller. Same rule
        // as the claim path, for the same reason.
        $contacts = [];
        try {
            $contacts = ClaimIndependence::contactsFor((int) $iv->nominee_id);
        } catch (\Throwable $e) {
            error_log('[interview] could not read contacts for nominee ' . $iv->nominee_id . ': ' . $e->getMessage());
        }

        $when = self::whenText($iv);
        foreach ($contacts as $c) {
            if (($c['channel'] ?? '') !== 'email') continue;
            $to = (string) ($c['value'] ?? '');
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            $body = self::nomineeInviteBody((string) $nominee->name, $when, $link, $iv);
            try {
                if ($mailer) {
                    $mailer->sendCustom($to, 'Your Africa GATES interview — ' . $when, $body);
                    $sent[] = $to;
                }
            } catch (\Throwable $e) {
                error_log('[interview] invite to ' . $to . ' failed: ' . $e->getMessage());
            }
            break; // one invitation, to the first address on file
        }

        // A text as well when we have a number: an emailed appointment three days out is
        // read once and lost, and the whole cost of a no-show is borne by the panel.
        foreach ($contacts as $c) {
            if (($c['channel'] ?? '') !== 'phone') continue;
            $num = (string) ($c['value'] ?? '');
            if ($num === '') continue;
            $text = 'Africa GATES: your interview is ' . $when . '. Confirm and read the details here: ' . $link;
            if (self::text($sms, $num, (string) ($c['country'] ?? ''), $text)) $sent[] = $num;
            break;
        }

        // ── the panel ─────────────────────────────────────────────────────────
        foreach (self::panel($id) as $j) {
            $to = (string) ($j['email'] ?? '');
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            try {
                if ($mailer) {
                    $name = (string) ($j['name'] ?? '');

                    // The judge's whole run, attached. Best-effort: a schedule that could
                    // not be built must not stop the invitation — the message is still true
                    // about the sitting it is announcing.
                    //
                    // ── TWO FILES, BECAUSE A CSV IS NOT "ADD TO CALENDAR" ────────
                    //
                    // The spreadsheet answers "what am I doing next week" and a judge can
                    // read it on a laptop. It cannot be added to a calendar, so on the
                    // morning of the call nothing reminds anybody and the link has to be
                    // found again in a three-day-old email — which is the whole failure
                    // `Support\Ics` was written for on the ticket side.
                    //
                    // One .ics with every sitting in it, not one per sitting: six
                    // attachments is six chances to import five, and the UIDs are derived
                    // from the sitting ids so a re-send after a reschedule UPDATES the
                    // entries instead of doubling them.
                    $file = self::panelAttachments((int) ($j['id'] ?? 0), $name);

                    $mailer->sendBranded($to,
                        'Interview: ' . $nominee->name . ' — ' . $when,
                        self::panelInviteHtml($name, (string) $nominee->name, $when, $iv),
                        self::panelInviteBody($name, (string) $nominee->name, $when, $iv),
                        'Judging', '', '', $file);
                    $sent[] = $to;
                }
            } catch (\Throwable $e) {
                error_log('[interview] panel invite to ' . $to . ' failed: ' . $e->getMessage());
            }
        }

        $now = Carbon::now()->toDateTimeString();

        $row = [
            'status'     => $iv->status === 'draft' ? 'invited' : $iv->status,
            'invited_at' => $now,
            'updated_at' => $now,
        ];

        // ── WHEN THE NOMINEE WAS TOLD ABOUT THE BOT ──────────────────────────
        //
        // Only when the invitation actually said it — {@see botDisclosure()} returns ''
        // when no bot will attend, and a stamp claiming a disclosure that was never
        // written is worse than an empty column: it is a consent record that lies.
        //
        // Stamped once and never overwritten. Re-inviting sends the sentence again, but
        // the fact worth keeping is when the person was FIRST told, not the last time we
        // repeated it — and an operator asking "were they told before the bot joined?"
        // is asking about the first.
        if (self::botDisclosure() !== '' && empty($iv->bot_disclosed_at)) {
            $row['bot_disclosed_at'] = $now;
        }

        DB::table('gates_interviews')->where('id', $id)->update(
            \AfricaGates\Support\OptionalColumn::filter('gates_interviews', $row, ['bot_disclosed_at'])
        );
        self::queueReminders($id, (string) $iv->scheduled_at);

        if ($sent === []) {
            return ['ok' => true, 'sent' => [], 'missing' => true,
                    'message' => 'The sitting is marked invited, but nothing could be sent — no email '
                               . 'address is on the nomination, or no mail transport is configured. '
                               . 'Send this link yourself: ' . $link];
        }
        return ['ok' => true, 'sent' => $sent,
                'message' => 'Invitation sent to ' . count($sent) . ' recipient(s).'];
    }

    /** Queue the two reminders. Deduped per sitting per offset, so re-inviting is safe. */
    public static function queueReminders(int $id, string $when): void
    {
        $ts = strtotime($when);
        if (!$ts) return;
        try {
            $q = new QueueService();
            foreach (self::REMIND_HOURS as $h) {
                $delay = $ts - (int) (Carbon::now()->getTimestamp()) - ($h * 3600);
                if ($delay < 0) continue;                      // that moment has passed
                $q->push(self::JOB_REMIND, ['interview_id' => $id, 'hours' => $h],
                         $delay, 'interview:' . $id . ':' . $h);
            }
        } catch (\Throwable $e) {
            error_log('[interview] could not queue reminders for ' . $id . ': ' . $e->getMessage());
        }
    }

    /**
     * Deliver one reminder. Runs in the job worker, so it never throws.
     *
     * Silently does nothing when the sitting has moved on — cancelled, already held, or
     * rescheduled past the reminder it was queued for.
     */
    public static function remind(array $p, ?OtpService $mailer = null, ?SmsService $sms = null): void
    {
        $id = (int) ($p['interview_id'] ?? 0);
        $iv = $id > 0 ? self::byId($id) : null;
        if (!$iv || !in_array($iv->status, ['invited', 'confirmed'], true)) return;
        if (empty($iv->scheduled_at)) return;

        $nominee = DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->first();
        if (!$nominee) return;
        $when = self::whenText($iv);
        $link = self::url($id);
        $need = empty($iv->consent_at);

        $body = "This is a reminder of your Africa GATES interview.\n\n"
              . 'When:  ' . $when . "\n"
              . 'Where: Google Meet — ' . (trim((string) ($iv->meet_url ?? '')) !== ''
                    ? (string) $iv->meet_url : 'the link is on your interview page') . "\n\n"
              . ($need
                    ? "ONE THING STILL NEEDS DOING: we do not yet have your permission to record "
                    . "and transcribe the conversation, and without it the panel cannot be shown "
                    . "anything from it. It takes one press:\n" . $link . "\n\n"
                    : "Your interview page, with the joining link and what to expect:\n" . $link . "\n\n")
              . "If you cannot make it, say so on that page and we will find another time. "
              . "Telling us is always better than missing it.\n";

        try {
            foreach (ClaimIndependence::contactsFor((int) $iv->nominee_id) as $c) {
                if (($c['channel'] ?? '') === 'email' && $mailer) {
                    $mailer->sendCustom((string) $c['value'],
                        'Reminder: your interview ' . $when, $body);
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('[interview] reminder email failed for ' . $id . ': ' . $e->getMessage());
        }

        // The two-hour reminder also texts, because that is the one that changes whether
        // somebody turns up.
        if ((int) ($p['hours'] ?? 0) <= 4 && $sms) {
            try {
                foreach (ClaimIndependence::contactsFor((int) $iv->nominee_id) as $c) {
                    if (($c['channel'] ?? '') !== 'phone') continue;
                    self::text($sms, (string) $c['value'], (string) ($c['country'] ?? ''),
                        'Africa GATES: your interview is at ' . $when . '. Details and joining link: ' . $link);
                    break;
                }
            } catch (\Throwable $e) {
                error_log('[interview] reminder SMS failed for ' . $id . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * One text message, if a transport exists and the number can be made E.164.
     *
     * A nomination stores the number as it was typed (08031234567) and every provider
     * wants +234…; the country travels with the number from the nomination rather than
     * being assumed, exactly as {@see ClaimNotifier} does it.
     */
    private static function text(?SmsService $sms, string $number, string $country, string $body): bool
    {
        if ($sms === null || !$sms->configured()) return false;
        $e164 = \AfricaGates\Support\Phone::normalize($number, $country !== '' ? $country : 'NG');
        if ($e164 === null) return false;
        try { return $sms->sendSms($e164, $body, 'interview'); }
        catch (\Throwable $e) {
            error_log('[interview] SMS failed: ' . $e->getMessage());
            return false;
        }
    }

    // ══ 3. the nominee's own page ════════════════════════════════════════════

    /**
     * Everything the nominee's page needs, from the token alone.
     *
     * Carries no other nominee, no panel scores, no dossier — a token is an appointment
     * credential, not a read of the platform.
     */
    public static function preview(string $token): ?array
    {
        $iv = self::byToken($token);
        if (!$iv) return null;
        $nominee = DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->first();
        if (!$nominee) return null;

        $ts   = $iv->scheduled_at ? strtotime((string) $iv->scheduled_at) : false;
        $past = $ts ? $ts < Carbon::now()->getTimestamp() : false;

        return [
            'id'            => (int) $iv->id,
            'nominee'       => (string) $nominee->name,
            'status'        => (string) $iv->status,
            'when'          => (string) ($iv->scheduled_at ?? ''),
            'when_text'     => self::whenText($iv),
            'timezone'      => (string) $iv->timezone,
            'duration'      => (int) $iv->duration_mins,
            'meet_url'      => (string) ($iv->meet_url ?? ''),
            'confirmed'     => !empty($iv->confirmed_at),
            'declined'      => !empty($iv->declined_at),
            'consented'     => !empty($iv->consent_at),
            'consent_name'  => (string) ($iv->consent_name ?? ''),
            'past'          => $past,
            'open'          => in_array($iv->status, self::PENDING, true),
            // The themes, never the questions. A panel that hands over its exact wording
            // is interviewing a rehearsal; a nominee told nothing is being ambushed. What
            // is fair to share is what the conversation is ABOUT.
            'themes'        => InterviewBrief::themes((int) $iv->id),
        ];
    }

    /**
     * The nominee says yes, and gives (or withholds) permission to record.
     *
     * Consent and confirmation are separate on purpose: somebody may agree to attend and
     * refuse to be recorded, and that must be a possible answer rather than a dead end.
     * The interview still happens; only what may be shown to the panel changes.
     *
     * @return array{ok:bool, message:string, consented?:bool}
     */
    public static function confirm(string $token, string $name, bool $consent, string $ip = ''): array
    {
        $iv = self::byToken($token);
        if (!$iv) return ['ok' => false, 'message' => 'That link is not valid.'];
        if (!in_array($iv->status, self::PENDING, true)) {
            return ['ok' => false, 'message' => 'This interview is ' . $iv->status . ', so there is nothing to confirm.'];
        }
        $name = trim($name);
        if ($consent && $name === '') {
            return ['ok' => false, 'message' => 'Please type your name to record your permission.'];
        }

        $now = Carbon::now()->toDateTimeString();
        $set = [
            'status'       => 'confirmed',
            'confirmed_at' => $now,
            'declined_at'  => null,
            'updated_at'   => $now,
        ];
        if ($consent) {
            $set['consent_at']   = $now;
            $set['consent_name'] = mb_substr($name, 0, 160);
            $set['consent_ip']   = mb_substr($ip, 0, 45);
        }
        DB::table('gates_interviews')->where('id', (int) $iv->id)->update($set);

        return ['ok' => true, 'consented' => $consent,
                'message' => $consent
                    ? 'Thank you — you are confirmed, and we have your permission to record.'
                    : 'You are confirmed. We have NOT recorded permission to keep a transcript, '
                    . 'so nothing from the conversation will be shown to the panel in writing.'];
    }

    /** The nominee cannot make it. A note is optional and goes to the desk. */
    public static function decline(string $token, string $note = ''): array
    {
        $iv = self::byToken($token);
        if (!$iv) return ['ok' => false, 'message' => 'That link is not valid.'];
        if (!in_array($iv->status, self::PENDING, true)) {
            return ['ok' => false, 'message' => 'This interview is already ' . $iv->status . '.'];
        }
        $now = Carbon::now()->toDateTimeString();
        DB::table('gates_interviews')->where('id', (int) $iv->id)->update([
            'status'          => 'declined',
            'declined_at'     => $now,
            'confirmed_at'    => null,
            'reschedule_note' => $note !== '' ? mb_substr(trim($note), 0, 500) : null,
            'updated_at'      => $now,
        ]);

        // A decline that nobody reads is a no-show with extra steps.
        try {
            $nominee = DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->value('name');
            (new SupportTicketService())->open(
                'A nominee cannot attend their scheduled interview and has asked to move it.' . "\n\n"
                . 'Nominee:   ' . (string) $nominee . "\n"
                . 'Interview: #' . $iv->id . "\n"
                . 'Was set for: ' . (string) ($iv->scheduled_at ?? 'unscheduled') . "\n"
                . 'They said: ' . ($note !== '' ? $note : '(nothing)') . "\n\n"
                . 'Open ' . rtrim(SiteUrl::base(), '/') . '/admin/interviews/' . $iv->id
                . ' to offer another time.',
                [],
                new SupportContext(null, null),
                [],
                ['subject_override' => 'Interview declined — ' . (string) $nominee,
                 'severity' => 'high']
            );
        } catch (\Throwable $e) {
            error_log('[interview] could not open a ticket for decline of ' . $iv->id . ': ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Thank you for telling us. We will be in touch with another time.'];
    }

    // ══ 4. running and closing the sitting ═══════════════════════════════════

    /** The panel opened the console. Recorded so a sitting nobody ran is visible as one. */
    public static function markLive(int $id): void
    {
        $iv = self::byId($id);
        if (!$iv || !in_array($iv->status, ['draft', 'invited', 'confirmed'], true)) return;
        DB::table('gates_interviews')->where('id', $id)->update([
            'status'     => 'live',
            'started_at' => (string) ($iv->started_at ?? Carbon::now()->toDateTimeString()),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** Close the sitting. `held` false records a no-show rather than a completed interview. */
    public static function close(int $id, bool $held = true, string $note = ''): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];
        DB::table('gates_interviews')->where('id', $id)->update([
            'status'       => $held ? 'done' : 'no_show',
            'ended_at'     => Carbon::now()->toDateTimeString(),
            'outcome_note' => $note !== '' ? mb_substr(trim($note), 0, 500) : null,
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'message' => $held ? 'Interview closed.' : 'Recorded as a no-show.'];
    }

    /**
     * Called off by us.
     *
     * ── AND CALLED OFF IN THE CALENDAR TOO ───────────────────────────────────
     *
     * This used to change one column. The sitting read "cancelled" in the console while
     * the appointment stayed in the nominee's diary, the panel's diary, and the guests' —
     * with a Meet link that still opened a room. Nobody was told. The first anybody knew
     * was somebody sitting in a call alone at the appointed time, and the console had
     * been showing "cancelled" the whole while, which is what made it unreportable.
     *
     * The calendar leg is BEST-EFFORT and never blocks the cancellation: an Apps Script
     * that is unreachable, unconfigured or not yet redeployed must not leave an interview
     * we have decided is off still marked as happening. What it must do is SAY so, which
     * is why the outcome is folded into the message rather than swallowed — an operator
     * who reads "the calendar could not be reached" deletes the event by hand, and one
     * who reads nothing does not.
     */
    public static function cancel(int $id, string $note = ''): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];
        if ($iv->status === 'done') {
            return ['ok' => false, 'message' => 'That interview has already been held.'];
        }

        $patch = [
            'status'       => 'cancelled',
            'outcome_note' => $note !== '' ? mb_substr(trim($note), 0, 500) : null,
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ];

        $said    = '';
        $eventId = trim((string) ($iv->calendar_event_id ?? ''));
        if ($eventId !== '') {
            try {
                $svc = GoogleMeetService::boot();
                if (!$svc->canSchedule()) {
                    $said = ' The calendar event could not be removed (' . $svc->why()
                          . ') — delete it by hand.';
                } else {
                    $r = $svc->cancelEvent(['event_id' => $eventId]);
                    if ($r['ok']) {
                        // Cleared whether or not the event was still there: either way there
                        // is no longer a room to send anybody — or a bot — to.
                        $patch['meet_url']          = null;
                        $patch['meet_code']         = null;
                        $patch['calendar_event_id'] = null;
                        $said = $r['cancelled']
                            ? ' The calendar event has been deleted and the guests told.'
                            : ' The calendar event was already gone.';
                    } else {
                        $said = ' The calendar event could not be removed (' . $r['message']
                              . ') — delete it by hand.';
                    }
                }
            } catch (\Throwable $e) {
                error_log('[interview] calendar cancel ' . $id . ': ' . $e->getMessage());
                $said = ' The calendar could not be reached — delete the event by hand.';
            }
        }

        DB::table('gates_interviews')->where('id', $id)->update($patch);
        return ['ok' => true, 'message' => 'Interview #' . $id . ' cancelled.' . $said];
    }

    /** Append one captured answer to the live record. */
    public static function recordAnswer(int $id, string $questionKey, string $question,
                                        string $note, ?int $criterionId = null, string $flag = ''): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];

        $answers = json_decode((string) ($iv->answers_json ?? '[]'), true);
        if (!is_array($answers)) $answers = [];

        $entry = [
            'key'          => mb_substr($questionKey, 0, 60),
            'question'     => mb_substr($question, 0, 500),
            'note'         => mb_substr(trim($note), 0, 4000),
            'criterion_id' => $criterionId,
            'flag'         => in_array($flag, ['good', 'thin', 'evasive', 'contradiction'], true) ? $flag : '',
            'at'           => Carbon::now()->toDateTimeString(),
        ];

        // Re-answering a question replaces its entry rather than appending a second one:
        // the console lets a panel come back to a question, and a list with the same
        // question twice is a list nobody can read afterwards.
        $replaced = false;
        foreach ($answers as $i => $a) {
            if (($a['key'] ?? '') === $entry['key'] && $entry['key'] !== '') {
                $answers[$i] = $entry + ['first_at' => (string) ($a['first_at'] ?? $a['at'] ?? '')];
                $replaced = true;
                break;
            }
        }
        if (!$replaced) $answers[] = $entry;

        DB::table('gates_interviews')->where('id', $id)->update([
            'answers_json' => json_encode(array_values($answers)),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'count' => count($answers), 'message' => 'Saved.'];
    }

    // ══ 5. publish to the panel ══════════════════════════════════════════════

    /**
     * Write the transcript into the dossier the judges read.
     *
     * THE CONSENT GATE IS HERE. Not in the template, not in a convention, not in a
     * reviewer's memory — in the one function that can make a nominee's recorded words
     * visible to the people deciding their award.
     *
     * @param array{source?:string, transcriber?:string, language?:string,
     *              translated_from?:string, interviewer?:string, medium?:string} $meta
     * @return array{ok:bool, message:string, transcript_id?:int}
     */
    public static function publish(int $id, string $transcript, array $meta = [], ?int $adminId = null): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'That interview could not be found.'];

        $transcript = trim($transcript);
        if ($transcript === '') {
            return ['ok' => false, 'message' => 'There is no transcript to publish.'];
        }
        if (mb_strlen($transcript) < 120) {
            return ['ok' => false, 'message' => 'That is too short to be a transcript of an interview. '
                                             . 'Paste the whole thing, or leave it and add notes instead.'];
        }
        if (empty($iv->consent_at)) {
            return ['ok' => false, 'message' =>
                'The nominee has not given permission for a transcript to be kept and shown to the '
                . 'panel, so this cannot be published. Ask them to press the button on their '
                . 'interview page, or record their permission with them on a call and use their link.'];
        }

        $source = in_array((string) ($meta['source'] ?? ''), ['human', 'machine', 'hybrid'], true)
            ? (string) $meta['source'] : 'machine';
        $now = Carbon::now()->toDateTimeString();

        $row = [
            'nominee_id'        => (int) $iv->nominee_id,
            'interviewed_at'    => (string) ($iv->scheduled_at ?? $now),
            // trim() and not `??`. The admin form posts `interviewer` as an EMPTY STRING when
            // it is left blank, and `??` only falls back on null — so the panel's names never
            // reached the column, and every judge saw the ballot's generic "Programme team"
            // instead of who actually conducted the interview.
            'interviewer'       => mb_substr(
                trim((string) ($meta['interviewer'] ?? '')) !== ''
                    ? trim((string) $meta['interviewer'])
                    : self::panelNames($id), 0, 160),
            'medium'            => in_array((string) ($meta['medium'] ?? ''), ['in_person','phone','video','written'], true)
                                    ? (string) $meta['medium'] : 'video',
            // Same trap as `interviewer` below: a blank form field is '' and not null, and a
            // transcript stored with an empty language tells a judge nothing about whether
            // they are reading a translation.
            'language'          => mb_substr(
                trim((string) ($meta['language'] ?? '')) !== ''
                    ? trim((string) $meta['language'])
                    : ((string) ($iv->language ?? '') !== '' ? (string) $iv->language : 'en'), 0, 12),
            'translated_from'   => ($tf = trim((string) ($meta['translated_from'] ?? ''))) !== ''
                                    ? mb_substr($tf, 0, 12) : null,
            'transcript'        => $transcript,
            'transcript_source' => $source,
            'transcriber'       => mb_substr(
                trim((string) ($meta['transcriber'] ?? '')) !== ''
                    ? trim((string) $meta['transcriber'])
                    : ($source === 'machine' ? 'Google Meet transcription' : ''), 0, 160),
            'source_ref'        => mb_substr((string) ($iv->meet_url ?? ''), 0, 400) ?: null,
            'consent_given'     => 1,
            'consent_note'      => 'Given by ' . (string) ($iv->consent_name ?? 'the nominee')
                                 . ' on their interview page at ' . (string) $iv->consent_at,
            'status'            => 'published',
            'created_by'        => $adminId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        try {
            // Republishing replaces rather than duplicates: a corrected transcript must not
            // leave the panel reading two versions of the same conversation.
            if (!empty($iv->transcript_id)
                && DB::table('gates_nominee_interviews')->where('id', (int) $iv->transcript_id)->exists()) {
                DB::table('gates_nominee_interviews')->where('id', (int) $iv->transcript_id)->update($row);
                $tid = (int) $iv->transcript_id;
            } else {
                $tid = (int) DB::table('gates_nominee_interviews')->insertGetId($row);
            }
        } catch (\Throwable $e) {
            error_log('[interview] could not publish transcript for ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The transcript could not be saved just now.'];
        }

        DB::table('gates_interviews')->where('id', $id)->update([
            'transcript_id' => $tid,
            'status'        => in_array($iv->status, ['done', 'no_show', 'cancelled'], true)
                                ? $iv->status : 'done',
            'ended_at'      => (string) ($iv->ended_at ?? $now),
            'updated_at'    => $now,
        ]);

        // The figure check and the coverage check need no network, so they run NOW and the
        // operator sees them on the page they land on. The model's reading of the whole
        // transcript is the slow half and goes on the queue — and overwrites this when it
        // arrives. A platform whose queue is not draining still gets the useful part.
        InterviewReview::quick($id);
        InterviewReview::queue($id);

        return ['ok' => true, 'transcript_id' => $tid,
                'message' => 'Published. The panel can now read this in the nominee\'s dossier.'];
    }

    /** Take a published transcript back out of the dossier (a nominee may retract consent). */
    public static function withdraw(int $id, string $why = ''): array
    {
        $iv = self::byId($id);
        if (!$iv || empty($iv->transcript_id)) {
            return ['ok' => false, 'message' => 'Nothing is published for that interview.'];
        }
        DB::table('gates_nominee_interviews')->where('id', (int) $iv->transcript_id)->update([
            'status'       => 'withdrawn',
            'consent_note' => mb_substr('Withdrawn: ' . ($why !== '' ? $why : 'no reason recorded'), 0, 400),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'message' => 'Withdrawn. Judges can no longer see it; the record survives.'];
    }

    // ══ 6. reading ═══════════════════════════════════════════════════════════

    /** One sitting with the nominee's name and category resolved, for a screen. */
    public static function detail(int $id): ?array
    {
        $iv = self::byId($id);
        if (!$iv) return null;
        $n = DB::table('gates_nominees as n')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', (int) $iv->nominee_id)
            ->select('n.name', 'n.organisation', 'n.country_code', 'n.status', 'c.title as category')
            ->first();

        $answers = json_decode((string) ($iv->answers_json ?? '[]'), true);
        return [
            'row'        => (array) $iv,
            'nominee'    => (string) ($n->name ?? 'Unknown'),
            'category'   => (string) ($n->category ?? ''),
            'org'        => (string) ($n->organisation ?? ''),
            'country'    => strtoupper((string) ($n->country_code ?? '')),
            'when_text'  => self::whenText($iv),
            'link'       => self::url($id),
            'panel'      => self::panel($id),
            // Everybody in the meeting who is NOT on the judging panel — an interpreter, a
            // note-taker, a programme officer, the nominee's support person.
            'guests'     => self::guests($iv),
            'guests_can_edit' => (int) ($iv->guests_can_edit ?? 0) === 1,
            'brief'      => InterviewBrief::forInterview($id),
            'answers'    => is_array($answers) ? $answers : [],
            'review'     => InterviewReview::forInterview($id),
            'transcript' => self::transcriptOf($id),
            'consented'  => !empty($iv->consent_at),
            'hours_away' => self::hoursAway($iv),
            // The live-capture half: the extension's key, and whether captions are actually
            // arriving. `live` matters because "running" and "silently broken by a Google
            // markup change" are indistinguishable from here without it.
            'live'       => InterviewLive::status($id),
            'live_key'   => InterviewLive::tokenFor($id),
            'live_text'  => InterviewLive::assemble($id),
            // The recording bot's half. `voice_effective` is deliberately not the same
            // field as the row's `voice_mode`: the platform cap and a missing provider key
            // both lower it, and a console that printed the REQUESTED mode would tell an
            // operator the bot will speak when it will not.
            'bot' => [
                'available'       => AttendeeBot::configured() && InterviewBot::enabled(),
                'self_hosted'     => AttendeeBot::selfHosted(),
                'state'           => (string) ($iv->bot_state ?? ''),
                'error'           => (string) ($iv->bot_error ?? ''),
                'joined_at'       => (string) ($iv->bot_joined_at ?? ''),
                // Whether there IS a recording, not a link to it. Attendee's download
                // URL expires in thirty minutes, so it is minted on demand behind
                // /admin/interviews/{id}/bot/recording — see InterviewBot::collectRecording().
                'has_recording'   => trim((string) ($iv->bot_recording_at ?? '')) !== '',
                'recording_at'    => (string) ($iv->bot_recording_at ?? ''),
                'voice_mode'      => (string) ($iv->voice_mode ?? 'off'),
                'voice_effective' => InterviewVoice::mode($iv),
                'voice_ready'     => InterviewVoice::configured(),
                'platform_max'    => InterviewVoice::platformMax(),
                'engine'          => InterviewVoice::engine(),
                'opening'         => InterviewBot::opening($iv),
                // What the guard stopped the bot saying in this sitting. On the screen
                // rather than only in a table, because "the AI is safe, trust us" is not
                // a claim a panel can check and a list of refused sentences is.
                'refused'         => InterviewGuard::forInterview($id, 5),
            ],
        ];
    }

    /**
     * The list an operator works from: soonest first among the sittings still expected,
     * then the rest by recency.
     *
     * @return list<array<string,mixed>>
     */
    public static function queue(int $limit = 60): array
    {
        $rows = DB::table('gates_interviews as i')
            ->leftJoin('gates_nominees as n', 'n.id', '=', 'i.nominee_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'i.category_id')
            ->orderByDesc('i.id')->limit($limit)
            ->select('i.*', 'n.name as nominee', 'c.title as category')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int) $r->id,
                'nominee_id' => (int) $r->nominee_id,
                'nominee'    => (string) ($r->nominee ?? 'Unknown'),
                'category'   => (string) ($r->category ?? ''),
                'status'     => (string) $r->status,
                'when'       => (string) ($r->scheduled_at ?? ''),
                'when_text'  => self::whenText($r),
                'hours_away' => self::hoursAway($r),
                'pending'    => in_array((string) $r->status, self::PENDING, true),
                'consented'  => !empty($r->consent_at),
                'has_meet'   => trim((string) ($r->meet_url ?? '')) !== '',
                'has_brief'  => trim((string) ($r->brief_json ?? '')) !== '',
                'published'  => !empty($r->transcript_id),
                'answers'    => count((array) json_decode((string) ($r->answers_json ?? '[]'), true)),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['pending'] !== $b['pending']) return $a['pending'] ? -1 : 1;
            if ($a['pending']) {
                // Soonest first; unscheduled last among the pending.
                $x = $a['when'] === '' ? PHP_INT_MAX : (int) strtotime($a['when']);
                $y = $b['when'] === '' ? PHP_INT_MAX : (int) strtotime($b['when']);
                return $x <=> $y;
            }
            return $b['id'] <=> $a['id'];
        });
        return $out;
    }

    /** Counts for a heading: what is waiting, what is missing, what has landed. */
    public static function summary(): array
    {
        $rows = DB::table('gates_interviews')
            ->select('status', 'consent_at', 'transcript_id', 'meet_url', 'scheduled_at')->get();
        $now = Carbon::now()->getTimestamp();
        $s = ['total' => 0, 'pending' => 0, 'today' => 0, 'no_meet' => 0,
              'no_consent' => 0, 'published' => 0, 'overdue' => 0];
        foreach ($rows as $r) {
            $s['total']++;
            $pending = in_array((string) $r->status, self::PENDING, true);
            if ($pending) {
                $s['pending']++;
                if (trim((string) ($r->meet_url ?? '')) === '')  $s['no_meet']++;
                if (empty($r->consent_at))                        $s['no_consent']++;
                $ts = $r->scheduled_at ? strtotime((string) $r->scheduled_at) : false;
                if ($ts && $ts < $now)                            $s['overdue']++;
                if ($ts && $ts >= $now && $ts - $now < 86400)     $s['today']++;
            }
            if (!empty($r->transcript_id)) $s['published']++;
        }
        return $s;
    }

    /** Sittings held but never published — the pile where interviews go to be forgotten. */
    public static function unpublished(): array
    {
        return DB::table('gates_interviews as i')
            ->leftJoin('gates_nominees as n', 'n.id', '=', 'i.nominee_id')
            ->where('i.status', 'done')->whereNull('i.transcript_id')
            ->orderBy('i.ended_at')
            ->select('i.id', 'i.ended_at', 'i.consent_at', 'n.name as nominee')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    // ══ 7. plumbing ══════════════════════════════════════════════════════════

    public static function byId(int $id): ?object
    {
        try { return DB::table('gates_interviews')->where('id', $id)->first() ?: null; }
        catch (\Throwable) { return null; }
    }

    public static function byToken(string $token): ?object
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        try { return DB::table('gates_interviews')->where('invite_token', $token)->first() ?: null; }
        catch (\Throwable) { return null; }
    }

    public static function tokenFor(int $id): ?string
    {
        $t = DB::table('gates_interviews')->where('id', $id)->value('invite_token');
        return $t ? (string) $t : null;
    }

    /** The nominee's page. Absolute, because it goes in an email. */
    public static function url(int $id, string $base = ''): string
    {
        $base = rtrim($base !== '' ? $base : SiteUrl::base(), '/');
        $t = self::tokenFor($id);
        return $t ? $base . '/interview/' . $t : $base . '/interview';
    }

    public static function mintToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Judges on this sitting's panel, with contact details for the invitation. */
    public static function panel(int $id): array
    {
        $iv = self::byId($id);
        $ids = $iv ? (json_decode((string) ($iv->panel_json ?? '[]'), true) ?: []) : [];
        if (!$ids) return [];
        try {
            return DB::table('gates_judges')->whereIn('id', array_map('intval', $ids))
                ->orderBy('name')->get()
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name,
                                  'email' => (string) $r->email, 'title' => (string) ($r->title ?? '')])
                ->all();
        } catch (\Throwable) { return []; }
    }

    /** Replace the panel on a sitting. */
    public static function setPanel(int $id, array $judgeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $judgeIds))));
        DB::table('gates_interviews')->where('id', $id)->update([
            'panel_json' => $ids ? json_encode($ids) : null,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'count' => count($ids), 'message' => count($ids) . ' judge(s) on the panel.'];
    }

    private static function panelNames(int $id): string
    {
        $names = array_column(self::panel($id), 'name');
        return $names ? implode(', ', $names) : 'Africa GATES panel';
    }

    /** The published transcript text for a sitting, or ''. */
    public static function transcriptOf(int $id): string
    {
        $iv = self::byId($id);
        if (!$iv || empty($iv->transcript_id)) return '';
        $t = DB::table('gates_nominee_interviews')->where('id', (int) $iv->transcript_id)->first();
        return $t ? (string) ($t->transcript ?? '') : '';
    }

    /** "Sat 15 Aug 2026, 14:30 (Africa/Lagos)" — never a naked timestamp. */
    public static function whenText(object $iv): string
    {
        $when = (string) ($iv->scheduled_at ?? '');
        if ($when === '') return 'not scheduled yet';
        $tz = (string) ($iv->timezone ?? 'Africa/Lagos');
        try {
            return Carbon::parse($when, 'UTC')->setTimezone($tz)->format('D j M Y, H:i') . ' (' . $tz . ')';
        } catch (\Throwable) {
            return $when . ' (' . $tz . ')';
        }
    }

    /** Whole hours until the sitting; negative once it has passed; null if unscheduled. */
    public static function hoursAway(object $iv): ?int
    {
        $when = (string) ($iv->scheduled_at ?? '');
        if ($when === '') return null;
        $ts = strtotime($when);
        if (!$ts) return null;
        return (int) floor(($ts - Carbon::now()->getTimestamp()) / 3600);
    }

    /**
     * Times are STORED as UTC and displayed in the sitting's zone.
     *
     * An operator in Lagos types "2026-08-15 14:30" meaning half two their afternoon, so a
     * bare timestamp is read in the sitting's timezone and converted. Storing what they
     * typed and hoping every reader shares their clock is how a judge in London joins an
     * empty room an hour early.
     *
     * ── WHY AN EXPLICIT OFFSET IS HONOURED ───────────────────────────────────
     *
     * Because this function is not idempotent and both of its callers used to run it twice.
     * The controller converted the operator's local time to UTC and handed the result to
     * create(), which converted it AGAIN — so every sitting was stored an hour early in
     * Lagos, and would have been an hour early on the invitation, the reminder, the
     * calendar event and the nominee's page. Consistently wrong is the worst kind: nothing
     * looks broken and everybody arrives at the wrong time.
     *
     * A string carrying `Z` or `+01:00` already knows what instant it is, so it is taken as
     * absolute. A `datetime-local` input never sends one, which makes the marker a clean
     * signal rather than a guess — and it lets an already-converted value pass through
     * unharmed.
     */
    public static function normaliseWhen(string $when, string $timezone = ''): ?string
    {
        $when = trim($when);
        if ($when === '') return null;

        $absolute = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $when);
        $when = str_replace('T', ' ', $when);
        try {
            return $absolute
                ? Carbon::parse($when)->setTimezone('UTC')->toDateTimeString()
                : Carbon::parse($when, self::normaliseZone($timezone))
                    ->setTimezone('UTC')->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normaliseZone(string $tz): string
    {
        $tz = trim($tz);
        if ($tz === '') return (string) Env::get('APP_TIMEZONE', 'Africa/Lagos');
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Lagos';
    }

    private static function cycleFor(int $categoryId): ?int
    {
        if ($categoryId <= 0) return null;
        $c = DB::table('gates_award_categories')->where('id', $categoryId)->value('cycle_id');
        return $c ? (int) $c : null;
    }

    private static function nomineeInviteBody(string $nominee, string $when, string $link, object $iv): string
    {
        $meet = trim((string) ($iv->meet_url ?? ''));
        return "Dear " . $nominee . ",\n\n"
             . "You have been nominated for an Africa GATES award, and the panel would like to "
             . "speak with you. This is a conversation, not a test — it exists so the judges hear "
             . "about your work from you rather than only from the person who nominated you.\n\n"
             . 'When:  ' . $when . "\n"
             . 'Long:  about ' . (int) $iv->duration_mins . " minutes\n"
             . 'Where: Google Meet' . ($meet !== '' ? ' — ' . $meet : ' (link on the page below)') . "\n\n"
             . "PLEASE OPEN THIS PAGE: " . $link . "\n\n"
             . "On it you can confirm you are coming, see what the conversation will cover, and give "
             . "or refuse permission for it to be recorded and written down. That permission matters: "
             . "without it the panel cannot be shown a transcript, and your answers reach them only "
             . "as the notes a judge takes by hand. Refusing is allowed and costs you nothing in the "
             . "judging.\n\n"
             // ── WHO ELSE WILL BE IN THE CALL ─────────────────────────────────
             //
             // Said only when it is true — the bot attends only where it is switched on
             // AND configured, and a sentence about a participant who never arrives is
             // its own small dishonesty.
             //
             // Separate from the recording permission above because it is a different
             // thing to agree to: consenting to be recorded by the person you are talking
             // to is not consenting to a stranger appearing in the call. Somebody who
             // opens a Meet link and finds an unnamed participant already there has been
             // surprised in a conversation about their own work.
             . self::botDisclosure()
             . "If the time does not work, say so on that page and we will find another. Nothing "
             . "about this stage costs money, and nobody will ever ask you to pay for an interview, "
             . "a nomination or a result.\n";
    }

    /**
     * The sentence naming the recording bot, or ''.
     *
     * ── THE MECHANISM THE INDEX ALREADY DESCRIBED ────────────────────────────
     *
     * `docs/CODEBASE-INDEX.md` §on the interview bot says the disclosure is "stamped by
     * the invitation rather than by an admin ticking a box, because a bot in the room is a
     * materially different thing to consent to than a human taking notes."
     *
     * It was not. `bot_disclosed_at` existed in the schema and nothing in the codebase
     * wrote it, read it or rendered it — the column, the paragraph explaining it, and no
     * code between them. §18's fault exactly: every part complete in isolation.
     *
     * The invitation did say the sitting may be recorded, which is the consent that gates
     * capture. What it never said is that a participant would join to do the recording.
     */
    private static function botDisclosure(): string
    {
        if (!\AfricaGates\Services\InterviewBot::enabled()
            || !\AfricaGates\Services\AttendeeBot::configured()) {
            return '';
        }

        $name = trim(\AfricaGates\Services\AttendeeBot::botName());

        return 'A note about the call itself: a recording assistant'
             . ($name !== '' ? ' called "' . $name . '"' : '')
             . " will join and appear in the participant list. It is there only to record "
             . "and write down the conversation, it does not take part, and it records "
             . "nothing at all unless you have given the permission above. If you would "
             . "rather it did not join, say so on that page.\n\n";
    }

    private static function panelInviteBody(string $judge, string $nominee, string $when, object $iv): string
    {
        $meet = trim((string) ($iv->meet_url ?? ''));
        return 'Dear ' . ($judge !== '' ? $judge : 'Judge') . ",\n\n"
             . 'You are on the panel for an interview with ' . $nominee . ".\n\n"
             . 'When:  ' . $when . "\n"
             . 'Where: Google Meet' . ($meet !== '' ? ' — ' . $meet : ' (link to follow)') . "\n\n"
             . "The interview console — the question pack, what each question is testing, and the "
             . "place to capture answers as you go — is here:\n"
             . rtrim(SiteUrl::base(), '/') . '/admin/interviews/' . (int) $iv->id . "/run\n\n"
             . "The questions are built from this nominee's own dossier and are mapped to the "
             . "scoring criteria, so you can see which part of the rubric each one is for. Nothing "
             . "you capture there writes a score: scoring stays on your ballot, where it belongs.\n\n"
             . "Attached is every sitting you are on, as a spreadsheet.\n";
    }

    /**
     * The same invitation, as the branded HTML every other message on this platform uses.
     *
     * ── WHY THIS WAS WORTH DOING ─────────────────────────────────────────────
     *
     * The panel invitation went out through `sendCustom()` — plain text, no wrapper, no
     * mark. Every other message this platform sends is branded, so the one that lands in a
     * judge's inbox asking them to join a video call with a stranger was the one that
     * looked least like it came from us. That is the exact shape of a message people do
     * not click, and the thing they are being asked to click is a meeting link.
     *
     * The two bodies are built side by side rather than one derived from the other, because
     * a plain-text part made by stripping tags reads like stripped HTML, and it is what a
     * judge on a text-only client actually gets.
     */
    private static function panelInviteHtml(string $judge, string $nominee, string $when, object $iv): string
    {
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $meet = trim((string) ($iv->meet_url ?? ''));
        $run  = rtrim(SiteUrl::base(), '/') . '/admin/interviews/' . (int) $iv->id . '/run';

        $html = '<p>Dear <strong>' . $e($judge !== '' ? $judge : 'Judge') . '</strong>,</p>'
              . '<p style="font-size:17px;font-weight:700;color:#10292C">You are on the panel for an '
              . 'interview with ' . $e($nominee) . '.</p>'
              . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0;'
              . 'background:#f6f2e6;border-left:4px solid #c9a24b;border-radius:0 8px 8px 0;padding:14px 18px">'
              . '<tr><td style="font-size:14px;color:#5b4a1f;line-height:1.8">'
              . 'When: <strong>' . $e($when) . '</strong><br>Where: <strong>Google Meet</strong>'
              . ($meet === '' ? ' — link to follow' : '')
              . '</td></tr></table>';

        // The meeting link is the whole point of the message, so it is a button and it is
        // first. The console is the second action and reads as one.
        if ($meet !== '') {
            $html .= '<p style="text-align:center;margin:22px 0"><a href="' . $e($meet)
                   . '" style="display:inline-block;padding:13px 30px;background:#12481d;color:#fff;'
                   . 'border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">'
                   . 'Join the interview &rarr;</a></p>';
        }

        $html .= '<p style="font-size:15px;line-height:1.7">The interview console — the question pack, '
               . 'what each question is testing, and the place to capture answers as you go — is '
               . '<a href="' . $e($run) . '" style="color:#237b22">here</a>.</p>'
               . '<p style="font-size:14px;line-height:1.7;color:#5a6d6f">The questions are built from this '
               . "nominee's own dossier and are mapped to the scoring criteria, so you can see which part "
               . 'of the rubric each one is for. <strong>Nothing you capture there writes a score</strong> — '
               . 'scoring stays on your ballot, where it belongs.</p>'
               . '<p style="font-size:13.5px;line-height:1.7;color:#5a6d6f">Attached is every sitting you '
               . 'are on, as a spreadsheet — open it to see the whole run in one place.</p>';

        return $html;
    }

    /**
     * Every upcoming sitting a judge is on, as a spreadsheet.
     *
     * ── WHY A FILE AND NOT A LIST IN THE MESSAGE ─────────────────────────────
     *
     * A judge sitting six interviews gets six emails, each true about one of them and
     * silent about the other five. The question they actually have — "what am I doing next
     * week" — is answered by none of them, and the answer lives across an inbox.
     *
     * The attachment is the whole run: dates, nominee, category, the meeting link and the
     * console link, one row each. It opens in a spreadsheet, it can be printed, and it can
     * be read on a phone with no signal, which is where a judge often is the morning of.
     *
     * The panel is stored as a JSON array on each sitting, so this filters in PHP rather
     * than in SQL — a LIKE against a JSON column would match judge 1 inside judge 13.
     */
    /**
     * What a judge's invitation carries: their schedule to read, and their schedule to keep.
     *
     * Best-effort in both halves and independently — a calendar file that could not be
     * built must not cost the spreadsheet, and neither must stop the invitation. The
     * message is still true about the sitting it is announcing.
     *
     * @return list<array{name:string, mime:string, body:string}>
     */
    public static function panelAttachments(int $judgeId, string $judgeName = ''): array
    {
        $out = [];

        try {
            $csv = self::scheduleCsv($judgeId);
            if ($csv !== '') {
                $out[] = ['name' => 'africa-gates-interviews.csv', 'mime' => 'text/csv', 'body' => $csv];
            }
        } catch (\Throwable $e) {
            error_log('[interview] schedule csv for judge ' . $judgeId . ': ' . $e->getMessage());
        }

        try {
            $sittings = JudgeSchedule::forJudge($judgeId);
            if ($sittings !== []) {
                $ics = JudgeSchedule::icsFor($sittings, $judgeName);
                if (trim($ics) !== '') {
                    $out[] = ['name' => Ics::filename('africa-gates-judging'),
                              'mime' => Ics::MIME, 'body' => $ics];
                }
            }
        } catch (\Throwable $e) {
            error_log('[interview] schedule ics for judge ' . $judgeId . ': ' . $e->getMessage());
        }

        return $out;
    }

    public static function scheduleCsv(int $judgeId): string
    {
        $rows = [];
        try {
            $sittings = DB::table('gates_interviews as iv')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'iv.nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereNotIn('iv.status', ['cancelled', 'done'])
                ->orderBy('iv.scheduled_at')
                ->limit(200)
                ->get(['iv.id', 'iv.scheduled_at', 'iv.timezone', 'iv.meet_url', 'iv.status',
                       'iv.panel_json', 'n.name as nominee', 'c.title as category']);
        } catch (\Throwable $e) {
            error_log('[interview] schedule csv for judge ' . $judgeId . ': ' . $e->getMessage());
            return '';
        }

        $base = rtrim(SiteUrl::base(), '/');
        foreach ($sittings as $iv) {
            $panel = json_decode((string) ($iv->panel_json ?? '[]'), true) ?: [];
            if (!in_array($judgeId, array_map('intval', (array) $panel), true)) continue;

            $rows[] = [
                (string) ($iv->scheduled_at ?? ''),
                (string) ($iv->timezone ?? ''),
                (string) ($iv->nominee ?? ''),
                (string) ($iv->category ?? ''),
                (string) ($iv->status ?? ''),
                (string) ($iv->meet_url ?? ''),
                $base . '/admin/interviews/' . (int) $iv->id . '/run',
            ];
        }

        $out = fopen('php://temp', 'r+');
        // A BOM, because the commonest reader of this file is Excel on Windows and without
        // one it renders a nominee's name in the wrong encoding — which is a worse first
        // impression than no attachment at all.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['When', 'Timezone', 'Nominee', 'Category', 'Status', 'Meeting link', 'Console'], ',', '"', '');
        foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
    // ═══════════════════════════════════════════════════════════════════════
    // GUESTS WHO ARE NOT ON THE PANEL
    // ═══════════════════════════════════════════════════════════════════════

    /** No more than this many, because an attendee list is not a mailing list. */
    public const MAX_GUESTS = 12;

    /**
     * The extra addresses invited to a sitting.
     *
     * ── WHY THIS EXISTS SEPARATELY FROM THE PANEL ───────────────────────────
     *
     * The Google event's attendee list was built from one source — the judges assigned to
     * the sitting — so the ONLY way to get somebody into the meeting was to appoint them to
     * the judging panel. That is an integrity decision with a published consequence (they
     * appear on "Meet the Judges", and their scores count toward a result), and a wildly
     * disproportionate thing to do so that an interpreter can join a call.
     *
     * @return list<string>
     */
    public static function guests(object|int|null $iv): array
    {
        if (is_int($iv)) $iv = self::byId($iv);
        if (!$iv) return [];

        $raw = json_decode((string) ($iv->guests_json ?? '[]'), true);

        return is_array($raw)
            ? array_values(array_filter(array_map(
                static fn ($e): string => strtolower(trim((string) $e)),
                $raw
              ), static fn (string $e): bool => $e !== ''))
            : [];
    }

    /**
     * Replace the guest list from whatever the operator typed.
     *
     * Accepts commas, semicolons, newlines and spaces, because a list of addresses arrives
     * pasted out of an email far more often than it is typed one at a time — and a field
     * that only accepts one separator silently keeps the first address and drops the rest.
     *
     * Every REJECTED address is named in the result rather than quietly dropped. Somebody
     * who mistypes one and is told "saved" will discover it when a person does not turn up
     * to the interview, which is the one moment nobody can fix it.
     *
     * @return array{ok:bool, saved:int, guests:list<string>, rejected:list<string>, message:string}
     */
    public static function setGuests(int $id, string $raw, bool $canEdit = false): array
    {
        $iv = self::byId($id);
        if (!$iv) {
            return ['ok' => false, 'saved' => 0, 'guests' => [], 'rejected' => [],
                    'message' => 'That interview could not be found.'];
        }

        $parts = preg_split('~[\s,;]+~', trim($raw)) ?: [];

        $good = $bad = [];
        foreach ($parts as $p) {
            $e = strtolower(trim((string) $p, " \t\n\r\0\x0B<>\"'"));
            if ($e === '') continue;
            if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) { $bad[] = $e; continue; }
            if (!in_array($e, $good, true)) $good[] = $e;
        }

        if (count($good) > self::MAX_GUESTS) {
            return ['ok' => false, 'saved' => 0, 'guests' => self::guests($iv), 'rejected' => [],
                    'message' => 'That is more than ' . self::MAX_GUESTS . ' guests. An interview '
                               . 'with a room that size is a webinar — invite the people who need '
                               . 'to speak, and send everybody else the transcript.'];
        }

        try {
            DB::table('gates_interviews')->where('id', $id)->update([
                'guests_json'     => $good === [] ? null : json_encode($good),
                'guests_can_edit' => $canEdit ? 1 : 0,
                'updated_at'      => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[interview] guests for ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'saved' => 0, 'guests' => self::guests($iv), 'rejected' => [],
                    'message' => 'The guest list could not be saved just now.'];
        }

        // Saved is not invited. The calendar event is only touched when somebody presses
        // the button that touches it, and saying otherwise here is how a guest ends up
        // recorded on our side and absent from the meeting.
        $msg = $good === []
            ? 'Guest list cleared.'
            : count($good) . ' guest' . (count($good) === 1 ? '' : 's') . ' saved. '
              . 'Create or update the meeting to send the invitation.';

        if ($bad !== []) {
            $msg .= ' NOT saved, because they are not valid addresses: ' . implode(', ', $bad) . '.';
        }

        return ['ok' => true, 'saved' => count($good), 'guests' => $good,
                'rejected' => $bad, 'message' => $msg];
    }
    // ═══════════════════════════════════════════════════════════════════════
    // THE CALENDAR IS THE TRUTH
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Pull this sitting's appointment back out of Google, and correct our copy of it.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THE BOT NEEDED THIS BEFORE IT COULD BE TRUSTED
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The recording bot is dispatched off `scheduled_at` and `meet_url` in OUR table. Both
     * are copies of something that lives in a calendar, and an organiser who moves the
     * meeting does it there — that is the whole point of putting it in a calendar.
     *
     * Nothing told us. So the bot turned up at the old time to an empty room, the interview
     * happened an hour later with nobody recording it, and the only symptom was a missing
     * transcript afterwards, at exactly the moment nobody can do anything about it.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * AND WHY IT ONLY EVER MOVES ONE WAY
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This never writes to Google. It reads, and it corrects us. A reconciler that also
     * pushed would fight with the operator: they drag the meeting, we push it back, and
     * neither side can tell which of them last won.
     *
     * Rescheduling from here is deliberately NOT routed through {@see reschedule()}, which
     * clears the nominee's confirmation and re-queues the reminders. Those are the right
     * consequences of US moving an appointment and the wrong ones for noticing that it has
     * already moved: the organiser dragging it in Google Calendar has already sent everyone
     * the update, and clearing the confirmation would make a nominee who said yes read as
     * though they had not.
     *
     * @return array{ok:bool, changed:bool, gone:bool, message:string}
     */
    public static function reconcileFromCalendar(int $id): array
    {
        $iv = self::byId($id);
        if (!$iv) return ['ok' => false, 'changed' => false, 'gone' => false,
                          'message' => 'That interview could not be found.'];

        $eventId = trim((string) ($iv->calendar_event_id ?? ''));
        if ($eventId === '') {
            // Nothing was ever booked through us, so there is nothing to reconcile
            // against. Not an error — most sittings in a fresh installation are here.
            return ['ok' => true, 'changed' => false, 'gone' => false,
                    'message' => 'This sitting has no calendar event.'];
        }

        $svc = GoogleMeetService::boot();
        if (!$svc->canSchedule()) {
            return ['ok' => false, 'changed' => false, 'gone' => false, 'message' => $svc->why()];
        }

        $r = $svc->readEvent(['event_id' => $eventId]);
        if (!($r['ok'] ?? false)) {
            return ['ok' => false, 'changed' => false, 'gone' => false,
                    'message' => (string) $r['message']];
        }

        // ── THE EVENT IS GONE ───────────────────────────────────────────────
        //
        // Deleted or cancelled in Google. Recorded, and the sitting is NOT auto-cancelled:
        // an operator who deleted the wrong row, or a calendar that briefly lied, must not
        // be able to cancel an interview a nominee has already confirmed. Clearing the
        // link is enough — the bot will not be sent to a room that no longer exists, and
        // the screen says why.
        if (empty($r['found'])) {
            try {
                DB::table('gates_interviews')->where('id', $id)->update([
                    'meet_url'   => null,
                    'meet_code'  => null,
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable) {
            }
            return ['ok' => true, 'changed' => true, 'gone' => true,
                    'message' => 'The calendar event for this sitting has been deleted, so the '
                               . 'meeting link has been cleared. Create the meeting again, or '
                               . 'cancel the interview if it is not happening.'];
        }

        $patch = [];
        $said  = [];

        $start = trim((string) ($r['start'] ?? ''));
        if ($start !== '' && $start !== trim((string) ($iv->scheduled_at ?? ''))) {
            $patch['scheduled_at'] = $start;
            $said[] = 'moved to ' . $start . ' UTC';
        }

        $url = trim((string) ($r['meet_url'] ?? ''));
        if ($url !== '' && $url !== trim((string) ($iv->meet_url ?? ''))) {
            $patch['meet_url']  = mb_substr($url, 0, 400);
            $patch['meet_code'] = self::meetCode($url);
            $said[] = 'a new meeting link';
        }

        if ($patch === []) {
            return ['ok' => true, 'changed' => false, 'gone' => false,
                    'message' => 'The calendar agrees with this sitting.'];
        }

        try {
            $patch['updated_at'] = Carbon::now()->toDateTimeString();
            DB::table('gates_interviews')->where('id', $id)->update($patch);
        } catch (\Throwable $e) {
            error_log('[interview] reconcile ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'changed' => false, 'gone' => false,
                    'message' => 'The calendar could not be read into this sitting just now.'];
        }

        return ['ok' => true, 'changed' => true, 'gone' => false,
                'message' => 'Updated from the calendar: ' . implode(' and ', $said) . '.'];
    }
}
