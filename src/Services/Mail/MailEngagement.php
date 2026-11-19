<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * WHO RESPONDS TO OUR ANNOUNCEMENTS, MEASURED FROM WHAT THEY DID — NOT GUESSED.
 *
 * The outbox orders a campaign by this ({@see Outbox}). Four tiers, each from evidence this
 * platform already holds — the mail log (what was SENT to an address) and the click log (what
 * they PRESSED). No open pixel: Apple Mail fetches every image on receipt, so "opened" is noise
 * on a large share of phones, and a tracking image is the one thing here that would contradict
 * the privacy page. A click is somebody choosing to come back.
 *
 *   engaged  pressed a link in the last ENGAGED_DAYS          first in line: they read us
 *   new      has never been sent an announcement               early: a first impression
 *   quiet    sent QUIET_AFTER or more in SPAN_DAYS, never      last in line, and spaced out
 *            pressed anything                                  (Outbox: at most one per
 *                                                              `mail_quiet_days`)
 *   normal   everyone else
 *
 * Why the order matters beyond fairness: mailbox providers judge a sender by how its mail is
 * received, and the first few hundred of a send set that impression. Engaged readers first is
 * the cheapest deliverability there is; mailing people who never respond, repeatedly, is how a
 * domain lands in spam for everybody. Quiet readers are not dropped — they still get the
 * announcement, just not every one.
 *
 * And {@see hours()}: the hour (display zone) each address has pressed links at, for the
 * outbox's send-time choice. Two clicks at least, or it is a coincidence, not a habit.
 */
final class MailEngagement
{
    public const ENGAGED_DAYS = 120;
    public const SPAN_DAYS    = 180;
    public const QUIET_AFTER  = 5;

    public const TIERS = ['engaged' => 100, 'new' => 70, 'normal' => 50, 'quiet' => 10];

    /**
     * Tier and evidence for a set of addresses, in a few grouped queries however long the list.
     *
     * @param list<string> $hashes EmailOptOut::hash() values
     * @return array<string, array{tier:string, priority:int, hour:?int, last_bulk:?string}>
     */
    public static function profile(array $hashes, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $hashes = array_values(array_unique(array_filter($hashes)));
        $out = [];
        foreach ($hashes as $h) $out[$h] = ['tier' => 'new', 'priority' => self::TIERS['new'], 'hour' => null, 'last_bulk' => null,
                                            'sent' => 0, 'clicks' => 0, 'recent' => 0];

        $span = $now->copy()->subDays(self::SPAN_DAYS)->toDateTimeString();
        $eng  = $now->copy()->subDays(self::ENGAGED_DAYS)->toDateTimeString();
        $zone = DisplayTime::zone();

        foreach (array_chunk($hashes, 500) as $chunk) {
            if (SchemaHas::column('gates_mail_log', 'to_hash')) {
                foreach (DB::table('gates_mail_log')->whereIn('to_hash', $chunk)->where('bulk', 1)->where('status', MailLog::SENT)
                             ->where('created_at', '>=', $span)->groupBy('to_hash')
                             ->get([DB::raw('to_hash as h'), DB::raw('COUNT(*) as n'), DB::raw('MAX(created_at) as last')]) as $r) {
                    $out[$r->h]['sent'] = (int) $r->n;
                    $out[$r->h]['last_bulk'] = (string) $r->last;
                }
            }
            if (SchemaHas::table('gates_mail_clicks')) {
                foreach (DB::table('gates_mail_clicks')->whereIn('email_hash', $chunk)->where('clicked_at', '>=', $span)
                             ->get(['email_hash', 'clicked_at']) as $c) {
                    $o = &$out[$c->email_hash];
                    $o['clicks']++;
                    if ((string) $c->clicked_at >= $eng) $o['recent']++;
                    $hr = (int) Carbon::parse((string) $c->clicked_at)->setTimezone($zone)->format('G');
                    $o['hours'][$hr] = ($o['hours'][$hr] ?? 0) + 1;
                    unset($o);
                }
            }
        }

        foreach ($out as $h => &$o) {
            $o['tier'] = match (true) {
                $o['recent'] > 0                                   => 'engaged',
                $o['sent'] === 0                                   => 'new',
                $o['sent'] >= self::QUIET_AFTER && $o['clicks'] === 0 => 'quiet',
                default                                            => 'normal',
            };
            $o['priority'] = self::TIERS[$o['tier']];
            if (!empty($o['hours']) && array_sum($o['hours']) >= 2) {
                arsort($o['hours']);
                $o['hour'] = (int) array_key_first($o['hours']);
            }
            unset($o['hours'], $o['sent'], $o['clicks'], $o['recent']);
        }
        unset($o);
        return $out;
    }

    /** One address's tier, for a screen. */
    public static function tierOf(string $hash): string
    {
        return self::profile([$hash])[$hash]['tier'] ?? 'new';
    }
}
