<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A share of what your community raised in your name.
 *
 * ── THE IDEA, STATED ONCE ────────────────────────────────────────────────────
 *
 * Somebody contributes to the programme because of a nominee. A percentage of that
 * contribution is set aside for the nominee. It does not depend on winning, because
 * they raised the money either way — and that is the whole point: it rewards
 * building a community rather than beating one.
 *
 * ── IT IS A SHARE OF MONEY, NOT OF VOTES ─────────────────────────────────────
 *
 * Free votes raise nothing, so they earn nothing. Paying a share on them would mean
 * paying out of the programme's own pocket, with a liability that grows with
 * popularity and no revenue behind it. Every entry in this ledger traces to one
 * confirmed contribution and records the amount and rate it came from.
 *
 * ── THE QUALIFICATION IS PEOPLE, NOT VOTES ───────────────────────────────────
 *
 * A nominee starts earning once enough DISTINCT supporters have backed them, and
 * only from that moment forward — nothing before the line is earned retroactively.
 *
 * Counting distinct supporters rather than votes is deliberate, and it is the
 * difference between a rule and a formality. One person can buy fifty votes in a
 * single order; if the threshold were fifty VOTES, a nominee could cross it alone,
 * in one transaction, for the price of the threshold, and every genuine
 * contribution afterwards would earn. Fifty different verified people is not
 * something one person can arrange on a Tuesday afternoon.
 *
 * Prospective-only earning does the rest of the work: crossing the line is worth
 * exactly one vote's worth of future earnings, not a jackpot on everything below
 * it, so there is nothing to run at.
 *
 * ── MONEY RUNS BACKWARDS TOO ─────────────────────────────────────────────────
 *
 * Contributions get refunded, charged back, and voided. The share accrued on one
 * has to come off again, sometimes after the nominee has been told about it. That
 * is why this is an append-only signed ledger and not a balance column: a column
 * can be decremented but cannot say why, and the first dispute about it is
 * unanswerable. The balance is SUM(amount_kobo) and there is no cached total to
 * drift away from it.
 *
 * ── AND NOTHING IS PAYABLE YET ───────────────────────────────────────────────
 *
 * There is no withdrawal here. Accrual runs, people can see what they have raised,
 * and the money stays put. Opening cash-out while contributions are still going
 * missing on the way IN would multiply a problem the platform already has — see
 * {@see PaymentTriage}. Turn it on when the payin path is trustworthy.
 */
final class CommunityReturnService
{
    /** Entry types that are money owed to the member (positive) or taken back. */
    public const TYPES = ['accrual', 'reversal', 'adjustment', 'hold', 'release', 'forfeit', 'payout'];

    /** Rate is OFF unless deliberately configured. A revenue share must be a decision. */
    public const DEFAULT_RATE_BPS = 0;

    /** Distinct verified supporters before a nominee starts earning. */
    public const DEFAULT_MIN_SUPPORTERS = 25;

    // ─────────────────────────────────────────────────────────────────────────
    // Configuration
    // ─────────────────────────────────────────────────────────────────────────

    /** The share, in basis points (3000 = 30%), for this nominee's cycle. */
    public static function rateBps(int $nomineeId): int
    {
        $r = self::rulesFor($nomineeId);
        return max(0, min(10000, (int) ($r['community_return_bps'] ?? self::DEFAULT_RATE_BPS)));
    }

    public static function minSupporters(int $nomineeId): int
    {
        $r = self::rulesFor($nomineeId);
        return max(1, (int) ($r['community_return_min_supporters'] ?? self::DEFAULT_MIN_SUPPORTERS));
    }

    /** @return array<string,mixed> */
    private static function rulesFor(int $nomineeId): array
    {
        $ctx = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('n.id', $nomineeId)
            ->select('cy.id as cycle_id', 'cy.programme_id')->first();

        return (new RuleEngine())->effective($ctx->programme_id ?? null, $ctx->cycle_id ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Qualification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * How many DISTINCT people have backed this nominee.
     *
     * Voters and contributors are counted in one set by their hashed identity, so
     * somebody who voted free and then contributed is one supporter, not two.
     */
    public static function supporterCount(int $nomineeId, ?string $asOf = null): int
    {
        $seen = [];
        try {
            $q = DB::table('gates_votes')->where('nominee_id', $nomineeId)->whereNull('donation_id');
            if ($asOf !== null) $q->where('voted_at', '<=', $asOf);
            foreach ($q->pluck('voter_email_hash') as $h) $seen[(string) $h] = true;
        } catch (\Throwable) {}

        try {
            $q = DB::table('gates_donations')->where('intent_nominee_id', $nomineeId)
                ->where('status', 'confirmed')->whereNotNull('donor_email');
            if ($asOf !== null) $q->where('created_at', '<=', $asOf);
            foreach ($q->pluck('donor_email') as $e) {
                $seen[hash('sha256', strtolower(trim((string) $e)))] = true;
            }
        } catch (\Throwable) {}

        return count($seen);
    }

    /**
     * Was this nominee qualified — and if $asOf is given, WERE THEY QUALIFIED THEN?
     *
     * ── WHY THE TIMESTAMP IS NOT OPTIONAL IN PRACTICE ────────────────────────
     *
     * "Earning is prospective" is a claim about the contribution, not about the
     * moment somebody happens to run the accrual. Checking qualification against
     * TODAY looked prospective while accrual ran exactly once, at mint time — and
     * quietly stopped being so the instant anything re-ran it later. A reconciler
     * retry, a manual sweep, votes:remint: each would walk back over contributions
     * from before the community existed and pay them, because by then the community
     * did exist.
     *
     * That is precisely the retroactive jackpot the threshold is designed not to be,
     * and it would have arrived by accident rather than by decision. Judging the
     * question as of the contribution's own timestamp makes the answer stable
     * forever: a contribution that did not earn when it arrived never earns.
     *
     * @return array{qualified:bool, supporters:int, needed:int}
     */
    public static function qualification(int $nomineeId, ?string $asOf = null): array
    {
        $have = self::supporterCount($nomineeId, $asOf);
        $need = self::minSupporters($nomineeId);
        return ['qualified' => $have >= $need, 'supporters' => $have, 'needed' => $need];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Accrual
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Set aside a nominee's share of one confirmed contribution.
     *
     * Called after a paid-vote order mints. Silent and harmless when the rate is
     * zero, the nominee has not qualified, or this contribution has already been
     * accrued — all three are ordinary, not errors.
     *
     * @return array{ok:bool, code:string, kobo:int}
     */
    public static function accrue(int $donationId, ?int $adminId = null): array
    {
        $no = static fn (string $c): array => ['ok' => false, 'code' => $c, 'kobo' => 0];

        $don = DB::table('gates_donations')->where('id', $donationId)->first();
        if (!$don)                                          return $no('NO_ORDER');
        if ((string) ($don->tier ?? '') !== 'paid-vote')    return $no('NOT_A_CONTRIBUTION');
        if ((string) ($don->status ?? '') !== 'confirmed')  return $no('NOT_CONFIRMED');
        if (($don->refunded_at ?? null) !== null
            || ($don->refund_requested_at ?? null) !== null) return $no('REFUNDED');

        $nomineeId = (int) ($don->intent_nominee_id ?? 0);
        if ($nomineeId < 1) return $no('NO_NOMINEE');

        $rate = self::rateBps($nomineeId);
        if ($rate <= 0) return $no('RATE_OFF');

        // Qualification is checked NOW, at the moment of the contribution, and the
        // answer is never revisited for entries already written. That is what makes
        // earning prospective: crossing the line changes the future, not the past.
        $q = self::qualification($nomineeId, (string) $don->created_at);
        if (!$q['qualified']) return $no('NOT_QUALIFIED');

        $basis = (int) round(((int) $don->amount_naira) * 100);   // naira → kobo
        if ($basis <= 0) return $no('NO_AMOUNT');

        // intdiv, so a share is never a fraction of a kobo and never rounds UP into
        // money the programme did not receive. The remainder stays with the platform.
        $kobo = intdiv($basis * $rate, 10000);
        if ($kobo <= 0) return $no('TOO_SMALL');

        $ctx = DB::table('gates_nominees as n')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $nomineeId)
            ->select('n.profile_id', 'c.cycle_id')->first();

        try {
            DB::table('gates_community_returns')->insert([
                'nominee_id'  => $nomineeId,
                'profile_id'  => $ctx->profile_id ?? null,
                'cycle_id'    => $ctx->cycle_id ?? null,
                'entry_type'  => 'accrual',
                'amount_kobo' => $kobo,
                'basis_kobo'  => $basis,
                'rate_bps'    => $rate,
                'donation_id' => $donationId,
                'created_by'  => $adminId,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // uq_return_entry: already accrued. Re-running a mint must not pay twice.
            return $no('ALREADY_ACCRUED');
        }

        return ['ok' => true, 'code' => 'ACCRUED', 'kobo' => $kobo];
    }

    /**
     * Take the share back off when its contribution is reversed.
     *
     * The accrual STAYS on the ledger and a negative entry is added beside it. The
     * pair is the truth: money was set aside, and then the contribution behind it
     * went away. Deleting the accrual would leave a balance that dropped for no
     * recorded reason, which is the one thing a ledger exists to prevent.
     *
     * @return array{ok:bool, code:string, kobo:int}
     */
    public static function reverse(int $donationId, string $reason, ?int $adminId = null): array
    {
        $accrual = DB::table('gates_community_returns')
            ->where('donation_id', $donationId)->where('entry_type', 'accrual')->first();
        if (!$accrual) return ['ok' => false, 'code' => 'NOTHING_ACCRUED', 'kobo' => 0];

        try {
            DB::table('gates_community_returns')->insert([
                'nominee_id'  => (int) $accrual->nominee_id,
                'profile_id'  => $accrual->profile_id,
                'cycle_id'    => $accrual->cycle_id,
                'entry_type'  => 'reversal',
                'amount_kobo' => -1 * (int) $accrual->amount_kobo,
                'basis_kobo'  => (int) $accrual->basis_kobo,
                'rate_bps'    => (int) $accrual->rate_bps,
                'donation_id' => $donationId,
                'note'        => mb_substr(trim($reason) ?: 'contribution reversed', 0, 400),
                'created_by'  => $adminId,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'ALREADY_REVERSED', 'kobo' => 0];
        }

        return ['ok' => true, 'code' => 'REVERSED', 'kobo' => (int) $accrual->amount_kobo];
    }

    /**
     * Freeze a nominee's return pending an integrity finding.
     *
     * A hold is a negative entry rather than a flag, so the payable balance falls
     * immediately and no separate "is this on hold" check has to be remembered by
     * every future reader. Releasing adds the mirror entry back.
     */
    public static function hold(int $nomineeId, string $reason, ?int $adminId = null): array
    {
        if (trim($reason) === '') return ['ok' => false, 'code' => 'NO_REASON', 'kobo' => 0];

        // The EARNED share, not the payable part. A finding usually lands while the
        // cycle is still running, when nothing is payable yet — holding only what is
        // payable today would freeze nothing and quietly let the whole balance become
        // available the moment results were announced.
        $share = self::balance($nomineeId)['share_kobo'];
        if ($share <= 0) return ['ok' => false, 'code' => 'NOTHING_TO_HOLD', 'kobo' => 0];

        self::write($nomineeId, 'hold', -$share, trim($reason), $adminId);
        return ['ok' => true, 'code' => 'HELD', 'kobo' => $share];
    }

    public static function release(int $nomineeId, int $kobo, string $reason, ?int $adminId = null): array
    {
        if ($kobo <= 0) return ['ok' => false, 'code' => 'BAD_AMOUNT', 'kobo' => 0];
        self::write($nomineeId, 'release', $kobo, trim($reason) ?: 'hold lifted', $adminId);
        return ['ok' => true, 'code' => 'RELEASED', 'kobo' => $kobo];
    }

    /** A correction by a human. The note is required — an unexplained adjustment is a hole. */
    public static function adjust(int $nomineeId, int $kobo, string $reason, ?int $adminId = null): array
    {
        if ($kobo === 0)          return ['ok' => false, 'code' => 'BAD_AMOUNT', 'kobo' => 0];
        if (trim($reason) === '') return ['ok' => false, 'code' => 'NO_REASON', 'kobo' => 0];

        self::write($nomineeId, 'adjustment', $kobo, trim($reason), $adminId);
        return ['ok' => true, 'code' => 'ADJUSTED', 'kobo' => $kobo];
    }

    private static function write(int $nomineeId, string $type, int $kobo, string $note, ?int $adminId): void
    {
        $ctx = DB::table('gates_nominees as n')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $nomineeId)->select('n.profile_id', 'c.cycle_id')->first();

        DB::table('gates_community_returns')->insert([
            'nominee_id' => $nomineeId, 'profile_id' => $ctx->profile_id ?? null,
            'cycle_id' => $ctx->cycle_id ?? null, 'entry_type' => $type,
            'amount_kobo' => $kobo, 'note' => mb_substr($note, 0, 400),
            'created_by' => $adminId, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading it back
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What a nominee has raised, what their share is, and what is actually payable.
     *
     * THREE numbers, not one, because they answer different questions and a single
     * "balance" hides the ratio that makes this honest. `raised_kobo` is the
     * motivating figure and the one that makes somebody an advocate for the
     * programme; `share_kobo` is theirs; `payable_kobo` is what a withdrawal could
     * take today, which is less whenever a cycle is still running.
     *
     * @return array{raised_kobo:int, share_kobo:int, payable_kobo:int, held_kobo:int, entries:int}
     */
    public static function balance(int $nomineeId): array
    {
        $rows = DB::table('gates_community_returns')->where('nominee_id', $nomineeId)->get();

        $share = 0; $raised = 0; $held = 0;
        foreach ($rows as $r) {
            $share += (int) $r->amount_kobo;
            if ((string) $r->entry_type === 'accrual')  $raised += (int) $r->basis_kobo;
            if ((string) $r->entry_type === 'reversal') $raised -= (int) $r->basis_kobo;
            if ((string) $r->entry_type === 'hold')     $held   += -1 * (int) $r->amount_kobo;
            if ((string) $r->entry_type === 'release')  $held   -= (int) $r->amount_kobo;
        }

        return [
            'raised_kobo'  => max(0, $raised),
            'share_kobo'   => $share,
            'payable_kobo' => self::payableFor($nomineeId, $rows),
            'held_kobo'    => max(0, $held),
            'entries'      => $rows->count(),
        ];
    }

    /**
     * Of the share, how much a withdrawal could take today.
     *
     * Nothing is payable until the cycle that earned it has ANNOUNCED ITS RESULTS.
     * The rule protects both sides: a nominee cannot cash out mid-race and vanish,
     * and the platform is not paying out money that a fraud finding might still
     * reverse. Money earned in a live cycle is real, visible, and not yet available.
     */
    private static function payableFor(int $nomineeId, $rows): int
    {
        $byCycle = [];
        foreach ($rows as $r) {
            $byCycle[(int) ($r->cycle_id ?? 0)] = ($byCycle[(int) ($r->cycle_id ?? 0)] ?? 0) + (int) $r->amount_kobo;
        }
        if (!$byCycle) return 0;

        $settled = DB::table('gates_award_cycles')
            ->whereIn('id', array_keys($byCycle))
            ->whereIn('status', ['results', 'archived'])
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $payable = 0;
        foreach ($byCycle as $cycleId => $kobo) {
            if (in_array($cycleId, $settled, true)) $payable += $kobo;
        }
        return max(0, $payable);
    }

    /**
     * The statement. Every line, in order, with what it came from.
     *
     * A number by itself is something to argue with; a number with its workings is
     * something to check. Whoever asks "where did this come from" gets an answer
     * without anybody opening a database.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function statement(int $nomineeId, int $limit = 200): array
    {
        return DB::table('gates_community_returns')
            ->where('nominee_id', $nomineeId)->orderByDesc('id')->limit($limit)
            ->get()->map(static fn ($r) => [
                'at'        => (string) $r->created_at,
                'type'      => (string) $r->entry_type,
                'amount'    => (int) $r->amount_kobo,
                'basis'     => (int) $r->basis_kobo,
                'rate_pct'  => (int) $r->rate_bps > 0 ? round((int) $r->rate_bps / 100, 2) : null,
                'reference' => $r->donation_id
                    ? (string) (DB::table('gates_donations')->where('id', (int) $r->donation_id)->value('payment_ref') ?? '')
                    : null,
                'note'      => $r->note,
            ])->all();
    }

    /** Kobo → a naira string for display. Never used for arithmetic. */
    public static function naira(int $kobo): string
    {
        return number_format(intdiv(abs($kobo), 100)) . '.' . str_pad((string) (abs($kobo) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Does the ledger reconcile against the contributions behind it?
     *
     * Every accrual must trace to a confirmed, unrefunded contribution for the
     * nominee it credits. Anything else is a row that went around this service —
     * the same check payments:triage runs on recovered votes, for the same reason.
     *
     * @return array<int, array{entry:int, problem:string}>
     */
    public static function audit(?int $cycleId = null): array
    {
        $q = DB::table('gates_community_returns')->where('entry_type', 'accrual');
        if ($cycleId !== null) $q->where('cycle_id', $cycleId);

        $bad = [];
        foreach ($q->get() as $e) {
            $d = $e->donation_id
                ? DB::table('gates_donations')->where('id', (int) $e->donation_id)->first()
                : null;

            if (!$d) { $bad[] = ['entry' => (int) $e->id, 'problem' => 'no contribution behind it']; continue; }
            if ((string) $d->status !== 'confirmed') {
                $bad[] = ['entry' => (int) $e->id, 'problem' => 'contribution is ' . $d->status]; continue;
            }
            if ((int) $d->intent_nominee_id !== (int) $e->nominee_id) {
                $bad[] = ['entry' => (int) $e->id, 'problem' => 'credited to a different nominee']; continue;
            }
            $expected = intdiv((int) $e->basis_kobo * (int) $e->rate_bps, 10000);
            if ($expected !== (int) $e->amount_kobo) {
                $bad[] = ['entry' => (int) $e->id, 'problem' => 'amount does not match its own basis and rate'];
            }
        }
        return $bad;
    }
}
