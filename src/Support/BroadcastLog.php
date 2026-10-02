<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\EmailOptOut;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Claim-before-send on `gates_broadcast_log`, for mail that sends ITSELF.
 *
 * ── WHY THE CLAIM COMES FIRST ────────────────────────────────────────────────
 *
 * The operator-driven campaign writes its log row after the send, and says in a comment
 * that a crash between the two could mail somebody twice. A person pressing Send watches
 * the tally and can see that. A scheduled send has nobody watching, and it has a second
 * way to repeat that the button does not: two cron ticks overlapping, each reading the
 * same "not yet sent" list. So the row is written FIRST, as `sending`, and the UNIQUE
 * (campaign, email_hash) key decides which tick owns the address. The loser skips it.
 *
 * A row left at `sending` means the process died mid-send and nobody knows whether the
 * message left. It is never retried: missing one person is recoverable, mailing the same
 * person every five minutes because the claim never settled is not.
 */
final class BroadcastLog
{
    public const SENDING = 'sending';
    public const SENT    = 'sent';
    public const FAILED  = 'failed';

    /** True when this call now owns the address for this campaign. */
    public static function claim(string $campaign, string $email, ?int $nomineeId = null): bool
    {
        try {
            DB::table('gates_broadcast_log')->insert([
                'campaign'   => mb_substr($campaign, 0, 60),
                'email_hash' => EmailOptOut::hash($email),
                'email'      => EmailOptOut::normalise($email),
                'nominee_id' => $nomineeId,
                'status'     => self::SENDING,
                'sent_at'    => Carbon::now()->toDateTimeString(),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function settle(string $campaign, string $email, bool $ok, string $error = ''): void
    {
        try {
            DB::table('gates_broadcast_log')
                ->where('campaign', mb_substr($campaign, 0, 60))
                ->where('email_hash', EmailOptOut::hash($email))
                ->update([
                    'status'  => $ok ? self::SENT : self::FAILED,
                    'error'   => $error === '' ? null : mb_substr($error, 0, 300),
                    'sent_at' => Carbon::now()->toDateTimeString(),
                ]);
        } catch (\Throwable) {
            // The message has already gone (or failed); a lost status write leaves the row
            // at `sending`, which is the never-retried state — the safe one to be stuck in.
        }
    }

    /**
     * Every address this campaign has already dealt with, whatever the outcome.
     *
     * @return array<string,string> email_hash => status
     */
    public static function handled(string $campaign): array
    {
        $out = [];
        try {
            foreach (DB::table('gates_broadcast_log')->where('campaign', mb_substr($campaign, 0, 60))
                         ->get(['email_hash', 'status']) as $r) {
                $out[(string) $r->email_hash] = (string) $r->status;
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /** @return array{sent:int, failed:int, sending:int} */
    public static function tally(string $campaign): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'sending' => 0];
        foreach (self::handled($campaign) as $status) {
            if (isset($out[$status])) $out[$status]++;
        }
        return $out;
    }
}
