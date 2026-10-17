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
 *     PointsService      'points:<userId>:<random>'       a redemption — and it is
 *                                                         written with vote_type
 *                                                         'bonus', because the ENUM
 *                                                         has no 'points' in it. The
 *                                                         PREFIX is what tells a
 *                                                         member's own choice from an
 *                                                         operator's grant.
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
     * @param  list<int> $nomineeIds
     * @return array<int,int>
     */
    public static function forNominees(array $nomineeIds): array
    {
        $out = [];
        foreach (self::detailFor($nomineeIds) as $id => $d) $out[$id] = $d['people'];

        return $out;
    }

    /**
     * People AND the rows they were counted from, per nominee.
     *
     * ══ WHY THE ROW COUNT IS PUBLISHED ALONGSIDE THE PEOPLE COUNT ═══════════
     *
     * Because `people = 0` has two completely different meanings and the scorer has to be
     * able to tell them apart:
     *
     *   · THE ROWS ARE THERE AND THEY BELONG TO NOBODY. Every vote was an operator's
     *     grant. Zero reach is the correct, intended answer — {@see personKey()}.
     *   · THERE ARE NO ROWS AT ALL, while `gates_nominees.vote_count` says there is
     *     support. The tally was imported, restored or seeded from before this platform
     *     held rows. Nothing about this nominee has been measured; zero is not an answer,
     *     it is the absence of one.
     *
     * That distinction used to be invisible and it did not matter, because the scale was
     * one category: if a category had no rows, NOBODY in it had rows, the cohort maximum
     * came out zero and {@see CpiService::reachPart()} fell back to the tally for the whole
     * field at once. Once the scale is the whole edition, one category with rows keeps the
     * maximum above zero and every rowless category is silently scored at zero people —
     * seventy per cent of the community half, gone, for a data-migration reason, with
     * nothing on any screen. Precisely the shape of fault this codebase keeps shipping.
     *
     * So the row count travels with the people count, and
     * {@see \AfricaGates\Services\NomineeScoringService::scoreCategory()} raises
     * `reach_unmeasured` on the nominees it applies to.
     *
     * Fraud-flagged rows are excluded from `people` and INCLUDED in `rows`: a nominee whose
     * votes were all flagged has been measured and found to have nobody, which is a
     * verdict rather than a gap.
     *
     * One query for the votes and one for the buyers, whatever the number of nominees —
     * this runs once per edition recompute and a per-nominee query would make it N+1
     * against the largest table on the platform.
     *
     * @param  list<int> $nomineeIds
     * @return array<int, array{people:int, rows:int}>
     */
    public static function detailFor(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $nomineeIds)));
        $out = array_fill_keys($ids, ['people' => 0, 'rows' => 0]);
        if (!$ids) return $out;

        try {
            $rows = DB::table('gates_votes')
                ->whereIn('nominee_id', $ids)
                ->get(['nominee_id', 'voter_email_hash', 'vote_type', 'donation_id', 'fraud_flag']);
        } catch (\Throwable) {
            // Reach is a scoring input, not a safety one. A schema this cannot read must
            // leave the caller with zeroes it can see rather than an exception mid-release.
            return $out;
        }

        $buyers = self::buyerHashes($rows);

        /** @var array<int,array<string,true>> $seen */
        $seen = [];
        foreach ($rows as $r) {
            $nid = (int) $r->nominee_id;
            $out[$nid]['rows'] = ($out[$nid]['rows'] ?? 0) + 1;

            if ((int) ($r->fraud_flag ?? 0) !== 0) continue;

            $person = self::personKey($r, $buyers);
            if ($person === null) continue;
            $seen[$nid][$person] = true;
        }

        foreach ($seen as $nomineeId => $people) $out[$nomineeId]['people'] = count($people);

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

        // ── THE HASH PREFIX IS READ BEFORE THE TYPE, AND HAS TO BE ──────────────
        //
        // `gates_votes.vote_type` is ENUM('standard','bonus','paid') and there is no
        // 'points' in it, so {@see PointsService::redeemForVote()} writes a redemption as
        // `bonus` — the only value left that is not a lie about money. The prefix is the
        // only thing that tells the two apart.
        //
        // Tested after the grant check, this whole branch was UNREACHABLE for every row
        // the platform has ever written: `$type === 'bonus'` matched first and returned
        // nobody, so a member who spent their own points backing a nominee added no reach
        // — 70% of the community half — while this method's own docblock says in as many
        // words that a redemption is its member. A grant is nobody because nobody CHOSE
        // to cast it; a redemption is a choice, made by a named account, paid for out of
        // that account's balance.
        //
        // The test that was meant to hold this wrote `vote_type = 'standard'` beside a
        // `points:` hash, which is a row no service on this platform can produce — the
        // same shape of fixture that hid the TINYINT panel mark.
        if (str_starts_with($hash, 'points:')) {
            // 'points:<userId>:<random>' → 'points:<userId>'. A member redeeming twice is
            // one member; the suffix exists only to clear the one-vote unique key.
            $parts = explode(':', $hash);
            return isset($parts[1]) && $parts[1] !== '' ? 'points:' . $parts[1] : null;
        }

        if (str_starts_with($hash, 'paidvote:')) {
            $don = (int) ($r->donation_id ?? 0);
            // The buyer where we can name them — colliding with their organic vote by
            // construction — and the ORDER where we cannot. Never the row, which is what
            // the random suffix would make it.
            return $buyers[$don] ?? ($don > 0 ? 'order:' . $don : null);
        }

        // A grant is support the platform gave, not support the public gave. Checked on
        // BOTH the type and the hash prefix because the two are written by different
        // services and only one of them has to be wrong for a grant to buy reach.
        if ($type === 'bonus' || str_starts_with($hash, 'bonus:')) return null;

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
