<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Votes paid from a member's wallet — the community return they have earned, spent on
 * votes instead of withdrawn to a bank.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE RULES, AND THE LOOP THEY CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A community return is a share of what supporters raised in a nominee's name. Let the
 * nominee spend it on votes for themselves and, without rules, the money goes round:
 * the spend is a new contribution, the contribution earns a new share, the share is
 * spent again. That is a nominee buying their own support with money that came from it
 * — and it is the shape a launderer wants, stolen-card money in as contributions and out
 * as a "return" to a clean bank account. So a wallet vote:
 *
 *   1. EARNS NO RETURN. There is no gates_donations row for the accrual to read, so
 *      CommunityReturnService::accrue() never runs on it.
 *   2. DOES NOT COUNT TOWARDS QUALIFYING. CommunityReturnService::supportLedger() skips
 *      the hash prefix this class writes, so wallet votes never carry anybody over the
 *      threshold that starts a return.
 *   3. IS BOUGHT LIKE ANY OTHER VOTE. The same price and quantity ladder, the same
 *      per-order maximum, the same eligible-nominee rule and the same window — the card
 *      checkout's own functions, asked here, so the two can never drift apart. It counts
 *      in the public tally exactly as a card-bought vote does, and no further.
 *
 * ── WHERE THE MONEY GOES ─────────────────────────────────────────────────────
 *
 * Out of the nominee's community-return balance as a `payout` ledger entry — the type
 * that already means "left the balance" — and into the programme as vote income. On the
 * books that is a transfer from the returns reserve to operations, done by hand by the
 * operator (the admin community-return screen lists every one, with its reference). No
 * withdrawal fee: nothing goes to a bank.
 *
 * ── WHICH MONEY ──────────────────────────────────────────────────────────────
 *
 * Only a community-return pocket, and only what is AVAILABLE — released, past the claim
 * guard, not inside a withdrawal under review — read from the same figure a withdrawal
 * reads, under the same row lock. Referral earnings are paid out whole against the
 * credits they cover, so a part of them cannot be spent; points have their own vote path.
 */
final class WalletVotes
{
    /** The vote row's hash prefix: 'walletvote:<userId>:<random>'. Read by supportLedger and VoterReach. */
    public const HASH_PREFIX = 'walletvote:';

    public static function available(): bool
    {
        return PaidVoteService::enabled() && SchemaHas::table('gates_wallet_votes')
            && SchemaHas::table('gates_community_returns');
    }

    /**
     * The pockets this member can pay with, and what each holds now.
     *
     * @return list<array{key:string, label:string, sub:string, available_kobo:int}>
     */
    public static function sources(int $userId): array
    {
        if ($userId < 1 || !self::available()) return [];
        $out = [];
        try {
            foreach (MemberWallet::pockets($userId) as $p) {
                if ($p['kind'] !== 'return' || (int) $p['available_kobo'] <= 0) continue;
                $out[] = ['key' => (string) $p['key'], 'label' => (string) $p['label'], 'sub' => (string) $p['sub'],
                          'available_kobo' => (int) $p['available_kobo']];
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * Pay for votes from a wallet pocket. All or nothing: the debit, the vote and the
     * receipt land together or not at all, so there is never an unminted order for the
     * refund sweep to send to a gateway that never took the money.
     *
     * @return array{ok:bool, message:string, reference?:string, votes?:int, amount_kobo?:int}
     */
    public static function buy(int $userId, string $pocketKey, int $nomineeId, int $qty, bool $showName = false): array
    {
        $no = static fn (string $m): array => ['ok' => false, 'message' => $m];
        if (!self::available()) return $no('Paying from your wallet is not available right now.');
        if ($userId < 1) return $no('Sign in to pay from your wallet.');
        if (!preg_match('/^page-(\d+)$/', $pocketKey, $m)) return $no('Only a community return can pay for votes.');
        $sourceId = (int) $m[1];
        if (!in_array($sourceId, CommunityReturnWithdrawals::pagesFor($userId), true)) return $no('That pocket is not one of yours.');

        // Rule 3: the card checkout's own limits.
        if ($qty < 1) return $no('Choose how many votes.');
        $max = PaidVoteService::maxQtyForOrder();
        if ($qty > $max) return $no('That is more votes than one order can carry. The maximum is ' . number_format($max) . '.');
        $kobo = PaidVoteService::price($qty) * 100;

        $reference = 'AFG-WV-' . strtoupper(bin2hex(random_bytes(5)));
        try {
            $r = DB::transaction(static function () use ($userId, $sourceId, $nomineeId, $qty, $kobo, $reference, $showName): array {
                // The withdrawal path's own lock, on the page whose money this is, so a
                // withdrawal and a spend can never both read the same naira as unspent.
                DB::table('gates_nominees')->where('id', $sourceId)->lockForUpdate()->first();
                // The withdrawal path's checks, too: money a withdrawal would be HELD over
                // (fraud-flagged, a refund requested, a ledger that does not trace) must not
                // leave as public votes instead, which cannot be clawed back.
                if (WalletRisk::returnMoneyInDoubt($sourceId) !== null) {
                    return ['ok' => false, 'message' => 'Some of the money behind your community return is being checked, '
                        . 'so it cannot pay for votes yet. Nothing was taken from your wallet.'];
                }
                $b = CommunityReturnService::balance($sourceId);
                if ($b['claim_block'] !== null) {
                    return ['ok' => false, 'message' => 'Your community return is on hold: ' . (string) $b['claim_block']['reason']];
                }
                $have = (int) CommunityReturnService::wallet($sourceId)['available_kobo'];
                if ($have < $kobo) {
                    return ['ok' => false, 'message' => 'Your community return has ₦' . number_format($have / 100, 2)
                        . ' available, and ' . number_format($qty) . ' vote' . ($qty === 1 ? '' : 's') . ' cost ₦' . number_format($kobo / 100, 2) . '.'];
                }

                $nom = MergeService::notMerged(DB::table('gates_nominees')->where('id', $nomineeId)
                    ->whereIn('status', ['approved', 'winner', 'runner_up']))->lockForUpdate()->first();
                if (!$nom) return ['ok' => false, 'message' => 'That nominee is not open for votes.'];
                if (!PaidVoteService::checkoutOpenFor((int) $nom->category_id)) {
                    return ['ok' => false, 'message' => 'Voting with money has closed for this category. Free voting may still be open.'];
                }
                try {
                    BallotGuard::assertVotable((int) $nom->category_id);
                } catch (PhaseError $e) {
                    return ['ok' => false, 'message' => $e->getMessage()];
                }

                $now = Carbon::now()->toDateTimeString();
                $ctx = DB::table('gates_nominees as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                    ->where('n.id', $sourceId)->select('n.profile_id', 'c.cycle_id')->first();
                $ledgerId = (int) DB::table('gates_community_returns')->insertGetId([
                    'nominee_id'  => $sourceId,
                    'profile_id'  => $ctx->profile_id ?? null,
                    'cycle_id'    => $ctx->cycle_id ?? null,
                    'entry_type'  => 'payout',
                    'amount_kobo' => -1 * $kobo,
                    'note'        => mb_substr('votes · ' . $reference . ' · ' . number_format($qty) . ' for nominee #' . $nomineeId, 0, 400),
                    'created_by'  => null,
                    'created_at'  => $now,
                ]);

                $voteId = (int) DB::table('gates_votes')->insertGetId(OptionalColumn::filter('gates_votes', [
                    'nominee_id'       => $nomineeId,
                    'category_id'      => (int) $nom->category_id,
                    // Rules 1 and 2 hang on this prefix: no donation row, and a hash the
                    // qualifying count and the reach count both know.
                    'voter_email_hash' => self::HASH_PREFIX . $userId . ':' . bin2hex(random_bytes(6)),
                    'nominee_country'  => $nom->country_code ?? null,
                    'show_name'        => $showName ? 1 : 0,
                    'vote_type'        => 'paid',
                    'weight'           => $qty,
                    'voted_at'         => $now,
                ], ['show_name', 'nominee_country']));
                DB::table('gates_nominees')->where('id', $nomineeId)->increment('vote_count', $qty);

                DB::table('gates_wallet_votes')->insert([
                    'reference' => $reference, 'user_id' => $userId, 'source_nominee_id' => $sourceId,
                    'nominee_id' => $nomineeId, 'votes' => $qty, 'amount_kobo' => $kobo,
                    'vote_id' => $voteId, 'ledger_id' => $ledgerId, 'created_at' => $now,
                ]);
                return ['ok' => true, 'message' => number_format($qty) . ' vote' . ($qty === 1 ? '' : 's') . ' added, paid from your wallet (₦'
                    . number_format($kobo / 100, 2) . '). Reference ' . $reference . '.',
                    'reference' => $reference, 'votes' => $qty, 'amount_kobo' => $kobo];
            });
        } catch (\Throwable $e) {
            error_log('[wallet-votes] ' . $e->getMessage());
            return $no('That did not go through, and nothing was taken from your wallet. Please try again.');
        }
        if ($r['ok']) {
            try { (new CacheService())->forgetByTag('leaderboard'); } catch (\Throwable) {}
        }
        return $r;
    }
}
