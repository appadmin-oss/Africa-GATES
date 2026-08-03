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

    /**
     * Deliver the votes that are still owed — from a browser, with no shell.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS EXISTS AND `votes:remint` WAS NOT ENOUGH
     * ══════════════════════════════════════════════════════════════════════════
     *
     * There is no SSH on this deployment. That is not an unusual constraint to
     * have missed once — it is the constraint this whole codebase was built
     * around, which is why `/__setup/migrate` exists, why `/__setup/checkout`
     * exists, and why {@see PaymentReconciler} was extracted out of its console
     * command in the first place so that the admin screen and the CLI run the same
     * engine.
     *
     * A repair that can only be reached by a shell, on a platform whose operator
     * has no shell, is a repair that does not exist. Every supporter still owed
     * votes stays owed them, and the person answering those messages has no way to
     * act — which is precisely the position they said they did not want to be in.
     *
     * So this is the same work `votes:remint` does, callable from the Finance page.
     *
     * ── CHECK, THEN APPLY. THE SAME SHAPE AS RECONCILIATION ──────────────────
     *
     * `$apply = false` reports what WOULD be delivered and writes nothing. That is
     * the default on the web for the reason it is the default there: an operator
     * should see the population before anything moves, and the button that fixes
     * forty orders must not also be the button that quietly touches a
     * forty-first nobody looked at.
     *
     * ── EVERY DECISION IS STILL mint()'s ─────────────────────────────────────
     *
     * This chooses WHICH orders to offer; it decides nothing. mint() re-checks the
     * phase on the order's own clock, the grace window, the refund state and the
     * cap, and its idempotency claim means pressing the button twice cannot
     * double-credit anybody. Refunded orders are excluded here as well, because
     * belt and braces is right when the failure is paying for the same thing twice.
     *
     * @param bool $apply false = report only.
     * @param int  $limit orders considered in one pass.
     * @return array{ok:bool, applied:bool, considered:int, delivered:int, votes:int,
     *               blocked:array<string,int>, items:list<array<string,mixed>>, say:string}
     */
    public static function deliverOwed(bool $apply = false, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));

        try {
            $q = DB::table('gates_donations')
                ->where('status', 'confirmed')
                ->where('tier', 'paid-vote')
                ->where('votes_used', 0)
                ->whereNotNull('intent_nominee_id');

            foreach (['refunded_at', 'refund_requested_at'] as $col) {
                if (OptionalColumn::on('gates_donations', $col)) $q->whereNull($col);
            }

            // Oldest first here, unlike the reconciliation sweep. These people have
            // been waiting longest and none of them will be crowded out — the whole
            // population is small by construction, because it only contains orders
            // that failed.
            $rows = $q->orderBy('id')->limit($limit)->get();
        } catch (\Throwable $e) {
            return ['ok' => false, 'applied' => false, 'considered' => 0, 'delivered' => 0, 'votes' => 0,
                    'blocked' => [], 'items' => [],
                    'say' => 'Could not read the queue: ' . $e->getMessage()];
        }

        if ($rows->isEmpty()) {
            return ['ok' => true, 'applied' => $apply, 'considered' => 0, 'delivered' => 0, 'votes' => 0,
                    'blocked' => [], 'items' => [],
                    'say' => 'Nothing is waiting. Every confirmed paid-vote order has its votes.'];
        }

        $delivered = 0; $votes = 0; $blocked = []; $items = [];

        foreach ($rows as $d) {
            if (!$apply) {
                $items[] = ['ref' => (string) $d->payment_ref, 'votes' => (int) $d->bonus_votes,
                            'naira' => (int) $d->amount_naira, 'when' => (string) $d->created_at,
                            'outcome' => 'would try'];
                continue;
            }

            try {
                $r = PaidVoteService::mint((int) $d->id);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'code' => 'ERROR', 'message' => $e->getMessage()];
            }

            if (!empty($r['ok'])) {
                $delivered++;
                $n = (int) ($r['minted'] ?? $d->bonus_votes);
                $votes += $n;
                $items[] = ['ref' => (string) $d->payment_ref, 'votes' => $n,
                            'naira' => (int) $d->amount_naira, 'when' => (string) $d->created_at,
                            'outcome' => 'delivered'];
            } else {
                $code = (string) ($r['code'] ?? 'FAILED');
                $blocked[$code] = ($blocked[$code] ?? 0) + 1;
                $items[] = ['ref' => (string) $d->payment_ref, 'votes' => (int) $d->bonus_votes,
                            'naira' => (int) $d->amount_naira, 'when' => (string) $d->created_at,
                            'outcome' => $code];
            }
        }

        // Named outcomes rather than a total, because the two blocked reasons need
        // completely different responses and lumping them together hides that.
        $say = !$apply
            ? count($rows) . ' order(s) are waiting for their votes. Nothing has been changed — press '
              . 'Deliver to actually mint them.'
            : $delivered . ' order(s) delivered, ' . number_format($votes) . ' vote(s) added.';

        if ($apply && isset($blocked['CONFIRMED_TOO_LATE'])) {
            $say .= ' ' . $blocked['CONFIRMED_TOO_LATE'] . ' could not be delivered because their cycle closed '
                  . 'too long ago — a settled tally must not move. Those are refundable and the automatic '
                  . 'refund path will return them.';
        }
        if ($apply && isset($blocked['ALREADY_REFUNDED'])) {
            $say .= ' ' . $blocked['ALREADY_REFUNDED'] . ' had already been refunded, so there was nothing owed.';
        }

        return ['ok' => true, 'applied' => $apply, 'considered' => count($rows),
                'delivered' => $delivered, 'votes' => $votes, 'blocked' => $blocked,
                'items' => $items, 'say' => $say];
    }
}
