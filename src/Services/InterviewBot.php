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
 * Worth stating plainly, because the word suggests something this cannot do.
 *
 * A conversational voice agent — the kind that interrupts and back-channels — holds a
 * duplex audio stream and answers in a few hundred milliseconds. That needs the process
 * to live next to the media, which is precisely what this platform does not have.
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
        ]);

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

        $was   = (string) ($iv->bot_state ?? '');
        $state = AttendeeBot::botStatus($botId);

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
    public static function ingestFor(int $id, string $botId): int
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return 0;

        $cursor = (int) ($iv->bot_cursor ?? 0);
        $rows   = AttendeeBot::transcript($botId, $cursor);
        if ($rows === []) return 0;

        $lines = [];
        $last  = $cursor;
        foreach ($rows as $r) {
            $last    = max($last, (int) $r['index']);
            $lines[] = [
                // The provider's own ordinal makes the id stable, so the same utterance
                // arriving by webhook and by poll collapses instead of doubling.
                'id'      => 'att-' . $r['index'],
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
    private static function collectRecording(int $id, string $botId): void
    {
        $iv = InterviewService::byId($id);
        if (!$iv || trim((string) ($iv->bot_recording_url ?? '')) !== '') return;

        $url = AttendeeBot::recordingUrl($botId);
        if ($url === '') return;

        // recordingUrl() has already refused anything that is not a plain https URL —
        // see AttendeeBot::isSafeRecordingUrl() for why that matters on a page whose
        // session can publish a transcript to a judging panel.

        self::mark($id, [
            'bot_recording_url' => mb_substr($url, 0, 1000),
            'bot_recording_at'  => Carbon::now()->toDateTimeString(),
        ]);
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
        if (trim((string) Env::get('ATTENDEE_WEBHOOK_SECRET', '')) === '') return '';
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
