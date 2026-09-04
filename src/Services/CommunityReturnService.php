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
 * ── QUALIFYING SUPPORT: VOTES, BUT NOT FROM ONE POCKET ───────────────────────
 *
 * A nominee starts earning once they have gathered enough support, counted in
 * votes, and only from that moment forward — nothing below the line is earned
 * retroactively.
 *
 * Counting VOTES is the honest unit: somebody who bought twenty-five votes did
 * more for this nominee than somebody who cast one, and a rule that scored them
 * identically would be telling generous supporters their generosity was noise.
 *
 * But a raw vote threshold is not a rule, it is a price. One person can buy the
 * whole thing in a single order, cross the line alone on a Tuesday afternoon, and
 * every genuine contribution afterwards earns. So each supporter's votes count
 * toward qualification only up to a CAP — a fixed percentage of the threshold.
 *
 *      counted = Σ over distinct supporters of min(their votes, cap)
 *      cap     = threshold × cap_pct / 100
 *
 * At a 10% cap the arithmetic does the arguing: one person can carry at most a
 * tenth of the way, so it takes at least ten different verified people, whatever
 * anybody is willing to spend. Inside that ceiling paying more still counts for
 * more, which is the difference between this and counting heads.
 *
 * The cap is not a second knob to keep in step with a supporter minimum — it IS
 * the supporter minimum, expressed once. ceil(100 / cap_pct) people, derived, so
 * the two can never be configured into contradicting each other.
 *
 * Prospective-only earning does the rest of the work: crossing the line is worth
 * the future, not a jackpot on everything below it, so there is nothing to run at.
 *
 * ── ONE PERSON IS ONE SUPPORTER, HOWEVER THEY SHOWED UP ──────────────────────
 *
 * Free votes and contributions are folded into one tally keyed by hashed email, so
 * somebody who voted free and then contributed is one supporter with the sum of
 * both — not two supporters, which would have made splitting a purchase across
 * "free vote + order" a way to buy a second cap.
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

    /**
     * Fallbacks of last resort — used ONLY when RuleEngine itself cannot be read.
     *
     * These are not the policy. The policy is {@see RuleEngine::DEFAULTS}, overridden
     * per programme and per cycle from Settings → Community return, and every reader
     * on this class goes through {@see rulesFor()} to get it. They exist so a share
     * still resolves to something sane on a database that is mid-migration rather
     * than throwing on a nominee page.
     */
    public const FALLBACK_RATE_BPS       = 5000;   // 50% — keep in step with RuleEngine::DEFAULTS
    public const FALLBACK_VOTE_THRESHOLD = 250;
    public const FALLBACK_CAP_PCT        = 10;

    // ─────────────────────────────────────────────────────────────────────────
    // Configuration — all of it read, none of it decided here
    // ─────────────────────────────────────────────────────────────────────────

    /** The share, in basis points (5000 = 50%), for this nominee's cycle. */
    public static function rateBps(int $nomineeId): int
    {
        $r = self::rulesFor($nomineeId);
        return max(0, min(10000, (int) ($r['community_return_bps'] ?? self::FALLBACK_RATE_BPS)));
    }

    /** Qualifying support, in votes, before a nominee starts earning. */
    public static function voteThreshold(int $nomineeId): int
    {
        $r = self::rulesFor($nomineeId);
        return max(1, (int) ($r['community_return_vote_threshold'] ?? self::FALLBACK_VOTE_THRESHOLD));
    }

    /**
     * Most of the threshold any ONE supporter may supply, as a percentage.
     *
     * Clamped to 1–100 rather than trusted. At 0 the cap would be zero votes and no
     * nominee could ever qualify however many people backed them; above 100 it stops
     * being a cap at all and one person can buy the line outright. Both are one
     * keystroke away in a settings form, and both are silent — the page would simply
     * behave differently forever with nothing to notice.
     */
    public static function supporterCapPct(int $nomineeId): int
    {
        $r = self::rulesFor($nomineeId);
        return max(1, min(100, (int) ($r['community_return_supporter_cap_pct'] ?? self::FALLBACK_CAP_PCT)));
    }

    /** Votes any one supporter can contribute toward the threshold. At least one. */
    public static function supporterCapVotes(int $nomineeId): int
    {
        return max(1, (int) ceil(self::voteThreshold($nomineeId) * self::supporterCapPct($nomineeId) / 100));
    }

    /**
     * The fewest different people who could possibly qualify a nominee.
     *
     * DERIVED, never configured. A separate "minimum supporters" setting beside the
     * cap is two knobs describing one rule, and the moment they disagree the page
     * publishes one number while the engine enforces the other.
     */
    public static function minSupporters(int $nomineeId): int
    {
        return (int) ceil(100 / self::supporterCapPct($nomineeId));
    }

    /**
     * Is there any way for this mechanism to set money aside on this deployment?
     *
     * ── WHY THE ANSWER IS NOT "THE RATE IS ABOVE ZERO" ───────────────────────
     *
     * {@see accrue()} refuses anything whose tier is not `paid-vote`. Every kobo this
     * ledger has ever held came from somebody buying votes on a ballot — there is no
     * other producer, and a general contribution to the programme does not accrue.
     *
     * So where {@see PaidVoteService::enabled()} is off, nothing can raise a naira in
     * a nominee's name and the return is inert. And that toggle is OFF BY DEFAULT, so
     * the inert state is the state a fresh deployment ships in.
     *
     * Meanwhile /integrity §06, the Help Centre article and the settings screen all
     * described the return in the present tense, gated on nothing but the rate. A
     * nominee reading "you keep 50% of what your supporters raise" on a site that
     * sells no votes has been told something that cannot happen — the exact shape
     * this codebase has paid for six times over (docs/CODEBASE-INDEX.md §19), and the
     * worse half of it, because the prose is here promising the behaviour rather than
     * merely describing it.
     *
     * ── AND WHY {@see accrue()} IS DELIBERATELY NOT GATED ON THIS ────────────
     *
     * An operator switching paid voting off must not retrospectively cancel a share
     * on money already taken. A webhook can land days after its order — the contract
     * was live when the supporter paid, and this ledger's whole principle is that a
     * contribution's own moment decides it, forwards and backwards alike. So the gate
     * is on the PROMISE, never on the payment, and balances already earned are
     * untouched by it.
     */
    public static function active(): bool
    {
        return PaidVoteService::enabled();
    }

    /**
     * The same rules, shaped for PUBLISHING rather than for arithmetic.
     *
     * /integrity and the Help Centre both state these numbers, and both were
     * deriving them from a raw ruleset with their own copy of the basis-points
     * formatting. Two copies of one conversion is how a page ends up saying "30%"
     * while an article says "30.00%" about the same setting — trivially wrong, and
     * exactly the kind of wrong that makes a reader stop believing the rest.
     *
     * Takes an effective ruleset rather than a nominee, because the public pages are
     * describing the programme's rule in general and have no nominee in hand.
     *
     * @param  array<string,mixed> $eff a RuleEngine::effective() result
     * @return array{pct:string, bps:int, threshold:int, cap_pct:int, cap_votes:int,
     *                min_supporters:int, on:bool, off_reason:string}
     */
    public static function displayRules(array $eff): array
    {
        $bps       = max(0, min(10000, (int) ($eff['community_return_bps'] ?? self::FALLBACK_RATE_BPS)));
        $threshold = max(1, (int) ($eff['community_return_vote_threshold'] ?? self::FALLBACK_VOTE_THRESHOLD));
        $capPct    = max(1, min(100, (int) ($eff['community_return_supporter_cap_pct'] ?? self::FALLBACK_CAP_PCT)));

        // Basis points → a percentage somebody can read: 3000 → "30", 1250 → "12.5".
        // Never a trailing ".0", because "30.0%" in a sentence reads like a
        // measurement taken rather than a rule decided.
        $pct = rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');

        // TWO reasons this can be off, and they are not interchangeable. "The share is
        // set to 0%" is a decision an operator made about a live mechanism; "no votes
        // are sold here" is the mechanism having no input at all. A page that prints
        // one sentence for both tells half its readers something false, so the cause
        // travels with the flag rather than being re-derived by each caller.
        $paid = self::active();

        return [
            'pct'            => $pct === '' ? '0' : $pct,
            'bps'            => $bps,
            'threshold'      => $threshold,
            'cap_pct'        => $capPct,
            'cap_votes'      => max(1, (int) ceil($threshold * $capPct / 100)),
            'min_supporters' => (int) ceil(100 / $capPct),
            'on'             => $bps > 0 && $paid,
            // Paid voting first: with no vote sales the rate is moot, and naming the
            // rate there would send an operator to fix a setting that changes nothing.
            'off_reason'     => !$paid ? 'no_paid_voting' : ($bps > 0 ? '' : 'rate_zero'),
        ];
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
     * Every distinct supporter of this nominee, and how many votes each brought.
     *
     * ── WHY THE TWO HALVES ARE FETCHED DIFFERENTLY ───────────────────────────
     *
     * Free votes carry the voter's identity on the vote row: `voter_email_hash` is
     * sha256(lower(trim(email))), one row per person per category.
     *
     * Purchased votes DO NOT. {@see PaidVoteService::mint()} writes a single vote row
     * per order with a SYNTHETIC hash — 'paidvote:<order>:<random>' — precisely so a
     * buyer cannot collide with the one-vote-per-category unique key. Grouping
     * gates_votes by hash would therefore read every order as a separate stranger,
     * which is exactly the concentration this cap exists to catch. The buyer's real
     * identity lives on the ORDER, so the paid half is tallied from gates_donations
     * and hashed with the same recipe to land in the same bucket.
     *
     * Refunded and charge-backed orders are excluded: money that went back did not
     * raise anything, and letting it qualify somebody would make a card that is
     * charged and reversed a free ticket over the line.
     *
     * @param  string|null $asOf ISO timestamp — count only support at or before it
     * @return array<string,int> hashed identity => votes
     */
    public static function supportLedger(int $nomineeId, ?string $asOf = null): array
    {
        $byPerson = [];

        // Free, code-verified votes. One row is one vote by one person.
        try {
            $q = DB::table('gates_votes')->where('nominee_id', $nomineeId)->whereNull('donation_id');
            if ($asOf !== null) $q->where('voted_at', '<=', $asOf);
            foreach ($q->pluck('voter_email_hash') as $h) {
                $key = (string) $h;
                if ($key === '') continue;
                $byPerson[$key] = ($byPerson[$key] ?? 0) + 1;
            }
        } catch (\Throwable) {}

        // Purchased votes, folded in under the buyer's real hashed identity.
        try {
            $q = DB::table('gates_donations')
                ->where('intent_nominee_id', $nomineeId)
                ->where('status', 'confirmed')
                ->whereNull('refunded_at')
                ->whereNotNull('donor_email');
            if ($asOf !== null) $q->where('created_at', '<=', $asOf);

            foreach ($q->get(['donor_email', 'votes_used']) as $d) {
                $email = strtolower(trim((string) $d->donor_email));
                if ($email === '') continue;
                $key   = hash('sha256', $email);
                // votes_used is what actually minted. An order that was charged but
                // never delivered has raised money and delivered nothing; counting a
                // quantity it never got would qualify a nominee on votes that are not
                // on the board. It counts once the votes exist.
                $votes = max(0, (int) ($d->votes_used ?? 0));
                if ($votes === 0) continue;
                $byPerson[$key] = ($byPerson[$key] ?? 0) + $votes;
            }
        } catch (\Throwable) {}

        return $byPerson;
    }

    /**
     * How many DISTINCT people have backed this nominee, however they showed up.
     *
     * Kept as its own method because it is the figure worth SHOWING — "180 people"
     * is a sentence about a community, where "1,240 qualifying votes" is a sentence
     * about a rule.
     */
    public static function supporterCount(int $nomineeId, ?string $asOf = null): int
    {
        return count(self::supportLedger($nomineeId, $asOf));
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
     * @return array{qualified:bool, counted:int, raw:int, threshold:int, remaining:int,
     *                cap:int, cap_pct:int, supporters:int, min_supporters:int, capped:int}
     */
    public static function qualification(int $nomineeId, ?string $asOf = null): array
    {
        $threshold = self::voteThreshold($nomineeId);
        $capVotes  = self::supporterCapVotes($nomineeId);

        // Fetched ONCE. This runs inside the mint transaction on every paid vote, and
        // it is two queries; asking twice in one call to also count the keys would
        // double that on the hottest path the platform has.
        $ledger = self::supportLedger($nomineeId, $asOf);

        $counted = 0; $raw = 0; $capped = 0;
        foreach ($ledger as $votes) {
            $raw += $votes;
            if ($votes > $capVotes) { $capped++; $counted += $capVotes; }
            else                    { $counted += $votes; }
        }

        return [
            'qualified'      => $counted >= $threshold,
            // What the rule counted, after each supporter was capped.
            'counted'        => $counted,
            // …and what they actually gave. Published beside it deliberately: a
            // nominee whose two numbers are far apart is being told, without anybody
            // having to accuse them, that their support is concentrated in very few
            // hands and more of the same will not move the line.
            'raw'            => $raw,
            'threshold'      => $threshold,
            'remaining'      => max(0, $threshold - $counted),
            'cap'            => $capVotes,
            'cap_pct'        => self::supporterCapPct($nomineeId),
            'supporters'     => count($ledger),
            'min_supporters' => self::minSupporters($nomineeId),
            'capped'         => $capped,
        ];
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
     * @return array{raised_kobo:int, share_kobo:int, payable_kobo:int, held_kobo:int,
     *               entries:int, withdrawable_kobo:int,
     *               claim_block:?array{code:string,reason:string}}
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

        $payable = self::payableFor($nomineeId, $rows);

        // ── TWO QUESTIONS, TWO NUMBERS ────────────────────────────────────────
        //
        // `payable_kobo` answers "have the cycle rules released this money" — earned,
        // results announced, integrity holds deducted. It is about the MONEY, and it is
        // deliberately unchanged: the finance screens, the statement and the nominee's
        // own view of what they have earned all mean exactly what they meant before.
        //
        // `withdrawable_kobo` answers the different question: "is there a verified person
        // to send it to, and may it go yet". That is claim state, and conflating the two
        // into one figure would either report zero earned for every unclaimed nominee on
        // the platform or pay out to whoever claimed a page an hour ago.
        //
        // WHY THE SECOND NUMBER HAD TO EXIST. Every contact on a nomination is told, in
        // writing, when a page is claimed: "No money moves on a claim less than 7 days
        // old." That sentence lived in one private constant inside the notification email
        // and was referenced by nothing else — no code anywhere read claim state before
        // money was described as available. A hijacked claim was worth cash the instant
        // it activated. This is the number a payout acts on, and ClaimGuard is what it
        // asks.
        $state = \AfricaGates\Services\ClaimGuard::payoutState($nomineeId);
        $withdrawable = $state['payable'] ? $payable : 0;

        return [
            'raised_kobo'  => max(0, $raised),
            'share_kobo'   => $share,
            'payable_kobo' => $payable,
            'held_kobo'    => max(0, $held),
            'entries'      => $rows->count(),
            // What may actually leave. Never greater than payable_kobo.
            'withdrawable_kobo' => $withdrawable,
            // Null when claim state is withholding nothing — so a reader can tell "zero
            // because nothing is earned yet" from "zero because we will not pay this
            // yet", which read identically before.
            'claim_block'  => $state['payable'] ? null
                              : ['code' => $state['code'], 'reason' => $state['reason']],
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
