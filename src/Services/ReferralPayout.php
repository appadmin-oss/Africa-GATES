<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Turning an owed referral balance into money that leaves.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY paid_out_at IS NEVER STAMPED BY A BUTTON ALONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_referral_credits.paid_out_at IS NULL` is the whole definition of "owed", and
 * every screen that reports what the platform owes reads it. So writing it is a claim that
 * money moved. {@see \AfricaGates\Admin\Controllers\PayoutsController} can only make that
 * claim by recording a transfer reference against a request that names its own credits,
 * under a named admin — the Finance panel deliberately shipped with no bare "mark as paid"
 * control for exactly this reason, because a stamp with no transfer behind it makes the
 * ledger say somebody was paid AND destroys the evidence that they were not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SET OF CREDITS IS FROZEN AT REQUEST TIME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A request stores the ids it covers, not just an amount. Two things follow, and both are
 * the point:
 *
 *   · A credit earned AFTER the request is not swept into it. The member asked for what
 *     they were owed on Tuesday; Wednesday's referral is Wednesday's business.
 *   · The amount cannot drift. Recomputing at payment time would let a settings change or
 *     a refund silently alter what is being paid against a figure both sides already
 *     agreed.
 *
 * A credit already inside an open request is excluded from the next one, so two requests
 * can never both claim the same naira.
 */
final class ReferralPayout
{
    /** Nothing below this is worth a bank transfer's fee and somebody's afternoon. */
    public const MIN_NAIRA = 1000;

    public const STATUSES = ['requested' => 'Requested', 'paid' => 'Paid', 'rejected' => 'Rejected'];

    // ══ the member's side ════════════════════════════════════════════════════

    /**
     * What this member could ask for right now, and why not if they cannot.
     *
     * @return array{ok:bool, amount:int, credits:list<int>, reason:string}
     */
    public static function available(int $userId): array
    {
        $no = static fn (string $why): array => ['ok' => false, 'amount' => 0, 'credits' => [], 'reason' => $why];

        if ($userId < 1) return $no('Sign in to see your referral balance.');

        $stats = ReferralService::stats($userId);
        if (!$stats['unlocked']) {
            return $no('You need ' . $stats['remaining'] . ' more paid referral'
                     . ($stats['remaining'] === 1 ? '' : 's') . ' before earnings can be withdrawn.');
        }

        // Anything already inside an open request is spoken for.
        $claimed = self::claimedCreditIds($userId);

        try {
            $rows = DB::table('gates_referral_credits')
                ->where('user_id', $userId)->whereNull('paid_out_at')
                ->get(['id', 'commission_naira']);
        } catch (\Throwable) {
            return $no('Your balance could not be read just now.');
        }

        $ids = [];
        $sum = 0;
        foreach ($rows as $r) {
            if (isset($claimed[(int) $r->id])) continue;
            $ids[] = (int) $r->id;
            $sum  += (int) $r->commission_naira;
        }

        if ($sum <= 0)                return $no('You have nothing waiting to be withdrawn.');
        if ($sum < self::MIN_NAIRA)   return $no('The smallest withdrawal is ₦' . number_format(self::MIN_NAIRA)
                                              . '. You have ₦' . number_format($sum) . ' so far.');

        return ['ok' => true, 'amount' => $sum, 'credits' => $ids, 'reason' => ''];
    }

    /**
     * Ask to be paid.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function request(int $userId, string $bank, string $accountName, string $accountNumber): array
    {
        if (self::openFor($userId) !== null) {
            return ['ok' => false, 'message' => 'You already have a withdrawal waiting. '
                                             . 'We will email you when it has been paid.'];
        }

        $avail = self::available($userId);
        if (!$avail['ok']) return ['ok' => false, 'message' => $avail['reason']];

        $bank    = trim($bank);
        $name    = trim($accountName);
        // Digits only: a pasted account number arrives with spaces and dashes as often as not.
        $number  = preg_replace('/\D+/', '', $accountNumber) ?? '';

        if ($bank === '' || $name === '') {
            return ['ok' => false, 'message' => 'We need the bank name and the account name.'];
        }
        if (strlen($number) < 8 || strlen($number) > 20) {
            return ['ok' => false, 'message' => 'That account number does not look right.'];
        }

        try {
            $id = (int) DB::table('gates_referral_payouts')->insertGetId([
                'user_id'        => $userId,
                'amount_naira'   => $avail['amount'],
                'credit_ids'     => json_encode($avail['credits']),
                'status'         => 'requested',
                'bank_name'      => mb_substr($bank, 0, 120),
                'account_name'   => mb_substr($name, 0, 160),
                'account_number' => mb_substr($number, 0, 32),
                'requested_at'   => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'The request could not be saved just now.'];
        }

        return ['ok' => true, 'id' => $id,
                'message' => 'Requested ₦' . number_format($avail['amount'])
                           . '. Withdrawals are paid by bank transfer — you will hear from us when it is done.'];
    }

    /** This member's open request, if any. */
    public static function openFor(int $userId): ?object
    {
        try {
            return DB::table('gates_referral_payouts')
                ->where('user_id', $userId)->where('status', 'requested')
                ->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Their history, newest first. @return list<object> */
    public static function historyFor(int $userId, int $limit = 20): array
    {
        try {
            return DB::table('gates_referral_payouts')->where('user_id', $userId)
                ->orderByDesc('id')->limit(max(1, $limit))->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    // ══ the admin's side ═════════════════════════════════════════════════════

    /**
     * The queue, with the member attached.
     *
     * @return list<array<string,mixed>>
     */
    public static function queue(string $status = 'requested', int $limit = 100): array
    {
        try {
            $rows = DB::table('gates_referral_payouts')
                ->when($status !== 'all', static fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->limit(max(1, $limit))->get();
        } catch (\Throwable) {
            return [];
        }

        $ids   = array_values(array_unique(array_map(static fn ($r): int => (int) $r->user_id, $rows->all())));
        $users = [];
        if ($ids !== []) {
            try {
                foreach (DB::table('gates_users')->whereIn('id', $ids)->get(['id', 'name', 'email']) as $u) {
                    $users[(int) $u->id] = $u;
                }
            } catch (\Throwable) {}
        }

        $out = [];
        foreach ($rows as $r) {
            $u = $users[(int) $r->user_id] ?? null;
            $out[] = [
                'row'   => $r,
                'name'  => (string) ($u->name ?? ('Member #' . $r->user_id)),
                'email' => (string) ($u->email ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Record that a transfer happened.
     *
     * ── THE REFERENCE IS REQUIRED, AND THAT IS THE WHOLE MECHANISM ───────────
     *
     * It is what makes this a record of a transfer rather than a claim that one occurred.
     * Without it there is nothing to reconcile a bank statement against, and "paid" means
     * only that somebody clicked.
     *
     * The credits are stamped from the ids the REQUEST froze, so the amount paid and the
     * amount cleared are the same figure by construction.
     *
     * @return array{ok:bool, message:string}
     */
    public static function markPaid(int $id, string $reference, int $adminId = 0): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['ok' => false, 'message' => 'Record the bank transfer reference. It is what makes this a '
                                             . 'record of a payment rather than a claim that one happened.'];
        }

        $p = self::find($id);
        if (!$p)                              return ['ok' => false, 'message' => 'No such request.'];
        if ((string) $p->status !== 'requested') {
            return ['ok' => false, 'message' => 'That request is already ' . $p->status . '.'];
        }

        $ids = json_decode((string) ($p->credit_ids ?? '[]'), true);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        $now = Carbon::now()->toDateTimeString();

        try {
            // The credits first. If this succeeds and the status write fails, the request
            // shows as open against credits already cleared — visible and fixable. The
            // other order would report a payment whose credits are still owed, which reads
            // as money that has to be paid twice.
            if ($ids !== []) {
                DB::table('gates_referral_credits')->whereIn('id', $ids)
                    ->whereNull('paid_out_at')->update(['paid_out_at' => $now]);
            }
            DB::table('gates_referral_payouts')->where('id', $id)->update([
                'status' => 'paid', 'payment_ref' => mb_substr($reference, 0, 120),
                'settled_at' => $now, 'settled_by' => $adminId ?: null,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not record it: ' . $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Recorded ₦' . number_format((int) $p->amount_naira)
                                        . ' paid to ' . $p->account_name . ' (' . $reference . ').'];
    }

    /**
     * Refuse a request, with a reason the member can read.
     *
     * The credits stay owed — a refused request is not a refused debt, and the commonest
     * reason to refuse one is wrong bank details.
     *
     * @return array{ok:bool, message:string}
     */
    public static function reject(int $id, string $why, int $adminId = 0): array
    {
        $why = trim($why);
        if ($why === '') return ['ok' => false, 'message' => 'Say why, so the member can fix it and ask again.'];

        $p = self::find($id);
        if (!$p)                                 return ['ok' => false, 'message' => 'No such request.'];
        if ((string) $p->status !== 'requested') return ['ok' => false, 'message' => 'That request is already ' . $p->status . '.'];

        try {
            DB::table('gates_referral_payouts')->where('id', $id)->update([
                'status' => 'rejected', 'note' => mb_substr($why, 0, 400),
                'settled_at' => Carbon::now()->toDateTimeString(), 'settled_by' => $adminId ?: null,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not record it: ' . $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Refused, and the balance stays owed.'];
    }

    public static function find(int $id): ?object
    {
        try {
            return DB::table('gates_referral_payouts')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Totals for the admin screen. @return array{pending:int, pending_naira:int, paid_naira:int} */
    public static function totals(): array
    {
        $out = ['pending' => 0, 'pending_naira' => 0, 'paid_naira' => 0];
        try {
            $out['pending']       = (int) DB::table('gates_referral_payouts')->where('status', 'requested')->count();
            $out['pending_naira'] = (int) DB::table('gates_referral_payouts')->where('status', 'requested')->sum('amount_naira');
            $out['paid_naira']    = (int) DB::table('gates_referral_payouts')->where('status', 'paid')->sum('amount_naira');
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * Credit ids already inside an open request for this member.
     *
     * @return array<int,true>
     */
    private static function claimedCreditIds(int $userId): array
    {
        $out = [];
        try {
            foreach (DB::table('gates_referral_payouts')
                         ->where('user_id', $userId)->where('status', 'requested')
                         ->pluck('credit_ids') as $json) {
                foreach ((array) json_decode((string) $json, true) as $id) $out[(int) $id] = true;
            }
        } catch (\Throwable) {}
        return $out;
    }
}
