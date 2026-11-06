<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Signals that say "a person should look at this one", independent of whether the
 * claimant proved they hold a contact channel.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY OWNING A CHANNEL IS NOT ENOUGH BY ITSELF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see ClaimIndependence} asks the right question — is this contact independent of the
 * people who nominated? — and it closes the obvious attack, which is a nominator
 * claiming the page of the person they put forward.
 *
 * It cannot see three other situations, and all three are ordinary rather than exotic:
 *
 * ── ONE ADDRESS, MANY CHILDREN ───────────────────────────────────────────────
 *
 * A teacher nominates thirty pupils and, because the pupils have no email, puts the
 * SCHOOL's address on every nomination. That address is independent of the nominator on
 * twenty-nine of them. Anybody with the school inbox — a colleague, a leaver whose
 * account was never closed, whoever answers info@ — can then claim thirty children's
 * pages one after another, each claim passing every check cleanly.
 *
 * A contact that is the route to many different nominees is not proof of being any one
 * of them. It is a shared mailbox, and a shared mailbox cannot identify a person.
 *
 * ── ONE DEVICE, MANY NOMINEES ────────────────────────────────────────────────
 *
 * The rate limits cap codes per page and per browser per hour, which stops a burst. They
 * do not notice one device quietly claiming a different nominee every few hours. That
 * pattern has one innocent explanation — a teacher helping pupils claim at a school
 * computer — and it is precisely the case that should be reviewed rather than waved
 * through, because the honest version and the farm are indistinguishable from here.
 *
 * ── THE PAGES WORTH TAKING ───────────────────────────────────────────────────
 *
 * A winner's page, a runner-up's, or a leading page in a live category is worth more to
 * an impersonator than an average one: it carries an audience, a public result, and a
 * community-return balance. Auto-activating those on possession of one inbox is spending
 * the platform's credibility to save one review.
 *
 * ── WHAT THIS CLASS DOES AND DOES NOT DO ─────────────────────────────────────
 *
 * It NEVER refuses. Every signal here produces a HOLD — the state the doctrine defines
 * as "we need one more thing", which routes to a person, tells the claimant why, and
 * costs nothing. Refusing on a heuristic would fail exactly the population §1 is written
 * about: the nominee who shares a handset, whose address was made for them by a teacher,
 * and who trips two of these signals by being ordinary.
 *
 * The reasons are written for the CLAIMANT to read, not only for an operator, because a
 * hold nobody can understand feels like an accusation.
 */
final class ClaimRisk
{
    /**
     * A contact that is the route to this many different nominees is a shared mailbox.
     *
     * Three, not two: a parent legitimately being the contact for two siblings is common
     * and should not be reviewed. A single address on three or more nominations is either
     * an institution or a nominator who used their own details throughout, and both want
     * a person.
     */
    public const SHARED_CONTACT_NOMINEES = 3;

    /** Distinct nominees one device may claim in the window before it wants review. */
    public const DEVICE_NOMINEE_LIMIT = 2;

    /** How far back the device check looks. */
    public const DEVICE_WINDOW_DAYS = 30;

    /**
     * Should this claim be held for a person, and what should the claimant be told?
     *
     * @param array{channel:string, value:string, hint:string, country:string} $contact
     * @return array{hold:bool, signals:list<string>, say:string}
     */
    public static function assess(
        int $nomineeId,
        array $contact,
        string $deviceFp = '',
        string $ipHash = '',
    ): array {
        $signals = [];
        $reasons = [];

        // ── 1 · is this contact the route to several nominees? ───────────────
        $shared = self::nomineesReachableBy($contact);
        if ($shared >= self::SHARED_CONTACT_NOMINEES) {
            $signals[] = 'shared-contact:' . $shared;
            $reasons[] = 'the contact we sent the code to is also the contact on other nominations, '
                       . 'so holding it does not on its own tell us which of those people you are';
        }

        // ── 2 · has this device already claimed other nominees? ─────────────
        $seen = self::nomineesClaimedByDevice($deviceFp, $ipHash, $nomineeId);
        if ($seen > self::DEVICE_NOMINEE_LIMIT) {
            $signals[] = 'device-breadth:' . $seen;
            $reasons[] = 'several different pages have been claimed from this device recently, which '
                       . 'is normal if you are helping people claim theirs and is worth a person '
                       . 'checking either way';
        }

        // ── 3 · is this a page worth taking? ────────────────────────────────
        $why = self::highValue($nomineeId);
        if ($why !== null) {
            $signals[] = 'high-value:' . $why;
            $reasons[] = $why === 'winner'
                ? 'this page carries a published result, and we review every claim on one of those'
                : 'this page is near the top of its category, and we review every claim on one of those';
        }

        if ($signals === []) {
            return ['hold' => false, 'signals' => [], 'say' => ''];
        }

        // One sentence a human wrote the shape of, assembled from whichever clauses
        // fired. Never a list of signal names: "shared-contact:14" means nothing to the
        // person reading it, and looking like a fraud score is the opposite of the point.
        $say = 'We need one more thing before this page can be handed over — because '
             . self::join($reasons) . '. A person will finish it with you, nothing is being '
             . 'charged, and this is not a refusal.';

        return ['hold' => true, 'signals' => $signals, 'say' => $say];
    }

    /**
     * How many DISTINCT approved nominees this contact is a channel for.
     *
     * Counts nominees rather than nominations: a nominee nominated four times by four
     * people who all used the same school address is one child, not four, and treating
     * it as four would hold a claim for being popular.
     */
    public static function nomineesReachableBy(array $contact): int
    {
        $value = trim((string) ($contact['value'] ?? ''));
        if ($value === '') return 0;

        $col = ($contact['channel'] ?? '') === 'email' ? 'nominee_email' : 'nominee_phone';

        try {
            // Matched on the normalised form, because "Info@School.NG " and
            // "info@school.ng" are one mailbox and an exact-string comparison would find
            // neither the duplicate nor the risk.
            $needle = ($contact['channel'] ?? '') === 'email'
                ? mb_strtolower($value)
                : preg_replace('/\D+/', '', $value);
            if ($needle === '' || $needle === null) return 0;

            $rows = DB::table('gates_nominations')
                ->where('status', 'approved')
                ->whereNotNull($col)
                ->select('nominee_name', 'category_id', $col . ' as contact')
                ->limit(4000)
                ->get();
        } catch (\Throwable) {
            // FAIL SAFE, not fail open: an unreadable nominations table means the check
            // could not run, and a check that could not run must not read as a pass. It
            // returns the threshold so the claim is held for a person.
            return self::SHARED_CONTACT_NOMINEES;
        }

        $people = [];
        foreach ($rows as $r) {
            $have = ($contact['channel'] ?? '') === 'email'
                ? mb_strtolower(trim((string) $r->contact))
                : preg_replace('/\D+/', '', (string) $r->contact);
            if ($have === '' || $have === null) continue;
            // Phone numbers are compared on their last nine digits so +234 803… and
            // 0803… are one number — the same rule ClaimIndependence uses.
            $same = ($contact['channel'] ?? '') === 'email'
                ? $have === $needle
                : (strlen($have) >= 9 && strlen($needle) >= 9
                   && substr($have, -9) === substr($needle, -9));
            if (!$same) continue;

            $people[\AfricaGates\Support\Slug::make((string) $r->nominee_name, 80)
                    . '|' . (int) $r->category_id] = true;
        }
        return count($people);
    }

    /**
     * Distinct nominees this device or network has claimed lately, including this one.
     *
     * Device first, network second: a shared IP is a whole neighbourhood on a Nigerian
     * mobile carrier and would hold half the honest claims on the platform, so the IP
     * only counts when there is no device fingerprint to go on.
     */
    public static function nomineesClaimedByDevice(string $deviceFp, string $ipHash, int $includeNominee = 0): int
    {
        $deviceFp = trim($deviceFp);
        $ipHash   = trim($ipHash);
        if ($deviceFp === '' && $ipHash === '') return 0;

        try {
            $q = DB::table('gates_nominee_claims')
                ->where('created_at', '>=', Carbon::now()->subDays(self::DEVICE_WINDOW_DAYS)->toDateTimeString());
            if ($deviceFp !== '') {
                $q->where('device_fp', $deviceFp);
            } else {
                $q->where('ip_hash', $ipHash);
            }
            $ids = $q->limit(500)->pluck('nominee_id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return 0;   // no history readable is not evidence of breadth
        }

        if ($includeNominee > 0) $ids[] = $includeNominee;
        return count(array_unique(array_filter($ids)));
    }

    /**
     * Is this page one worth impersonating? Returns the reason, or null.
     *
     * 'winner' covers a published result. 'leader' covers the top three of a live
     * category — the pages a rally is currently pointed at, whose claim would inherit an
     * audience and a community-return balance.
     */
    public static function highValue(int $nomineeId): ?string
    {
        try {
            $n = DB::table('gates_nominees')->where('id', $nomineeId)
                ->select('status', 'category_id', 'vote_count')->first();
        } catch (\Throwable) { return null; }
        if ($n === null) return null;

        if (in_array((string) $n->status, ['winner', 'runner_up'], true)) return 'winner';

        try {
            $ahead = (int) DB::table('gates_nominees')
                ->where('category_id', (int) $n->category_id)
                ->whereIn('status', ['approved', 'winner', 'runner_up'])
                ->where('vote_count', '>', (int) $n->vote_count)
                ->count();
        } catch (\Throwable) { return null; }

        // Only when there is a real field to lead. Being "top three of three" is not a
        // standing, and holding those claims would review every nominee in a small
        // category for no gain.
        if ($ahead < 3) {
            try {
                $field = (int) DB::table('gates_nominees')
                    ->where('category_id', (int) $n->category_id)
                    ->whereIn('status', ['approved', 'winner', 'runner_up'])->count();
            } catch (\Throwable) { return null; }
            if ($field >= 6 && (int) $n->vote_count > 0) return 'leader';
        }
        return null;
    }

    /** "a, b and c" */
    private static function join(array $bits): string
    {
        $bits = array_values(array_filter($bits));
        if (count($bits) <= 1) return (string) ($bits[0] ?? '');
        $last = array_pop($bits);
        return implode(', ', $bits) . ' and ' . $last;
    }
}
