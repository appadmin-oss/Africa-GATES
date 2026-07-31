<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The public list of people who backed a nominee — and the single gate deciding
 * who is on it.
 *
 * ── THE RULE, IN ONE SENTENCE ────────────────────────────────────────────────
 *
 * A name appears only when someone typed it into a field that said it would appear.
 *
 * On the paid ballot the name is OPTIONAL and the microcopy states the audience, so
 * filling it in is the consent — there is no separate tickbox, because that would be
 * the same question asked twice. On the free OTP path the name is REQUIRED, so giving
 * one expresses no wish at all; those votes are never listed.
 *
 * ── WHY IT IS STILL A COLUMN AND NOT `donor_name != ''` ──────────────────────
 *
 * Because the sentence above is about the field a person SAW. Orders already in the
 * table were filled in under a label that read "Shown as the supporter name" and named
 * no audience; a reader that inferred consent from a non-empty name would publish every
 * one of them retroactively — including the people who typed a real name precisely
 * because they believed only the site would see it.
 *
 * So consent is recorded at write time, in a column with DEFAULT 0. The database's own
 * answer to "may we publish this?" is no until a request says otherwise, which excludes
 * the entire pre-existing table automatically rather than by an exception someone has to
 * remember. See `database/migrations/2026_07_31_voter_name_consent.php`.
 *
 * ── WHY THIS IS NOT A JOIN ONTO gates_donations ──────────────────────────────
 *
 * The obvious query reads confirmed paid orders and joins the nominee. It publishes
 * from the PAYMENTS table, so a refunded, clawed-back or never-minted order still shows
 * a supporter who has no votes on the board. The vote rows are what the tally is made
 * of, so they are what the list is made of — {@see PaidVoteService::mint()} copies the
 * buyer's consent onto the vote it mints, and the reader never touches a payments
 * table. It also leaves one place for a free-path opt-in to appear later without the
 * reader changing at all.
 *
 * ── DEGRADES TO SILENCE, NEVER TO A LEAK ─────────────────────────────────────
 *
 * Every failure path returns an empty list. A database without the migration, a
 * dropped column, a driver error: the section simply does not render. The opposite
 * default — "show the names we have when the consent column is unreadable" — is the
 * one bug in this file that could not be undone after it shipped.
 */
final class SupportersService
{
    /** How many names the ballot shows before "and N others". */
    public const DEFAULT_LIMIT = 12;

    /**
     * Consenting supporters of one nominee, most recent first.
     *
     * @return list<array{name:string, votes:int, paid:bool, when:string}>
     */
    public static function forNominee(int $nomineeId, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($nomineeId < 1) return [];

        try {
            $rows = DB::table('gates_votes')
                ->where('nominee_id', $nomineeId)
                ->where('show_name', 1)
                // A blank name is consent to show nothing. Publishing "Supporter" —
                // the placeholder the checkout substitutes for an empty field — would
                // pad the list with rows that name nobody.
                ->whereNotNull('voter_name')
                ->where('voter_name', '<>', '')
                ->orderByDesc('id')
                ->limit(max(1, $limit))
                ->get(['voter_name', 'weight', 'vote_type', 'voted_at']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r->voter_name ?? ''));
            // 'Supporter' is PaidVoteController's placeholder for "no name given",
            // not a name anyone chose. It reaches here only if someone both left the
            // field blank and ticked the box.
            if ($name === '' || strcasecmp($name, 'Supporter') === 0) continue;
            $out[] = [
                'name'  => mb_substr($name, 0, 60),
                'votes' => max(1, (int) ($r->weight ?? 1)),
                'paid'  => ((string) ($r->vote_type ?? '')) === 'paid',
                'when'  => (string) ($r->voted_at ?? ''),
            ];
        }
        return $out;
    }

    /**
     * How many supporters consented in total — the "and N others" figure.
     *
     * Counted separately from the list rather than derived from it, because the list
     * is truncated and the count is not.
     */
    public static function countForNominee(int $nomineeId): int
    {
        if ($nomineeId < 1) return 0;
        try {
            return (int) DB::table('gates_votes')
                ->where('nominee_id', $nomineeId)
                ->where('show_name', 1)
                ->whereNotNull('voter_name')
                ->where('voter_name', '<>', '')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
