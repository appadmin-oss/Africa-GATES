<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Telling people what happened, and telling everyone else once.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A CONGRATULATION THAT ARRIVES MONTHS LATE IS WORSE THAN SILENCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This codebase already holds the rule for an award: `CycleMaterialiser` withholds
 * every notification for a cycle promoted more than `ANNOUNCE_GRACE_DAYS` after its
 * date, because a months-old result mailed today reads as an apology for having
 * forgotten. A challenge is the same shape and the same window, deliberately — an
 * operator who runs a backfill in January must not text somebody about October.
 *
 * And the staleness check travels with the SEAL on the award side: a cycle that
 * withheld its notifications also withheld its seal, because a seal claims an
 * announcement. Here the equivalent is {@see winnersAnnounced()} — nothing is marked
 * announced that nobody was told about.
 *
 * ── EVERY SEND IS IDEMPOTENT, BECAUSE THE TRIGGER IS NOT ────────────────────
 *
 * `ChallengeService::recount()` runs on every confirmation, approval and refund, and a
 * qualification can be reached more than once in a race. Sending on "has a standing"
 * would text a winner twice. The ledger row is the guard: a `won_notified` event
 * exists or it does not, and the write happens before the send so a crash in the
 * mailer cannot produce a second message on the next pass.
 */
final class ChallengeAftercare
{
    /** The same window the award side uses. One number, read from there. */
    public static function graceDays(): int
    {
        return CycleMaterialiser::ANNOUNCE_GRACE_DAYS;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // One person
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Tell somebody they have a place, once.
     *
     * Called after `qualify()` assigns a standing. Returns the channels used, or `[]`
     * when there was nothing to do — already told, too late to tell, or no way to
     * reach them.
     */
    public static function tellWinner(int $entryId, ?SmsService $sms = null, ?OtpService $mail = null): array
    {
        $e = ChallengeService::entry($entryId);
        if (!$e || $e->standing === null) return [];
        if ($e->status === E::E_DISQUALIFIED) return [];

        $c = ChallengeService::find((int) $e->challenge_id);
        if (!$c) return [];

        // ── THE LEDGER IS THE GUARD, AND IT IS WRITTEN FIRST ────────────────
        // `recount()` runs on every confirmation and refund, so this is reached many
        // times for one winner. Claiming the row before sending means a mailer that
        // throws cannot produce a second message on the next pass — the cost of a
        // missed congratulation is a support call, the cost of six is a complaint.
        if (!self::claim((int) $c->id, $entryId, 'won_notified')) return [];

        if (self::tooLate($c)) {
            // Recorded, not sent. An operator backfilling in January must not text
            // somebody about a race that closed in October.
            self::event((int) $c->id, $entryId, 'won_notice_withheld',
                ['reason' => 'past the ' . self::graceDays() . '-day window']);

            return [];
        }

        $u = DB::table('gates_users')->where('id', $e->user_id)->first(['name', 'email', 'phone']);
        if (!$u) return [];

        $copy  = ChallengeCopy::for((array) $c);
        $first = explode(' ', trim((string) ($u->name ?? '')))[0] ?: 'there';
        $prize = trim($copy['prize_big'] . ' ' . $copy['prize_unit']);

        // What they are owed, where to look, and what happens next. No exclamation
        // mark, and no "congratulations" doing the work of a fact.
        $body = $first . ', you came #' . (int) $e->standing . ' in ' . $c->title . '. '
            . 'That is ' . $prize . '. We pay within 7 days to an account in your name — '
            . 'check the payout details in your account.';

        return self::send($u, $body, 'You placed #' . (int) $e->standing . ' in ' . $c->title,
            $sms, $mail, (int) $c->id, $entryId, 'won_notified');
    }

    /**
     * Tell somebody their entry was removed, and why.
     *
     * Not optional and not softened. A person who was in the running and is no longer
     * will notice; learning it from a silent row is how a complaint becomes a dispute.
     * The reason is the operator's own words, which is why {@see
     * ChallengeService::disqualify()} refuses an empty one.
     */
    public static function tellDisqualified(int $entryId, ?SmsService $sms = null, ?OtpService $mail = null): array
    {
        $e = ChallengeService::entry($entryId);
        if (!$e || $e->status !== E::E_DISQUALIFIED) return [];

        $c = ChallengeService::find((int) $e->challenge_id);
        if (!$c) return [];
        if (!self::claim((int) $c->id, $entryId, 'dq_notified')) return [];

        $u = DB::table('gates_users')->where('id', $e->user_id)->first(['name', 'email', 'phone']);
        if (!$u) return [];

        $first  = explode(' ', trim((string) ($u->name ?? '')))[0] ?: 'there';
        $reason = trim((string) ($e->disqualify_reason ?? '')) ?: 'the entry did not meet the rules';

        $body = $first . ', your entry in ' . $c->title . ' has been removed: ' . $reason . '. '
            . 'If you think that is wrong, reply to this or contact support — we will look again.';

        return self::send($u, $body, 'Your entry in ' . $c->title, $sms, $mail,
            (int) $c->id, $entryId, 'dq_notified');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Everybody else
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Post the winners to the Pulse, once per challenge.
     *
     * ── WHAT IS PUBLISHED, AND WHAT IS NOT ──────────────────────────────────
     *
     * First name and initial, and the place. The same shape as the challenge page's own
     * winners list, for the same reason: a full name beside a published cash amount is
     * an invitation addressed to whoever reads it. No email, no phone, no area here —
     * the page has the area in context and a feed post does not.
     *
     * It is a thread like any other, so it is moderated like any other and appears
     * wherever the Pulse appears. A second feed with its own rules is a second thing to
     * keep true.
     */
    public static function postWinners(int $challengeId, ?CommunityService $community = null): array
    {
        $c = ChallengeService::find($challengeId);
        if (!$c) return ['ok' => false, 'code' => 'NO_CHALLENGE'];
        if (!self::claim($challengeId, null, 'pulse_posted')) return ['ok' => false, 'code' => 'ALREADY'];

        if (self::tooLate($c)) {
            self::event($challengeId, null, 'pulse_withheld',
                ['reason' => 'past the ' . self::graceDays() . '-day window']);

            return ['ok' => false, 'code' => 'STALE'];
        }

        $winners = ChallengeService::winners($challengeId, 20);
        if ($winners === []) return ['ok' => false, 'code' => 'NO_WINNERS'];

        $copy  = ChallengeCopy::for((array) $c);
        $prize = trim($copy['prize_big'] . ' ' . $copy['prize_unit']);

        $lines = [];
        foreach ($winners as $w) {
            $lines[] = '#' . $w['standing'] . '  ' . $w['name']
                . ($w['where'] !== '' ? ' · ' . $w['where'] : '');
        }

        $body = $c->title . ' is done. ' . count($winners)
            . ($winners === [] ? '' : (count($winners) === 1 ? ' person' : ' people'))
            . ' took a place, each winning ' . $prize . ".\n\n"
            . implode("\n", $lines)
            . "\n\nThe rules it ran under are on the challenge page.";

        $svc = $community ?? self::community();
        if ($svc === null) return ['ok' => false, 'code' => 'NO_COMMUNITY'];

        $r = $svc->postThread([
            'title' => $c->title . ' — the winners',
            'body'  => $body,
            'author_name'  => 'Africa GATES',
            'author_email' => (string) (\AfricaGates\Support\Env::get('MAIL_FROM', 'noreply@localhost')),
        ], '', true);

        self::event($challengeId, null, 'pulse_posted', ['ok' => (bool) ($r['ok'] ?? false)]);

        return ['ok' => (bool) ($r['ok'] ?? false), 'code' => 'POSTED', 'winners' => count($winners)];
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Is this challenge too old to be talking about?
     *
     * Measured from the CLOSE, not from when somebody pressed the button: an operator
     * running a backfill is exactly the case this exists for, and "now" is always
     * recent from the backfill's point of view.
     */
    private static function tooLate(object $c): bool
    {
        $ends = trim((string) ($c->ends_at ?? ''));
        if ($ends === '') return false;   // no close recorded; nothing to be late about

        try {
            $late = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->diff(new \DateTimeImmutable($ends, new \DateTimeZone('UTC')));

            return $late->invert === 1 && (int) $late->days > self::graceDays();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Claim a one-time event, atomically.
     *
     * The UNIQUE on `gates_challenge_events` is what makes this a claim rather than a
     * check: two passes racing both insert, one gets a constraint violation, and only
     * one send happens. A `SELECT` then `INSERT` is the read-then-write gap this
     * codebase has already paid for twice.
     */
    private static function claim(int $challengeId, ?int $entryId, string $kind): bool
    {
        // The key carries its own scope, because `entry_id` is NULL for a
        // challenge-level event and NULL never collides with NULL in a UNIQUE index on
        // either engine — two Pulse posts would both insert.
        $key = $kind . ':' . ($entryId !== null ? 'e' . $entryId : 'c' . $challengeId);

        try {
            DB::table('gates_challenge_events')->insert([
                'challenge_id' => $challengeId, 'entry_id' => $entryId, 'kind' => $kind,
                'once_key' => $key, 'meta' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string> the channels actually used
     */
    private static function send(object $u, string $body, string $subject,
        ?SmsService $sms, ?OtpService $mail, int $challengeId, int $entryId, string $kind): array
    {
        $phone = Phone::normalize((string) ($u->phone ?? ''));
        $email = trim((string) ($u->email ?? ''));

        $sms ??= SmsService::boot();
        $used  = [];

        foreach (SmsService::channelPlan($email !== '' ? $email : null, $phone, $sms) as $channel) {
            try {
                if ($channel === 'email') {
                    if ($mail === null) continue;
                    $mail->sendBranded($email, $subject, '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>',
                        $body, 'challenge');
                    $used[] = 'email';
                    continue;
                }
                if ($phone !== null) { $sms->deliver($channel, $phone, $body, 'challenge'); $used[] = $channel; }
            } catch (\Throwable $e) {
                // One channel failing is not a reason to skip the others, and a failed
                // send must never roll the claim back — a second attempt would be a
                // second message to somebody who may already have had the first.
            }
        }

        self::event($challengeId, $entryId, $kind . '_sent', ['channels' => $used]);

        return $used;
    }

    private static function community(): ?CommunityService
    {
        try {
            return new CommunityService(new SpamService());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function event(int $challengeId, ?int $entryId, string $kind, array $meta = []): void
    {
        try {
            DB::table('gates_challenge_events')->insert([
                'challenge_id' => $challengeId, 'entry_id' => $entryId, 'kind' => $kind,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // The ledger is not what anybody's prize depends on.
        }
    }
}
