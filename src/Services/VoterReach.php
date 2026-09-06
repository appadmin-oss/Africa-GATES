<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * HOW MANY PEOPLE BACKED A NOMINEE — not how many transactions did.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SERVICE AND NOT `COUNT(DISTINCT voter_email_hash)`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * That one-liner is the obvious implementation and it is wrong — wrong in exactly the
 * direction the reach basis exists to close. Three of the five paths that mint a vote row
 * write a SYNTHETIC, RANDOMISED hash:
 *
 *     VoteService        sha256(email)                    a person, OTP-gated
 *     VoteRecoveryService the recovered voter's own hash   a person
 *     PaidVoteService    'paidvote:<order>:<random>'      ONE PER ORDER
 *     BonusVoteService   'bonus:<order>:<random>'         an operator's grant
 *     PointsService      'points:<userId>:<random>'       a redemption
 *
 * So a distinct count over that column answers "how many transactions", and one buyer
 * placing ten orders reads as ten supporters. The whole point of weighting reach at 70%
 * is that money cannot buy it; a naive count hands it straight back, and every screen
 * would agree with itself while doing so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT COUNTS AS ONE PERSON
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   A PAID ORDER RESOLVES TO ITS BUYER.  `donation_id` → `gates_donations.donor_email`,
 *                                        hashed through {@see VoteService::voterHash()} —
 *                                        the SAME function an organic vote is hashed with,
 *                                        deliberately and with no prefix, so somebody who
 *                                        voted once and then bought votes is ONE person
 *                                        and not two. Ten orders collapse to one.
 *   AN UNRESOLVABLE ORDER IS ONE PERSON.  A donation row that has gone (or predates the
 *                                        column) falls back to the order itself. At most
 *                                        one, never more: the failure direction has to be
 *                                        the one that cannot inflate reach.
 *   A POINTS REDEMPTION IS ITS MEMBER.    The random suffix is dropped; `points:<userId>`
 *                                        is a person, and redeeming twice is not two.
 *   A GRANT IS NOBODY.                    A bonus vote is awarded by an operator. Nobody
 *                                        chose to cast it, so it adds no reach — while its
 *                                        weight still counts toward the TOTAL, which is
 *                                        the 30% half. That asymmetry is the design: a
 *                                        grant is support the platform gave, not support
 *                                        the public gave.
 *   A FRAUD-FLAGGED ROW IS NOBODY.        Already excluded from trust everywhere else.
 *
 * ── AND WHY IT IS NOT A STORED COLUMN ───────────────────────────────────────
 *
 * `gates_nominees.vote_count` is a maintained counter and every path that mints a vote has
 * to remember to move it. A second such counter is a second thing that can silently drift
 * from the rows it claims to describe — and this one decides 315 of 1000 points. Counted
 * from the votes themselves, on demand, it cannot be wrong about them.
 */
final class VoterReach
{
    /**
     * Unique verified people behind each nominee. Missing ids come back as 0.
     *
     * One query for the votes and one for the buyers, whatever the number of nominees —
     * this runs once per category recompute and a per-nominee query would make it N+1
     * against the largest table on the platform.
     *
     * @param  list<int> $nomineeIds
     * @return array<int,int>
     */
    public static function forNominees(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $nomineeIds)));
        $out = array_fill_keys($ids, 0);
        if (!$ids) return $out;

        try {
            $rows = DB::table('gates_votes')
                ->whereIn('nominee_id', $ids)
                ->where('fraud_flag', 0)
                ->get(['nominee_id', 'voter_email_hash', 'vote_type', 'donation_id']);
        } catch (\Throwable) {
            // Reach is a scoring input, not a safety one. A schema this cannot read must
            // leave the caller with zeroes it can see rather than an exception mid-release.
            return $out;
        }

        $buyers = self::buyerHashes($rows);

        /** @var array<int,array<string,true>> $seen */
        $seen = [];
        foreach ($rows as $r) {
            $person = self::personKey($r, $buyers);
            if ($person === null) continue;
            $seen[(int) $r->nominee_id][$person] = true;
        }

        foreach ($seen as $nomineeId => $people) $out[$nomineeId] = count($people);

        return $out;
    }

    /** Unique verified people behind one nominee. */
    public static function forNominee(int $nomineeId): int
    {
        return self::forNominees([$nomineeId])[$nomineeId] ?? 0;
    }

    /**
     * The person a row belongs to, or null when it belongs to nobody.
     *
     * @param array<int,string> $buyers donation id → buyer's voter hash
     */
    private static function personKey(object $r, array $buyers): ?string
    {
        $hash = (string) ($r->voter_email_hash ?? '');
        $type = (string) ($r->vote_type ?? 'standard');

        // A grant is support the platform gave, not support the public gave. Checked on
        // BOTH the type and the hash prefix because the two are written by different
        // services and only one of them has to be wrong for a grant to buy reach.
        if ($type === 'bonus' || str_starts_with($hash, 'bonus:')) return null;

        if (str_starts_with($hash, 'paidvote:')) {
            $don = (int) ($r->donation_id ?? 0);
            // The buyer where we can name them — colliding with their organic vote by
            // construction — and the ORDER where we cannot. Never the row, which is what
            // the random suffix would make it.
            return $buyers[$don] ?? ($don > 0 ? 'order:' . $don : null);
        }

        if (str_starts_with($hash, 'points:')) {
            // 'points:<userId>:<random>' → 'points:<userId>'. A member redeeming twice is
            // one member; the suffix exists only to clear the one-vote unique key.
            $parts = explode(':', $hash);
            return isset($parts[1]) && $parts[1] !== '' ? 'points:' . $parts[1] : null;
        }

        return $hash !== '' ? $hash : null;
    }

    /**
     * Buyer hash for every paid row in the set, in one query.
     *
     * @param  iterable<object> $rows
     * @return array<int,string> donation id → VoteService::voterHash(donor_email)
     */
    private static function buyerHashes(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (str_starts_with((string) ($r->voter_email_hash ?? ''), 'paidvote:')) {
                $d = (int) ($r->donation_id ?? 0);
                if ($d > 0) $ids[$d] = true;
            }
        }
        if (!$ids) return [];

        try {
            $dons = DB::table('gates_donations')
                ->whereIn('id', array_keys($ids))->get(['id', 'donor_email']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($dons as $d) {
            $email = trim((string) ($d->donor_email ?? ''));
            if ($email !== '') $out[(int) $d->id] = VoteService::voterHash($email);
        }

        return $out;
    }
}
