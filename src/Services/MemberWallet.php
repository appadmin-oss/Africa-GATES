<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A member's wallet: every naira this platform owes them, in one place.
 *
 * ── ONE WALLET, SEVERAL POCKETS, NO NEW LEDGER ───────────────────────────────
 *
 * Money reaches a member two ways today: a COMMUNITY RETURN on a nominee page they hold
 * (CommunityReturnService, one ledger per page) and REFERRAL COMMISSION on what people they
 * invited bought (ReferralService). Each already has its own audited ledger and its own
 * reviewed payout path, and each is right for its own rules. So the wallet is a READ MODEL
 * over them — the way a banking app shows one balance across accounts — and not a third
 * ledger to keep in step: a figure here is computed from the rows that hold the money, and
 * cannot drift from them.
 *
 * A pocket is withdrawn through its own path (CommunityReturnWithdrawals, ReferralPayout);
 * the wallet only routes, and both take the same withdrawal fee (WalletFee).
 *
 * ── THE STATES ARE THE POINT ─────────────────────────────────────────────────
 *
 * Every transaction carries a status, and a credit that cannot be withdrawn yet says so —
 * LOCKED (a milestone or a referral threshold), AWAITING (results) — instead of sitting in
 * the balance. The headline is AVAILABLE money only. A wallet that shows money a member
 * cannot take is the fastest way to a support ticket and a lost trust.
 *
 * ── OWNERSHIP ────────────────────────────────────────────────────────────────
 *
 * A community-return pocket exists for a page the member holds an active, undisputed claim
 * on; a referral pocket for their own code. A transaction is looked up only among the rows
 * those yield, so a key typed into a URL cannot open somebody else's receipt.
 */
final class MemberWallet
{
    // Statuses, as the list and the receipt print them.
    public const ST_RECEIVED   = 'received';
    public const ST_LOCKED     = 'locked';
    public const ST_AWAITING   = 'awaiting';
    public const ST_PENDING    = 'pending';
    public const ST_PROCESSING = 'processing';
    public const ST_PAID       = 'paid';
    public const ST_REFUSED    = 'refused';
    public const ST_REVERSED   = 'reversed';
    public const ST_HELD       = 'held';

    public const STATUS_LABELS = [
        self::ST_RECEIVED   => 'Received',
        self::ST_LOCKED     => 'Locked',
        self::ST_AWAITING   => 'Awaiting results',
        self::ST_PENDING    => 'In review',
        self::ST_PROCESSING => 'Processing',
        self::ST_PAID       => 'Successful',
        self::ST_REFUSED    => 'Refused',
        self::ST_REVERSED   => 'Reversed',
        self::ST_HELD       => 'On hold',
    ];

    // ══ pockets and the headline ═══════════════════════════════════════════════

    /**
     * @return array{available_kobo:int, coming_kobo:int, review_kobo:int, paid_kobo:int,
     *               pockets:list<array<string,mixed>>}
     */
    public static function summary(int $userId): array
    {
        $pockets = self::pockets($userId);
        $s = ['available_kobo' => 0, 'coming_kobo' => 0, 'review_kobo' => 0, 'paid_kobo' => 0,
              'points' => 0, 'points_votes' => 0, 'pockets' => $pockets];
        foreach ($pockets as $p) {
            // Points are a balance in their own unit, never added to naira: shown beside the
            // cash the way OPay shows a bonus balance beside the main one.
            if ($p['kind'] === 'points') {
                $s['points'] = $p['points'];
                $s['points_votes'] = $p['points_votes'];
                continue;
            }
            $s['available_kobo'] += $p['available_kobo'];
            $s['coming_kobo']    += $p['coming_kobo'];
            $s['review_kobo']    += $p['review_kobo'];
            $s['paid_kobo']      += $p['paid_kobo'];
        }
        return $s;
    }

    /**
     * Every pocket this member has, community returns first.
     *
     * `coming_kobo` is money that is theirs and not yet theirs to take (locked or awaiting
     * results) — shown as its own line, never added to the available headline.
     *
     * @return list<array{key:string, kind:string, label:string, sub:string, available_kobo:int,
     *                    coming_kobo:int, coming_note:string, review_kobo:int, paid_kobo:int,
     *                    can:bool, why:string, partial:bool, nominee_id:?int}>
     */
    public static function pockets(int $userId): array
    {
        if ($userId < 1) return [];
        $out = [];

        foreach (CommunityReturnWithdrawals::pagesFor($userId) as $nid) {
            $w = CommunityReturnService::wallet($nid);
            $c = CommunityReturnWithdrawals::checks($nid, $userId, true, null, true);
            $out[] = [
                'key' => 'page-' . $nid, 'kind' => 'return', 'label' => 'Community return',
                'sub' => self::pageName($nid),
                'available_kobo' => $w['available_kobo'],
                'coming_kobo'    => $w['locked_kobo'] + $w['awaiting_kobo'],
                'coming_note'    => $w['locked_kobo'] > 0 ? 'Locked until your edition’s milestone is confirmed'
                                   : ($w['awaiting_kobo'] > 0 ? 'Released when your edition announces its results' : ''),
                'review_kobo'    => $w['under_review_kobo'],
                'paid_kobo'      => $w['paid_kobo'],
                'can' => $c['ok'], 'why' => $c['reason'], 'partial' => true, 'nominee_id' => $nid,
                // The edition's rule and, under a milestone, how far it has got — the one
                // thing that tells a nominee with locked money when it will be theirs.
                'points' => 0, 'points_votes' => 0,
                'milestone' => ($cy = self::pageCycle($nid)) ? CommunityReturnMilestone::progress($cy) : null,
            ];
        }

        $ref = self::referralPocket($userId);
        if ($ref !== null) $out[] = $ref;

        $pts = self::pointsPocket($userId);
        if ($pts !== null) $out[] = $pts;

        return $out;
    }

    /**
     * Voting points: a pocket of the wallet in their own unit. Spent on votes, never paid
     * out, so `can` is always false for a withdrawal and the action is "use for votes".
     *
     * @return array<string,mixed>|null null when points are off and the member holds none
     */
    private static function pointsPocket(int $userId): ?array
    {
        try {
            $on  = PointsService::enabled();
            $bal = PointsService::balance($userId);
            $per = PointsService::pointsPerVote();
            $votes = PointsService::votesForPoints($bal);
        } catch (\Throwable) {
            return null;
        }
        if (!$on && $bal <= 0) return null;
        return [
            'key' => 'points', 'kind' => 'points', 'label' => 'Voting points',
            'sub' => $on ? number_format($per) . ' points buy one vote' : 'Points are switched off for now; your balance is kept',
            'available_kobo' => 0, 'coming_kobo' => 0, 'coming_note' => '', 'review_kobo' => 0, 'paid_kobo' => 0,
            'points' => $bal, 'points_votes' => $on ? $votes : 0,
            'can' => false, 'why' => 'Points are spent on votes. They are never paid out as cash.',
            'partial' => false, 'nominee_id' => null, 'milestone' => null,
        ];
    }

    /** @return array<string,mixed>|null null when the member has never had a referral code */
    private static function referralPocket(int $userId): ?array
    {
        try {
            $st = ReferralService::stats($userId);
        } catch (\Throwable) {
            return null;
        }
        if ($st['code'] === null && $st['accrued_naira'] <= 0) return null;

        $pa   = ReferralPayout::available($userId);
        $open = ReferralPayout::openFor($userId);
        $owed = max(0, $st['accrued_naira'] - $st['paid_out_naira']) * 100;

        return [
            'milestone' => null, 'points' => 0, 'points_votes' => 0,
            'key' => 'ref', 'kind' => 'referral', 'label' => 'Referral earnings',
            'sub' => $st['code'] ? 'Commission on what people you invite buy' : '',
            'available_kobo' => $pa['ok'] ? (int) $pa['amount'] * 100 : 0,
            'coming_kobo'    => $st['unlocked'] ? 0 : $owed,
            'coming_note'    => $st['unlocked'] ? '' : ($st['remaining'] === 1
                ? 'Unlocks after 1 more paid referral' : 'Unlocks after ' . $st['remaining'] . ' more paid referrals'),
            'review_kobo'    => $open ? (int) $open->amount_naira * 100 : 0,
            'paid_kobo'      => $st['paid_out_naira'] * 100,
            // Referral payouts take the whole owed balance (the request freezes the credits
            // it covers), so the amount is not a choice there.
            'can' => $pa['ok'] && $open === null, 'why' => $open ? 'You already have a withdrawal being reviewed.' : $pa['reason'],
            'partial' => false, 'nominee_id' => null,
        ];
    }

    /** One of this member's pockets, by key, or null — a key typed into a URL opens nothing else. */
    public static function pocket(int $userId, string $key): ?array
    {
        foreach (self::pockets($userId) as $p) {
            if ($p['key'] === $key) return $p;
        }
        return null;
    }

    // ══ taking money out ═══════════════════════════════════════════════════════

    /**
     * What a withdrawal would be, asked of the pocket's own path: the review step's figures.
     *
     * Nothing is written. The same path is asked again by {@see withdraw()}, under its own
     * lock, so a figure shown here is a preview and never the authority.
     *
     * @param  array{bank:string, account_name:string, account_number:string} $bank
     * @return array{ok:bool, reason:string, amount_kobo:int, fee_kobo:int, net_kobo:int, bank:array<string,string>}
     */
    public static function quote(int $userId, string $pocketKey, ?int $amountKobo, array $bank): array
    {
        $bank = CommunityReturnWithdrawals::cleanBank($bank);
        $no = static fn (string $why): array => ['ok' => false, 'reason' => $why, 'amount_kobo' => 0,
                                                 'fee_kobo' => 0, 'net_kobo' => 0, 'bank' => $bank];
        $p = self::pocket($userId, $pocketKey);
        if ($p === null) return $no('That pocket is not one of yours.');

        $bp = CommunityReturnWithdrawals::bankProblem($bank);
        if ($bp !== null) return $no($bp);

        if ($p['kind'] === 'points') return $no($p['why']);
        if ($p['kind'] === 'referral') {
            if (ReferralPayout::openFor($userId) !== null) return $no('You already have a withdrawal being reviewed.');
            $a = ReferralPayout::available($userId);
            if (!$a['ok']) return $no($a['reason']);
            $amt = (int) $a['amount'] * 100;
            $fee = (int) ($a['fee'] ?? 0) * 100;
        } else {
            // Rules are accepted on the review step itself, so they are not asked here.
            $c = CommunityReturnWithdrawals::checks((int) $p['nominee_id'], $userId, true, $bank, false, $amountKobo);
            if (!$c['ok']) return $no($c['reason']);
            $amt = $c['amount_kobo'];
            $fee = $c['fee_kobo'];
        }
        return ['ok' => true, 'reason' => '', 'amount_kobo' => $amt, 'fee_kobo' => $fee,
                'net_kobo' => max(0, $amt - $fee), 'bank' => $bank];
    }

    /**
     * Ask for the money, through the pocket's own reviewed path.
     *
     * @param  array{bank:string, account_name:string, account_number:string} $bank
     * @return array{ok:bool, message:string, key?:string}
     */
    public static function withdraw(int $userId, string $pocketKey, ?int $amountKobo, array $bank, bool $accepted,
                                    ?PaymentService $ps = null): array
    {
        $p = self::pocket($userId, $pocketKey);
        if ($p === null) return ['ok' => false, 'message' => 'That pocket is not one of yours.'];
        if ($p['kind'] === 'points') return ['ok' => false, 'message' => (string) $p['why']];
        if (!$accepted) return ['ok' => false, 'message' => 'Read and accept the withdrawal rules first.'];

        $code = mb_substr(preg_replace('/[^0-9A-Za-z]/', '', (string) ($bank['bank_code'] ?? '')) ?? '', 0, 12);
        $bank = CommunityReturnWithdrawals::cleanBank($bank);
        if ($p['kind'] === 'referral') {
            $r = ReferralPayout::request($userId, $bank['bank'], $bank['account_name'], $bank['account_number']);
            $kind = WalletAutoPay::KIND_REFERRAL;
        } else {
            $r = CommunityReturnWithdrawals::request((int) $p['nominee_id'], $userId, $bank, true, $amountKobo);
            $kind = WalletAutoPay::KIND_RETURN;
        }
        if (!$r['ok']) return ['ok' => false, 'message' => (string) $r['message']];

        $id  = (int) $r['id'];
        $key = $kind . '-' . $id;
        // The bank's code travels on the request: a transfer is built from it, never from a
        // name. Written before the engine looks; absent only for a bank typed by hand.
        if ($code !== '' && SchemaHas::column(WalletAutoPay::TABLES[$kind], 'bank_code')) {
            DB::table(WalletAutoPay::TABLES[$kind])->where('id', $id)->update(['bank_code' => $code]);
        }
        // The next withdrawal starts from these, the way the referral form always has. Saved
        // BEFORE the checks, so a changed destination is a changed destination to them too.
        ReferralPayout::saveBank($userId, $bank['bank'], $bank['account_name'], $bank['account_number'], $code !== '' ? $code : null);

        // ── AUTOMATIC, WHERE NOTHING IS DOUBTFUL ─────────────────────────────
        $ps ??= new PaymentService();
        $auto = WalletAutoPay::enabled($ps) ? WalletAutoPay::process($ps, $kind, $id)
                                            : ['state' => '', 'message' => '', 'tell' => []];
        $w = WalletAutoPay::load($kind, $id);
        $net = '₦' . number_format((int) ($w['net_kobo'] ?? 0) / 100, 2);
        $message = match ($auto['state']) {
            WalletAutoPay::A_DONE    => $net . ' has been paid to your bank.',
            WalletAutoPay::A_SENDING => $net . ' is on its way to your bank. We will tell you the moment it lands.',
            WalletAutoPay::A_HELD    => 'Requested. A person will check this one before it is sent'
                                        . ($auto['tell'] !== [] ? ': ' . implode(' ', $auto['tell']) : ', usually within a working day.'),
            default                  => (string) $r['message'],
        };
        // The debit alert a banking app sends: what was asked for, and what happens next.
        $moving = in_array($auto['state'], [WalletAutoPay::A_SENDING, WalletAutoPay::A_DONE], true);
        self::tell($userId, $moving ? 'Your withdrawal is on its way' : 'We received your withdrawal request', $message, $key);
        return ['ok' => true, 'message' => $message, 'key' => $key, 'auto' => $auto['state']];
    }

    /**
     * Email a member about a movement in their wallet, with a link to its receipt.
     *
     * Best-effort, like every notice here: a mail outage never undoes a recorded act, and
     * the wallet itself (and its Activity entry) says the same thing whether or not the
     * mail arrives. One sender, so every wallet email carries the receipt link.
     */
    public static function tell(int $userId, string $subject, string $body, string $txKey): void
    {
        $to = '';
        try {
            $to = (string) (DB::table('gates_users')->where('id', $userId)->value('email') ?? '');
        } catch (\Throwable) {}
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $link = rtrim(\AfricaGates\Support\SiteUrl::base(), '/') . '/account/wallet/tx/' . $txKey;
        try {
            Notifier::confirm(OtpService::boot(), $to, $subject, $body . "\n\nSee it in your wallet: " . $link);
        } catch (\Throwable) {}
    }

    /** "₦2,500" or "2,500.50" typed into the amount field, in kobo; null when blank or unreadable. */
    public static function parseNaira(string $typed): ?int
    {
        $t = str_replace([',', '₦', ' '], '', trim($typed));
        if ($t === '' || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $t)) return null;
        return (int) round(((float) $t) * 100);
    }

    // ══ the transaction history ════════════════════════════════════════════════

    /**
     * Every movement, newest first.
     *
     * @return list<array{key:string, dir:string, title:string, sub:string, amount_kobo:int,
     *                    status:string, status_label:string, at:string, ref:string, fee_kobo:int,
     *                    pocket:string, details:list<array{0:string,1:string}>}>
     */
    public static function transactions(int $userId, int $limit = 100): array
    {
        if ($userId < 1) return [];
        $tx = array_merge(self::returnTx($userId), self::referralTx($userId), self::pointsTx($userId));
        usort($tx, static fn ($a, $b) => strcmp($b['at'], $a['at']) ?: strcmp($b['key'], $a['key']));
        return array_slice($tx, 0, max(1, $limit));
    }

    /** One transaction, only if it is this member's. */
    public static function transaction(int $userId, string $key): ?array
    {
        foreach (self::transactions($userId, 2000) as $t) {
            if ($t['key'] === $key) return $t;
        }
        return null;
    }

    /**
     * Credits newer than a moment, for the live balance and the notifications.
     *
     * @return list<array<string,mixed>>
     */
    public static function creditsSince(int $userId, string $since): array
    {
        return array_values(array_filter(self::transactions($userId, 200),
            static fn ($t) => $t['dir'] === 'in' && strcmp($t['at'], $since) > 0));
    }

    /** @return list<array<string,mixed>> */
    private static function returnTx(int $userId): array
    {
        $out = [];
        foreach (CommunityReturnWithdrawals::pagesFor($userId) as $nid) {
            $page = self::pageName($nid);
            try {
                $rows = DB::table('gates_community_returns')->where('nominee_id', $nid)
                    ->where('entry_type', '!=', 'payout')->orderByDesc('id')->limit(500)->get();
            } catch (\Throwable) {
                $rows = collect();
            }
            $cycles = [];
            foreach ($rows as $r) $cycles[(int) ($r->cycle_id ?? 0)] = true;
            $released = CommunityReturnService::releasedCycles(array_keys($cycles));

            foreach ($rows as $r) {
                $type = (string) $r->entry_type;
                $amt  = (int) $r->amount_kobo;
                $rel  = isset($released[(int) ($r->cycle_id ?? 0)]);
                $ref  = $r->donation_id ? (string) (self::donationRef((int) $r->donation_id)) : '';

                [$title, $status] = match ($type) {
                    'accrual'    => ['Community return',
                                     (string) ($r->stage ?? '') === CommunityReturnService::STAGE_LOCKED && !$rel
                                         ? self::ST_LOCKED : ($rel ? self::ST_RECEIVED : self::ST_AWAITING)],
                    'reversal'   => ['Reversed: the contribution was refunded', self::ST_REVERSED],
                    'hold'       => ['Put on hold', self::ST_HELD],
                    'release'    => ['Hold lifted', self::ST_RECEIVED],
                    'adjustment' => ['Correction by our team', self::ST_RECEIVED],
                    'forfeit'    => ['Forfeited', self::ST_REVERSED],
                    default      => [ucfirst($type), self::ST_RECEIVED],
                };

                $details = [['Pocket', 'Community return · ' . $page]];
                if ($type === 'accrual') {
                    $details[] = ['Contribution', '₦' . CommunityReturnService::naira((int) $r->basis_kobo)];
                    $details[] = ['Your share', CommunityReturnLadder::pct((int) $r->rate_bps) . '%'];
                }
                if ($r->note) $details[] = ['Note', (string) $r->note];

                $out[] = self::tx('cr-' . (int) $r->id, $amt >= 0 ? 'in' : 'out', $title,
                    $type === 'accrual' ? 'From a supporter of ' . $page : $page,
                    abs($amt), $status, (string) $r->created_at, $ref, 0, 'return', $details);
            }

            foreach (CommunityReturnWithdrawals::historyFor($nid, 100) as $w) {
                $status = match ((string) $w->status) {
                    CommunityReturnWithdrawals::WD_REQUESTED => self::ST_PENDING,
                    CommunityReturnWithdrawals::WD_APPROVED  => self::ST_PROCESSING,
                    CommunityReturnWithdrawals::WD_PAID      => self::ST_PAID,
                    default                                  => self::ST_REFUSED,
                };
                [$status, $extra] = self::auto($w, $status);
                $out[] = self::withdrawalTx('crw-' . (int) $w->id, (int) $w->amount_kobo, (int) $w->fee_kobo,
                    (string) $w->bank_name, (string) $w->account_name, (string) $w->account_number,
                    $status, (string) ($w->paid_at ?: ($w->sent_at ?? null) ?: $w->reviewed_at ?: $w->requested_at),
                    (string) ($w->payment_ref ?? ''), (string) ($w->review_note ?? ''), 'Community return · ' . $page,
                    (string) $w->requested_at, $extra);
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function pointsTx(int $userId): array
    {
        $out = [];
        foreach (PointsService::ledger($userId, 300) as $e) {
            $d = (int) $e['delta'];
            if ($d === 0) continue;
            $what = trim((string) ($e['note'] ?? '')) ?: ucfirst(str_replace('_', ' ', (string) $e['reason']));
            $t = self::tx('pt-' . (int) $e['id'], $d > 0 ? 'in' : 'out', $d > 0 ? 'Points earned' : 'Points spent',
                $what, abs($d), $d > 0 ? self::ST_RECEIVED : self::ST_PAID, (string) $e['created_at'],
                (string) ($e['ref_id'] ?? ''), 0, 'points',
                [['Pocket', 'Voting points'], ['What for', $what], ['Balance after', number_format((int) $e['balance_after']) . ' pts']]);
            $t['unit'] = 'pts';
            // A spend is complete the moment it is made; "Successful" reads wrong for points.
            if ($d < 0) $t['status_label'] = 'Spent';
            $out[] = $t;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function referralTx(int $userId): array
    {
        $out = [];
        try {
            $st = ReferralService::stats($userId);
            $hasRev = SchemaHas::column('gates_referral_credits', 'reversed_at');
            $rows = DB::table('gates_referral_credits as c')
                ->leftJoin('gates_site_events as e', 'e.id', '=', 'c.event_id')
                ->where('c.user_id', $userId)->orderByDesc('c.id')->limit(500)
                ->get(['c.*', 'e.title as event_title']);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            $reversed = $hasRev && ($r->reversed_at ?? null) !== null;
            $status = $reversed ? self::ST_REVERSED
                : (($r->paid_out_at ?? null) !== null || $st['unlocked'] ? self::ST_RECEIVED : self::ST_LOCKED);
            $details = [['Pocket', 'Referral earnings'],
                        ['Their purchase', '₦' . number_format((int) $r->paid_naira)],
                        ['Your commission', CommunityReturnLadder::pct((int) $r->rate_bps) . '%']];
            if ($status === self::ST_LOCKED) {
                $details[] = ['Unlocks', 'After ' . $st['remaining'] . ' more paid referral' . ($st['remaining'] === 1 ? '' : 's')];
            }
            $out[] = self::tx('rc-' . (int) $r->id, 'in', 'Referral commission',
                $r->event_title ? 'A ticket for ' . $r->event_title . ', bought through your link' : 'A purchase through your link',
                (int) $r->commission_naira * 100, $status, (string) $r->created_at, '', 0, 'referral', $details);
        }

        foreach (ReferralPayout::historyFor($userId, 100) as $p) {
            $status = match ((string) $p->status) {
                'requested' => self::ST_PENDING,
                'paid'      => self::ST_PAID,
                default     => self::ST_REFUSED,
            };
            [$status, $extra] = self::auto($p, $status);
            $out[] = self::withdrawalTx('rp-' . (int) $p->id, (int) $p->amount_naira * 100,
                (int) ($p->fee_naira ?? 0) * 100, (string) $p->bank_name, (string) $p->account_name,
                (string) $p->account_number, $status, (string) ($p->settled_at ?: ($p->sent_at ?? null) ?: $p->requested_at),
                (string) ($p->payment_ref ?? ''), (string) ($p->note ?? ''), 'Referral earnings',
                (string) $p->requested_at, $extra);
        }
        return $out;
    }

    /**
     * What the automatic payout adds to a withdrawal's status and receipt.
     *
     * In flight is PROCESSING whatever the request's own status says — a referral payout
     * stays `requested` until the gateway confirms it. Held is IN REVIEW, with only the
     * member-safe half of what was found: "the bank says this account is X" helps them;
     * "account used by another member" would teach somebody what is watched.
     *
     * @return array{0:string, 1:list<array{0:string,1:string}>}
     */
    private static function auto(object $w, string $status): array
    {
        $state = $w->auto_state ?? null;
        $extra = [];
        if ((string) ($w->resolved_name ?? '') !== '') $extra[] = ['Name at the bank', (string) $w->resolved_name];
        if ($state === WalletAutoPay::A_SENDING) {
            $status = self::ST_PROCESSING;
            $extra[] = ['Checks', 'Passed automatically · sent to your bank'];
        } elseif ($state === WalletAutoPay::A_DONE) {
            $extra[] = ['Checks', 'Passed automatically'];
        } elseif ($state === WalletAutoPay::A_HELD && $status === self::ST_PENDING) {
            $tell = array_values(array_filter(array_column(WalletAutoPay::findings($w), 'tell')));
            $extra[] = ['Checks', 'A person is checking this one' . ($tell !== [] ? ': ' . implode(' ', array_unique($tell)) : '')];
        }
        return [$status, $extra];
    }

    /**
     * @param list<array{0:string,1:string}> $extra
     * @return array<string,mixed>
     */
    private static function withdrawalTx(string $key, int $amount, int $fee, string $bank, string $name, string $number,
                                         string $status, string $at, string $ref, string $note, string $pocket,
                                         string $requestedAt, array $extra = []): array
    {
        $last4 = substr($number, -4);
        $details = [
            ['From', $pocket],
            ['To', trim($name . ' · ' . $bank . ($last4 !== '' ? ' ••' . $last4 : ''))],
            ['Amount', '₦' . CommunityReturnService::naira($amount)],
            ['Fee', '₦' . CommunityReturnService::naira($fee)],
            ['You receive', '₦' . CommunityReturnService::naira(max(0, $amount - $fee))],
            // A third element marks a timestamp, so the receipt formats it in the site's zone
            // exactly as it formats the transaction's own date — never a raw database string.
            ['Requested', $requestedAt, 'date'],
        ];
        foreach ($extra as $e) $details[] = $e;
        if ($note !== '') $details[] = [$status === self::ST_REFUSED ? 'Why it was refused' : 'Note', $note];
        return self::tx($key, 'out', 'Withdrawal to ' . ($bank !== '' ? $bank : 'your bank'),
            $name . ($last4 !== '' ? ' ••' . $last4 : ''), $amount, $status, $at, $ref, $fee,
            str_starts_with($key, 'rp') ? 'referral' : 'return', $details);
    }

    /** @return array<string,mixed> */
    private static function tx(string $key, string $dir, string $title, string $sub, int $amount, string $status,
                               string $at, string $ref, int $fee, string $pocket, array $details): array
    {
        return [
            'key' => $key, 'dir' => $dir, 'title' => $title, 'sub' => $sub, 'amount_kobo' => $amount, 'unit' => 'naira',
            'status' => $status, 'status_label' => self::STATUS_LABELS[$status] ?? ucfirst($status),
            'at' => $at, 'ref' => $ref, 'fee_kobo' => $fee, 'pocket' => $pocket, 'details' => $details,
        ];
    }

    // ══ small readers ══════════════════════════════════════════════════════════

    /** "Name · Category" for a nominee page. */
    public static function pageName(int $nomineeId): string
    {
        $name = 'Your page';
        try {
            $n = DB::table('gates_nominees as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)->first(['n.name', 'c.title as category']);
            if ($n) $name = trim((string) $n->name . ($n->category ? ' · ' . $n->category : ''));
        } catch (\Throwable) {}
        return $name;
    }

    /** The edition a nominee page stands in, through its category. */
    private static function pageCycle(int $nomineeId): ?int
    {
        try {
            $c = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)->value('c.cycle_id');
            return $c ? (int) $c : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function donationRef(int $donationId): string
    {
        try {
            return (string) (DB::table('gates_donations')->where('id', $donationId)->value('payment_ref') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
