<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The two things that come before the first question.
 *
 * ── 1 · WHAT IS EXPECTED OF THEM, SAID FIRST ─────────────────────────────────
 *
 * The questionnaire opened with a greeting and then a question. A nominee had no idea how
 * long it would take, what a usable answer looks like, whether a half-finished draft was
 * safe, or what happens to any of it afterwards. The people worst served by not knowing are
 * exactly the ones who will not ask.
 *
 * So the brief is its own stage, and passing it is recorded. It costs twenty seconds and it
 * is the difference between an answer of two sentences and one a panel can judge.
 *
 * ── 2 · A MINUTE OF THEM, IN THEIR OWN VOICE ─────────────────────────────────
 *
 * A dossier has never contained the person. It has a nominator's paragraph, a category, a
 * photograph, and now the nominee's typed answers. An introduction they SPEAK is the one
 * thing in it that cannot be ghost-written, and for somebody whose work is oral — a
 * storyteller, a teacher, a drummer — it is also the most accurate evidence available.
 *
 * ── AND THIS RECORDING IS KEPT, WHICH IS A DEPARTURE ─────────────────────────
 *
 * A spoken ANSWER ({@see QuestionnaireVoice::hear()}) is transcribed and the audio is
 * discarded: the words are what a judge reads, the nominee corrects them first, and keeping
 * the file would be holding somebody's voice for no purpose.
 *
 * An introduction is different in kind — the recording IS the artefact, and a judge is meant
 * to hear it. So it is stored, transcribed as well so it can be read and searched, and both
 * are published as `nominee_supplied`: their own claim, never verified.
 *
 * Three consequences follow, and none is optional:
 *
 *   • IT IS NOT PUBLIC. The file lives outside the web root and is served through a route
 *     that requires either a panel session or the submission's own token. A nominee's voice
 *     on a guessable public URL is a confidentiality change nobody asked for — the same
 *     reasoning this codebase already applies to nomination PDFs.
 *   • CONSENT IS EXPLICIT AND SEPARATE. {@see consent()} stamps the moment they accepted
 *     that a panel will hear it, and {@see forDossier()} refuses without it.
 *   • THEY CAN DELETE IT. Right up to submitting, {@see remove()} takes the file off the
 *     disk. Somebody who recorded a false start and cannot undo it will abandon the whole
 *     questionnaire rather than send a bad first impression.
 */
final class QuestionnaireIntro
{
    /** Long enough to say who you are and what you do; short enough that nobody rambles. */
    public const MAX_SECONDS = 120;

    /** A minute of Opus is well under a megabyte; this is a generous ceiling, not a target. */
    public const MAX_BYTES = 12582912;

    /** What a browser's MediaRecorder actually produces. */
    public const AUDIO_TYPES = [
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/m4a',
        'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'video/webm',
    ];

    /**
     * The default brief.
     *
     * Written as sentences rather than bullet fragments, because the person reading it may be
     * doing so on a phone, in a second or third language, having never seen this site before.
     * Every claim in it is one the platform actually keeps: the draft really does save, the
     * link really does keep working, nothing really is sent until they press the button.
     *
     * An operator can replace it per programme — see {@see brief()} — because an award for
     * teachers and an award for exporters want different examples. What must not change is
     * that SOMETHING is said before the first question.
     */
    public const DEFAULT_BRIEF = <<<'TXT'
        Right now the judges have only what the person who nominated you wrote about you. This
        is where you tell them about the work yourself.

        It takes about fifteen minutes, and you do not have to do it in one sitting — every
        answer is saved as you go and this link keeps working. Nothing reaches a judge until
        you press the send button yourself.

        WHAT MAKES AN ANSWER USEFUL. Numbers and names, wherever you have them. "We trained
        many farmers" tells a panel nothing; "we trained 1,240 farmers across 8 states between
        2019 and 2024, and the state agriculture office has the register" is something they can
        weigh. If you do not have a figure, say what you do have — an estimate you can stand
        behind is better than a round number you cannot.

        WRITE IT AS YOU WOULD SAY IT. In whatever English you are comfortable with. Nobody is
        marking your grammar, and what you write is stored exactly as you write it — your words
        go to the panel, not a tidied-up version of them.

        THE HARD QUESTIONS ARE NOT TRAPS. You will be asked what went wrong, and what the work
        cost you. A panel trusts an account with a setback in it more than one without; nobody
        has ever lost an award for answering that honestly.

        AND NOTHING ABOUT THIS COSTS MONEY, at any stage. If anybody asks you to pay a fee for
        a nomination, an interview or a result, it is not us.
        TXT;

    // ══ 1. the brief ═════════════════════════════════════════════════════════

    /**
     * The brief for this programme: its own text, else a site-wide one, else the default.
     *
     * Read through settings rather than a column on the programme, because a brief is
     * editorial copy an operator wants to tune during a cycle and a schema change is a
     * deploy. Both keys are plain text and neither is secret.
     */
    public static function brief(?int $programmeId = null): string
    {
        $get = static function (string $key): string {
            try {
                $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
                return is_string($v) ? trim($v) : '';
            } catch (\Throwable) { return ''; }
        };

        if ($programmeId !== null && $programmeId > 0) {
            $own = $get('questionnaire_brief_' . $programmeId);
            if ($own !== '') return $own;
        }
        $global = $get('questionnaire_brief');
        if ($global !== '') return $global;

        // Dedented here rather than in the constant, so the heredoc above can stay indented
        // with the class and still print as flush-left prose.
        return (string) preg_replace('/^[ \t]+/m', '', self::DEFAULT_BRIEF);
    }

    /** Split into paragraphs, which is all the template needs. @return list<string> */
    public static function briefParagraphs(?int $programmeId = null): array
    {
        $parts = preg_split('/\n\s*\n/', self::brief($programmeId)) ?: [];
        return array_values(array_filter(array_map(
            static fn (string $p): string => trim((string) preg_replace('/\s*\n\s*/', ' ', $p)),
            $parts
        ), static fn (string $p): bool => $p !== ''));
    }

    /** They have read it. The questions do not start before this. */
    public static function markSeen(string $token): bool
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return false;
        if (($s->intro_seen_at ?? null) !== null) return true;
        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
                ->update(OptionalColumn::filter('gates_nominee_submissions', [
                    'intro_seen_at' => Carbon::now()->toDateTimeString(),
                ], ['intro_seen_at']));
            return true;
        } catch (\Throwable) { return false; }
    }

    public static function seen(?object $s): bool
    {
        return $s !== null && ($s->intro_seen_at ?? null) !== null;
    }

    // ══ 2. the recording ═════════════════════════════════════════════════════

    /**
     * Store a spoken introduction, and transcribe it if a voice key is configured.
     *
     * Order matters. The FILE is written first and the transcript attempted second, because
     * the recording is the artefact and the transcript is a convenience: a nominee whose
     * upload succeeded and whose transcription failed still has an introduction, and telling
     * them otherwise would make them record it again for nothing.
     *
     * @return array{ok:bool, message:string, seconds?:int, transcript?:string, has_text?:bool}
     */
    public static function record(string $token, UploadedFileInterface $file, int $seconds = 0,
                                  ?VoiceService $voice = null): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if ((string) $s->status === 'submitted') {
            return ['ok' => false, 'message' => 'This has already gone to the panel, so it cannot be '
                                              . 'changed. Write to us if it needs correcting.'];
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'That recording did not finish uploading. On a weak '
                                              . 'connection, a shorter one usually gets through.'];
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0)              return ['ok' => false, 'message' => 'That recording was empty.'];
        if ($size > self::MAX_BYTES) return ['ok' => false, 'message' => 'That recording is too large. '
                                                                      . 'Please keep it under two minutes.'];

        $mime = strtolower(trim(explode(';', (string) ($file->getClientMediaType() ?? ''))[0]));
        if ($mime !== '' && !in_array($mime, self::AUDIO_TYPES, true)) {
            return ['ok' => false, 'message' => 'That kind of recording is not one we can store.'];
        }

        $dir = self::dir();
        if ($dir === null) {
            return ['ok' => false, 'message' => 'We could not save the recording just now. Please try again.'];
        }

        // The old one goes only once the new one is safely down, so a failed re-record does
        // not leave somebody with nothing where they used to have an introduction.
        $previous = trim((string) ($s->intro_audio_path ?? ''));

        $ext  = str_contains($mime, 'mp4') || str_contains($mime, 'm4a') ? 'm4a'
              : (str_contains($mime, 'mpeg') ? 'mp3' : (str_contains($mime, 'wav') ? 'wav' : 'webm'));
        $name = 'intro-' . (int) $s->id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $name;

        try { $file->moveTo($path); }
        catch (\Throwable $e) {
            error_log('[intro] could not store the recording: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'We could not save the recording just now. Please try again.'];
        }

        // Trusted from the CLIENT because only the client has a clock on the recording — but
        // clamped, because a number from a browser decides what a judge is told the length is.
        $seconds = max(0, min(self::MAX_SECONDS + 10, $seconds));

        $voice = $voice ?? VoiceService::boot();
        $transcript = '';
        $source = 'none';
        if ($voice->configured()) {
            $bytes = (string) @file_get_contents($path);
            if ($bytes !== '') {
                $r = $voice->transcribe($bytes, $name, $mime !== '' ? $mime : 'audio/webm');
                if ($r['ok'] ?? false) {
                    $transcript = (string) $r['text'];
                    $source = 'ai';
                }
            }
        }

        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
                ->update(OptionalColumn::filter('gates_nominee_submissions', [
                    'intro_audio_path'  => $name,
                    'intro_seconds'     => $seconds,
                    'intro_recorded_at' => Carbon::now()->toDateTimeString(),
                    'intro_transcript'  => $transcript !== '' ? mb_substr($transcript, 0, 8000) : null,
                    'intro_source'      => $source,
                    // Consent is WITHDRAWN by re-recording, and it has to be done here rather
                    // than only in the page: a panel agreed to hear one recording, not
                    // whichever one happens to be in the column later. Doing it client-side
                    // alone would mean a second upload from a stale tab — or from anything
                    // that is not the page — silently inheriting permission for audio nobody
                    // has heard.
                    'intro_consent_at'  => null,
                ], ['intro_audio_path', 'intro_seconds', 'intro_recorded_at',
                    'intro_transcript', 'intro_source', 'intro_consent_at']));
        } catch (\Throwable $e) {
            @unlink($path);
            error_log('[intro] could not record the introduction: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'We could not save the recording just now. Please try again.'];
        }

        if ($previous !== '' && $previous !== $name) @unlink($dir . '/' . $previous);

        return ['ok' => true, 'seconds' => $seconds, 'transcript' => $transcript,
                'has_text' => $transcript !== '',
                'message' => $transcript !== ''
                    ? 'Saved. We have written down what you said so the judges can read it as well as hear it — '
                    . 'have a look, and record it again if it did not come out the way you meant.'
                    : 'Saved. The judges will hear this.'];
    }

    /** Take it off the disk. Available until the questionnaire is sent. */
    public static function remove(string $token): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if ((string) $s->status === 'submitted') {
            return ['ok' => false, 'message' => 'This has already gone to the panel.'];
        }

        $name = trim((string) ($s->intro_audio_path ?? ''));
        $dir  = self::dir();
        if ($name !== '' && $dir !== null) @unlink($dir . '/' . $name);

        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
                ->update(OptionalColumn::filter('gates_nominee_submissions', [
                    'intro_audio_path' => null, 'intro_seconds' => null,
                    'intro_recorded_at' => null, 'intro_transcript' => null,
                    'intro_source' => null, 'intro_consent_at' => null,
                ], ['intro_audio_path', 'intro_seconds', 'intro_recorded_at',
                    'intro_transcript', 'intro_source', 'intro_consent_at']));
        } catch (\Throwable) {}

        return ['ok' => true, 'message' => 'Deleted. You can record another one, or skip it.'];
    }

    /**
     * They accept that a judging panel will hear it.
     *
     * Separate from recording, and required before {@see forDossier()} will hand it over.
     * Pressing "record" is somebody trying the microphone; agreeing that a panel of strangers
     * will listen to it is a different decision, and the platform should not read the first
     * as the second — the same reasoning that put the interview's recording consent on its
     * own button.
     */
    public static function consent(string $token): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if (trim((string) ($s->intro_audio_path ?? '')) === '') {
            return ['ok' => false, 'message' => 'There is no recording to share yet.'];
        }
        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
                ->update(OptionalColumn::filter('gates_nominee_submissions', [
                    'intro_consent_at' => Carbon::now()->toDateTimeString(),
                ], ['intro_consent_at']));
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }
        return ['ok' => true, 'message' => 'Thank you — the panel will hear your introduction.'];
    }

    // ══ 3. reading it back ═══════════════════════════════════════════════════

    /**
     * What the nominee's own page shows about their recording.
     *
     * @return array{has:bool, seconds:int, transcript:string, source:string, consented:bool,
     *               url:string, recorded_at:string}
     */
    public static function state(?object $s, string $token = ''): array
    {
        $name = $s !== null ? trim((string) ($s->intro_audio_path ?? '')) : '';
        return [
            'has'         => $name !== '',
            'seconds'     => (int) ($s->intro_seconds ?? 0),
            'transcript'  => (string) ($s->intro_transcript ?? ''),
            'source'      => (string) ($s->intro_source ?? ''),
            'consented'   => $s !== null && ($s->intro_consent_at ?? null) !== null,
            'recorded_at' => (string) ($s->intro_recorded_at ?? ''),
            // Through the token, so a nominee can play back their own recording without an
            // account. The same route serves a panel from a session.
            'url'         => $name !== '' && $token !== '' ? '/my-work/' . $token . '/intro.audio' : '',
        ];
    }

    /**
     * The introduction as something a judge may be shown — or nothing.
     *
     * Refuses without consent, and that refusal is HERE rather than on a screen for the same
     * reason every other consent gate on this platform lives in the writer: a screen can be
     * bypassed and the next caller will not remember to check.
     *
     * @return array{audio:string, transcript:string, seconds:int}|null
     */
    public static function forDossier(?object $s): ?array
    {
        if ($s === null) return null;
        $name = trim((string) ($s->intro_audio_path ?? ''));
        if ($name === '') return null;
        if (($s->intro_consent_at ?? null) === null) return null;

        return ['audio' => $name, 'seconds' => (int) ($s->intro_seconds ?? 0),
                'transcript' => (string) ($s->intro_transcript ?? '')];
    }

    /** Absolute path of a stored recording, or null. Never built from caller input. */
    public static function fileFor(?object $s): ?string
    {
        $name = $s !== null ? trim((string) ($s->intro_audio_path ?? '')) : '';
        if ($name === '') return null;
        // Basename only. The column is written by this class alone, but a path assembled for
        // a file-serving route is exactly the place a traversal would matter, and asserting it
        // costs nothing.
        $name = basename($name);
        $dir  = self::dir(false);
        if ($dir === null) return null;
        $path = $dir . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function mimeFor(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'm4a', 'mp4' => 'audio/mp4',
            'mp3'        => 'audio/mpeg',
            'wav'        => 'audio/wav',
            'ogg'        => 'audio/ogg',
            default      => 'audio/webm',
        };
    }

    /**
     * Where recordings live: outside the web root, deliberately.
     *
     * A nominee's voice on a guessable public URL under /uploads/ would make an unlisted guess
     * the only thing between it and the internet. The same argument this codebase already
     * makes about nomination PDFs, and it applies more strongly to a recording of a person.
     */
    private static function dir(bool $create = true): ?string
    {
        $dir = dirname(__DIR__, 2) . '/var/uploads/nominee-intros';
        if (is_dir($dir)) return is_writable($dir) ? $dir : null;
        if (!$create) return null;
        return (@mkdir($dir, 0775, true) || is_dir($dir)) ? $dir : null;
    }
}
