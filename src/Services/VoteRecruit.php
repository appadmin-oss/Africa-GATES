<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "Bring your people": after somebody votes, a link of their own for that nominee, and a
 * count of the people who voted through it.
 *
 * The owner's brief (27 Oct 2026): make people want to vote, and make sure people bring other
 * people to vote. A vote is the moment somebody cares most about a nominee, and until this the
 * page thanked them and let them go. Now it hands them a link that is theirs and tells them,
 * later, how many people they brought — which is the one figure that makes asking a friend
 * feel like it did something.
 *
 * ── WHAT COUNTS AS BRINGING SOMEBODY ─────────────────────────────────────────
 *
 * A PERSON who cast a counted vote for the SAME nominee after arriving through the link, once
 * each, and never the link's own owner: voting twice through your own link brings nobody. A
 * person is the ledger's own hash (VoteService::voterHash), so "brought 4 people" counts people
 * exactly the way the scoring's people term does. A paid order is credited when its votes are
 * MINTED, never at checkout — an abandoned checkout brought nobody.
 *
 * ── WHAT IT NEVER DOES ───────────────────────────────────────────────────────
 *
 * It moves no tally and no score, and it pays nothing: it is a count for the supporter's own
 * screen. It never names who came through a link — the count is all anybody sees. And it is
 * identical for every nominee (D32): every voter gets the same link, whoever they voted for.
 */
final class VoteRecruit
{
    public const SESSION_KEY = 'vote_via';

    /** A code a person can read aloud: no 0/O, 1/I/L. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vote_recruits') && SchemaHas::table('gates_vote_recruit_credits');
    }

    /** `?via=` on a nominee page: kept for this browser until a vote spends it. */
    public static function capture(string $raw): void
    {
        $code = self::normalise($raw);
        if ($code === '' || !isset($_SESSION) || !is_array($_SESSION)) return;
        $_SESSION[self::SESSION_KEY] = $code;
    }

    public static function fromSession(): string
    {
        return isset($_SESSION[self::SESSION_KEY]) && is_string($_SESSION[self::SESSION_KEY])
            ? self::normalise($_SESSION[self::SESSION_KEY]) : '';
    }

    public static function normalise(string $raw): string
    {
        $c = strtolower(trim($raw));
        return preg_match('/^[a-z0-9]{6,16}$/', $c) === 1 ? $c : '';
    }

    /**
     * The voter's link for a nominee: made once, the same one every time after.
     *
     * @return array{code:string, brought:int}|null
     */
    public static function forVoter(string $emailHash, int $nomineeId): ?array
    {
        if (!self::ready() || $nomineeId < 1 || !preg_match('/^[a-f0-9]{64}$/', $emailHash)) return null;
        try {
            $row = DB::table('gates_vote_recruits')->where('email_hash', $emailHash)->where('nominee_id', $nomineeId)->first();
            if (!$row) {
                for ($i = 0; $i < 5 && !$row; $i++) {
                    DB::table('gates_vote_recruits')->insertOrIgnore([
                        'code' => self::mint(), 'email_hash' => $emailHash, 'nominee_id' => $nomineeId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $row = DB::table('gates_vote_recruits')->where('email_hash', $emailHash)->where('nominee_id', $nomineeId)->first();
                }
            }
            if (!$row) return null;
            return ['code' => (string) $row->code, 'brought' => self::brought((int) $row->id)];
        } catch (\Throwable) {
            return null;
        }
    }

    /** How many people a link brought. */
    public static function brought(int $recruitId): int
    {
        try {
            return (int) DB::table('gates_vote_recruit_credits')->where('recruit_id', $recruitId)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The same count, by code — for a link's own page. */
    public static function broughtByCode(string $code): ?int
    {
        $code = self::normalise($code);
        if ($code === '' || !self::ready()) return null;
        try {
            $r = DB::table('gates_vote_recruits')->where('code', $code)->first();
            return $r ? self::brought((int) $r->id) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A counted vote arrived through a link. True when it brought somebody new.
     *
     * Refused, quietly, for a code that does not exist, a code for a DIFFERENT nominee, and the
     * link's own owner. A second vote by the same person counts once (UNIQUE recruit+voter).
     */
    public static function credit(string $code, int $nomineeId, string $voterHash, string $voteRef): bool
    {
        $code = self::normalise($code);
        if ($code === '' || !self::ready()) return false;
        try {
            $r = DB::table('gates_vote_recruits')->where('code', $code)->first();
            if (!$r || (int) $r->nominee_id !== $nomineeId || hash_equals((string) $r->email_hash, $voterHash)) return false;
            return DB::table('gates_vote_recruit_credits')->insertOrIgnore([
                'recruit_id' => (int) $r->id, 'voter_hash' => $voterHash,
                'vote_ref' => substr($voteRef, 0, 64), 'created_at' => date('Y-m-d H:i:s'),
            ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A paid order started from a link: remembered by its reference until it is minted. */
    public static function pend(string $paymentRef, string $code): void
    {
        $code = self::normalise($code);
        if ($code === '' || $paymentRef === '' || !SchemaHas::table('gates_vote_recruit_pending')) return;
        try {
            DB::table('gates_vote_recruit_pending')->insertOrIgnore([
                'payment_ref' => substr($paymentRef, 0, 64), 'code' => $code, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {}
    }

    /** The order's votes were minted: credit the link it came from, if any. */
    public static function creditPaid(int $donationId): bool
    {
        if (!SchemaHas::table('gates_vote_recruit_pending')) return false;
        try {
            $don = DB::table('gates_donations')->where('id', $donationId)->first();
            if (!$don || trim((string) ($don->donor_email ?? '')) === '') return false;
            $p = DB::table('gates_vote_recruit_pending')->where('payment_ref', (string) $don->payment_ref)->first();
            if (!$p) return false;
            return self::credit((string) $p->code, (int) ($don->intent_nominee_id ?? 0),
                VoteService::voterHash((string) $don->donor_email), 'paid:' . (string) $don->payment_ref);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function mint(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        return $out;
    }
}
