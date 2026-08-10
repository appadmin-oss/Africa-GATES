<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What the browser extension talks to: the live half of an interview.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT AN EXTENSION MAKES POSSIBLE, AND WHAT IT DOES NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It closes two real gaps and leaves one open.
 *
 * CLOSED — the transcript no longer needs a paid Google plan. Meet's own transcription is a
 * Workspace tier feature; its LIVE CAPTIONS are free to everybody. An extension in the
 * interviewer's tab reads the captions as they render and posts them here, so a sitting on
 * a free Google account produces a transcript without anybody retyping a conversation.
 *
 * CLOSED — the follow-ups become real. Until now the panel got the pack's pre-written
 * probes. With captions arriving, the model can be asked "they just said this; what is the
 * one question worth asking next?" — and that is the difference between a scripted
 * interview and an interview.
 *
 * STILL OPEN — the AI has no voice in the room. Occupying a participant seat and putting
 * audio into a Meet call needs Google's Meet Media API and a persistent media process; an
 * extension has neither, and this host has neither. The next question appears on the
 * interviewer's screen and a human reads it aloud. Ears and brain, borrowed mouth.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TOKEN IS THE WHOLE CREDENTIAL, AND IT IS DELIBERATELY SMALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The request comes from a Meet tab on Google's origin. The admin session cookie is
 * SameSite=Lax — which is what protects every other admin POST here — so it is not sent,
 * and loosening it would weaken the whole console to buy this one feature.
 *
 * So: a token scoped to one sitting, which can do exactly three things. Read that sitting's
 * questions, append caption lines, close it. It cannot read another interview, cannot see a
 * nominee's email or phone, cannot list anything, and cannot reach any other admin route.
 * {@see hello()} is the entire read surface and it is a fixed shape.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CONSENT IS CHECKED HERE TOO, BEFORE A SINGLE LINE IS KEPT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see InterviewService::publish()} already refuses to show a panel a transcript without
 * the nominee's own permission. That is the last gate, and a last gate on its own would
 * mean capturing an unconsented conversation and holding it until somebody decides.
 *
 * So capture does not start: `hello()` reports `capture: false` with a reason the extension
 * prints, and `append()` discards the lines. The interview still runs and the panel still
 * takes notes — what stops is the recording of a person who has not agreed to be recorded.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT ASSUMES GOOGLE WILL BREAK IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Captions are read out of a page whose markup Google owns and changes without notice. That
 * WILL stop working one day. The whole point of `live_at` and `live_source` is that the
 * failure is visible on the interview screen — "captions last arrived 40 minutes ago" —
 * rather than being discovered when a panel finds an empty transcript. A silent capture
 * that quietly stopped is the exact failure shape this codebase keeps digging out.
 */
final class InterviewLive
{
    /** Caption lines kept in the buffer. Roughly two hours of talking. */
    private const MAX_LINES = 4000;

    /** Never ask the model for a follow-up more often than this. */
    public const FOLLOWUP_COOLDOWN_SECONDS = 40;

    /** Words of answer needed before a follow-up is worth asking for. */
    private const FOLLOWUP_MIN_WORDS = 12;

    // ══ the credential ═══════════════════════════════════════════════════════

    /** The live key for a sitting, minting one on first use. */
    public static function tokenFor(int $id): string
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return '';
        $t = trim((string) ($iv->live_token ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $t)) return $t;
        return self::rotate($id);
    }

    /** Replace it — for a key pasted into the wrong browser, or shared by accident. */
    public static function rotate(int $id): string
    {
        $t = bin2hex(random_bytes(16));
        try {
            DB::table('gates_interviews')->where('id', $id)->update([
                'live_token' => $t,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[interview] could not mint a live token for ' . $id . ': ' . $e->getMessage());
            return '';
        }
        return $t;
    }

    private static function byToken(string $token): ?object
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        try {
            return DB::table('gates_interviews')->where('live_token', $token)->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    // ══ 1. hello ═════════════════════════════════════════════════════════════

    /**
     * Everything the extension needs, from the token alone.
     *
     * The entire read surface of this credential. No contact details, no other nominee, no
     * scores, no list — a fixed shape, so a leaked key is worth one interview's questions
     * and nothing else.
     *
     * @return array{ok:bool, message:string, id?:int, nominee?:string, capture?:bool,
     *               reason?:string, questions?:list<array<string,mixed>>, lines?:int}
     */
    public static function hello(string $token, string $meetCode = ''): array
    {
        $iv = self::byToken($token);
        if (!$iv) return ['ok' => false, 'message' => 'That live key is not valid. Copy it again from '
                                                    . 'the interview screen.'];

        // A code mismatch is a warning, not a refusal: an operator may legitimately move the
        // call to a new room, and refusing here would strand them mid-interview with no
        // capture and no explanation.
        $expected = strtolower(trim((string) ($iv->meet_code ?? '')));
        $seen     = strtolower(trim($meetCode));
        $warning  = ($expected !== '' && $seen !== '' && $expected !== $seen)
            ? 'This call (' . $seen . ') is not the room saved on the interview (' . $expected
            . '). Capturing anyway — check you are in the right meeting.'
            : '';

        $nominee = (string) (DB::table('gates_nominees')
            ->where('id', (int) $iv->nominee_id)->value('name') ?? 'the nominee');

        [$capture, $reason] = self::mayCapture($iv);

        $pack = InterviewBrief::forInterview((int) $iv->id);
        if (($pack['questions'] ?? []) === []) {
            InterviewBrief::build((int) $iv->id);
            $pack = InterviewBrief::forInterview((int) $iv->id);
        }

        // Only what the panel needs to read on screen. `why` is included because it is the
        // line that stops an interviewer asking a question they do not understand.
        $questions = [];
        foreach (($pack['questions'] ?? []) as $q) {
            $questions[] = [
                'key'       => (string) ($q['key'] ?? ''),
                'criterion' => (string) ($q['criterion'] ?? ''),
                'q'         => (string) ($q['q'] ?? ''),
                'why'       => (string) ($q['why'] ?? ''),
                'probe'     => array_values(array_map('strval', (array) ($q['probe'] ?? []))),
                'source'    => (string) ($q['source'] ?? ''),
            ];
        }

        return [
            'ok'        => true,
            'id'        => (int) $iv->id,
            'nominee'   => $nominee,
            'status'    => (string) $iv->status,
            'capture'   => $capture,
            'reason'    => $reason,
            'warning'   => $warning,
            'opening'   => (string) ($pack['opening'] ?? ''),
            'closing'   => (string) ($pack['closing'] ?? ''),
            'questions' => $questions,
            'lines'     => count(self::buffer((int) $iv->id)),
            'console'   => rtrim(\AfricaGates\Support\SiteUrl::base(), '/')
                         . '/admin/interviews/' . (int) $iv->id . '/run',
            'message'   => 'Connected to interview #' . $iv->id . '.',
        ];
    }

    /**
     * May this sitting be captured?
     *
     * @return array{0:bool, 1:string}
     */
    private static function mayCapture(object $iv): array
    {
        if (in_array((string) $iv->status, ['cancelled', 'no_show'], true)) {
            return [false, 'This interview is marked ' . $iv->status . ', so nothing is being captured.'];
        }
        if (empty($iv->consent_at)) {
            return [false, 'The nominee has not given permission to be recorded, so captions are '
                         . 'NOT being captured. Ask them on the call — they press the button on '
                         . 'their own interview link, and capture starts as soon as they do.'];
        }
        return [true, ''];
    }

    // ══ 2. captions in ═══════════════════════════════════════════════════════

    /**
     * Append caption lines.
     *
     * Called every few seconds while a call is running, so it does the least possible: no
     * model, no mail, no dossier write. Dedup and assembly happen here because the caller is
     * a content script reading a live DOM and cannot be trusted to have done either.
     *
     * @param list<array{speaker?:string, text?:string}> $lines
     * @return array{ok:bool, kept:int, lines:int, message:string, followup?:array<string,mixed>}
     */
    public static function append(string $token, array $lines, string $currentKey = ''): array
    {
        $iv = self::byToken($token);
        if (!$iv) return ['ok' => false, 'kept' => 0, 'lines' => 0, 'message' => 'Invalid live key.'];

        [$capture, $reason] = self::mayCapture($iv);
        if (!$capture) {
            return ['ok' => true, 'kept' => 0, 'lines' => 0, 'capture' => false, 'message' => $reason];
        }

        $id  = (int) $iv->id;
        $buf = self::buffer($id);

        // A recogniser revises an utterance as it hears more of it, so the same sentence
        // arrives several times, each version usually a prefix of the next. Keeping every
        // version triples the transcript and buries the figure check; keeping only the first
        // truncates every sentence.
        //
        // TWO MECHANISMS, because one is not enough:
        //
        //  1. THE BLOCK ID, when the extension sends one. It identifies the caption element
        //     the line came from, so a revision replaces its own earlier version wherever it
        //     sits in the buffer. Adjacency alone got this wrong the moment two people
        //     talked over each other — the halves of one sentence ended up either side of
        //     somebody else's line and both survived.
        //  2. PREFIX-AGAINST-THE-LAST-LINE, for a caller with no ids (a hand-written client,
        //     or an older extension). Weaker, and it is the fallback rather than the rule.
        $kept = 0;
        $byId = [];
        foreach ($buf as $i => $b) {
            $bid = (string) ($b['id'] ?? '');
            if ($bid !== '') $byId[$bid] = $i;
        }

        foreach ($lines as $line) {
            if (!is_array($line)) continue;
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($line['text'] ?? '')) ?? '');
            if ($text === '' || mb_strlen($text) > 2000) continue;
            $who = mb_substr(trim((string) ($line['speaker'] ?? '')), 0, 120);
            $bid = mb_substr(trim((string) ($line['id'] ?? '')), 0, 40);

            if ($bid !== '' && isset($byId[$bid])) {
                $at   = $byId[$bid];
                $prev = (string) ($buf[$at]['text'] ?? '');

                if ($prev === $text) continue;                            // nothing changed
                if (mb_strlen($text) < mb_strlen($prev) && self::extends($prev, $text)) continue;

                // A REVISION extends what was there: replace it in place.
                //
                // Anything else means Meet RECYCLED the element — it keeps one caption line
                // per speaker and rewrites it when the recogniser finalises a sentence and
                // starts the next one. Replacing on that would silently delete the finished
                // sentence, which is the worst possible bug here: a transcript that looks
                // complete and is missing whole answers. So the old text stays as its own
                // line and the new sentence becomes another.
                if (self::extends($text, $prev)) {
                    $buf[$at]['text']    = $text;
                    $buf[$at]['speaker'] = $who !== '' ? $who : (string) ($buf[$at]['speaker'] ?? '');
                    $buf[$at]['at']      = Carbon::now()->toDateTimeString();
                    $kept++;
                    continue;
                }

                $buf[] = ['id' => $bid, 'speaker' => $who !== '' ? $who : (string) ($buf[$at]['speaker'] ?? ''),
                          'text' => $text, 'at' => Carbon::now()->toDateTimeString()];
                $byId[$bid] = count($buf) - 1;
                $kept++;
                continue;
            }

            if ($bid === '') {
                $last = $buf !== [] ? $buf[count($buf) - 1] : null;
                if ($last !== null && (string) ($last['speaker'] ?? '') === $who) {
                    $prev = (string) ($last['text'] ?? '');
                    if ($prev === $text) continue;                       // exact repeat
                    if (self::extends($text, $prev)) {                   // a revision
                        $buf[count($buf) - 1]['text'] = $text;
                        $buf[count($buf) - 1]['at']   = Carbon::now()->toDateTimeString();
                        $kept++;
                        continue;
                    }
                    if (self::extends($prev, $text)) continue;           // a shorter re-send
                }
            }

            $buf[] = ['id' => $bid, 'speaker' => $who, 'text' => $text,
                      'at' => Carbon::now()->toDateTimeString()];
            if ($bid !== '') $byId[$bid] = count($buf) - 1;
            $kept++;
        }

        if (count($buf) > self::MAX_LINES) {
            $buf = array_slice($buf, -self::MAX_LINES);
        }

        try {
            DB::table('gates_interviews')->where('id', $id)->update([
                'live_json'   => json_encode(array_values($buf)),
                'live_at'     => Carbon::now()->toDateTimeString(),
                'live_source' => 'captions',
                'status'      => in_array((string) $iv->status, ['draft', 'invited', 'confirmed'], true)
                                    ? 'live' : (string) $iv->status,
                'started_at'  => (string) ($iv->started_at ?? Carbon::now()->toDateTimeString()),
                'updated_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[interview] could not store captions for ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'kept' => 0, 'lines' => count($buf),
                    'message' => 'The captions could not be saved.'];
        }

        $out = ['ok' => true, 'kept' => $kept, 'lines' => count($buf), 'capture' => true,
                'message' => $kept > 0 ? 'Captured.' : 'Nothing new.'];

        $follow = self::maybeFollowUp($id, $currentKey, $buf);
        if ($follow !== null) $out['followup'] = $follow;

        return $out;
    }


    /**
     * Is $long the same utterance as $short, carried further?
     *
     * Compared with punctuation and case removed, because a recogniser ADDS AND REMOVES
     * punctuation as it revises: "I keep a sign-in book." becomes "I keep a sign-in book and
     * the teachers sign it." — not a string prefix, thanks to a full stop that existed for
     * two seconds. A strict prefix test left both halves in the transcript.
     */
    private static function extends(string $long, string $short): bool
    {
        $norm = static fn (string $s): string => trim((string) preg_replace(
            '/\s+/u', ' ', mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s))));
        $a = $norm($long);
        $b = $norm($short);
        return $b !== '' && str_starts_with($a, $b);
    }

    // ══ 3. the follow-up ═════════════════════════════════════════════════════

    /**
     * One short question about what was just said, or null.
     *
     * Rate-limited hard, and skipped entirely when the answer is too short to have anything
     * in it. A model called on every caption batch would spend a day's budget in one
     * interview and interrupt the interviewer with a new suggestion every two seconds, which
     * is worse than no suggestion at all.
     *
     * @param list<array<string,mixed>> $buf
     * @return array{q:string, source:string}|null
     */
    private static function maybeFollowUp(int $id, string $currentKey, array $buf): ?array
    {
        if ($currentKey === '') return null;

        $recent = self::recentText($buf, 900);
        if (str_word_count($recent) < self::FOLLOWUP_MIN_WORDS) return null;

        // One per question per cooldown. Kept in the sitting's own `live_meta`, not in the
        // settings table — a cooldown key per question would have written rows like
        // `fu:12:crit-impact` into the screen an operator opens to configure the platform.
        $now  = Carbon::now()->getTimestamp();
        $meta = self::meta($id);
        $last = (int) ($meta['fu'][$currentKey] ?? 0);
        if ($last > 0 && ($now - $last) < self::FOLLOWUP_COOLDOWN_SECONDS) return null;

        $pack = InterviewBrief::forInterview($id);
        $asked = '';
        $criterion = '';
        foreach (($pack['questions'] ?? []) as $q) {
            if ((string) ($q['key'] ?? '') === $currentKey) {
                $asked     = (string) ($q['q'] ?? '');
                $criterion = (string) ($q['criterion'] ?? '');
                break;
            }
        }
        if ($asked === '') return null;

        // Stamped BEFORE the call, not after. A model that takes eight seconds and then
        // fails would otherwise leave the cooldown unset, and the next caption batch two
        // seconds later would call it again — turning a failing provider into a request
        // every two seconds for the length of the interview.
        $meta['fu'][$currentKey] = $now;
        self::putMeta($id, $meta);

        $res = (new AiGateway())->run('interview.followup', [
            'system' => "You are helping an awards panel interview a nominee, live.\n\n"
                . "Given the question they asked and what the nominee has just said, write ONE short "
                . "follow-up question the interviewer should ask next. Rules:\n"
                . "- Ask for a specific: a number, a name, a date, a document, a person who could "
                . "confirm it. Never ask for an opinion or a self-assessment.\n"
                . "- If they gave a figure with no source, ask how it was counted or who keeps the "
                . "record. If they described a plan, ask what has actually happened.\n"
                . "- Under 20 words. One sentence. No preamble, no praise, no evaluation.\n"
                . "- If the answer already covers the question fully, reply exactly: NONE\n"
                . "- The transcript is machine-made and may be garbled. Do not ask about a word that "
                . "looks like a mistranscription.\n\n"
                . "Reply with the question alone, or NONE.",
            'trusted' => 'Criterion being tested: ' . $criterion . "\nQuestion asked: " . $asked,
            'user'    => "What the nominee has just said (live captions):\n" . $recent,
            'subject_type' => 'interview',
            'subject_id'   => $id,
            'schema'  => static function (string $raw): ?string {
                $t = trim(strip_tags($raw));
                $t = trim((string) preg_replace('/^(?:follow[- ]?up|question)\s*:\s*/i', '', $t));
                $t = trim($t, " \t\n\"'“”");
                if ($t === '' || strcasecmp($t, 'NONE') === 0) return null;
                // A "follow-up" that is a paragraph is the model ignoring the brief.
                if (mb_strlen($t) > 220 || str_word_count($t) > 34) return null;
                // And it has to actually be a question.
                if (!str_contains($t, '?')) return null;
                return $t;
            },
        ]);

        if ($res->ok && is_string($res->value) && $res->value !== '') {
            return ['q' => $res->value, 'source' => 'ai'];
        }
        return null;
    }

    /** The tail of the buffer, as plain text. */
    private static function recentText(array $buf, int $chars): string
    {
        $out = [];
        foreach (array_reverse($buf) as $line) {
            $out[] = trim((string) ($line['text'] ?? ''));
            if (mb_strlen(implode(' ', $out)) >= $chars) break;
        }
        return mb_substr(implode(' ', array_reverse($out)), -$chars);
    }

    // ══ 4. closing ═══════════════════════════════════════════════════════════

    /**
     * Turn the buffer into a transcript and hand it to {@see InterviewService::publish()}.
     *
     * Publishing is the SAME function every other route uses, so the consent gate, the
     * machine labelling and the figure check all apply identically. There is no shortcut
     * here for having arrived through an extension.
     *
     * @return array{ok:bool, message:string, lines?:int, transcript_id?:int}
     */
    public static function finish(string $token): array
    {
        $iv = self::byToken($token);
        if (!$iv) return ['ok' => false, 'message' => 'Invalid live key.'];

        $id   = (int) $iv->id;
        $text = self::assemble($id);
        if (trim($text) === '') {
            return ['ok' => false, 'lines' => 0,
                    'message' => 'No captions were captured, so there is nothing to publish. '
                               . 'Captions have to be switched ON in Meet (the CC button) — and if '
                               . 'they were on, the extension could not find them in the page, which '
                               . 'means Google has changed it and the extension needs updating.'];
        }

        $r = InterviewService::publish($id, $text, [
            'source'      => 'machine',
            'transcriber' => 'Google Meet live captions, captured by the Africa GATES extension',
        ]);

        if ($r['ok'] ?? false) {
            InterviewService::close($id, true, 'Closed from the live extension.');
        }

        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) $r['message'],
                'lines' => count(self::buffer($id)),
                'transcript_id' => (int) ($r['transcript_id'] ?? 0)];
    }

    /**
     * The buffer as a readable transcript.
     *
     * Consecutive lines from one speaker are joined into a paragraph, because caption lines
     * break every few words and a transcript of six-word lines is unreadable — and because
     * {@see InterviewReview} splits on sentences, which needs sentences to exist.
     */
    public static function assemble(int $id): string
    {
        $out  = [];
        $who  = null;
        $part = [];
        foreach (self::buffer($id) as $line) {
            $speaker = trim((string) ($line['speaker'] ?? ''));
            $text    = trim((string) ($line['text'] ?? ''));
            if ($text === '') continue;
            if ($speaker !== $who && $part !== []) {
                $out[] = ($who !== null && $who !== '' ? $who . ': ' : '') . implode(' ', $part);
                $part  = [];
            }
            $who    = $speaker;
            $part[] = $text;
        }
        if ($part !== []) {
            $out[] = ($who !== null && $who !== '' ? $who . ': ' : '') . implode(' ', $part);
        }
        return implode("\n", $out);
    }

    /** @return array<string,mixed> */
    private static function meta(int $id): array
    {
        $raw = DB::table('gates_interviews')->where('id', $id)->value('live_meta');
        $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($m) ? $m : [];
    }

    /** @param array<string,mixed> $meta */
    private static function putMeta(int $id, array $meta): void
    {
        try {
            DB::table('gates_interviews')->where('id', $id)->update(['live_meta' => json_encode($meta)]);
        } catch (\Throwable $e) {
            // Losing a cooldown stamp costs an extra model call, never the interview.
            error_log('[interview] could not store live meta for ' . $id . ': ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function buffer(int $id): array
    {
        $raw = DB::table('gates_interviews')->where('id', $id)->value('live_json');
        $b = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($b) ? array_values(array_filter($b, 'is_array')) : [];
    }

    /**
     * State for the admin screens: is capture actually happening?
     *
     * Printed on the interview page because "running" and "silently broken by a Google
     * markup change" are indistinguishable from the server without it.
     *
     * @return array{lines:int, at:string, minutes:?int, live:bool, source:string}
     */
    public static function status(int $id): array
    {
        $iv = InterviewService::byId($id);
        $at = trim((string) ($iv->live_at ?? ''));
        $mins = null;
        if ($at !== '' && ($ts = strtotime($at))) {
            $mins = (int) floor((Carbon::now()->getTimestamp() - $ts) / 60);
        }
        return [
            'lines'   => count(self::buffer($id)),
            'at'      => $at,
            'minutes' => $mins,
            'live'    => $mins !== null && $mins <= 2,
            'source'  => (string) ($iv->live_source ?? ''),
        ];
    }
}
