<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What an attendee gets back if they cancel right now, and why.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE RULE THIS FILE EXISTS TO ENFORCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The figure is computed and SHOWN BEFORE the person commits. Never "cancel, and we will let
 * you know what you get" — a cancellation flow that hides the consequence until after the
 * irreversible step is the pattern regulators now actively enforce against, and it is also
 * simply how a person gets angry at an organiser who did nothing wrong.
 *
 * So {@see quote()} is a pure read: it answers the question without changing anything, and
 * the same function produces both the sentence on the page and the amount actually refunded.
 * Two implementations of "how much is this worth" would eventually differ, and the moment they
 * did, the platform would be quoting one number and paying another.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE PROSE STAYS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_site_events.refund_policy` is free text an organiser writes, and it survives this
 * unchanged. A rule can say "50% up to 48 hours before"; it cannot say "we will always try to
 * help if your flight is cancelled". The rule decides what the platform DOES automatically;
 * the prose says what the organiser MEANS, and is shown beside it.
 */
final class EventRefundPolicy
{
    public const MODES = [
        'none'    => 'No automatic refund',
        'full'    => 'Refund in full',
        'partial' => 'Refund part of it',
    ];

    /** Why a cancellation would pay back nothing. Each is a sentence somebody can act on. */
    public const BLOCKED = [
        'off'        => 'This organiser handles cancellations themselves.',
        'free'       => 'This was a free place, so there is nothing to refund.',
        'unpaid'     => 'This booking was never paid for.',
        'checked_in' => 'This ticket has already been used at the door.',
        'gone'       => 'This booking has already been cancelled.',
        'past'       => 'This event has already taken place.',
        'cutoff'     => 'It is too close to the event for an automatic refund.',
        'none'       => 'This organiser does not offer automatic refunds.',
    ];

    /**
     * May this attendee end their own booking, and what would come back?
     *
     * `can_cancel` and `naira` are DELIBERATELY separate. An organiser may well allow somebody
     * to give a seat up — freeing it for the waiting list, which is worth having — while
     * refunding nothing, because it is two days before and the catering is ordered. Collapsing
     * them into one flag would force a choice between a locked seat and an unwanted refund.
     *
     * @return array{can_cancel:bool, naira:int, reason:string, why:string, mode:string,
     *                policy_text:string, contact:string}
     */
    public static function quote(object $reg, ?object $event = null): array
    {
        $event ??= self::event((int) ($reg->event_id ?? 0));

        $out = [
            'can_cancel'  => false,
            'naira'       => 0,
            'reason'      => 'off',
            'why'         => self::BLOCKED['off'],
            'mode'        => 'none',
            'policy_text' => trim((string) ($event->refund_policy ?? '')),
            // Always carried, even when cancelling is impossible — especially then. A page
            // that says "you cannot do this here" and does not say who can is a dead end.
            'contact'     => trim((string) ($event->organiser_email ?? '')) ?: Notifier::supportEmail(),
        ];
        if (!$event) return $out;

        // ── may they cancel at all ───────────────────────────────────────────
        if ((int) ($event->self_cancel ?? 0) !== 1) return $out;

        $paid = (int) ($reg->amount_naira ?? 0);
        $stop = static function (string $reason) use ($out): array {
            $out['reason'] = $reason;
            $out['why']    = self::BLOCKED[$reason] ?? self::BLOCKED['none'];
            return $out;
        };

        if ((string) ($reg->status ?? '') !== 'confirmed')  return $stop('gone');
        if (($reg->checked_in_at ?? null) !== null)         return $stop('checked_in');

        // The event itself. Past is past — the seat cannot be resold and the organiser has
        // already paid for the room.
        $starts = trim((string) ($event->event_date ?? ''));
        if ($starts !== '' && $starts < Carbon::now()->toDateTimeString()) return $stop('past');

        // ── from here they CAN cancel; the only question is the money ────────
        $out['can_cancel'] = true;
        $out['mode'] = strtolower(trim((string) ($event->refund_mode ?? 'none'))) ?: 'none';

        if ($paid < 1) {
            $out['reason'] = 'free';
            $out['why']    = self::BLOCKED['free'];
            return $out;
        }
        if ($out['mode'] === 'none' || !isset(self::MODES[$out['mode']])) {
            $out['mode']   = 'none';
            $out['reason'] = 'none';
            $out['why']    = self::BLOCKED['none'];
            return $out;
        }

        // The cutoff, measured against the event's START rather than "now plus N" — an
        // organiser thinks "nothing back inside 48 hours of the doors", not "48 hours from
        // whenever somebody happens to ask".
        $cutoff = max(0, (int) ($event->refund_cutoff_hours ?? 0));
        if ($cutoff > 0 && $starts !== '') {
            try {
                if (Carbon::now()->gte(Carbon::parse($starts)->subHours($cutoff))) {
                    $out['reason'] = 'cutoff';
                    $out['why']    = 'Refunds close ' . self::hours($cutoff)
                                   . ' before the event, and that has passed. You can still give '
                                   . 'the seat up so somebody else can have it.';
                    return $out;
                }
            } catch (\Throwable) { /* an unparseable date is not a reason to refuse money */ }
        }

        if ($out['mode'] === 'full') {
            $out['naira']  = $paid;
            $out['reason'] = 'full';
            $out['why']    = 'You get the full ₦' . number_format($paid) . ' back.';
            return $out;
        }

        // Partial. Floored, not rounded: rounding up would pay out more than the policy says,
        // and on a large ticket the difference is real money the organiser did not agree to.
        $pct = min(100, max(1, (int) ($event->refund_percent ?? 0)));
        $out['naira']  = (int) floor($paid * $pct / 100);
        $out['reason'] = 'partial';
        $out['why']    = $pct . '% of ₦' . number_format($paid) . ' comes back — ₦'
                       . number_format($out['naira']) . '.';
        return $out;
    }

    /** The same quote, from a reference. Convenience for the HTTP layer. */
    public static function quoteByReference(string $reference): array
    {
        $reg = EventTicketService::byReference(trim($reference));
        if (!$reg) {
            return ['can_cancel' => false, 'naira' => 0, 'reason' => 'gone',
                    'why' => 'That booking could not be found.', 'mode' => 'none',
                    'policy_text' => '', 'contact' => Notifier::supportEmail()];
        }
        return self::quote($reg);
    }

    /**
     * What the organiser's settings mean, in one sentence, for the event page.
     *
     * Shown BEFORE anybody buys. A refund policy a buyer discovers only when they try to leave
     * is not a policy, it is a surprise.
     */
    public static function summary(object $event): string
    {
        if ((int) ($event->self_cancel ?? 0) !== 1) {
            return 'To cancel, contact the organiser.';
        }
        $mode = strtolower(trim((string) ($event->refund_mode ?? 'none')));
        $cut  = max(0, (int) ($event->refund_cutoff_hours ?? 0));
        $when = $cut > 0 ? ' up to ' . self::hours($cut) . ' before the event' : '';

        return match ($mode) {
            'full'    => 'You can cancel and get a full refund' . $when . '.',
            'partial' => 'You can cancel and get '
                         . min(100, max(1, (int) ($event->refund_percent ?? 0)))
                         . '% back' . $when . '.',
            default   => 'You can cancel your place, but the ticket is non-refundable.',
        };
    }

    /** '48 hours' / '2 days' — days once it is a round number of them, because people say days. */
    private static function hours(int $h): string
    {
        if ($h % 24 === 0 && $h >= 24) {
            $d = intdiv($h, 24);
            return $d . ' ' . ($d === 1 ? 'day' : 'days');
        }
        return $h . ' ' . ($h === 1 ? 'hour' : 'hours');
    }

    private static function event(int $id): ?object
    {
        if ($id < 1) return null;
        try { return DB::table('gates_site_events')->where('id', $id)->first(); }
        catch (\Throwable) { return null; }
    }
}
