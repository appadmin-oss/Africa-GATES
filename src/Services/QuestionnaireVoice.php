<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Voice, bound to one nominee's submission.
 *
 * {@see VoiceService} knows how to talk to ElevenLabs and nothing about this platform.
 * This class is the other half: it decides WHAT may be spoken, WHO is allowed to ask, and
 * what the asking cost — and it writes those two numbers to the row so an operator can see
 * where their quota went.
 *
 * ── THE TWO GUARDS, AND WHY THEY ARE DIFFERENT SHAPES ────────────────────────
 *
 * Speaking is guarded by ADDRESSING. {@see say()} takes the index of a turn, resolves it
 * through {@see QuestionnaireChat::spokenTurn()}, and so can only ever read back something
 * this conversation already said to this nominee. There is no character quota because there
 * does not need to be one: a conversation holds a bounded number of turns, the clip cache is
 * keyed by the text, and therefore a submission's lifetime text-to-speech cost is "each of
 * its own questions, once, ever". Replays are file reads.
 *
 * Listening is guarded by COUNTING, because it cannot be guarded by addressing — every
 * recording is new audio that nobody has paid for yet, and no cache can help. So
 * {@see hear()} counts, and past {@see MAX_CALLS} the microphone goes away and the typing
 * box stays. That number is deliberately generous: a nominee re-recording an answer six
 * times because they keep changing their mind is somebody trying hard, not somebody abusing
 * anything, and a cap that punishes care would defeat the reason voice exists here.
 *
 * ── AND WHAT IS NOT STORED ───────────────────────────────────────────────────
 *
 * The audio. It is streamed to ElevenLabs from the request and never written to disk, and
 * the words come back to the PAGE, not to the row. Only the ordinary chat turn writes an
 * answer, after the nominee has read the transcription and pressed send. A transcriber
 * writing directly into the record would be putting words in somebody's mouth that a judge
 * later reads as a quotation — and speech recognition on the Englishes actually spoken
 * across this continent is precisely where that goes wrong.
 */
final class QuestionnaireVoice
{
    /**
     * Spoken answers one submission may have transcribed.
     *
     * Eleven questions, a handful of follow-ups, and room to re-record every one of them
     * several times over two or three sittings.
     */
    public const MAX_CALLS = 240;

    /** True when voice can run at all. The page renders no microphone when this is false. */
    public static function enabled(?VoiceService $voice = null): bool
    {
        return ($voice ?? VoiceService::boot())->configured();
    }

    // ══ Speaking ══════════════════════════════════════════════════════════════

    /**
     * Read one of this conversation's own turns aloud.
     *
     * @return array{ok:bool, audio?:string, mime?:string, cached?:bool, message?:string}
     */
    public static function say(string $token, int $index, ?VoiceService $voice = null): array
    {
        $voice = $voice ?? VoiceService::boot();
        if (!$voice->configured()) {
            return ['ok' => false, 'message' => 'Voice is not available.'];
        }

        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];

        $text = QuestionnaireChat::spokenTurn($token, $index);
        if ($text === null) {
            // Says nothing about WHY — a wrong index, somebody else's turn and a nominee's
            // own answer are all "no". Distinguishing them would turn this into a way to
            // probe the shape of a conversation the caller cannot otherwise read.
            return ['ok' => false, 'message' => 'There is nothing to read out there.'];
        }

        $r = $voice->speak($text);
        if (!($r['ok'] ?? false)) return $r;

        // Only the characters actually sent. A cache hit reports zero, so the number answers
        // "what did this cost" rather than "how often was play pressed".
        $chars = (int) ($r['chars'] ?? 0);
        if ($chars > 0) self::bump((int) $s->id, ['voice_chars' => $chars]);
        self::markUsed((int) $s->id);

        return $r;
    }

    /**
     * Read one of this submission's own QUESTIONS aloud.
     *
     * ── WHY THIS EXISTS ALONGSIDE say() ─────────────────────────────────────
     *
     * {@see say()} addresses a turn in the guided chat. The guided chat is gone — it was a
     * third way of answering, neither the live interview nor the form, and being able to
     * choose between three doors is not the same as being helped through one.
     *
     * Removing it must not remove VOICE from everybody who is not in an interview. A nominee
     * answering on a phone, or one who reads slowly, or one for whom this is a third
     * language, is exactly who the read-aloud is for — and they are now on the form.
     *
     * The addressing guard is identical and that is the point: a slug is resolved against
     * THIS submission's own question list, so the page can still only ask the platform to
     * speak something it already showed this nominee. Never free text.
     *
     * @return array{ok:bool, audio?:string, mime?:string, cached?:bool, message?:string}
     */
    public static function sayQuestion(string $token, string $slug, ?VoiceService $voice = null): array
    {
        $voice = $voice ?? VoiceService::boot();
        if (!$voice->configured()) {
            return ['ok' => false, 'message' => 'Voice is not available.'];
        }

        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];

        $slug = trim($slug);
        $text = null;
        foreach (QuestionnaireService::questionsFor($s) as $q) {
            if ((string) ($q['slug'] ?? '') !== $slug) continue;

            // The label, and the help under it when there is any. Read together because the
            // help is usually the half that says what a usable answer contains — reading the
            // question alone would speak the shorter and less useful of the two.
            $text = trim((string) ($q['label'] ?? ''));
            $help = trim((string) ($q['help'] ?? ''));
            if ($help !== '') $text .= '. ' . $help;
            break;
        }

        if ($text === null || $text === '') {
            // Deliberately one message for "no such slug" and "an empty question": telling
            // them apart would turn this into a way to probe a question list the caller
            // cannot otherwise read.
            return ['ok' => false, 'message' => 'There is nothing to read out there.'];
        }

        $r = $voice->speak($text);
        if (!($r['ok'] ?? false)) return $r;

        $chars = (int) ($r['chars'] ?? 0);
        if ($chars > 0) self::bump((int) $s->id, ['voice_chars' => $chars]);
        self::markUsed((int) $s->id);

        return $r;
    }

    // ══ Listening ═════════════════════════════════════════════════════════════

    /**
     * Turn a recording into words and hand them back. Writes no answer.
     *
     * @return array{ok:bool, text?:string, language?:string, left?:int, message?:string}
     */
    public static function hear(string $token, string $bytes, string $filename = 'answer.webm',
                                string $mime = 'audio/webm', ?VoiceService $voice = null): array
    {
        $voice = $voice ?? VoiceService::boot();
        if (!$voice->configured()) {
            return ['ok' => false, 'message' => 'Voice is not available.'];
        }

        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];

        $used = (int) ($s->voice_calls ?? 0);
        if ($used >= self::MAX_CALLS) {
            return ['ok' => false, 'left' => 0,
                    'message' => 'You have used the spoken answers available on this link. Please '
                               . 'type the rest — everything you have already answered is saved.'];
        }

        // The type is checked here rather than trusted from the browser, because a MediaRecorder
        // on an older Android reports container types this platform has no business forwarding
        // and ElevenLabs would reject after we had already paid for the upload.
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime !== '' && !in_array($mime, VoiceService::AUDIO_TYPES, true)) {
            return ['ok' => false, 'left' => self::MAX_CALLS - $used,
                    'message' => 'That kind of recording is not one we can read. Type the answer '
                               . 'instead, or try the microphone again.'];
        }

        $r = $voice->transcribe($bytes, $filename, $mime !== '' ? $mime : 'audio/webm');

        // Counted on SUCCESS only. Charging a nominee's allowance for a call that gave them
        // nothing back would make a bad connection look like a limit they had hit.
        if ($r['ok'] ?? false) {
            self::bump((int) $s->id, ['voice_calls' => 1]);
            self::markUsed((int) $s->id);
            $r['left'] = max(0, self::MAX_CALLS - ($used + 1));
        } else {
            $r['left'] = self::MAX_CALLS - $used;
        }
        return $r;
    }

    // ══ What it cost ══════════════════════════════════════════════════════════

    /**
     * The two numbers, for the operator screen.
     *
     * @return array{used:bool, chars:int, calls:int, left:int}
     */
    public static function usage(object $s): array
    {
        $calls = (int) ($s->voice_calls ?? 0);
        return [
            'used'  => (int) ($s->voice_used ?? 0) === 1,
            'chars' => (int) ($s->voice_chars ?? 0),
            'calls' => $calls,
            'left'  => max(0, self::MAX_CALLS - $calls),
        ];
    }

    /** The only two column names this class will ever write. */
    private const COUNTERS = ['voice_chars', 'voice_calls'];

    /**
     * Add to a counter.
     *
     * Done in SQL rather than read-modify-write, because two tabs open on the same link is the
     * normal case for somebody who mailed themselves the URL and a lost increment would
     * under-report the one number an operator is relying on.
     *
     * COALESCE, not `increment()`. The columns are added as nullable (an ALTER on a live table
     * with existing rows leaves them NULL), and `NULL + 1` is NULL in both MySQL and SQLite —
     * so the plain increment silently left every counter at nothing forever. That is the exact
     * failure this platform keeps finding: a number on a screen with nothing behind it.
     *
     * @param array<string,int> $by
     */
    private static function bump(int $id, array $by): void
    {
        foreach ($by as $col => $n) {
            if (!in_array($col, self::COUNTERS, true)) continue;
            try {
                DB::table('gates_nominee_submissions')->where('id', $id)
                    ->update([$col => DB::raw('COALESCE(' . $col . ', 0) + ' . (int) $n)]);
            } catch (\Throwable) {
                // A deployment that has not run 2026_08_31 yet still has working voice; it just
                // cannot report the cost. Losing the counter is not worth losing the feature.
            }
        }
    }

    private static function markUsed(int $id): void
    {
        try {
            DB::table('gates_nominee_submissions')->where('id', $id)
                ->where(static function ($q): void { $q->whereNull('voice_used')->orWhere('voice_used', 0); })
                ->update(['voice_used' => 1]);
        } catch (\Throwable) {}
    }
}
