<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The checks a withdrawal passes before it is sent without a person.
 *
 * ── THE RULE IS ONE SENTENCE ─────────────────────────────────────────────────
 *
 * The owner (17 Oct 2026): pay automatically "as long as the system verifies it is valid
 * and there's no fraud or fraud-ishness". So every check here can only HOLD a withdrawal
 * for a person; none can refuse one. A held request is the same request a person always
 * reviewed before this existed, now with the reason written on it. A false alarm costs a
 * day's wait; a false pass costs the money. Every doubtful case falls to the side that
 * can be undone.
 *
 * ── WHAT IS CHECKED, AND WHY EACH ────────────────────────────────────────────
 *
 *   valid      the money is still there and still the member's: the pocket's own path
 *              asked again (balance still covers it, claim still held, no refunded or
 *              fraud-flagged contribution behind a community return, no reversed sale
 *              inside a referral payout)
 *   account    the bank knows the account (Paystack /bank/resolve), and the name the
 *              BANK holds matches the member — what they typed is a claim, the bank's
 *              answer is evidence. Matched against a name the member cannot edit where
 *              there is one (the nominee page's, for a community return), and never on a
 *              single word: one shared word matches any account that contains it.
 *   shared     no other member has used the same account number. One account collecting
 *              several members' money is the shape of a ring, whatever else is true.
 *   takeover   no password reset, no change of bank details — the FIRST saved account
 *              included — and no change of the member's name in the last N hours, and no
 *              sign-in by one-time code before a payment to an account this member has
 *              never been paid at. A new destination followed at once by a withdrawal is
 *              how a taken-over account is emptied; the owner of a real one waits a day
 *              without noticing. (Review, 20 Oct 2026: a code sign-in, a name edited to
 *              match, and a first bank account each counted as nothing, so somebody reading
 *              a member's inbox could take up to the first-payment limit with no person
 *              involved.)
 *   new        the first payment to an account this member has never been paid at is
 *              capped; above the cap a person looks.
 *   member     an active, verified account older than a few days.
 *   limits     per withdrawal, per member per day and for the platform per day — the last
 *              is a circuit breaker: if something here is wrong, it cannot be wrong at
 *              scale before a person notices.
 *   burst      most of a referral payout earned in the last two days — commission on
 *              sales still inside their refund window, in a pattern real referrals rarely
 *              make.
 *
 * "We could not ASK the bank" is not a finding. A transport failure leaves the request
 * for the next sweep rather than holding it, because an outage is not evidence about a
 * person (the same rule PaymentService::resolveAccount() states).
 */
final class WalletRisk
{
    public const DEFAULTS = [
        'wallet_auto_max_naira'            => 100000,
        'wallet_auto_daily_naira'          => 250000,
        'wallet_auto_daily_count'          => 3,
        'wallet_auto_platform_daily_naira' => 2000000,
        'wallet_auto_new_account_naira'    => 50000,
        'wallet_auto_min_account_days'     => 3,
        'wallet_auto_change_hours'         => 48,
    ];

    /** Honorifics and particles a bank prints that say nothing about who someone is. */
    private const NOISE = ['MR', 'MRS', 'MS', 'MISS', 'DR', 'CHIEF', 'ALHAJI', 'ALHAJA', 'ALH', 'PROF',
                           'ENGR', 'PASTOR', 'REV', 'HRH', 'SIR', 'BARR', 'OF', 'THE'];

    /** A rule value, from the effective rules or the default above. */
    public static function limit(array $eff, string $key): int
    {
        return max(0, (int) ($eff[$key] ?? self::DEFAULTS[$key]));
    }

    /**
     * Run every check.
     *
     * @param  array<string,mixed> $w  WalletAutoPay::load()
     * @return array{reachable:bool, resolved_name:string,
     *               findings:list<array{code:string, title:string, detail:string, tell:string}>,
     *               checks:list<array{code:string, label:string, ok:bool}>}
     */
    public static function assess(PaymentService $ps, array $w, array $eff): array
    {
        $findings = [];
        $checks = [];
        $add = static function (string $code, string $label, ?array $finding) use (&$findings, &$checks): void {
            $checks[] = ['code' => $code, 'label' => $label, 'ok' => $finding === null];
            if ($finding !== null) $findings[] = ['code' => $code] + $finding;
        };
        $f = static fn (string $title, string $detail, string $tell = ''): array =>
            ['title' => $title, 'detail' => $detail, 'tell' => $tell];
        $naira = static fn (int $k): string => '₦' . number_format($k / 100, 2);

        $user = null;
        try { $user = DB::table('gates_users')->where('id', (int) $w['user_id'])->first(); } catch (\Throwable) {}

        // ── the money is still valid ─────────────────────────────────────────
        $add('valid', 'The money is still there and still theirs', self::validity($w, $f));

        // ── the member ───────────────────────────────────────────────────────
        $m = null;
        if (!$user) {
            $m = $f('No member account', 'The request names a member who no longer exists.');
        } elseif ((string) ($user->status ?? 'active') !== 'active') {
            $m = $f('Account not active', 'The member’s account is ' . $user->status . '.');
        } elseif (empty($user->email_verified)) {
            $m = $f('Email not confirmed', 'The member has not confirmed their email address.',
                    'Confirm your email address, and withdrawals can be sent straight away.');
        } else {
            $days = self::limit($eff, 'wallet_auto_min_account_days');
            $made = (string) ($user->created_at ?? '');
            if ($days > 0 && $made !== '' && strcmp($made, Carbon::now()->subDays($days)->toDateTimeString()) > 0) {
                $m = $f('New account', 'The account was opened on ' . $made . ', within ' . $days . ' days.');
            }
        }
        $add('member', 'An active, confirmed, established member', $m);

        // ── a takeover ───────────────────────────────────────────────────────
        $add('takeover', 'No recent password reset, sign-in by code, or change of name or bank details', self::takeover($w, $user, $eff, $f));

        // ── the limits ───────────────────────────────────────────────────────
        $amt = (int) $w['amount_kobo'];
        $max = self::limit($eff, 'wallet_auto_max_naira') * 100;
        $add('amount', 'Within the automatic limit', $amt > $max
            ? $f('Above the automatic limit', $naira($amt) . ' is above the ' . $naira($max) . ' sent without a person.',
                 'Withdrawals above ' . $naira($max) . ' are checked by a person first.')
            : null);

        $since = Carbon::now()->subDay()->toDateTimeString();
        [$allSum] = self::sentSince($since, null);
        $add('velocity', 'Within the member’s daily limit', self::overDaily((int) $w['user_id'], $amt, $eff));
        $cap = self::limit($eff, 'wallet_auto_platform_daily_naira') * 100;
        $add('platform', 'Within the platform’s daily limit', $allSum + $amt > $cap
            ? $f('Platform daily limit reached', 'Sent automatically today across every member: ' . $naira($allSum)
                 . ' of ' . $naira($cap) . '. Automatic payouts pause above it until a person looks.')
            : null);

        // ── the account ──────────────────────────────────────────────────────
        $resolved = '';
        $code = (string) ($w['bank_code'] ?? '');
        $number = (string) $w['account_number'];
        if ($code === '') {
            $add('account', 'The bank confirms the account and the name matches',
                 $f('No bank code', 'The bank was typed rather than chosen from the list, so a transfer cannot be built.',
                    'Choose your bank from the list next time, and withdrawals can be sent straight away.'));
        } else {
            $r = $ps->resolveAccount($number, $code);
            if (!$r['ok'] && !$r['reachable']) {
                // Not a finding: nobody could be asked. The sweep comes back to it.
                return ['reachable' => false, 'resolved_name' => '', 'findings' => $findings, 'checks' => $checks];
            }
            if (!$r['ok']) {
                $add('account', 'The bank confirms the account and the name matches',
                     $f('Account not found', 'The bank did not recognise ' . $number . ': ' . $r['message'],
                        'Your bank did not recognise that account number. Check it and try again.'));
            } else {
                $resolved = $r['name'];
                // The page's name where there is one: it is set by the programme, and the
                // member cannot edit it. The account name otherwise — editable, which is why
                // a recent edit is a takeover signal above.
                $page  = trim((string) ($w['page_name'] ?? ''));
                $names = $page !== '' ? [$page] : array_values(array_filter([(string) ($user->name ?? '')]));
                $add('account', 'The bank confirms the account and the name matches',
                     self::nameMatches($resolved, $names) ? null
                     : $f('Name does not match', 'The bank holds this account as “' . $resolved . '”; the member is “'
                          . implode('” / “', $names) . '”.',
                          'The bank says this account belongs to ' . $resolved . ', which is not the name on your account. '
                          . 'Withdrawals go only to an account in your own name.'));
            }
        }

        // ── shared and new accounts ──────────────────────────────────────────
        $add('shared', 'No other member uses this account', self::sharedWith($w)
            ? $f('Account used by another member', 'Account ' . $number . ' appears on another member’s details or withdrawals.')
            : null);

        $newCap = self::limit($eff, 'wallet_auto_new_account_naira') * 100;
        $add('new', 'A known account, or a first payment within the limit',
             (!self::paidBefore($w) && $amt > $newCap)
             ? $f('First payment to this account', 'Nothing has been paid to this account before, and '
                  . $naira($amt) . ' is above the ' . $naira($newCap) . ' first-payment limit.',
                  'The first payment to a new account above ' . $naira($newCap) . ' is checked by a person.')
             : null);

        if ($w['kind'] === WalletAutoPay::KIND_REFERRAL) {
            $add('burst', 'Commission earned over time', self::referralBurst($w, $f));
        }

        return ['reachable' => true, 'resolved_name' => $resolved, 'findings' => $findings, 'checks' => $checks];
    }

    /**
     * Do two names belong to the same person? Order-free, case-free, noise-free tokens.
     *
     * Nigerian banks print "SURNAME FIRSTNAME MIDDLENAME", members type "Firstname
     * Surname", and either may drop a middle name — so it is the SET of names compared,
     * and two shared is a match. One shared name is not: "Amina Yusuf" and "Yusuf Bello"
     * share a surname and are two people, and a one-name member is held for a person.
     *
     * @param list<string> $candidates
     */
    public static function nameMatches(string $bankName, array $candidates): bool
    {
        $b = self::tokens($bankName);
        if ($b === []) return false;
        foreach ($candidates as $c) {
            $t = self::tokens($c);
            // Two shared names, always. A one-word name ("Burna") shares its one word with
            // every account that contains it — any "BURNA" anywhere — so it is never an
            // automatic match; a person compares it instead.
            if (count(array_intersect($b, $t)) >= 2) return true;
        }
        return false;
    }

    /** @return list<string> */
    private static function tokens(string $name): array
    {
        $n = strtoupper(preg_replace('/[^\p{L}\s]+/u', ' ', $name) ?? '');
        $out = [];
        foreach (preg_split('/\s+/u', trim($n)) ?: [] as $t) {
            if (mb_strlen($t) < 2 || in_array($t, self::NOISE, true)) continue;
            $out[$t] = true;
        }
        return array_keys($out);
    }

    // ══ the individual checks ══════════════════════════════════════════════════

    private static function validity(array $w, callable $f): ?array
    {
        try {
            if ($w['kind'] === WalletAutoPay::KIND_RETURN) {
                $nid = (int) $w['nominee_id'];
                $ctl = ClaimGuard::controlledBy($nid);
                if ($ctl === null || (int) ($ctl['user_id'] ?? 0) !== (int) $w['user_id'] || !ClaimGuard::payable($nid)) {
                    return $f('Claim no longer holds', 'The member no longer holds a payable claim on this page.');
                }
                $b = CommunityReturnService::balance($nid);
                if ($b['claim_block'] !== null) return $f('Claim blocked', (string) $b['claim_block']['reason']);
                // The same comparison CommunityReturnWithdrawals::stillCovered() makes before
                // a person approves: a hold or a refund since asking can have shrunk it.
                if ($b['withdrawable_kobo'] < (int) $w['amount_kobo']) {
                    return $f('Balance short', 'Since it was requested, a hold or a refund has reduced the balance below this withdrawal.');
                }
                if (CommunityReturnService::audit(null, $nid) !== []) {
                    return $f('Ledger entries need checking', 'Some shares behind this balance do not trace cleanly to a confirmed contribution.');
                }
                // Fraud-flagged or refund-requested money behind any share not yet reversed.
                $bad = DB::table('gates_community_returns as r')
                    ->join('gates_donations as d', 'd.id', '=', 'r.donation_id')
                    ->where('r.nominee_id', $nid)->where('r.entry_type', 'accrual')
                    ->whereNotExists(static function ($q) {
                        $q->select(DB::raw(1))->from('gates_community_returns as x')
                          ->whereColumn('x.donation_id', 'r.donation_id')->where('x.entry_type', 'reversal');
                    })
                    ->where(static function ($q) {
                        $q->whereNotNull('d.refund_requested_at')
                          ->orWhereExists(static function ($v) {
                              $v->select(DB::raw(1))->from('gates_votes as v')
                                ->whereColumn('v.donation_id', 'd.id')->where('v.fraud_flag', 1);
                          });
                    })->count();
                if ($bad > 0) {
                    return $f('Flagged contributions', $bad . ' contribution' . ($bad === 1 ? '' : 's')
                        . ' behind this balance ' . ($bad === 1 ? 'is' : 'are') . ' fraud-flagged or awaiting a refund.');
                }
                return null;
            }
            $ids = json_decode((string) ($w['row']->credit_ids ?? '[]'), true);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            if ($ids !== [] && SchemaHas::column('gates_referral_credits', 'reversed_at')
                && DB::table('gates_referral_credits')->whereIn('id', $ids)->whereNotNull('reversed_at')->exists()) {
                return $f('A sale was refunded', 'A sale inside this payout has been refunded since it was requested.');
            }
            return null;
        } catch (\Throwable $e) {
            return $f('Could not check the balance', 'Reading the ledger failed: ' . $e->getMessage());
        }
    }

    private static function takeover(array $w, ?object $user, array $eff, callable $f): ?array
    {
        $h = self::limit($eff, 'wallet_auto_change_hours');
        if ($h < 1 || !$user) return null;
        $since = Carbon::now()->subHours($h)->toDateTimeString();
        $recent = static fn (string $at): bool => $at !== '' && strcmp($at, $since) > 0;

        $changed = (string) ($user->payout_bank_changed_at ?? '');
        if ($recent($changed)) {
            return $f('Bank details just changed', 'The member saved new bank details at ' . $changed . '.',
                      'Your bank details changed recently, so a person will check this withdrawal first.');
        }
        $renamed = (string) ($user->name_changed_at ?? '');
        if ($recent($renamed)) {
            return $f('Name just changed', 'The member changed the name on their account at ' . $renamed
                      . ', and the bank\'s account name is checked against it.',
                      'The name on your account changed recently, so a person will check this withdrawal first.');
        }
        // A code proves the inbox or the phone, nothing more. It counts only before a payment
        // to an account this member has never been paid at: the owner withdrawing to their
        // usual account after a code sign-in is the ordinary case, a stranger's account after
        // one is the takeover.
        $code = (string) ($user->otp_signin_at ?? '');
        if ($recent($code) && !self::paidBefore($w)) {
            return $f('Signed in with a code, new account', 'The member signed in with a one-time code at ' . $code
                      . ' and has never been paid at this account.',
                      'This is the first payment to this account since you signed in with a code, so a person will check it first.');
        }
        try {
            $reset = DB::table('gates_otp_tokens')->where('purpose', UserAccountService::RESET_PURPOSE)
                ->where('nominee_id', (int) $user->id)->where('is_used', 1)
                ->where('created_at', '>', $since)->exists();
        } catch (\Throwable) {
            $reset = false;
        }
        return $reset ? $f('Password just reset', 'A password reset for this account was used in the last ' . $h . ' hours.',
                           'Your password was reset recently, so a person will check this withdrawal first.') : null;
    }

    /**
     * The member's daily limit, as a finding or null. Public because it is asked TWICE: once
     * with the other checks, and again by WalletAutoPay::send() under a lock on the member's
     * row at the moment the transfer is committed — two withdrawals checked at the same
     * instant each saw the other as not yet sent, and both passed (review, 20 Oct 2026).
     *
     * @return array{title:string, detail:string, tell:string}|null
     */
    public static function overDaily(int $userId, int $amountKobo, array $eff): ?array
    {
        [$sum, $n] = self::sentSince(Carbon::now()->subDay()->toDateTimeString(), $userId);
        $day = self::limit($eff, 'wallet_auto_daily_naira') * 100;
        $cnt = self::limit($eff, 'wallet_auto_daily_count');
        if ($sum + $amountKobo <= $day && $n < $cnt) return null;
        return ['title'  => 'Daily limit',
                'detail' => 'Sent automatically to this member in 24 hours: ₦' . number_format($sum / 100, 2) . ' in '
                          . $n . ' withdrawal' . ($n === 1 ? '' : 's') . '.',
                'tell'   => 'You have reached today’s limit for instant withdrawals, so a person will check this one.'];
    }

    /** @return array{0:int,1:int} kobo sent automatically since a moment, and how many */
    private static function sentSince(string $since, ?int $userId): array
    {
        $sum = 0; $n = 0;
        foreach (WalletAutoPay::TABLES as $kind => $t) {
            try {
                if (!SchemaHas::column($t, 'sent_at')) continue;
                $q = DB::table($t)->where('sent_at', '>', $since)
                    ->whereIn('auto_state', [WalletAutoPay::A_SENDING, WalletAutoPay::A_DONE]);
                if ($userId !== null) $q->where('user_id', $userId);
                foreach ($q->get() as $r) {
                    $n++;
                    $sum += $kind === WalletAutoPay::KIND_RETURN ? (int) $r->amount_kobo : (int) $r->amount_naira * 100;
                }
            } catch (\Throwable) {}
        }
        return [$sum, $n];
    }

    private static function sharedWith(array $w): bool
    {
        $num = (string) $w['account_number'];
        $uid = (int) $w['user_id'];
        if ($num === '') return false;
        try {
            if (DB::table('gates_users')->where('payout_account_number', $num)->where('id', '!=', $uid)->exists()) return true;
            foreach (WalletAutoPay::TABLES as $t) {
                if (DB::table($t)->where('account_number', $num)->where('user_id', '!=', $uid)->exists()) return true;
            }
        } catch (\Throwable) {}
        return false;
    }

    /** Has this member been paid at this account before, by either path? */
    private static function paidBefore(array $w): bool
    {
        try {
            foreach (WalletAutoPay::TABLES as $t) {
                if (DB::table($t)->where('user_id', (int) $w['user_id'])->where('account_number', (string) $w['account_number'])
                    ->where('status', 'paid')->exists()) return true;
            }
        } catch (\Throwable) {}
        return false;
    }

    private static function referralBurst(array $w, callable $f): ?array
    {
        $ids = json_decode((string) ($w['row']->credit_ids ?? '[]'), true);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if ($ids === []) return null;
        try {
            $all = (int) DB::table('gates_referral_credits')->whereIn('id', $ids)->sum('commission_naira');
            $recent = (int) DB::table('gates_referral_credits')->whereIn('id', $ids)
                ->where('created_at', '>', Carbon::now()->subHours(48)->toDateTimeString())->sum('commission_naira');
        } catch (\Throwable) {
            return null;
        }
        return ($all > 0 && $recent * 2 > $all)
            ? $f('Most of it earned in the last two days', '₦' . number_format($recent) . ' of ₦' . number_format($all)
                 . ' came from sales in the last 48 hours, still inside their refund window.')
            : null;
    }
}
