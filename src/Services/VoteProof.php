<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Evidence, for the two people who need it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * "IS THERE ANY PROOF TO SHOW THEM?"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Asked after telling supporters the unminted-vote incident was resolved, and it
 * is the right question to be asked. Until now the answer was no, and that is a
 * bigger problem than it looks: a platform whose whole proposition is *trusted*
 * reputation infrastructure had no way to demonstrate the one thing it had just
 * been publicly wrong about. "We fixed it" is a claim. Trust is built by handing
 * people the means to check the claim themselves.
 *
 * Two audiences, two completely different artefacts, and confusing them is why
 * most incident comms fail:
 *
 *   THE SUPPORTER wants proof about THEIR order. An aggregate is worthless to
 *   them — "99.8% of orders are fine" is not an answer to "where are MY votes".
 *   {@see forReference()} answers exactly one order, with timestamps, and it is
 *   openable by anybody holding the reference.
 *
 *   THE OPERATOR wants a number they can say out loud. {@see tally()} produces
 *   it, and it is deliberately built to be able to say NO. A report that can only
 *   confirm good news is not evidence, it is marketing.
 *
 * ── WHY IT COUNTS VOTE ROWS AND NOT `votes_used` ─────────────────────────────
 *
 * `votes_used` is the mint CLAIM stamp — a counter on the order. The votes
 * themselves are rows in `gates_votes` carrying `donation_id`. Reading the
 * counter would be asking the system whether it thinks it did the work; reading
 * the rows asks whether the work is there.
 *
 * Those can disagree, and the disagreement is the single most valuable thing this
 * file surfaces: a claim stamped with no rows behind it means a mint that died
 * half-way, which no other report on the platform would notice. So both are read
 * and any mismatch is reported as its own category rather than being smoothed
 * into a total.
 *
 * ── NO PII, EVER ─────────────────────────────────────────────────────────────
 *
 * A payment reference is unguessable but it is still a BEARER token: anyone
 * holding it gets the receipt. So the receipt contains what the holder already
 * knows — their order — and nothing about the payer. No name, no email, no phone.
 * The same rule the repair tools follow, for the same reason.
 */
final class VoteProof
{
    /**
     * The receipt for one order: what was paid, what was delivered, and when.
     *
     * `found = false` for an unknown reference, with no hint as to whether it
     * merely does not exist — splitting those would make this an oracle for
     * probing the reference space.
     *
     * @return array{found:bool, state?:string, ...}
     */
    public static function forReference(string $reference): array
    {
        $ref = trim($reference);
        if ($ref === '' || mb_strlen($ref) > 120) {
            return ['found' => false, 'say' => 'That does not look like a payment reference.'];
        }

        try {
            $d = DB::table('gates_donations')->where('payment_ref', $ref)->first();
        } catch (\Throwable) {
            return ['found' => false, 'say' => 'The record could not be read just now.'];
        }
        if (!$d) {
            return ['found' => false,
                    'say' => 'No payment with that reference is on record. If you paid inside a bank or '
                           . 'wallet app, that app shows its own different number — ours begins with AFG-.'];
        }

        // The votes themselves, not the counter that claims they exist.
        $rows = [];
        try {
            $rows = DB::table('gates_votes as v')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'v.nominee_id')
                ->where('v.donation_id', (int) $d->id)
                ->orderBy('v.id')
                ->get(['v.id', 'v.weight', 'v.vote_type', 'v.voted_at', 'n.name as nominee'])
                ->all();
        } catch (\Throwable) {}

        $delivered = 0;
        foreach ($rows as $r) $delivered += (int) $r->weight;
        $claimed = (int) ($d->votes_used ?? 0);
        $ordered = (int) ($d->bonus_votes ?? 0);

        $nominee = null;
        if (!empty($d->intent_nominee_id)) {
            try {
                $nominee = (string) (DB::table('gates_nominees')->where('id', (int) $d->intent_nominee_id)
                    ->value('name') ?? '');
            } catch (\Throwable) {}
        }

        return [
            'found'      => true,
            'reference'  => (string) $d->payment_ref,
            'state'      => self::stateOf($d, $delivered, $claimed),
            'ordered'    => $ordered,
            'delivered'  => $delivered,
            // Surfaced deliberately. A claim with no rows behind it is a mint that
            // died half-way, and it is invisible to every other report we have.
            'claimed'    => $claimed,
            'mismatch'   => $delivered !== $claimed,
            'amount'     => (int) ($d->amount_naira ?? 0),
            'nominee'    => $nominee !== '' ? $nominee : null,
            'paid_at'    => self::stamp($d->created_at ?? null),
            'confirmed_at' => self::stamp($d->confirmed_at ?? null),
            'refunded_at'  => self::stamp($d->refunded_at ?? null),
            // Each vote row, so the reader can see the actual entries rather than
            // a total we assert. This is the part that makes it evidence.
            'votes' => array_map(static fn($r) => [
                'weight'  => (int) $r->weight,
                'type'    => (string) $r->vote_type,
                'nominee' => (string) ($r->nominee ?? ''),
                'at'      => self::stamp($r->voted_at ?? null),
            ], $rows),
        ];
    }

    /**
     * One word for what happened, chosen so no state is flattering by accident.
     */
    private static function stateOf(object $d, int $delivered, int $claimed): string
    {
        $status = (string) ($d->status ?? '');
        if (($d->refunded_at ?? null) !== null)                 return 'refunded';
        if ($status !== 'confirmed')                            return $status === 'failed' ? 'not_paid' : 'pending';
        if ($delivered > 0 && $delivered === $claimed)          return 'delivered';
        if ($delivered > 0)                                     return 'partial';
        if ($claimed > 0)                                       return 'claimed_but_missing';
        return 'awaiting_delivery';
    }

    private static function stamp(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') return null;
        try { return Carbon::parse($s)->format('Y-m-d H:i'); } catch (\Throwable) { return null; }
    }

    /**
     * The platform-wide number, built to be able to say NO.
     *
     * ── WHAT EACH BUCKET MEANS, AND WHY NONE OF THEM IS "OTHER" ──────────────
     *
     *   delivered            paid, and the vote rows exist and match the claim.
     *                        This is the only good outcome.
     *   awaiting_delivery    paid, confirmed, no votes and no claim. STILL OWED.
     *                        These are the incident. `votes:remint` fixes the ones
     *                        inside the grace window; the rest are refundable.
     *   claimed_but_missing  the order says it minted and there are no rows. The
     *                        worst category and the one nothing else detects.
     *   partial              some rows, fewer than claimed. Also a broken mint.
     *   refunded             money returned; votes correctly absent.
     *
     * `clean` is true only when the three failure buckets are all zero. A single
     * outstanding order makes it false, because a supporter in that bucket does
     * not care about the other thousand.
     *
     * @param int|null $sinceDays null = all time
     */
    public static function tally(?int $sinceDays = null): array
    {
        $q = DB::table('gates_donations')->where('tier', 'paid-vote');
        if ($sinceDays !== null) {
            $q->where('created_at', '>=', Carbon::now()->subDays(max(1, $sinceDays))->toDateTimeString());
        }

        $buckets = ['delivered' => 0, 'awaiting_delivery' => 0, 'claimed_but_missing' => 0,
                    'partial' => 0, 'refunded' => 0, 'pending' => 0, 'not_paid' => 0];
        $naira   = ['awaiting_delivery' => 0, 'claimed_but_missing' => 0, 'partial' => 0];
        $votes   = ['delivered' => 0, 'owed' => 0];
        $examples = [];

        try {
            $rows = $q->orderByDesc('id')->limit(20000)->get();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not read the orders: ' . $e->getMessage()];
        }

        // One grouped query rather than a lookup per order: a per-row query over
        // twenty thousand orders is a report nobody runs twice.
        $weightByDonation = [];
        $countByDonation  = [];
        try {
            foreach (DB::table('gates_votes')->whereNotNull('donation_id')
                        ->groupBy('donation_id')
                        ->get([DB::raw('donation_id as did'), DB::raw('SUM(weight) as w'), DB::raw('COUNT(*) as c')])
                     as $g) {
                $weightByDonation[(int) $g->did] = (int) $g->w;
                $countByDonation[(int) $g->did]  = (int) $g->c;
            }
        } catch (\Throwable) {}

        foreach ($rows as $d) {
            $delivered = $weightByDonation[(int) $d->id] ?? 0;
            $claimed   = (int) ($d->votes_used ?? 0);
            $state     = self::stateOf($d, $delivered, $claimed);

            $buckets[$state] = ($buckets[$state] ?? 0) + 1;

            if ($state === 'delivered') $votes['delivered'] += $delivered;
            if (isset($naira[$state])) {
                $naira[$state] += (int) ($d->amount_naira ?? 0);
                $votes['owed']  += max(0, (int) ($d->bonus_votes ?? 0) - $delivered);
                // A handful of real references, so the operator can spot-check the
                // report instead of trusting it.
                if (count($examples) < 10) {
                    $examples[] = ['ref' => (string) $d->payment_ref, 'state' => $state,
                                   'ordered' => (int) ($d->bonus_votes ?? 0), 'delivered' => $delivered,
                                   'naira' => (int) ($d->amount_naira ?? 0)];
                }
            }
        }

        $broken = $buckets['awaiting_delivery'] + $buckets['claimed_but_missing'] + $buckets['partial'];
        $paid   = $broken + $buckets['delivered'] + $buckets['refunded'];

        return [
            'ok'        => true,
            'window'    => $sinceDays === null ? 'all time' : 'last ' . $sinceDays . ' days',
            'generated' => Carbon::now()->format('Y-m-d H:i'),
            'orders'    => $buckets,
            'paid_orders' => $paid,
            'broken'    => $broken,
            // The headline, and it is a claim about the failure buckets rather
            // than a percentage. 99.8% delivered is not "sorted" to the 0.2%.
            'clean'     => $broken === 0,
            'naira_owed' => array_sum($naira),
            'votes'     => $votes,
            'examples'  => $examples,
            'say'       => $broken === 0
                ? 'Every confirmed paid-vote order has its votes on the tally. There is nothing outstanding.'
                : $broken . ' confirmed order(s) do not have their votes — ₦' . number_format(array_sum($naira))
                  . ' worth. This is NOT resolved. Run `votes:remint --commit` for the ones still inside the '
                  . 'delivery window; the rest are refundable and RefundService will return them.',
        ];
    }

    /** True when the schema can answer these questions at all. */
    public static function ready(): bool
    {
        return OptionalColumn::on('gates_donations', 'votes_used');
    }
}
