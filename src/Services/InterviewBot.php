<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The bot's life inside a sitting: sent, watched, listened to, and eventually asked to leave.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHERE THIS SITS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see AttendeeBot} is transport and has no opinions. {@see InterviewVoice} owns the
 * microphone and the rules about using it. This file owns the SITTING — whether a bot
 * should be there at all, what it does with what it hears, and when it goes home.
 *
 * It is also the only file in the three that touches the cron, which is the constraint
 * that shapes everything below.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE SWEEP IS THE PRIMARY PATH AND THE WEBHOOK IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Attendee will POST state changes and transcript updates to a callback URL. This
 * deployment cannot depend on that. It is cPanel: the operator has changed hostname
 * once already (see the deploy note in `public/index.php`), TLS is somebody else's
 * renewal, and a webhook that silently stops delivering looks exactly like an interview
 * where nobody spoke.
 *
 * So {@see sweep()} polls, on the tick that already runs, and every piece of state can be
 * recovered by asking. {@see ingestFor()} is idempotent against the cursor, so a webhook
 * arriving as well as a poll produces one transcript and not two. The webhook is a
 * latency optimisation for 'auto' mode and nothing depends on it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT 'auto' MODE HONESTLY IS ON THIS HOST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Worth stating plainly, because the word suggests something this does not do.
 *
 * A conversational voice agent — the kind that interrupts and back-channels — holds a
 * duplex audio stream and answers in a few hundred milliseconds. This host cannot be in
 * that audio path: it is PHP-FPM on cPanel and it will never hold a WebRTC session.
 *
 * An earlier version of this docblock concluded from that that real-time interviewing is
 * therefore impossible here. THAT WAS WRONG, and it was wrong in the direction that
 * closes off a design rather than the one that oversells it — so it is corrected here
 * rather than quietly dropped.
 *
 * Attendee's `voice_agent_settings.url` takes a URL to a page running a voice agent,
 * loads it IN AN ATTENDEE-MANAGED CONTAINER, streams that page's audio into the meeting
 * and feeds meeting audio back in as its microphone. Its own documentation is explicit —
 * "No backend worker required" — and `url_is_allowed_for_voice_agent()` in
 * `bots/serializers.py` confirms the API accepts it. So a static page running a realtime
 * model in the browser would give genuine sub-second conversation, and this host would
 * never carry a single audio packet. The constraint is real; the conclusion drawn from
 * it was not.
 *
 * WHY IT IS STILL NOT SWITCHED ON, WHICH IS A DIFFERENT ARGUMENT:
 *
 * That path bypasses {@see InterviewGuard} completely. The guard sits in the PHP path
 * between {@see AiGateway} and TTS; an agent speaking from its own browser page never
 * passes through it. Taking it would trade every grounding, verdict, promise and
 * protected-characteristic check for latency, in the feature that decides 55% of a
 * nominee's score. That is a blocker, not a footnote. If real-time is ever wanted, the
 * design question is how the guard survives it — most likely by reimplementing the
 * checks inside the agent page and having it POST every utterance back to
 * `gates_interview_guard_log`, so refusals stay auditable in one place.
 *
 * The turn-based path below stays regardless: it is the right default for a final panel
 * even on a host that could do better.
 *
 * What 'auto' does here is turn-based. The nominee finishes a thought; the transcript
 * reaches us (webhook in a second or two, or the next cron tick otherwise); a model
 * writes one question; OpenAI synthesises it; Attendee plays it. End to end that is a
 * few seconds on the webhook path and up to a minute on the cron path. It is a competent
 * structured interviewer and it is not a conversationalist, and a panel choosing 'auto'
 * for a final sitting should know which of those they are getting. {@see turn()} enforces
 * the pacing that makes the slow version bearable rather than pretending it is fast.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CONSENT DECIDES WHETHER A BOT IS SENT AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not just whether words are kept. {@see dispatch()} refuses without `consent_at`, which
 * means a nominee who consents late — on the call, from their own link, which is the flow
 * {@see InterviewLive::mayCapture()} describes — gets a bot on the next tick rather than
 * at the start. That is a worse experience than sending a bot that sits silently until
 * permission arrives, and it is the right trade: a recording process in the room before
 * anybody agreed to it is the thing that must not happen, and "it was not recording yet"
 * is not a distinction a nominee can verify.
 */
final class InterviewBot
{
    /** Queue job: ingest and take a turn, off the request that received a webhook. */
    public const JOB_TICK = 'interview.bot_tick';

    /**
     * How long before the scheduled start the bot is sent.
     *
     * Attendee holds it and joins at `join_at`, so this is only how early the REQUEST is
     * made. Wide enough that a cron tick every five minutes cannot miss the window.
     */
    public const DISPATCH_LEAD_MINUTES = 12;

    /**
     * After this long past the scheduled end, a bot still in the call is pulled out.
     *
     * Interviews overrun; a bot that leaves on the half-hour truncates the answer that
     * was still being given. A bot that never leaves bills for a container all night.
     */
    public const OVERRUN_GRACE_MINUTES = 25;

    /** Words the nominee must have said before 'auto' will move to the next question. */
    private const ADVANCE_MIN_WORDS = 25;

    /** Seconds of transcript silence that count as "they have finished answering". */
    private const ADVANCE_SILENCE_SECONDS = 12;

    // ── sending and removing ─────────────────────────────────────────────────

    /**
     * Put a bot into a sitting.
     *
     * Idempotent: a sitting that already has a live bot returns the one it has rather
     * than sending a second. Two bots in one room is not a cosmetic problem — both
     * transcribe, both are billed, and the buffer interleaves two copies of every line.
     *
     * @return array{ok:bool, bot_id:string, message:string}
     */
    public static function dispatch(int $id, bool $force = false): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'bot_id' => '', 'message' => 'No such interview.'];

        if (!AttendeeBot::configured()) {
            return ['ok' => false, 'bot_id' => '', 'message' =>
                'No recording bot is configured. Set ATTENDEE_API_KEY and ATTENDEE_BASE_URL.'];
        }
        if (!self::enabled()) {
            return ['ok' => false, 'bot_id' => '', 'message' => 'The interview bot is switched off for this platform.'];
        }

        $existing = trim((string) ($iv->bot_id ?? ''));
        if ($existing !== '' && !$force
            && in_array((string) ($iv->bot_state ?? ''), ['requested', 'joining', 'in_call'], true)) {
            return ['ok' => true, 'bot_id' => $existing, 'message' => 'A bot is already on its way to this sitting.'];
        }

        if (in_array((string) $iv->status, ['cancelled', 'no_show'], true)) {
            return ['ok' => false, 'bot_id' => '', 'message' => 'This interview is marked ' . $iv->status . '.'];
        }
        if (empty($iv->consent_at)) {
            return ['ok' => false, 'bot_id' => '', 'message' =>
                'The nominee has not given permission to be recorded yet. The bot is sent automatically '
                . 'within a few minutes of them pressing the button on their interview link.'];
        }

        $url = trim((string) ($iv->meet_url ?? ''));
        if ($url === '') {
            return ['ok' => false, 'bot_id' => '', 'message' => 'This sitting has no meeting link yet.'];
        }

        $res = AttendeeBot::createBot($url, [
            'join_at'     => self::joinAt($iv),
            'webhook_url' => self::webhookUrl(),
            'prompt'      => self::recogniserPrompt($iv),
            'language'    => (string) ($iv->language ?? 'en'),
            // The sitting's own id, so a callback or a poll result names the interview
            // it belongs to without a lookup. Deliberately nothing about the nominee:
            // metadata lives on the bot host and is echoed in every webhook, and an id
            // is all this platform needs to find the rest locally.
            'metadata'    => ['ag_interview_id' => (string) $id, 'ag_source' => 'interview'],
            // Attendee refuses a second bot under a key one live bot already holds,
            // which closes the window where the cron sweep and a hand-pressed button
            // land together. Omitted under $force: an operator replacing a bot on
            // purpose is asking for exactly the second bot this would block.
            'dedup'       => $force ? '' : 'ag-interview-' . $id,
        ]);

        if (!empty($res['duplicate'])) {
            // The key did its job — a live bot for this sitting already exists, put
            // there by an earlier tick. Recording an error here would overwrite the
            // bot_id of a bot that is on its way into the room.
            return ['ok' => true, 'bot_id' => trim((string) ($iv->bot_id ?? '')),
                    'message' => 'A bot is already on its way to this sitting.'];
        }

        if (!$res['ok']) {
            self::mark($id, ['bot_state' => 'error', 'bot_error' => mb_substr((string) $res['error'], 0, 500)]);
            return ['ok' => false, 'bot_id' => '', 'message' => (string) $res['error']];
        }

        self::mark($id, [
            'bot_provider'     => 'attendee',
            'bot_id'           => $res['bot_id'],
            'bot_state'        => 'requested',
            'bot_error'        => null,
            'bot_requested_at' => Carbon::now()->toDateTimeString(),
            'bot_cursor'       => 0,
        ]);

        return ['ok' => true, 'bot_id' => $res['bot_id'], 'message' => 'The bot has been sent to this sitting.'];
    }

    /**
     * Take the bot out, and ingest whatever it heard on the way.
     *
     * The ingest comes first on purpose. A bot asked to leave stops producing transcript,
     * and pulling it before reading is how the last few minutes of an interview get lost.
     *
     * @return array{ok:bool, message:string}
     */
    public static function remove(int $id): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'message' => 'No such interview.'];

        $botId = trim((string) ($iv->bot_id ?? ''));
        if ($botId === '') return ['ok' => true, 'message' => 'There is no bot in this sitting.'];

        self::ingestFor($id, $botId);

        $res = AttendeeBot::leave($botId);
        self::mark($id, [
            'bot_state'    => 'removed',
            'bot_left_at'  => Carbon::now()->toDateTimeString(),
        ]);

        return $res['ok']
            ? ['ok' => true, 'message' => 'The bot has left the meeting.']
            : ['ok' => false, 'message' => 'Asked the bot to leave, but Attendee said: ' . (string) $res['error']];
    }

    // ── the sweep ────────────────────────────────────────────────────────────

    /**
     * One pass: send what is due, follow what is in flight, tidy what is finished.
     *
     * Returns the number of sittings it touched, which is what the cron log prints. Every
     * arm is wrapped, because one sitting with a malformed meeting URL must not stop the
     * sweep reaching the interview happening in ten minutes.
     */
    public static function sweep(int $limit = 25): int
    {
        if (!AttendeeBot::configured()) return 0;

        // Switched off means GET THE BOT OUT, not "stop noticing".
        //
        // Returning 0 here left any bot already in a call sitting in the room recording,
        // for the rest of the meeting, because the retire path lives further down this
        // same method. An operator flips this switch when something is wrong; the least it
        // can do is evacuate.
        if (!self::enabled()) return self::evacuate($limit);

        $touched = 0;

        // ── 0. THE CALENDAR IS THE TRUTH; OUR ROW IS A COPY ──────────────────
        //
        // Everything below dispatches off `scheduled_at` and `meet_url` in OUR table, and
        // an organiser who moves a meeting does it in Google Calendar — which is the whole
        // reason for putting it there. Nothing told us. So the bot turned up at the old
        // time to an empty room, the interview happened later with nobody recording it, and
        // the only symptom was a missing transcript afterwards, at exactly the point where
        // nobody can do anything about it.
        //
        // Reconciled BEFORE the dispatch window is evaluated, not after, because a sitting
        // moved INTO the next twelve minutes has to be picked up on this tick and not the
        // one after it.
        //
        // Scoped to sittings with a calendar event that are near enough to matter: one call
        // per sitting is a network round trip through Apps Script, and the whole table
        // every five minutes would be a quota problem rather than a feature. A window from
        // an hour behind to two hours ahead covers both a meeting moved earlier and one
        // pushed back.
        self::reconcileDue($limit);

        // 1. Due, consented, and no bot yet.
        $due = DB::table('gates_interviews')
            ->whereIn('status', ['confirmed', 'live'])
            ->whereNotNull('consent_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', Carbon::now()->addMinutes(self::DISPATCH_LEAD_MINUTES)->toDateTimeString())
            ->where('scheduled_at', '>=', Carbon::now()->subHours(3)->toDateTimeString())
            ->where(static fn ($q) => $q->whereNull('bot_id')->orWhere('bot_id', ''))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        foreach ($due as $iv) {
            try {
                self::dispatch((int) $iv->id);
                $touched++;
            } catch (\Throwable $e) {
                error_log('[interview-bot] dispatch ' . $iv->id . ': ' . $e->getMessage());
            }
        }

        // 2. In flight: refresh state, read new lines, maybe take a turn.
        $live = DB::table('gates_interviews')
            ->whereIn('bot_state', ['requested', 'joining', 'in_call'])
            ->whereNotNull('bot_id')
            ->where('bot_id', '!=', '')
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        foreach ($live as $iv) {
            try {
                self::poll((int) $iv->id);
                $touched++;
            } catch (\Throwable $e) {
                error_log('[interview-bot] poll ' . $iv->id . ': ' . $e->getMessage());
            }
        }

        return $touched;
    }

    /**
     * Re-read the calendar for the sittings about to happen.
     *
     * Best-effort and individually guarded: a calendar that is unreachable must not stop
     * the bot being sent to the sittings whose details have not changed. The stale row is a
     * worse outcome than no reconcile, but a dead sweep is worse than both.
     *
     * @return int how many sittings were actually corrected
     */
    private static function reconcileDue(int $limit): int
    {
        try {
            if (!\AfricaGates\Services\GoogleMeetService::boot()->canSchedule()) return 0;
        } catch (\Throwable) {
            return 0;
        }

        try {
            $rows = DB::table('gates_interviews')
                ->whereIn('status', ['draft', 'invited', 'confirmed', 'live'])
                ->whereNotNull('calendar_event_id')
                ->where('calendar_event_id', '!=', '')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', Carbon::now()->addHours(2)->toDateTimeString())
                ->where('scheduled_at', '>=', Carbon::now()->subHour()->toDateTimeString())
                ->orderBy('scheduled_at')
                ->limit($limit)
                ->get(['id']);
        } catch (\Throwable $e) {
            error_log('[interview-bot] reconcile query: ' . $e->getMessage());
            return 0;
        }

        $changed = 0;
        foreach ($rows as $r) {
            try {
                $res = InterviewService::reconcileFromCalendar((int) $r->id);
                if (!empty($res['changed'])) {
                    $changed++;
                    error_log('[interview-bot] sitting ' . $r->id . ' ' . (string) $res['message']);
                }
            } catch (\Throwable $e) {
                error_log('[interview-bot] reconcile ' . $r->id . ': ' . $e->getMessage());
            }
        }

        return $changed;
    }

    /**
     * Pull every bot out of every call. What the master switch actually does.
     *
     * Reads the transcript on the way, because {@see remove()} does — a sitting whose bot
     * is withdrawn mid-interview should still keep the half nobody has read yet, which the
     * nominee has already consented to.
     */
    private static function evacuate(int $limit): int
    {
        $live = DB::table('gates_interviews')
            ->whereIn('bot_state', ['requested', 'joining', 'in_call'])
            ->whereNotNull('bot_id')->where('bot_id', '!=', '')
            ->limit($limit)->get();

        $out = 0;
        foreach ($live as $iv) {
            try {
                self::remove((int) $iv->id);
                $out++;
            } catch (\Throwable $e) {
                error_log('[interview-bot] evacuate ' . $iv->id . ': ' . $e->getMessage());
            }
        }
        if ($out > 0) {
            error_log('[interview-bot] master switch is off — withdrew ' . $out . ' bot(s).');
        }
        return $out;
    }

    /**
     * Refresh one sitting against the provider.
     *
     * @return array{state:string, ingested:int}
     */
    public static function poll(int $id): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['state' => '', 'ingested' => 0];

        $botId = trim((string) ($iv->bot_id ?? ''));
        if ($botId === '') return ['state' => '', 'ingested' => 0];

        $was = (string) ($iv->bot_state ?? '');

        // One fetch, both readings. The raw state is needed as well as the normalised one
        // because {@see enforceConsent()} has to tell an already-paused bot from a
        // recording one, and normalisation folds both into 'in_call' — correctly, since a
        // paused bot is still in the room and must keep being polled.
        $bot   = AttendeeBot::fetchBot($botId);
        $raw   = $bot === null ? '' : strtolower(trim((string) ($bot['state'] ?? '')));
        $state = $raw === '' ? '' : AttendeeBot::normaliseState($raw);

        // Empty means the provider could not be reached, which is not news about the bot.
        // Overwriting `in_call` with an error here would end a sitting that is running.
        if ($state === '') return ['state' => $was, 'ingested' => 0];

        $patch = ['bot_state' => $state];
        if ($state === 'in_call' && empty($iv->bot_joined_at)) {
            $patch['bot_joined_at'] = Carbon::now()->toDateTimeString();
            // The sitting is demonstrably happening. InterviewService::markLive() is what
            // the console uses; a bot in the room is the same evidence.
            if ((string) $iv->status === 'confirmed') InterviewService::markLive($id);
        }
        if (in_array($state, ['done', 'error', 'removed'], true) && empty($iv->bot_left_at)) {
            $patch['bot_left_at'] = Carbon::now()->toDateTimeString();
        }
        self::mark($id, $patch);

        // Consent, on the provider's side of the wire. Before ingest, because a sitting
        // that lost consent should stop accumulating a recording at the far end too, not
        // merely stop having one read from it.
        self::enforceConsent($id, $botId, $state, $raw, $iv);

        $ingested = self::ingestFor($id, $botId);

        if ($state === 'in_call') {
            self::turn($id);
            self::maybeRetire($id);
        }

        if (in_array($state, ['done', 'removed'], true)) {
            self::collectRecording($id, $botId);
        }

        return ['state' => $state, 'ingested' => $ingested];
    }

    /**
     * Pull new utterances into the caption buffer.
     *
     * Goes through {@see InterviewLive::append()} rather than writing `live_json`
     * directly, so the bot inherits three things already written and tested there: the
     * consent gate, the revision dedup, and the follow-up suggestion the panel console
     * renders. A second ingest path would be a second place for those to be wrong.
     *
     * @return int lines accepted
     */
    /**
     * Keep the provider's recording in step with consent.
     *
     * ── THE HALF OF CONSENT THAT WAS NOT ENFORCED ────────────────────────────
     *
     * `consent_at` gates three things, and it is deliberately one column: whether a bot
     * is SENT, whether what it hears is STORED, and whether it may SPEAK. Storage was
     * enforced where the words arrive — {@see InterviewLive::mayCapture()} refuses to keep
     * one without consent — and dispatch refuses without it. Neither reaches the recording
     * being written on the bot host.
     *
     * So a nominee who withdrew mid-call had this platform correctly refusing to keep
     * their words while the recording of them kept growing on another machine. The only
     * lever was {@see remove()}, which also ends the sitting for the panel and cannot be
     * undone if the withdrawal was a misunderstanding.
     *
     * Pausing is the proportionate act, and it is reversible: consent returning resumes
     * the recording on the next tick.
     *
     * ── WHY THIS READS THE RAW STATE ─────────────────────────────────────────
     *
     * The provider answers 400 for a bot that cannot pause from where it is, INCLUDING one
     * already paused. Calling pause every tick would be harmless and would also mean a
     * failed call a minute for the rest of the sitting, which is indistinguishable in a log
     * from something actually wrong. So the raw state decides, and the normalised one
     * cannot: {@see AttendeeBot::normaliseState()} folds `joined_recording_paused` into
     * `in_call`, correctly — a paused bot is in the room and must keep being polled.
     */
    private static function enforceConsent(int $id, string $botId, string $state, string $raw, object $iv): void
    {
        if ($state !== 'in_call') return;

        $consented = trim((string) ($iv->consent_at ?? '')) !== '';
        $paused    = $raw === 'joined_recording_paused';

        if (!$consented && !$paused) {
            $r = AttendeeBot::pauseRecording($botId);
            if ($r['ok']) {
                self::mark($id, ['bot_error' => 'Recording paused: no consent is on file for this sitting.']);
            }
            return;
        }

        // Consent arrived, or came back. Resume rather than leaving a consented sitting
        // silently unrecorded — that failure looks exactly like a working one.
        if ($consented && $paused) {
            $r = AttendeeBot::resumeRecording($botId);
            if ($r['ok']) self::mark($id, ['bot_error' => '']);
        }
    }

    public static function ingestFor(int $id, string $botId): int
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return 0;

        // `bot_cursor` is the highest `timestamp_ms` already ingested — an offset into
        // the meeting, NOT a position in the response array. See the note on
        // {@see AttendeeBot::transcript()} for why counting positions corrupted the
        // transcript rather than merely skipping lines. A cursor written by the old
        // ordinal scheme is a small integer, reads as a few milliseconds, and costs one
        // over-wide fetch before it corrects itself.
        $cursor = (int) ($iv->bot_cursor ?? 0);
        $rows   = AttendeeBot::transcript($botId, $cursor);
        if ($rows === []) return 0;

        $lines = [];
        $last  = $cursor;
        foreach ($rows as $r) {
            $last    = max($last, (int) $r['ms']);
            $lines[] = [
                // Identity from the utterance itself, so the same words arriving by
                // webhook and by poll collapse instead of doubling — and so a late
                // transcription inserted earlier in the list cannot make this id point
                // at somebody else's sentence.
                'id'      => $r['uid'],
                'speaker' => $r['speaker'],
                'text'    => $r['text'],
            ];
        }

        $token = InterviewLive::tokenFor($id);
        if ($token === '') return 0;

        $res = InterviewLive::append($token, $lines, self::currentKey($id));

        // The cursor advances even when the append was refused for want of consent.
        // Otherwise every tick re-fetches the whole conversation to be refused again, and
        // the moment consent lands the buffer fills with the part that predates it.
        self::mark($id, ['bot_cursor' => $last]);

        // The suggestion InterviewLive just generated is what 'auto' speaks. Stored rather
        // than spoken here so {@see turn()} remains the single place a decision to talk is
        // made — and so the panel console shows the same question in 'assisted'.
        if (isset($res['followup']['q']) && is_string($res['followup']['q'])) {
            $meta = self::meta($id);
            $meta['pending_q'] = $res['followup']['q'];
            self::putMeta($id, $meta);
        }

        return (int) ($res['kept'] ?? 0);
    }

    // ── the turn loop ────────────────────────────────────────────────────────

    /**
     * In 'auto' mode, decide whether to say something and say it.
     *
     * A no-op in every other mode, and safe to call on every tick: the pacing rules and
     * {@see InterviewVoice::maySpeak()} between them mean a tight loop produces silence
     * rather than a bot talking over itself.
     *
     * @return array{spoke:bool, text:string, why:string}
     */
    public static function turn(int $id): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['spoke' => false, 'text' => '', 'why' => 'No such interview.'];

        if (InterviewVoice::mode($iv) !== InterviewVoice::MODE_AUTO) {
            return ['spoke' => false, 'text' => '', 'why' => 'Not in auto mode.'];
        }
        [$may, $why] = InterviewVoice::maySpeak($iv, true);
        if (!$may) return ['spoke' => false, 'text' => '', 'why' => $why];

        $meta = self::meta($id);
        $buf  = InterviewLive::buffer($id);

        // The opening. Said once, and it is the disclosure as well as the greeting — a
        // nominee should hear what is in the room from the thing that is in the room, not
        // only from an email they read last week.
        if (empty($meta['opened'])) {
            $meta['opened'] = Carbon::now()->getTimestamp();
            self::putMeta($id, $meta);
            return self::speak($id, self::opening($iv), 'opening');
        }

        // Nothing to react to yet: the nominee has not spoken since the bot last did.
        if (!self::nomineeHasAnswered($buf, (int) ($meta['said_at'] ?? 0))) {
            return ['spoke' => false, 'text' => '', 'why' => 'Waiting for the nominee to answer.'];
        }

        // A follow-up that InterviewLive already wrote from what was just said beats the
        // next scripted question, which is the entire difference between an interview and
        // a questionnaire read aloud.
        $pending = trim((string) ($meta['pending_q'] ?? ''));
        if ($pending !== '') {
            unset($meta['pending_q']);
            self::putMeta($id, $meta);
            return self::speak($id, $pending, 'followup');
        }

        // Otherwise move down the pack.
        $next = self::nextPackQuestion($id, $meta);
        if ($next === null) {
            return ['spoke' => false, 'text' => '', 'why' => 'The question pack is finished.'];
        }
        $meta['q_at']    = (int) $next['at'];
        $meta['q_key']   = (string) $next['key'];
        self::putMeta($id, $meta);

        return self::speak($id, (string) $next['q'], 'pack');
    }

    /**
     * Speak, and note what it was for.
     *
     * @return array{spoke:bool, text:string, why:string}
     */
    private static function speak(int $id, string $text, string $reason): array
    {
        // The opening is scripted: a fixed string a human wrote, so it is the ground rather
        // than something to be grounded against. Every other guard rule still applies.
        $res = InterviewVoice::say($id, $text, true, $reason === 'opening');
        if (!$res['ok']) {
            return ['spoke' => false, 'text' => '', 'why' => (string) $res['error']];
        }
        return ['spoke' => true, 'text' => (string) $res['spoken'], 'why' => $reason];
    }

    /**
     * Has the nominee said anything since the bot last spoke, and stopped?
     *
     * Two conditions, because either alone is wrong. Length alone fires while they are
     * mid-sentence, and the bot interrupts. Silence alone fires on "yes", and the bot
     * moves off a question that was barely answered.
     */
    private static function nomineeHasAnswered(array $buf, int $sinceTs): bool
    {
        $words = 0;
        $lastAt = 0;
        foreach ($buf as $line) {
            if (!empty($line['bot'])) continue;
            $at = isset($line['at']) ? (int) strtotime((string) $line['at']) : 0;
            if ($sinceTs > 0 && $at > 0 && $at < $sinceTs) continue;
            // InterviewGuard::words(), not str_word_count(): the latter treats every
            // accented character as a word boundary, so a Francophone or Lusophone
            // nominee's answer is miscounted and the bot decides they have finished
            // talking when they have not.
            $words += InterviewGuard::words((string) ($line['text'] ?? ''));
            $lastAt = max($lastAt, $at);
        }
        if ($words < self::ADVANCE_MIN_WORDS) return false;
        if ($lastAt === 0) return true; // no usable timestamps; length is all we have

        return (Carbon::now()->getTimestamp() - $lastAt) >= self::ADVANCE_SILENCE_SECONDS;
    }

    /**
     * The next unasked question in the pack.
     *
     * @return array{at:int, key:string, q:string}|null
     */
    private static function nextPackQuestion(int $id, array $meta): ?array
    {
        $pack = InterviewBrief::forInterview($id);
        $qs   = is_array($pack['questions'] ?? null) ? array_values($pack['questions']) : [];
        if ($qs === []) return null;

        $at = (int) ($meta['q_at'] ?? -1);
        for ($i = $at + 1; $i < count($qs); $i++) {
            $q = is_array($qs[$i]) ? trim((string) ($qs[$i]['q'] ?? '')) : '';
            if ($q !== '') {
                return ['at' => $i, 'key' => (string) ($qs[$i]['key'] ?? ('q' . $i)), 'q' => $q];
            }
        }
        return null;
    }

    /** Which pack question the panel (or the loop) is on, for the follow-up generator. */
    public static function currentKey(int $id): string
    {
        return trim((string) (self::meta($id)['q_key'] ?? ''));
    }

    /**
     * The bot's first words.
     *
     * Deliberately not model-written. This sentence is a disclosure, and a disclosure that
     * varies with a sampler is not one — a nominee who was told something different from
     * the next nominee cannot be said to have been told the same thing.
     *
     * Public so the console can print it. An operator switching a sitting to 'auto' should
     * be able to read what the room will hear before the room hears it.
     */
    public static function opening(object $iv): string
    {
        $who = trim((string) AttendeeBot::botName());
        return 'Hello, and thank you for making time. I am ' . $who . ', an AI assistant. '
             . 'This sitting is being recorded and transcribed for the judging panel, with your permission. '
             . 'I will ask a few questions, and a panellist can step in at any point. '
             . 'Please say if you would rather not answer something.';
    }

    // ── finishing ────────────────────────────────────────────────────────────

    /** Pull a bot that is still in a call well past the end of its slot. */
    private static function maybeRetire(int $id): void
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return;

        $when = trim((string) ($iv->scheduled_at ?? ''));
        if ($when === '') return;
        $ends = strtotime($when);
        if ($ends === false) return;

        $ends += ((int) ($iv->duration_mins ?? 30) + self::OVERRUN_GRACE_MINUTES) * 60;
        if (Carbon::now()->getTimestamp() < $ends) return;

        self::remove($id);
    }

    /**
     * Store the recording link once post-processing has produced one.
     *
     * A link and not a copy: these are hours of audio and this host's disk is measured in
     * gigabytes. It expires, which is the trade — a judge who needs to hear how a name was
     * actually pronounced should do it during the judging window, and the transcript is
     * the durable record.
     */
    /**
     * Note that a recording exists. Deliberately does NOT keep the link.
     *
     * ── WHY THE URL IS NOT STORED ────────────────────────────────────────────
     *
     * It used to be, and it was dead by the time anybody clicked it. Attendee's
     * `/recording` mints a **presigned S3 URL with `ExpiresIn=1800`** — thirty minutes,
     * per `Recording.url` in the provider's `bots/models.py`. Its own API description
     * says "short-lived"; the previous code read that as a link and wrote it to a column.
     *
     * The two halves of the failure compounded. This method ran once per sitting (it
     * returns early once the column is set), at the end of the call. A panel opens the
     * sitting hours or days later. So every "The recording" link on the admin page was
     * guaranteed expired — not usually, always — and the whole stated reason for keeping
     * a recording at all is that {@see AttendeeBot}'s docblock promises a judge can hear
     * a name the recogniser mangled.
     *
     * Nothing here can make a 30-minute credential last, so the link is minted when
     * somebody actually asks for it — {@see InterviewsController::botRecording()}. What
     * is stored is the fact that there is something to fetch.
     *
     * (This is the real shape of item 6e in the handoff, which filed it as a 180-day GCS
     * lifecycle problem. That is also true, and it is the smaller half by two orders of
     * magnitude. Copying the bytes somewhere durable — `R2Service` exists — is still the
     * answer if recordings must outlive the bucket's retention.)
     */
    private static function collectRecording(int $id, string $botId): void
    {
        $iv = InterviewService::byId($id);
        if (!$iv || trim((string) ($iv->bot_recording_at ?? '')) !== '') return;

        // Ask once, so a sitting that produced no recording is not re-fetched forever.
        // The answer is thrown away: it is the existence that is being recorded.
        if (AttendeeBot::recordingUrl($botId) === '') return;

        self::mark($id, ['bot_recording_at' => Carbon::now()->toDateTimeString()]);
    }

    /**
     * A fresh download link for a sitting's recording, or '' if there is none.
     *
     * Minted per request because it expires in thirty minutes — see
     * {@see collectRecording()}. `recordingUrl()` has already refused anything that is
     * not a plain https URL; see {@see AttendeeBot::isSafeRecordingUrl()} for why that
     * matters on a page whose session can publish a transcript to a judging panel.
     */
    public static function recordingLink(int $id): string
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return '';

        $botId = trim((string) ($iv->bot_id ?? ''));
        if ($botId === '') return '';

        return AttendeeBot::recordingUrl($botId);
    }

    // ── plumbing ─────────────────────────────────────────────────────────────

    /** Master switch, separate from the AI kill switch: a recorder is not a model call. */
    public static function enabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'interview_bot_enabled')->value('value');
            return !is_string($v) || trim($v) !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * RFC3339 join time, clamped to now.
     *
     * Attendee rejects a join time in the past outright, and the resulting sitting has no
     * bot and — before this clamp — no explanation either.
     */
    private static function joinAt(object $iv): string
    {
        $when = trim((string) ($iv->scheduled_at ?? ''));
        if ($when === '') return '';
        $ts = strtotime($when);
        if ($ts === false) return '';

        $now = Carbon::now()->getTimestamp();
        return gmdate('Y-m-d\TH:i:s\Z', max($ts, $now + 30));
    }

    /**
     * Where Attendee should post, when it can reach us.
     *
     * '' when no shared secret is configured. An unguarded callback that appends to a
     * transcript is a stranger writing evidence into a judging file, and the polling path
     * makes refusing it free.
     */
    private static function webhookUrl(): string
    {
        // Either credential is enough to make the callback verifiable: Attendee's own
        // HMAC signature, or a shared secret injected by whatever fronts this host.
        // With neither, no callback is registered at all and polling carries the load.
        if (trim((string) Env::get('ATTENDEE_WEBHOOK_SIGNING_SECRET', '')) === ''
            && trim((string) Env::get('ATTENDEE_WEBHOOK_SECRET', '')) === '') return '';
        $base = rtrim((string) (SiteUrl::base() ?: Env::get('APP_URL', '')), '/');
        if ($base === '' || !str_starts_with($base, 'https://')) return '';
        return $base . '/api/v1/interview/bot/webhook';
    }

    /**
     * Names and terms to prime the recogniser with.
     *
     * This is the point of choosing OpenAI transcription over Meet's free captions, so it
     * is worth being specific: the nominee's name, their organisation, and their category.
     * A recogniser that has been told to expect "Chidiebere" writes it; one that has not
     * writes "Cheed a bear a".
     */
    private static function recogniserPrompt(object $iv): string
    {
        $bits = ['An awards judging interview for Africa GATES.'];

        try {
            $n = DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->first();
            if ($n) {
                foreach (['name', 'organisation'] as $f) {
                    $v = trim((string) ($n->$f ?? ''));
                    if ($v !== '') $bits[] = $v;
                }
            }
            if (!empty($iv->category_id)) {
                $c = DB::table('gates_award_categories')->where('id', (int) $iv->category_id)->value('name');
                if (is_string($c) && trim($c) !== '') $bits[] = trim($c);
            }
        } catch (\Throwable) {
            // A prompt is an improvement, not a precondition. A sitting whose nominee row
            // cannot be read still gets a bot with an unprimed recogniser.
        }

        return implode(' ', $bits);
    }

    /** @param array<string,mixed> $patch */
    private static function mark(int $id, array $patch): void
    {
        try {
            $patch['updated_at'] = Carbon::now()->toDateTimeString();
            DB::table('gates_interviews')->where('id', $id)->update($patch);
        } catch (\Throwable $e) {
            error_log('[interview-bot] could not update ' . $id . ': ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private static function meta(int $id): array
    {
        try {
            $raw = DB::table('gates_interviews')->where('id', $id)->value('live_meta');
        } catch (\Throwable) {
            return [];
        }
        $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($m) ? $m : [];
    }

    /** @param array<string,mixed> $meta */
    private static function putMeta(int $id, array $meta): void
    {
        try {
            DB::table('gates_interviews')->where('id', $id)->update(['live_meta' => json_encode($meta)]);
        } catch (\Throwable $e) {
            error_log('[interview-bot] could not store meta for ' . $id . ': ' . $e->getMessage());
        }
    }
}
