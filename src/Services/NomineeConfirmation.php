<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The nominee's own answer: are you real, and may we put your name forward.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A NOMINATION NEEDS TWO SIGNATURES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A challenge pays money for nominations, which makes inventing people profitable.
 * `NominationStatus::countsForChallenge()` therefore requires BOTH halves: a
 * moderator's approval and a stamp on `nominee_confirmed_at`. A moderator looking at a
 * well-filled form cannot tell a real teacher from a plausible one; the person at the
 * other end of the phone number can.
 *
 * It is also the half that is about the nominee rather than about us. Somebody's name
 * is being entered into a public award by a third party. "I agree to be nominated" is
 * the only place they are asked, and it is the reason a DECLINE is a first-class
 * answer here rather than an absence. {@see decline()}.
 *
 * ── THE COLUMNS EXISTED FIRST, WHICH IS THE FAULT THIS CLOSES ───────────────
 *
 * `nominee_confirm_token`, `nominee_confirmed_at` and `confirm_sends` were added by
 * `2027_02_10_challenges.php` and, until this class, were written by NOTHING. A
 * declared field with no reader is this codebase's most expensive shape and it had
 * just acquired a seventh instance — mine. The count in `ChallengeService` read
 * `nominee_confirmed_at` and nothing ever stamped it, so no nomination could ever
 * reach `verified` and no challenge could ever pay out. Green suite throughout.
 *
 * ── THE LINK IS THE CREDENTIAL, SO IT IS TREATED AS ONE ─────────────────────
 *
 * Anybody holding the URL can answer for the nominee. So: 40 random hex characters
 * from `random_bytes`, UNIQUE-indexed, seven days, spent on use, and a resend ceiling
 * — because the send is an SMS to a number somebody ELSE typed, and an uncapped
 * resend is a way to make this platform text a stranger repeatedly. Three, which is
 * the handoff's figure.
 */
final class NomineeConfirmation
{
    /** Seven days, per §3 of the handoff. Stated to the nominee in the message. */
    public const TTL_DAYS = 7;

    /** The handoff's ceiling. The first send is not a resend. */
    public const MAX_SENDS = 3;

    // ══════════════════════════════════════════════════════════════════════════
    // Asking
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Mint a token and ask the nominee, over whichever channel we have.
     *
     * Returns `['ok', 'code', 'channels']`. It is idempotent on the TOKEN — a second
     * call reuses a live one rather than minting a second credential for one person,
     * which would leave the first working and uncountable.
     */
    public static function ask(int $nominationId, ?SmsService $sms = null, ?OtpService $mail = null): array
    {
        $n = DB::table('gates_nominations')->where('id', $nominationId)->first();
        if (!$n) return ['ok' => false, 'code' => 'NO_NOMINATION', 'channels' => []];

        if (!empty($n->nominee_confirmed_at)) {
            return ['ok' => true, 'code' => 'ALREADY_CONFIRMED', 'channels' => []];
        }

        // ── AN EXPIRED NOMINATION IS NOT RE-ASKED ───────────────────────────
        // The window runs from the nomination, not from the send (see `expired()`), so
        // minting a fresh token for an old row produces a link that is dead the moment
        // it arrives. Sending it would text a stranger to no purpose and leave the
        // nominator waiting on an answer that cannot come.
        if (self::expired($n)) return ['ok' => false, 'code' => 'EXPIRED', 'channels' => []];

        $sent = (int) ($n->confirm_sends ?? 0);
        if ($sent >= self::MAX_SENDS) {
            // Not an error to the nominator — the ceiling is about the NOMINEE, who did
            // not ask to be contacted at all. The screen says so rather than failing.
            return ['ok' => false, 'code' => 'SEND_LIMIT', 'channels' => []];
        }

        // Idempotent on the token: a second ask reuses the live one rather than minting
        // a second credential for one person, which would leave the first working and
        // uncountable.
        $token = (string) ($n->nominee_confirm_token ?? '');
        if ($token === '') $token = self::mint($nominationId);

        $phone = Phone::normalize((string) ($n->nominee_phone ?? ''), (string) ($n->country_code ?? ''));
        $email = trim((string) ($n->nominee_email ?? ''));

        $sms  ??= SmsService::boot();
        $plan   = SmsService::channelPlan($email !== '' ? $email : null, $phone, $sms);

        if ($plan === []) {
            // No way to reach them is a FACT about the nomination, recorded rather than
            // retried: a challenge entry whose nominee cannot be asked can never count,
            // and the nominator has to be told that now rather than on the closing day.
            return ['ok' => false, 'code' => 'NO_CHANNEL', 'channels' => []];
        }

        $url  = self::url($token);
        $who  = trim((string) ($n->nominator_name ?? 'Somebody'));
        $name = trim((string) ($n->nominee_name ?? 'you'));

        $body = $who . ' has nominated you for an Africa GATES award. '
            . 'Confirm you are real and happy to be nominated: ' . $url . ' '
            . '(expires in ' . self::TTL_DAYS . ' days). Not you? Open the link and say no.';

        $used = [];
        foreach ($plan as $channel) {
            try {
                if ($channel === 'email' && $mail !== null) {
                    $mail->sendBranded($email, 'Did ' . $who . ' nominate you?',
                        self::html($who, $name, $url), $body, 'nomination');
                    $used[] = 'email';
                    continue;
                }
                if ($channel !== 'email' && $phone !== null) {
                    $sms->deliver($channel, $phone, $body, 'nominee_confirm');
                    $used[] = $channel;
                }
            } catch (\Throwable $e) {
                // One channel failing must not stop the others. A nominee reachable by
                // WhatsApp and not by SMS is the ordinary case here, not an exception.
            }
        }

        if ($used === []) return ['ok' => false, 'code' => 'SEND_FAILED', 'channels' => []];

        // Counted ONCE per ask, not once per channel: the ceiling is about how many
        // times we interrupt a person, and reaching them twice in one ask is one
        // interruption.
        DB::table('gates_nominations')->where('id', $nominationId)
            ->update(['confirm_sends' => $sent + 1]);

        return ['ok' => true, 'code' => 'SENT', 'channels' => $used, 'sends' => $sent + 1];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Answering
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The nominee says yes.
     *
     * The token is burned FIRST, before anything else is written. A presented link is
     * spent even where what follows throws — otherwise a failure leaves it live in an
     * inbox for the rest of its window, and this codebase has the rule written down
     * already for password resets.
     */
    public static function confirm(string $token): array
    {
        $n = self::byToken($token);
        if (!$n) return ['ok' => false, 'code' => 'INVALID'];
        if (self::expired($n)) return ['ok' => false, 'code' => 'EXPIRED'];

        $now = date('Y-m-d H:i:s');

        DB::table('gates_nominations')->where('id', $n->id)->update([
            'nominee_confirm_token' => null,
            'nominee_confirmed_at'  => $now,
        ]);

        self::recount($n);

        return ['ok' => true, 'code' => 'CONFIRMED', 'nominee' => (string) $n->nominee_name];
    }

    /**
     * The nominee says no, and that is RECORDED rather than ignored.
     *
     * `gates_nominee_submissions.skipped_json` exists in this codebase for exactly this
     * reason, and its own migration names the harm: a panel reading a decline as "not
     * answered" treats somebody who actively refused the same as somebody who never saw
     * the message. Here the stakes are higher — a nomination left merely unconfirmed
     * will be asked again, so a person who said no gets texted twice more.
     *
     * The status goes to `rejected` with a reason, which is terminal, and the entry is
     * recounted so the nominator sees the number move. They are not told WHO declined
     * beyond the row they already filled in, and the decline reason is not published.
     */
    public static function decline(string $token): array
    {
        $n = self::byToken($token);
        if (!$n) return ['ok' => false, 'code' => 'INVALID'];
        if (self::expired($n)) return ['ok' => false, 'code' => 'EXPIRED'];

        DB::table('gates_nominations')->where('id', $n->id)->update([
            'nominee_confirm_token' => null,
            'nominee_confirmed_at'  => null,
            'status'                => NS::REJECTED,
            'decision_reason'       => 'The nominee declined to be nominated.',
        ]);

        self::recount($n);

        return ['ok' => true, 'code' => 'DECLINED'];
    }

    /** The nomination behind a presented link, for the page that asks the question. */
    public static function peek(string $token): ?object
    {
        $n = self::byToken($token);

        return ($n && !self::expired($n)) ? $n : null;
    }

    // ══════════════════════════════════════════════════════════════════════════

    private static function byToken(string $token): ?object
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;

        return DB::table('gates_nominations')->where('nominee_confirm_token', $token)->first();
    }

    /**
     * Seven days from the nomination, not from the send.
     *
     * The alternative — a window restarting on every resend — makes a nomination
     * confirmable indefinitely by a nominator who keeps pressing resend, which is the
     * thing the ceiling above exists to bound.
     */
    private static function expired(object $n): bool
    {
        $from = (string) ($n->created_at ?? '');
        if ($from === '') return false;

        try {
            return (new \DateTimeImmutable($from))
                ->modify('+' . self::TTL_DAYS . ' days') < new \DateTimeImmutable();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function mint(int $nominationId): string
    {
        // 40 hex characters. The link is a complete credential for answering as somebody
        // else, so it is sized like one rather than like a code read off a screen.
        $token = bin2hex(random_bytes(20));

        DB::table('gates_nominations')->where('id', $nominationId)
            ->update(['nominee_confirm_token' => $token]);

        return $token;
    }

    public static function url(string $token): string
    {
        $base = rtrim((string) (getenv('APP_URL') ?: ''), '/');

        return ($base !== '' ? $base : '') . '/n/confirm/' . $token;
    }

    /** Move the challenge entry's count on, if this nomination belongs to one. */
    private static function recount(object $n): void
    {
        $entry = (int) ($n->challenge_entry_id ?? 0);
        if ($entry > 0) ChallengeService::recount($entry);
    }

    private static function html(string $who, string $name, string $url): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<p>Hello ' . $e($name) . ',</p>'
            . '<p><strong>' . $e($who) . '</strong> has nominated you for an Africa GATES award.</p>'
            . '<p>Before it can go any further we need to hear from you — that you are a real '
            . 'person, and that you are happy to have your name put forward.</p>'
            . '<p><a href="' . $e($url) . '">Answer here</a></p>'
            . '<p>The link works for ' . self::TTL_DAYS . ' days. If this was not meant for you, '
            . 'open it and say no — we will take the nomination down.</p>';
    }
}
