<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Tell a member, at once, that where their money goes or who the account says they are
 * has changed.
 *
 * ── WHY ──────────────────────────────────────────────────────────────────────
 *
 * The automatic payout holds a withdrawal for `wallet_auto_change_hours` after new bank
 * details, a new account name or a sign-in by code (WalletRisk::takeover()). A hold is
 * only a DELAY: somebody reading a member's inbox can sign in with a code, rename the
 * account to match their own bank account, save that account, wait out the hold and be
 * paid, and at no point was the member told anything. The hold buys time; this is what
 * spends it, by telling the one person who can say "that was not me".
 *
 * ── WHICH WIRES, AND WHY THE OLD ONES ───────────────────────────────────────
 *
 * Email to the account's address AND a text to the phone on file. The email alone is weak
 * against the exact case above (the attacker reads that inbox and deletes it); the text is
 * the channel they do not hold. When the change is to the profile itself, the text goes to
 * the number on file BEFORE the change, because "change the phone, then the bank" would
 * otherwise send the warning to the attacker. A security notice ignores the event-text
 * opt-out (SmsService::sendSms's $respectOptOut), as a sign-in code does: somebody who
 * asked to stop hearing about events did not ask not to be told their payouts moved.
 *
 * Best-effort throughout: a notice that fails must never undo the change it reports, and
 * nothing here throws to the caller.
 */
final class AccountChangeNotice
{
    /** Test seams, as CrmAudiencePush::$transport: null means the configured transports. */
    public static ?OtpService $mailer = null;
    public static ?SmsService $texter = null;

    /** The member saved bank details that send their withdrawals somewhere new. */
    public static function payoutBank(int $userId, string $accountNumber, ?OtpService $mail = null, ?SmsService $sms = null): void
    {
        $u = self::user($userId);
        if ($u === null) return;
        $tail = substr((string) preg_replace('/\D+/', '', $accountNumber), -4);
        $what = 'the bank account your Africa GATES withdrawals are paid to was set to one ending ' . $tail;
        self::tell($u, 'Your payout bank account was changed', $what, (string) ($u->phone_e164 ?? $u->phone ?? ''), $mail, $sms);
    }

    /**
     * The member's name or phone changed. `$was` is the row as it stood before the update.
     *
     * @param object $was needs name, phone (and phone_e164 where present)
     */
    public static function profile(int $userId, object $was, string $newName, string $newPhone,
                                   ?OtpService $mail = null, ?SmsService $sms = null): void
    {
        $u = self::user($userId);
        if ($u === null) return;
        $parts = [];
        if (trim((string) $was->name) !== trim($newName)) $parts[] = 'the name on your Africa GATES account was changed to "' . trim($newName) . '"';
        $oldPhone = (string) ($was->phone_e164 ?? $was->phone ?? '');
        if (preg_replace('/\D+/', '', (string) ($was->phone ?? '')) !== preg_replace('/\D+/', '', $newPhone)) {
            $parts[] = 'the phone number on your Africa GATES account was ' . ($newPhone === '' ? 'removed' : 'changed');
        }
        if ($parts === []) return;
        self::tell($u, 'Your account details were changed', implode(', and ', $parts), $oldPhone, $mail, $sms);
    }

    /** @return object|null */
    private static function user(int $userId): ?object
    {
        if ($userId < 1) return null;
        try {
            return DB::table('gates_users')->where('id', $userId)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function tell(object $u, string $subject, string $what, string $phone,
                                 ?OtpService $mail, ?SmsService $sms): void
    {
        $when    = \AfricaGates\Support\DisplayTime::showZoned(Carbon::now()->toDateTimeString(), 'j M Y, H:i');
        $support = rtrim(SiteUrl::base(), '/') . '/support';
        $hours   = WalletRisk::limit((new RuleEngine())->effective(), 'wallet_auto_change_hours');
        $hold    = $hours > 0 ? ' Withdrawals wait at least ' . $hours . ' hours after a change like this.' : '';

        $email = (string) ($u->email ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                Notifier::confirm($mail ?? self::$mailer ?? OtpService::boot(), $email, $subject,
                    'On ' . $when . ', ' . $what . '.' . "\n\n"
                    . 'If this was you, there is nothing to do.' . "\n\n"
                    . 'If it was not you, contact us straight away at ' . $support
                    . ' and do not use any sign-in code you did not ask for.' . $hold);
            } catch (\Throwable) {}
        }
        if ($phone !== '') {
            try {
                ($sms ?? self::$texter ?? SmsService::boot())->sendSms($phone,
                    'Africa GATES: on ' . $when . ', ' . $what . '. Not you? Contact us now: ' . $support,
                    'account_change', false);
            } catch (\Throwable) {}
        }
    }
}
