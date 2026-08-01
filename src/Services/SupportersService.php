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
    /** How many names the ballot shows before "and N more". */
    public const DEFAULT_LIMIT = 10;

    /**
     * How many rows to read before aggregating. One person's votes can be spread
     * over many rows, so finding the top 10 PEOPLE needs considerably more than
     * 10 rows — but not the whole table, which on a popular nominee is unbounded.
     */
    private const SCAN = 600;

    /**
     * The nominee's biggest named backers — deduplicated, ranked, capped.
     *
     * ── ONE PERSON IS ONE SUPPORTER ─────────────────────────────────────────
     *
     * This used to return vote ROWS. Someone who bought votes on three occasions
     * appeared three times, so a list of "supporters" was really a list of
     * transactions, and a nominee with four loyal backers looked like it had
     * twelve. Rows are now folded by name and their weights summed, which is
     * also what makes "top" mean anything: the ranking is by what a person
     * actually contributed, not by who happened to pay most recently.
     *
     * Folding ignores case and spacing, so "ADA  OKONKWO" and "Ada Okonkwo" are
     * one person; the spelling kept is the one carrying the most votes.
     *
     * ── THE NUMBERS RANK THE LIST AND ARE NEVER PRINTED ─────────────────────
     *
     * `votes` decides the order and nothing else. The ballot deliberately does
     * not show it: publishing what each named person paid turns a thank-you into
     * a table of who spent most, invites exactly the escalation this platform
     * should not encourage, and discloses one person's spending to every reader.
     * Ranking by a number nobody sees is fine. Printing it is not.
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
                ->limit(self::SCAN)
                ->get(['voter_name', 'weight', 'vote_type', 'voted_at']);
        } catch (\Throwable) {
            return [];
        }

        /** @var array<string, array{name:string, votes:int, paid:bool, when:string, top:int}> $people */
        $people = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r->voter_name ?? ''));
            // 'Supporter' is PaidVoteController's placeholder for "no name given",
            // not a name anyone chose. It reaches here only if someone both left the
            // field blank and ticked the box.
            if ($name === '' || strcasecmp($name, 'Supporter') === 0) continue;

            $name   = mb_substr($name, 0, 60);
            $key    = self::fold($name);
            $weight = max(1, (int) ($r->weight ?? 1));

            if (!isset($people[$key])) {
                // Rows arrive newest first, so the first date seen is the latest.
                $people[$key] = ['name' => $name, 'votes' => 0, 'paid' => false,
                                 'when' => (string) ($r->voted_at ?? ''), 'top' => 0];
            }
            $people[$key]['votes'] += $weight;
            $people[$key]['paid']   = $people[$key]['paid'] || ((string) ($r->vote_type ?? '')) === 'paid';
            if ($weight > $people[$key]['top']) {
                $people[$key]['top']  = $weight;
                $people[$key]['name'] = $name;
            }
        }

        // Biggest backer first, ties broken by name so the order is stable between
        // requests instead of depending on however the rows happened to arrive.
        uasort($people, static fn($a, $b) => $b['votes'] <=> $a['votes'] ?: strcasecmp($a['name'], $b['name']));

        $out = [];
        foreach (array_slice(array_values($people), 0, max(1, $limit)) as $p) {
            unset($p['top']);
            $out[] = $p;
        }
        return $out;
    }

    /**
     * How many DISTINCT people consented — the figure behind "and N more".
     *
     * Counted over people rather than rows so it agrees with the list above it.
     * A COUNT(*) of vote rows would say "and 40 more" beneath a list of four
     * names when the truth is that those same four people voted often.
     */
    public static function countForNominee(int $nomineeId): int
    {
        if ($nomineeId < 1) return 0;
        try {
            $names = DB::table('gates_votes')
                ->where('nominee_id', $nomineeId)
                ->where('show_name', 1)
                ->whereNotNull('voter_name')
                ->where('voter_name', '<>', '')
                ->limit(self::SCAN * 4)
                ->pluck('voter_name');
        } catch (\Throwable) {
            return 0;
        }

        $seen = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n === '' || strcasecmp($n, 'Supporter') === 0) continue;
            $seen[self::fold($n)] = true;
        }
        return count($seen);
    }

    /**
     * How to describe the people not shown.
     *
     * Exact while the number is small enough for exactness to be information, and
     * rounded to a round number with a "+" once it is not. "and 12 more" is a
     * fact; "and 1,247 more" is a big number wearing a comma, and it turns the
     * tail of the list into a counter that changes on every reload for no
     * reader's benefit. Rounding DOWN matters — "100+" must never be a claim the
     * real count cannot back.
     */
    public static function overflowLabel(int $remaining): string
    {
        if ($remaining < 1)  return '';
        if ($remaining < 25) return 'and ' . $remaining . ' more';

        foreach ([1000, 500, 100, 50, 25] as $step) {
            if ($remaining >= $step) {
                return 'and ' . number_format(intdiv($remaining, $step) * $step) . '+ more';
            }
        }
        return 'and ' . $remaining . ' more';   // unreachable, but every path returns
    }

    /** Case- and whitespace-insensitive identity for a supporter's name. */
    private static function fold(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
