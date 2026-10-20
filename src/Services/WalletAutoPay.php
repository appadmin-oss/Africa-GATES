<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Automatic withdrawals: checked by WalletRisk, sent by Paystack, settled by the gateway.
 *
 * ── THE SHAPE ────────────────────────────────────────────────────────────────
 *
 *   request ──► process() ── claims the row ──► WalletRisk::assess()
 *                 │                                 │
 *                 │                  any finding ───┴──► HELD: a person reviews it, exactly
 *                 │                                      as every withdrawal was before, with
 *                 │                                      the reason written on the row
 *                 │                  all clear ─────────► send(): reference committed, then
 *                 │                                      Paystack /transfer
 *                 ▼
 *   webhook / sweep ──► applyStatus(): success → markPaid (the ledger moves only now)
 *                                      failed  → refused, the money is still theirs
 *                                      reversed after success → put back on the ledger
 *
 * Both withdrawal paths (community return, referral) are paid by this one engine, so the
 * checks, the limits and the idempotency rules cannot differ between them.
 *
 * ── PAYING TWICE IS THE ONE FAILURE THAT CANNOT BE UNDONE ────────────────────
 *
 * Everything below is ordered against it, on OrgPayout's model (read its class note):
 *
 *  - a row is CLAIMED by a conditional update before anything is checked, so a request
 *    and a sweep running at once cannot both send it;
 *  - our reference is minted and COMMITTED before Paystack is called, and never changes —
 *    a retry sends the same reference, which Paystack refuses as a duplicate;
 *  - a failed call is not "it did not happen": the row stays SENDING and the sweep asks
 *    Paystack what became of that reference before doing anything else;
 *  - the ledger moves only on the gateway's word (`success`), never on our call returning.
 *
 * ── AND A STUCK PAYOUT GOES TO A PERSON, NOT ROUND AGAIN ─────────────────────
 *
 * After MAX_ATTEMPTS a sending row Paystack still does not recognise is HELD with its
 * reference intact. A person can then see exactly what was tried.
 */
final class WalletAutoPay
{
    public const KIND_RETURN   = 'crw';
    public const KIND_REFERRAL = 'rp';
    public const TABLES = [self::KIND_RETURN => 'gates_return_withdrawals', self::KIND_REFERRAL => 'gates_referral_payouts'];

    // Where the engine has a request. NULL: not looked at yet.
    public const A_CHECKING = 'checking';
    public const A_HELD     = 'held';
    public const A_SENDING  = 'sending';
    public const A_DONE     = 'done';
    public const A_FAILED   = 'failed';

    public const PREFIX = 'agwd-';
    public const MAX_ATTEMPTS = 5;

    /** Paystack's conclusive failures, before or instead of a success. */
    private const FAILED = ['failed', 'abandoned', 'blocked', 'rejected'];

    /** Is the engine on? The rule, the columns, and a gateway that can send. */
    public static function enabled(?PaymentService $ps = null, ?array $eff = null): bool
    {
        $eff ??= (new RuleEngine())->effective();
        if (empty($eff['wallet_auto_payout'])) return false;
        if (!SchemaHas::column('gates_return_withdrawals', 'auto_state')) return false;
        return ($ps ?? new PaymentService())->isEnabled('paystack');
    }

    /**
     * Paystack's bank list for the withdraw form, cached for a day. An empty answer is NOT
     * cached: during an outage the form falls back to typing the bank, and the next
     * request after it ends gets the list back rather than waiting a day.
     *
     * @return list<array{code:string, name:string}>
     */
    public static function banks(?PaymentService $ps = null): array
    {
        $cache = new CacheService();
        $hit = $cache->get('wallet:banks');
        if (is_array($hit) && $hit !== []) return $hit;
        $ps ??= new PaymentService();
        if (!$ps->isEnabled('paystack')) return [];
        $list = $ps->banks();
        if ($list !== []) $cache->put('wallet:banks', $list, 86400);
        return $list;
    }

    /**
     * Why a person may not settle or refuse this request by hand right now, or null.
     *
     * While a transfer is in flight (or the engine is mid-check) the gateway decides, not a
     * person: marking it paid by hand would record a second payment, and refusing it would
     * hand the money back to the wallet while the bank pays it anyway — the shape of paying
     * twice. Asked by every manual settle and refuse, in the services, so no screen can
     * forget it.
     *
     * ── AND A HELD TRANSFER THAT WAS SENT IS ASKED ABOUT AGAIN, NOW ──────────
     *
     * A row held as "stuck" carries a reference Paystack was asked to pay up to
     * MAX_ATTEMPTS times. "Not recognised" at the sweep's last look is not proof it never
     * went: a late completion of the original would pay the member a second time beside a
     * manual payment (review, 20 Oct 2026). So a held row with a reference that was sent is
     * settled or refused by hand only once Paystack answers, at that moment, that it holds
     * no such transfer — and if it does hold it, its answer is applied and the gateway
     * settles it as usual.
     */
    public static function busy(object $row, ?PaymentService $ps = null): ?string
    {
        $ref = (string) ($row->transfer_ref ?? '');
        if (($row->auto_state ?? null) === self::A_HELD && $ref !== '' && (int) ($row->transfer_attempts ?? 0) > 0) {
            $v = ($ps ?? new PaymentService())->verifyTransfer($ref);
            if ($v['ok']) {
                self::applyStatus($ref, (string) $v['status'], 'Found when a person went to settle it by hand.');
                return 'Paystack has this transfer (reference ' . $ref . ', ' . $v['status'] . '), so it was not paid by hand: '
                     . 'the bank’s answer settles it. Refresh to see where it is.';
            }
            if (empty($v['absent'])) {
                return 'Paystack could not confirm that transfer ' . $ref . ' never went through ('
                     . ($v['message'] !== '' ? $v['message'] : 'no answer') . '). Paying it by hand now could pay it twice; '
                     . 'try again when Paystack answers.';
            }
            // Confirmed absent: written on the row, so the reviewer's evidence is on record.
            try {
                $risk = json_decode((string) ($row->risk_json ?? ''), true) ?: ['checks' => [], 'findings' => []];
                $risk['unsent_confirmed_at'] = Carbon::now()->toDateTimeString();
                $t = isset($row->amount_kobo) ? self::TABLES[self::KIND_RETURN] : self::TABLES[self::KIND_REFERRAL];
                DB::table($t)->where('id', (int) $row->id)->update(['risk_json' => json_encode($risk)]);
            } catch (\Throwable) {}
            return null;
        }
        return match ($row->auto_state ?? null) {
            self::A_SENDING  => 'This one is being paid automatically (reference ' . ($row->transfer_ref ?? '?')
                              . '). The bank’s answer settles it; nothing to do by hand.',
            self::A_CHECKING => 'The automatic checks are running on this one right now. Try again in a minute.',
            self::A_DONE     => 'This one was paid automatically.',
            default          => null,
        };
    }

    public static function isOurs(string $reference): bool
    {
        return str_starts_with($reference, self::PREFIX);
    }

    // ══ one request ════════════════════════════════════════════════════════════

    /**
     * Check a new request and, if nothing is doubtful, send it.
     *
     * @return array{state:string, message:string, tell:list<string>}
     */
    public static function process(PaymentService $ps, string $kind, int $id): array
    {
        $out = static fn (string $state, string $msg, array $tell = []): array =>
            ['state' => $state, 'message' => $msg, 'tell' => $tell];
        $t = self::TABLES[$kind] ?? null;
        if ($t === null) return $out('', 'Unknown kind.');
        if (!self::enabled($ps)) return $out('', 'Automatic payouts are off.');

        // ── THE CLAIM ────────────────────────────────────────────────────────
        $n = DB::table($t)->where('id', $id)->whereNull('auto_state')->where('status', 'requested')
            ->update(['auto_state' => self::A_CHECKING, 'transfer_checked_at' => Carbon::now()->toDateTimeString()]);
        if ($n !== 1) return $out('', 'Already being handled.');

        $w = self::load($kind, $id);
        if ($w === null) return $out('', 'No such request.');

        $eff = (new RuleEngine())->effective();
        $a = WalletRisk::assess($ps, $w, $eff);

        if (!$a['reachable']) {
            // Nobody could be asked. Released for the next sweep; not a verdict on anyone.
            DB::table($t)->where('id', $id)->where('auto_state', self::A_CHECKING)->update(['auto_state' => null]);
            return $out('', 'The bank could not be reached; it will be tried again shortly.');
        }

        DB::table($t)->where('id', $id)->update([
            'risk_json' => json_encode(['checks' => $a['checks'], 'findings' => $a['findings'],
                                        'at' => Carbon::now()->toDateTimeString()]),
            'resolved_name' => $a['resolved_name'] !== '' ? mb_substr($a['resolved_name'], 0, 160) : null,
        ]);

        if ($a['findings'] !== []) {
            DB::table($t)->where('id', $id)->update(['auto_state' => self::A_HELD]);
            $tell = array_values(array_unique(array_filter(array_column($a['findings'], 'tell'))));
            return $out(self::A_HELD, 'Held for a person: ' . implode('; ', array_column($a['findings'], 'title')) . '.', $tell);
        }

        // CR has an explicit approval step; recording it here is what the screen shows as
        // "checked automatically" — reviewed_by NULL, the reason in the note.
        if ($kind === self::KIND_RETURN) {
            $ap = CommunityReturnWithdrawals::approve($id, 0, 'Passed the automatic checks.');
            if (!$ap['ok']) {
                self::hold($t, $id, 'approve', 'Could not approve', $ap['message']);
                return $out(self::A_HELD, $ap['message']);
            }
        }
        return self::send($ps, $kind, $id, true);
    }

    /**
     * A person releases a HELD request to be sent by transfer — the override. The account
     * is still resolved (a transfer needs a recipient), but nothing else is asked: the
     * person asking is the check.
     *
     * @return array{state:string, message:string, tell:list<string>}
     */
    public static function release(PaymentService $ps, string $kind, int $id, int $adminId): array
    {
        $t = self::TABLES[$kind] ?? null;
        $w = $t ? self::load($kind, $id) : null;
        if ($w === null) return ['state' => '', 'message' => 'No such request.', 'tell' => []];
        if (!$ps->isEnabled('paystack')) return ['state' => '', 'message' => 'Paystack is not configured, so it cannot be sent from here.', 'tell' => []];
        if ((string) ($w['bank_code'] ?? '') === '') {
            return ['state' => '', 'message' => 'This request has no bank code, so a transfer cannot be built. Pay it by hand and record the reference.', 'tell' => []];
        }
        if (in_array($w['auto_state'], [self::A_SENDING, self::A_DONE], true)) {
            return ['state' => $w['auto_state'], 'message' => 'That one has already been sent.', 'tell' => []];
        }
        if ($kind === self::KIND_RETURN && (string) $w['status'] === CommunityReturnWithdrawals::WD_REQUESTED) {
            $ap = CommunityReturnWithdrawals::approve($id, $adminId, 'Released for transfer after review.');
            if (!$ap['ok']) return ['state' => '', 'message' => $ap['message'], 'tell' => []];
        }
        $open = $kind === self::KIND_RETURN ? CommunityReturnWithdrawals::WD_APPROVED : 'requested';
        $n = DB::table($t)->where('id', $id)->where('status', $open)
            ->where(static fn ($q) => $q->whereNull('auto_state')->orWhereIn('auto_state', [self::A_HELD, self::A_FAILED]))
            ->update(['auto_state' => self::A_CHECKING]);
        if ($n !== 1) return ['state' => '', 'message' => 'That request is not open any more.', 'tell' => []];

        $r = $ps->resolveAccount((string) $w['account_number'], (string) $w['bank_code']);
        if (!$r['ok']) {
            self::hold($t, $id, 'account', 'Account not resolved', $r['message']);
            return ['state' => self::A_HELD, 'message' => 'The bank could not confirm the account: ' . $r['message'], 'tell' => []];
        }
        DB::table($t)->where('id', $id)->update(['resolved_name' => mb_substr($r['name'], 0, 160)]);
        return self::send($ps, $kind, $id);
    }

    /**
     * Commit the reference and hand the row to the gateway.
     *
     * $recheck (the automatic path; a person's release is the check itself) asks the
     * member's daily limit again INSIDE a transaction holding the member's row, and moves the
     * row to SENDING in the same transaction — so a second withdrawal checked at the same
     * moment waits on the lock, then counts this one. On SQLite the lock is a no-op and the
     * suite runs one process; it is MySQL, where requests are concurrent, that it serves.
     *
     * @return array{state:string, message:string, tell:list<string>}
     */
    private static function send(PaymentService $ps, string $kind, int $id, bool $recheck = false): array
    {
        $t = self::TABLES[$kind];
        $w = self::load($kind, $id);
        if ($w === null) return ['state' => '', 'message' => 'No such request.', 'tell' => []];

        // ── THE REFERENCE, COMMITTED FIRST ───────────────────────────────────
        $ref = (string) ($w['transfer_ref'] ?? '') ?: self::PREFIX . $kind . '-' . $id . '-' . bin2hex(random_bytes(4));
        $commit = static fn (): int => DB::table($t)->where('id', $id)->where('auto_state', self::A_CHECKING)->update([
            'transfer_ref' => $ref, 'auto_state' => self::A_SENDING,
            'sent_at' => Carbon::now()->toDateTimeString(), 'transfer_checked_at' => Carbon::now()->toDateTimeString(),
        ]);

        if ($recheck) {
            $over = null;
            $n = DB::transaction(static function () use ($w, $commit, &$over): int {
                DB::table('gates_users')->where('id', (int) $w['user_id'])->lockForUpdate()->first(['id']);
                $over = WalletRisk::overDaily((int) $w['user_id'], (int) $w['amount_kobo'], (new RuleEngine())->effective());
                return $over === null ? $commit() : 0;
            });
            if ($over !== null) {
                self::hold($t, $id, 'velocity', $over['title'], $over['detail']);
                return ['state' => self::A_HELD, 'message' => 'Held for a person: ' . $over['title'] . '.',
                        'tell' => $over['tell'] !== '' ? [$over['tell']] : []];
            }
        } else {
            $n = $commit();
        }
        if ($n !== 1) return ['state' => '', 'message' => 'Already being handled.', 'tell' => []];

        return self::initiate($ps, self::load($kind, $id) ?? $w);
    }

    /** Ask Paystack to send. Safe to repeat: the reference never changes. */
    private static function initiate(PaymentService $ps, array $w): array
    {
        $t = self::TABLES[$w['kind']];
        $recipient = self::recipientFor($ps, $w);
        if ($recipient === '') {
            // Nothing was sent — a transfer needs a recipient first — so this is safe to hold.
            self::hold($t, (int) $w['id'], 'recipient', 'Recipient not created', 'Paystack would not create a transfer recipient for this account.');
            return ['state' => self::A_HELD, 'message' => 'Held: the transfer recipient could not be created.', 'tell' => []];
        }

        $sent = $ps->initiateTransferKobo((int) $w['net_kobo'], $recipient, (string) $w['transfer_ref'],
            'Africa GATES wallet withdrawal');
        DB::table($t)->where('id', (int) $w['id'])->update([
            'transfer_attempts' => DB::raw('transfer_attempts + 1'),
            'transfer_message'  => mb_substr((string) $sent['message'], 0, 250) ?: null,
            'transfer_checked_at' => Carbon::now()->toDateTimeString(),
        ]);
        if (!$sent['ok']) {
            // Not "it did not happen": the sweep asks Paystack about this reference next.
            return ['state' => self::A_SENDING, 'message' => 'Sent to the bank; waiting for it to confirm.', 'tell' => []];
        }
        self::applyStatus((string) $w['transfer_ref'], $sent['status'] ?: 'pending', (string) $sent['message'],
                          ['transfer_code' => $sent['transfer_code']]);
        $now = self::load($w['kind'], (int) $w['id']);
        return ['state' => (string) ($now['auto_state'] ?? self::A_SENDING),
                'message' => ($now['auto_state'] ?? '') === self::A_DONE ? 'Paid.' : 'Sent to the bank; waiting for it to confirm.',
                'tell' => []];
    }

    /**
     * The member's Paystack recipient for this account, made once and reused.
     */
    private static function recipientFor(PaymentService $ps, array $w): string
    {
        $key = (string) $w['bank_code'] . ':' . (string) $w['account_number'];
        try {
            $u = DB::table('gates_users')->where('id', (int) $w['user_id'])->first(['payout_recipient_code', 'payout_recipient_key']);
            if ($u && (string) ($u->payout_recipient_key ?? '') === $key && (string) ($u->payout_recipient_code ?? '') !== '') {
                return (string) $u->payout_recipient_code;
            }
        } catch (\Throwable) {}
        $name = (string) ($w['resolved_name'] ?? '') ?: (string) $w['account_name'];
        $r = $ps->createTransferRecipient($name, (string) $w['account_number'], (string) $w['bank_code']);
        if (!$r['ok']) return '';
        try {
            DB::table('gates_users')->where('id', (int) $w['user_id'])
                ->update(['payout_recipient_code' => $r['code'], 'payout_recipient_key' => $key]);
        } catch (\Throwable) {}
        return $r['code'];
    }

    // ══ the gateway's word ═════════════════════════════════════════════════════

    /**
     * A transfer's status from the webhook or the sweep. Never reopens a settled row.
     */
    public static function applyStatus(string $reference, string $status, string $message = '', array $extra = []): bool
    {
        $status = strtolower(trim($status));
        $w = self::byReference($reference);
        if ($w === null || $status === '') return false;
        $t = self::TABLES[$w['kind']];
        $id = (int) $w['id'];

        if (!in_array($status, OrgPayout::KNOWN, true)) {
            error_log('[wallet-payout] ' . $reference . ': unknown status "' . mb_substr($status, 0, 40) . '"; held as it was');
            return false;
        }

        // A reversal after success is the one event that may follow a settled row.
        if ($w['auto_state'] === self::A_DONE) {
            return $status === OrgPayout::ST_REVERSED && self::reverseSettled($w, $message);
        }
        if ($w['auto_state'] === self::A_FAILED) return false;

        $upd = ['transfer_status' => $status, 'transfer_checked_at' => Carbon::now()->toDateTimeString()];
        if ($message !== '') $upd['transfer_message'] = mb_substr($message, 0, 250);
        if (!empty($extra['transfer_code'])) $upd['transfer_code'] = mb_substr((string) $extra['transfer_code'], 0, 64);
        DB::table($t)->where('id', $id)->update($upd);

        if ($status === OrgPayout::ST_SUCCESS) {
            $r = $w['kind'] === self::KIND_RETURN
                ? CommunityReturnWithdrawals::markPaid($id, $reference, 0, true)
                : ReferralPayout::markPaid($id, $reference, 0, true);
            if (!$r['ok']) {
                // Money has left and the ledger would not take it: never silent.
                error_log('[wallet-payout] ' . $reference . ' succeeded but could not be recorded: ' . $r['message']);
                self::hold($t, $id, 'record', 'Paid but not recorded', $r['message']);
                return false;
            }
            DB::table($t)->where('id', $id)->update(['auto_state' => self::A_DONE]);
            return true;
        }

        if (in_array($status, self::FAILED, true) || $status === OrgPayout::ST_REVERSED) {
            DB::table($t)->where('id', $id)->update(['auto_state' => self::A_FAILED]);
            $why = 'The bank did not accept the transfer' . ($message !== '' ? ' (' . $message . ')' : '')
                 . '. Check the account details and ask again.';
            $w['kind'] === self::KIND_RETURN
                ? CommunityReturnWithdrawals::reject($id, $why, 0)
                : ReferralPayout::reject($id, $why, 0);
            return true;
        }
        return true;   // pending · received · otp: in flight, recorded above
    }

    /** Paid, then the bank sent it back: the money is the member's again, on the record. */
    private static function reverseSettled(array $w, string $message): bool
    {
        $t = self::TABLES[$w['kind']];
        $id = (int) $w['id'];
        $now = Carbon::now()->toDateTimeString();
        $why = 'The bank returned this transfer' . ($message !== '' ? ' (' . $message . ')' : '') . '. The money is back in your wallet.';
        try {
            DB::transaction(static function () use ($w, $t, $id, $now, $why): void {
                $n = DB::table($t)->where('id', $id)->where('auto_state', self::A_DONE)
                    ->update(['auto_state' => self::A_FAILED, 'transfer_status' => OrgPayout::ST_REVERSED, 'status' => 'rejected',
                              ($w['kind'] === self::KIND_RETURN ? 'review_note' : 'note') => mb_substr($why, 0, 400)]);
                if ($n !== 1) throw new \RuntimeException('already handled');
                if ($w['kind'] === self::KIND_RETURN) {
                    $p = DB::table('gates_community_returns')->where('nominee_id', (int) $w['nominee_id'])
                        ->where('entry_type', 'payout')->where('note', 'like', 'withdrawal #' . $id . ' %')->first();
                    DB::table('gates_community_returns')->insert([
                        'nominee_id' => (int) $w['nominee_id'], 'profile_id' => $p->profile_id ?? null, 'cycle_id' => $p->cycle_id ?? null,
                        'entry_type' => 'adjustment', 'amount_kobo' => (int) $w['amount_kobo'],
                        'note' => mb_substr('withdrawal #' . $id . ' returned by the bank', 0, 400), 'created_at' => $now,
                    ]);
                } else {
                    $ids = json_decode((string) ($w['row']->credit_ids ?? '[]'), true);
                    if (is_array($ids) && $ids !== []) {
                        DB::table('gates_referral_credits')->whereIn('id', array_map('intval', $ids))->update(['paid_out_at' => null]);
                    }
                }
            });
        } catch (\Throwable) {
            return false;
        }
        MemberWallet::tell((int) $w['user_id'], 'Your withdrawal came back', $why, $w['kind'] . '-' . $id);
        return true;
    }

    // ══ the backstop ═══════════════════════════════════════════════════════════

    /**
     * Pick up what the request path left: new requests not yet checked, checks that died
     * half way, and transfers the webhook has not settled.
     *
     * @return array{processed:int, checked:int, changed:int}
     */
    public static function sweep(PaymentService $ps, int $limit = 20): array
    {
        $done = ['processed' => 0, 'checked' => 0, 'changed' => 0];
        if (!self::enabled($ps)) return $done;
        $now = Carbon::now();

        foreach (self::TABLES as $kind => $t) {
            try {
                // A check that died half way is released after ten minutes.
                DB::table($t)->where('auto_state', self::A_CHECKING)
                    ->where('transfer_checked_at', '<', $now->copy()->subMinutes(10)->toDateTimeString())
                    ->whereNull('transfer_ref')->update(['auto_state' => null]);

                $new = DB::table($t)->whereNull('auto_state')->where('status', 'requested')->whereNotNull('bank_code')
                    ->where('requested_at', '<', $now->copy()->subMinute()->toDateTimeString())
                    ->orderBy('id')->limit($limit)->pluck('id');
                foreach ($new as $id) {
                    self::process($ps, $kind, (int) $id);
                    $done['processed']++;
                }

                $flying = DB::table($t)->where('auto_state', self::A_SENDING)->whereNotNull('transfer_ref')
                    ->where('sent_at', '<', $now->copy()->subMinutes(2)->toDateTimeString())
                    ->orderByRaw('transfer_checked_at IS NULL DESC')->orderBy('transfer_checked_at')
                    ->limit($limit)->get();
            } catch (\Throwable) {
                continue;
            }
            foreach ($flying as $row) {
                $done['checked']++;
                $ref = (string) $row->transfer_ref;
                $v = $ps->verifyTransfer($ref);
                if ($v['ok']) {
                    if (self::applyStatus($ref, $v['status'], 'Resolved by the payout sweep.')) $done['changed']++;
                    continue;
                }
                DB::table($t)->where('id', (int) $row->id)->update(['transfer_checked_at' => $now->toDateTimeString()]);
                if (!$v['reachable']) continue;
                // Paystack has no transfer under this reference. Ask again with the SAME one
                // — a duplicate is refused there — or, after enough tries, hand it to a person.
                if ((int) $row->transfer_attempts >= self::MAX_ATTEMPTS) {
                    self::hold($t, (int) $row->id, 'stuck', 'Transfer not confirmed',
                        'Paystack does not recognise reference ' . $ref . ' after ' . $row->transfer_attempts . ' attempts.');
                    $done['changed']++;
                } elseif (strcmp((string) $row->sent_at, $now->copy()->subMinutes(10)->toDateTimeString()) < 0) {
                    $w = self::load($kind, (int) $row->id);
                    if ($w) self::initiate($ps, $w);
                }
            }
        }
        return $done;
    }

    // ══ reading a request ══════════════════════════════════════════════════════

    /**
     * Either table's row, in one shape.
     *
     * @return array<string,mixed>|null
     */
    public static function load(string $kind, int $id): ?array
    {
        $t = self::TABLES[$kind] ?? null;
        if ($t === null) return null;
        try {
            $r = DB::table($t)->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$r) return null;
        $cr = $kind === self::KIND_RETURN;
        $amount = $cr ? (int) $r->amount_kobo : (int) $r->amount_naira * 100;
        $fee    = $cr ? (int) $r->fee_kobo : (int) ($r->fee_naira ?? 0) * 100;
        return [
            'kind' => $kind, 'id' => (int) $r->id, 'row' => $r, 'user_id' => (int) ($r->user_id ?? 0),
            'nominee_id' => $cr ? (int) $r->nominee_id : null,
            'page_name' => $cr ? self::nomineeName((int) $r->nominee_id) : '',
            'amount_kobo' => $amount, 'fee_kobo' => $fee, 'net_kobo' => max(0, $amount - $fee),
            'status' => (string) $r->status, 'auto_state' => $r->auto_state ?? null,
            'bank_name' => (string) ($r->bank_name ?? ''), 'bank_code' => (string) ($r->bank_code ?? ''),
            'account_name' => (string) ($r->account_name ?? ''), 'account_number' => (string) ($r->account_number ?? ''),
            'resolved_name' => (string) ($r->resolved_name ?? ''), 'transfer_ref' => (string) ($r->transfer_ref ?? ''),
            'requested_at' => (string) ($r->requested_at ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function byReference(string $ref): ?array
    {
        if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . '(crw|rp)-(\d+)-/', $ref, $m)) return null;
        $w = self::load($m[1], (int) $m[2]);
        return ($w !== null && $w['transfer_ref'] === $ref) ? $w : null;
    }

    private static function hold(string $t, int $id, string $code, string $title, string $detail): void
    {
        try {
            $r = DB::table($t)->where('id', $id)->first(['risk_json']);
            $risk = json_decode((string) ($r->risk_json ?? ''), true) ?: ['checks' => [], 'findings' => []];
            $risk['findings'][] = ['code' => $code, 'title' => $title, 'detail' => mb_substr($detail, 0, 300), 'tell' => ''];
            DB::table($t)->where('id', $id)->update(['auto_state' => self::A_HELD, 'risk_json' => json_encode($risk)]);
        } catch (\Throwable) {}
    }

    private static function nomineeName(int $id): string
    {
        try {
            return (string) (DB::table('gates_nominees')->where('id', $id)->value('name') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * What the engine found, for a reviewer: the findings frozen on the row.
     *
     * @return list<array{code:string, title:string, detail:string}>
     */
    public static function findings(object $row): array
    {
        $r = json_decode((string) ($row->risk_json ?? ''), true);
        return is_array($r['findings'] ?? null) ? $r['findings'] : [];
    }
}
