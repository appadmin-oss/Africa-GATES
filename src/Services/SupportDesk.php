<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "From your account" — what the help desk shows a signed-in member before they type.
 *
 * REFERENCE §8.22: up to two rows in one card — the most recent unresolved payment
 * (amount · method, "Pending for N min", the reference on its own line, Check now) and
 * the newest open support ticket ("A person replied N ago"). Both are the member's OWN,
 * and the only identity this class ever sees is the one the controller read from the
 * session ({@see SupportContext::fromSession()} for the same rule): there is no request
 * field that names whose account this is.
 *
 * ── "UNRESOLVED" MEANS WHAT THE REPAIR CAN ACT ON ────────────────────────────
 *
 * A pending row in `gates_donations` — the table {@see PaymentReconciler::reclaim()}
 * repairs, so Check now on this row can actually do something. Not a refunded row, not
 * one the sweep has written off (`expired_at`: TIME decided, and the gateway is not
 * asked again), and never a row behind a rehearsal nominee: the sandbox is contained by
 * its programme chain, and this reader reaches a nominee by id, which has no chain
 * ({@see DemoSeeder::liveAwardOnly()}).
 *
 * ── "A PERSON REPLIED" MEANS A PERSON ────────────────────────────────────────
 *
 * The ticket line says "A person replied N ago" only when the last visible message on
 * it is staff's. {@see SupportTicketService::forMember()} already knows who spoke last;
 * the assistant answering a ticket is not a person replying, and a waiting ticket says
 * it is waiting. The tickets button's live dot is the same fact for any open ticket.
 */
final class SupportDesk
{
    /**
     * @return array{payment:?array<string,mixed>, ticket:?array<string,mixed>, replied:bool}
     */
    public static function forMember(int $userId, string $email, ?SupportTicketService $tickets = null): array
    {
        $email = mb_strtolower(trim($email));
        if ($userId < 1 && $email === '') return ['payment' => null, 'ticket' => null, 'replied' => false];

        $rows = ($tickets ?? new SupportTicketService())->forMember($userId, $email, 25);
        $open = array_values(array_filter($rows, static fn (array $t): bool => $t['status'] === 'open'));

        $staffLast = static fn (array $t): bool => !$t['waiting'] && !$t['answered_by_assistant'];

        $ticket = null;
        if ($open !== []) {
            $t = $open[0];   // forMember() orders by latest activity
            $ticket = [
                'reference' => $t['reference'],
                'subject'   => $t['subject'],
                'replied'   => $staffLast($t),
                'minutes'   => self::minutesSince($t['last_activity']),
                'url'       => '/support/tickets?ref=' . rawurlencode($t['reference']),
            ];
        }

        return [
            'payment' => $email !== '' ? self::pendingPayment($email) : null,
            'ticket'  => $ticket,
            'replied' => array_filter($open, $staffLast) !== [],
        ];
    }

    /** @return array<string,mixed>|null */
    private static function pendingPayment(string $email): ?array
    {
        try {
            $q = DB::table('gates_donations')
                ->whereRaw('LOWER(gates_donations.donor_email) = ?', [$email])
                ->where('gates_donations.status', 'pending');
            // Both optional on this table (see PartnerOrg::countableDonations()): absent
            // means none has been recorded, not an error.
            foreach (['refunded_at', 'expired_at'] as $c) {
                if (SchemaHas::column('gates_donations', $c)) $q->whereNull('gates_donations.' . $c);
            }
            DemoSeeder::liveAwardOnly($q, 'gates_donations.intent_nominee_id');

            $o = $q->orderByDesc('gates_donations.id')->first(OptionalColumn::filter('gates_donations',
                ['payment_ref', 'amount_naira', 'created_at', 'provider'], ['provider']));
        } catch (\Throwable $e) {
            error_log('[support] desk could not read payments: ' . $e->getMessage());
            return null;
        }
        if (!$o || trim((string) $o->payment_ref) === '') return null;

        return [
            'reference' => (string) $o->payment_ref,
            'amount'    => '₦' . number_format((float) $o->amount_naira),
            'provider'  => PaymentService::label((string) ($o->provider ?? '')),
            'minutes'   => self::minutesSince((string) $o->created_at),
        ];
    }

    private static function minutesSince(string $stored): int
    {
        try {
            return max(0, (int) Carbon::parse($stored)->diffInMinutes(Carbon::now(), false));
        } catch (\Throwable) {
            return 0;
        }
    }
}
