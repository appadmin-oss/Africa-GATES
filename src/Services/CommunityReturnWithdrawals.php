<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A nominee asking for their community return.
 *
 * ── THE SIX CONDITIONS ───────────────────────────────────────────────────────
 *
 * The brief (owner, 16 Oct 2026) makes withdrawal conditional on six things, and each is
 * a check this class runs and SHOWS, so a nominee who cannot withdraw is told which one
 * is missing rather than handed a disabled button:
 *
 *   1. released      the money is released — the edition's milestone is confirmed (or, under
 *                    the threshold model, its results are announced)
 *   2. identity      the person asking holds the page's verified claim, past its cooling-off
 *                    window and not disputed ({@see ClaimGuard}, the one authority on that)
 *   3. account       bank details are given; the reviewer confirms the account is in the
 *                    nominee's own name — the claim email already promises "any payment can
 *                    only ever go to a bank account in the nominee's own name"
 *   4. valid         every share behind the balance traces to a confirmed, unrefunded,
 *                    unflagged contribution ({@see CommunityReturnService::audit()})
 *   5. rules         the withdrawal terms are published, and the nominee has accepted them
 *   6. review        a person reviews the request — always outstanding at request time
 *
 * ── SHAPED ON REFERRAL PAYOUTS, ON PURPOSE ───────────────────────────────────
 *
 * {@see ReferralPayout} already runs a human-reviewed bank-transfer flow and has paid for
 * its lessons: one open request decided under a row lock, bank details copied onto the
 * request, and "paid" recorded only against a transfer REFERENCE, because without one there
 * is nothing to reconcile a bank statement against. Each of those is repeated here.
 *
 * ── THE AMOUNT IS FROZEN; THE BALANCE IS RE-CHECKED ──────────────────────────
 *
 * A request is for what was available when it was asked, and that figure does not drift.
 * But a hold, a refund or a dispute can arrive between asking and paying — so approving and
 * paying both re-check that the balance still covers the request, and refuse if it does
 * not. Rejecting puts nothing back because nothing was taken: the money only leaves the
 * ledger when the payment is recorded.
 */
final class CommunityReturnWithdrawals
{
    public const WD_REQUESTED = 'requested';
    public const WD_APPROVED  = 'approved';
    public const WD_PAID      = 'paid';
    public const WD_REJECTED  = 'rejected';

    /** Statuses that still hold money against the balance. */
    public const OPEN = [self::WD_REQUESTED, self::WD_APPROVED];

    public const STATUSES = [
        self::WD_REQUESTED => 'Requested',
        self::WD_APPROVED  => 'Approved',
        self::WD_PAID      => 'Paid',
        self::WD_REJECTED  => 'Rejected',
    ];

    // ══ the terms ══════════════════════════════════════════════════════════════

    /**
     * The withdrawal terms, as published. One reader for the page and the arithmetic.
     *
     * Global rules, not per edition: a withdrawal draws on every edition a nominee earned
     * in, so its terms cannot depend on which one.
     *
     * @return array{min_kobo:int, fee_text:string, payout_days:int, appeal_days:int,
     *               unclaimed_days:int, tax_note:string, published:bool}
     */
    public static function terms(?array $eff = null): array
    {
        $eff ??= (new RuleEngine())->effective();
        $tax = trim((string) ($eff['community_return_tax_note'] ?? ''));
        return [
            'min_kobo'       => max(0, (int) ($eff['community_return_min_withdrawal_naira'] ?? 1000)) * 100,
            // The wallet's fee schedule, in words; WalletFee::forKobo() does the arithmetic.
            'fee_text'       => WalletFee::describe($eff),
            'payout_days'    => max(1, (int) ($eff['community_return_payout_days'] ?? 10)),
            'appeal_days'    => max(1, (int) ($eff['community_return_appeal_days'] ?? 14)),
            'unclaimed_days' => max(1, (int) ($eff['community_return_unclaimed_days'] ?? 365)),
            'tax_note'       => $tax,
            // Published means an operator said so AND the one term no default can supply is
            // present. A flag alone could be ticked over an empty tax position.
            'published'      => (bool) ($eff['community_return_terms_published'] ?? false) && $tax !== '',
            // Whether a withdrawal that passes every check is sent without a person. Read
            // from the engine, so the published terms cannot promise a review that no longer
            // happens, or speed that is switched off.
            'automatic'      => WalletAutoPay::enabled(null, $eff),
        ];
    }

    // ══ the nominee's side ═════════════════════════════════════════════════════

    /**
     * The six conditions, for this nominee and the member asking.
     *
     * @return array{ok:bool, amount_kobo:int, fee_kobo:int, reason:string,
     *               checks:list<array{key:string, label:string, ok:bool, detail:string}>}
     */
    public static function checks(int $nomineeId, int $userId, bool $accepted = false, ?array $bank = null,
                                  bool $beforeForm = false, ?int $amountKobo = null): array
    {
        $terms  = self::terms();
        $wallet = CommunityReturnService::wallet($nomineeId);
        $open   = self::openFor($nomineeId);
        $ctl    = ClaimGuard::controlledBy($nomineeId);
        $state  = ClaimGuard::payoutState($nomineeId);
        $available = $wallet['available_kobo'];
        // Part of the balance may be asked for, as from any wallet; null means all of it.
        $amount = $amountKobo === null ? $available : max(0, $amountKobo);

        $checks = [];

        $checks[] = ['key' => 'released', 'label' => 'Money released', 'ok' => $available > 0,
            'detail' => $available > 0
                ? '₦' . CommunityReturnService::naira($available) . ' is available.'
                : ($wallet['locked_kobo'] > 0
                    ? '₦' . CommunityReturnService::naira($wallet['locked_kobo']) . ' is locked until your edition’s milestone is confirmed.'
                    : ($wallet['awaiting_kobo'] > 0
                        ? '₦' . CommunityReturnService::naira($wallet['awaiting_kobo']) . ' is released when your edition announces its results.'
                        : 'Nothing is available yet.'))];

        $isClaimant = $ctl !== null && (int) ($ctl['user_id'] ?? 0) === $userId && $userId > 0;
        $checks[] = ['key' => 'identity', 'label' => 'Verified claim', 'ok' => $isClaimant && $state['payable'],
            'detail' => !$isClaimant ? 'Only the person holding this page’s verified claim can ask for its return.'
                : ($state['payable'] ? 'Your claim is verified.' : (string) $state['reason'])];

        // Before the form is drawn the account is not a condition yet: the form is where it
        // is given. Asking it there kept the form from ever appearing for somebody with no
        // saved details — the one person who needed it.
        $bankOk = $beforeForm || ($bank !== null && self::bankProblem($bank) === null);
        $checks[] = ['key' => 'account', 'label' => 'Payout account', 'ok' => $bankOk,
            'detail' => $beforeForm ? 'Given on the form, in your own name; a reviewer confirms it before paying.'
                : ($bankOk ? 'A reviewer confirms the account is in your own name before paying.'
                : ($bank === null ? 'Give the bank account it should go to, in your own name.' : (string) self::bankProblem($bank)))];

        $bad = CommunityReturnService::audit(null, $nomineeId);
        $checks[] = ['key' => 'valid', 'label' => 'Valid contributions', 'ok' => $bad === [],
            'detail' => $bad === [] ? 'Every share traces to a confirmed contribution.'
                : 'Some entries need checking first; our team has been shown which.'];

        $checks[] = ['key' => 'rules', 'label' => 'Published rules accepted', 'ok' => $terms['published'] && $accepted,
            'detail' => !$terms['published'] ? 'Withdrawals open once the withdrawal terms are published.'
                : ($accepted ? 'You accepted the published rules.' : 'Read and accept the published rules.')];

        $checks[] = ['key' => 'review', 'label' => 'Payout review', 'ok' => true,
            'detail' => $terms['automatic']
                ? 'Checked automatically and usually sent within minutes; anything that needs a closer look is checked by a person first, within '
                  . $terms['payout_days'] . ' working days.'
                : 'A person reviews every request before it is paid, usually within '
                  . $terms['payout_days'] . ' working days.'];

        $reason = '';
        foreach ($checks as $c) { if (!$c['ok']) { $reason = $c['detail']; break; } }
        if ($reason === '' && $open !== null) {
            $reason = 'You already have a withdrawal being reviewed.';
        }
        if ($reason === '' && $amount > $available) {
            $reason = 'You can ask for up to ₦' . CommunityReturnService::naira($available) . '.';
        }
        if ($reason === '' && $amount < $terms['min_kobo']) {
            $reason = 'The smallest withdrawal is ₦' . CommunityReturnService::naira($terms['min_kobo'])
                    . ($amountKobo === null ? '. You have ₦' . CommunityReturnService::naira($amount) . '.' : '.');
        }
        $fee = WalletFee::forKobo($amount);
        if ($reason === '' && $fee >= $amount) {
            $reason = 'The withdrawal fee would take the whole amount.';
        }

        return ['ok' => $reason === '', 'amount_kobo' => $amount, 'fee_kobo' => $fee,
                'available_kobo' => $available, 'reason' => $reason, 'checks' => $checks];
    }

    /**
     * Ask for the available balance.
     *
     * @param array{bank:string, account_name:string, account_number:string} $bank
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function request(int $nomineeId, int $userId, array $bank, bool $accepted, ?int $amountKobo = null): array
    {
        $bank = self::cleanBank($bank);

        $pre = self::checks($nomineeId, $userId, $accepted, $bank, false, $amountKobo);
        if (!$pre['ok']) return ['ok' => false, 'message' => $pre['reason']];

        // ── ONE OPEN REQUEST, DECIDED UNDER A LOCK ───────────────────────────
        //
        // The same race ReferralPayout::request() closed: two submits in one second each
        // see no open request and each freeze the same balance. The checks run again inside
        // a transaction holding the nominee's row, so the second waits and then finds the
        // first. SQLite has no row lock and serialises the write instead — the same answer.
        try {
            $out = DB::transaction(static function () use ($nomineeId, $userId, $bank, $accepted, $amountKobo): array {
                DB::table('gates_nominees')->where('id', $nomineeId)->lockForUpdate()->first();

                $c = self::checks($nomineeId, $userId, $accepted, $bank, false, $amountKobo);
                if (!$c['ok']) return ['ok' => false, 'message' => $c['reason']];

                $now = Carbon::now()->toDateTimeString();
                $id  = (int) DB::table('gates_return_withdrawals')->insertGetId([
                    'nominee_id'        => $nomineeId,
                    'user_id'           => $userId,
                    'amount_kobo'       => $c['amount_kobo'],
                    'fee_kobo'          => $c['fee_kobo'],
                    'status'            => self::WD_REQUESTED,
                    'bank_name'         => $bank['bank'],
                    'account_name'      => $bank['account_name'],
                    'account_number'    => $bank['account_number'],
                    'checks_json'       => json_encode($c['checks']),
                    'rules_accepted_at' => $now,
                    'requested_at'      => $now,
                ]);
                return ['ok' => true, 'id' => $id, 'amount' => $c['amount_kobo'], 'fee' => $c['fee_kobo']];
            });
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'The request could not be saved just now.'];
        }
        if (!$out['ok']) return $out;

        $net = (int) $out['amount'] - (int) $out['fee'];
        return ['ok' => true, 'id' => (int) $out['id'],
                'message' => 'Requested ₦' . CommunityReturnService::naira((int) $out['amount'])
                           . ($out['fee'] > 0 ? ' (₦' . CommunityReturnService::naira($net) . ' after the fee)' : '')
                           . '. A person will review it, and we will email you when it is paid.'];
    }

    /**
     * The nominee pages this member holds an active claim on AND that have anything on the
     * ledger — the pages their account's Community return section is about.
     *
     * One person can be several nominee rows (one per category), each with its own ledger
     * and its own claim, so this is a list. A disputed claim is not "held": control reverts
     * while a dispute stands (ClaimGuard::controlledBy()).
     *
     * @return list<int>
     */
    public static function pagesFor(int $userId): array
    {
        if ($userId < 1) return [];
        try {
            return DB::table('gates_nominee_claims as c')
                ->where('c.user_id', $userId)->where('c.status', 'active')->whereNull('c.disputed_at')
                ->whereExists(static function ($q) {
                    $q->select(DB::raw(1))->from('gates_community_returns as r')->whereColumn('r.nominee_id', 'c.nominee_id');
                })
                ->distinct()->orderBy('c.nominee_id')->pluck('c.nominee_id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** This nominee's open request, if any. */
    public static function openFor(int $nomineeId): ?object
    {
        try {
            return DB::table('gates_return_withdrawals')->where('nominee_id', $nomineeId)
                ->whereIn('status', self::OPEN)->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<object> newest first */
    public static function historyFor(int $nomineeId, int $limit = 20): array
    {
        try {
            return DB::table('gates_return_withdrawals')->where('nominee_id', $nomineeId)
                ->orderByDesc('id')->limit(max(1, $limit))->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** What open requests are holding against this nominee's balance. */
    public static function underReviewKobo(int $nomineeId): int
    {
        try {
            return (int) DB::table('gates_return_withdrawals')->where('nominee_id', $nomineeId)
                ->whereIn('status', self::OPEN)->sum('amount_kobo');
        } catch (\Throwable) {
            return 0;   // no table yet: nothing can be under review
        }
    }

    /**
     * The address of the member holding this page's claim, or ''.
     *
     * The claim is the platform's proof of who the nominee is; a nomination's contact
     * fields are what somebody ELSE typed about them, and are not where money news goes.
     */
    public static function claimantEmail(int $nomineeId): string
    {
        $ctl = ClaimGuard::controlledBy($nomineeId);
        $uid = (int) ($ctl['user_id'] ?? 0);
        if ($uid < 1) return '';
        try {
            $e = (string) (DB::table('gates_users')->where('id', $uid)->value('email') ?? '');
        } catch (\Throwable) {
            return '';
        }
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    // ══ the reviewer's side ════════════════════════════════════════════════════

    /**
     * Approve after review: the person has checked the account is the nominee's own.
     *
     * @return array{ok:bool, message:string}
     */
    public static function approve(int $id, int $adminId, string $note = ''): array
    {
        $w = self::find($id);
        if (!$w) return ['ok' => false, 'message' => 'No such request.'];
        if ((string) $w->status !== self::WD_REQUESTED) return ['ok' => false, 'message' => 'That request is already ' . $w->status . '.'];
        if ($why = self::stillCovered($w)) return ['ok' => false, 'message' => $why];

        DB::table('gates_return_withdrawals')->where('id', $id)->where('status', self::WD_REQUESTED)->update([
            'status' => self::WD_APPROVED, 'reviewed_by' => $adminId > 0 ? $adminId : null,
            'reviewed_at' => Carbon::now()->toDateTimeString(),
            'review_note' => mb_substr(trim($note), 0, 400) ?: null,
        ]);
        return ['ok' => true, 'message' => 'Approved. Transfer the money, then record the reference.'];
    }

    /**
     * Record that the transfer happened. The reference is required, and it is the whole
     * mechanism — see ReferralPayout::markPaid().
     *
     * The ledger's `payout` entry and the request's status are written in ONE transaction:
     * a paid request whose money is still on the ledger reads as money owed twice, and a
     * payout row with no paid request is a balance that fell for no recorded reason.
     *
     * @return array{ok:bool, message:string}
     */
    public static function markPaid(int $id, string $reference, int $adminId, bool $alreadySent = false, ?PaymentService $ps = null): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['ok' => false, 'message' => 'Record the bank transfer reference. It is what makes this a '
                                             . 'record of a payment rather than a claim that one happened.'];
        }
        $w = self::find($id);
        if (!$w) return ['ok' => false, 'message' => 'No such request.'];
        if (!$alreadySent && ($busy = WalletAutoPay::busy($w, $ps))) return ['ok' => false, 'message' => $busy];
        if ((string) $w->status !== self::WD_APPROVED) {
            return ['ok' => false, 'message' => (string) $w->status === self::WD_REQUESTED
                ? 'Review and approve it first.' : 'That request is already ' . $w->status . '.'];
        }
        // `$alreadySent` is the automatic payout reporting a transfer the GATEWAY has
        // confirmed. The money has left; refusing to record it would leave it on the ledger
        // as still owed — a second payment waiting to happen. So it is recorded whatever the
        // balance now says, and a balance that dips below zero is the truth an operator
        // needs to see (WalletAutoPay raises it), not something to hide by refusing.
        if (!$alreadySent && ($why = self::stillCovered($w))) return ['ok' => false, 'message' => $why];

        try {
            DB::transaction(static function () use ($w, $reference, $adminId): void {
                $now = Carbon::now()->toDateTimeString();
                $n = DB::table('gates_return_withdrawals')->where('id', (int) $w->id)
                    ->where('status', self::WD_APPROVED)->update([
                        'status' => self::WD_PAID, 'payment_ref' => mb_substr($reference, 0, 120),
                        'paid_by' => $adminId > 0 ? $adminId : null, 'paid_at' => $now,
                    ]);
                // Somebody else recorded it a moment earlier: write nothing.
                if ($n < 1) throw new \RuntimeException('already settled');

                $ctx = DB::table('gates_nominees as n')
                    ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                    ->where('n.id', (int) $w->nominee_id)->select('n.profile_id', 'c.cycle_id')->first();

                DB::table('gates_community_returns')->insert([
                    'nominee_id'  => (int) $w->nominee_id,
                    'profile_id'  => $ctx->profile_id ?? null,
                    'cycle_id'    => $ctx->cycle_id ?? null,
                    'entry_type'  => 'payout',
                    'amount_kobo' => -1 * (int) $w->amount_kobo,
                    'note'        => mb_substr('withdrawal #' . (int) $w->id . ' · ' . $reference, 0, 400),
                    'created_by'  => $adminId > 0 ? $adminId : null,
                    'created_at'  => $now,
                ]);
            });
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Could not record it — it may already have been recorded. Reload and check.'];
        }

        self::tell((int) $w->id, 'Your community return has been paid',
            'We have paid your community return of ₦' . CommunityReturnService::naira((int) $w->amount_kobo - (int) $w->fee_kobo)
            . ' to ' . $w->account_name . ".\nTransfer reference: " . $reference . '.');

        return ['ok' => true, 'message' => 'Recorded ₦' . CommunityReturnService::naira((int) $w->amount_kobo)
                                        . ' paid to ' . $w->account_name . ' (' . $reference . ').'];
    }

    /**
     * Refuse, with a reason the nominee can read. Nothing comes back to the balance because
     * nothing left it — the money stays theirs and they can ask again.
     *
     * @return array{ok:bool, message:string}
     */
    public static function reject(int $id, string $why, int $adminId, ?PaymentService $ps = null): array
    {
        $why = trim($why);
        if ($why === '') return ['ok' => false, 'message' => 'Say why, so the nominee can fix it and ask again.'];
        $w = self::find($id);
        if (!$w) return ['ok' => false, 'message' => 'No such request.'];
        if (!in_array((string) $w->status, self::OPEN, true)) return ['ok' => false, 'message' => 'That request is already ' . $w->status . '.'];
        if ($busy = WalletAutoPay::busy($w, $ps)) return ['ok' => false, 'message' => $busy];

        DB::table('gates_return_withdrawals')->where('id', $id)->whereIn('status', self::OPEN)->update([
            'status' => self::WD_REJECTED, 'reviewed_by' => $adminId > 0 ? $adminId : null,
            'reviewed_at' => Carbon::now()->toDateTimeString(), 'review_note' => mb_substr($why, 0, 400),
        ]);

        $terms = self::terms();
        self::tell($id, 'About your community return request',
            "We could not pay your community return request.\n\nReason: " . $why
            . "\n\nThe money is still yours. You can correct this and ask again from your account, or appeal within "
            . $terms['appeal_days'] . ' days by replying to this email.');

        return ['ok' => true, 'message' => 'Refused. The balance stays the nominee’s.'];
    }

    /** @return list<array<string,mixed>> the queue, with nominee names */
    public static function queue(string $status = self::WD_REQUESTED, int $limit = 100): array
    {
        try {
            $rows = DB::table('gates_return_withdrawals as w')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'w.nominee_id')
                ->when($status !== 'all', static fn ($q) => $q->where('w.status', $status))
                ->orderByDesc('w.id')->limit(max(1, $limit))
                ->get(['w.*', 'n.name as nominee_name']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['row' => $r, 'checks' => (array) json_decode((string) ($r->checks_json ?? '[]'), true),
                      'found' => WalletAutoPay::findings($r)];
        }
        return $out;
    }

    /** @return array{open:int, open_kobo:int, paid_kobo:int} */
    public static function totals(): array
    {
        $t = ['open' => 0, 'open_kobo' => 0, 'paid_kobo' => 0];
        try {
            $t['open']      = (int) DB::table('gates_return_withdrawals')->whereIn('status', self::OPEN)->count();
            $t['open_kobo'] = (int) DB::table('gates_return_withdrawals')->whereIn('status', self::OPEN)->sum('amount_kobo');
            $t['paid_kobo'] = (int) DB::table('gates_return_withdrawals')->where('status', self::WD_PAID)->sum('amount_kobo');
        } catch (\Throwable) {}
        return $t;
    }

    public static function find(int $id): ?object
    {
        try {
            return DB::table('gates_return_withdrawals')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    // ══ internals ══════════════════════════════════════════════════════════════

    /** Null when the balance still covers this open request; otherwise why not. */
    private static function stillCovered(object $w): ?string
    {
        $b = CommunityReturnService::balance((int) $w->nominee_id);
        if ($b['claim_block'] !== null) {
            return 'Do not pay this one: ' . $b['claim_block']['reason'] . ' Refuse it, with that reason.';
        }
        if ($b['withdrawable_kobo'] < (int) $w->amount_kobo) {
            return 'Do not pay this one: since it was requested, a hold or a refund has reduced the balance to ₦'
                 . CommunityReturnService::naira($b['withdrawable_kobo'])
                 . '. Refuse it, and the nominee can request what is still available.';
        }
        return null;
    }

    /** @return array{bank:string, account_name:string, account_number:string} */
    public static function cleanBank(array $b): array
    {
        return [
            'bank'           => mb_substr(trim((string) ($b['bank'] ?? '')), 0, 120),
            'account_name'   => mb_substr(trim((string) ($b['account_name'] ?? '')), 0, 160),
            // Digits only: a pasted account number arrives with spaces and dashes as often as not.
            'account_number' => mb_substr(preg_replace('/\D+/', '', (string) ($b['account_number'] ?? '')) ?? '', 0, 32),
        ];
    }

    /** The same rule ReferralPayout applies, so one set of bank details satisfies both. */
    public static function bankProblem(array $b): ?string
    {
        $b = self::cleanBank($b);
        if ($b['bank'] === '' || $b['account_name'] === '') return 'We need the bank name and the account name.';
        $len = strlen($b['account_number']);
        if ($len < 8 || $len > 20) return 'That account number does not look right.';
        return null;
    }

    /** Email the member who asked, with the receipt link — MemberWallet::tell(), the one sender. */
    private static function tell(int $id, string $subject, string $body): void
    {
        $w = self::find($id);
        if (!$w) return;
        MemberWallet::tell((int) ($w->user_id ?? 0), $subject, $body, 'crw-' . $id);
    }
}
