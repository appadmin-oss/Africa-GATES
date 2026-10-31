<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The help desk's live work card, built from what a payment repair actually did.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE STEPS ARE A RECORD, NOT A PERFORMANCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The handoff's help desk (REFERENCE §8.22) shows a card with three steps — "Found
 * your order", "Asking {provider} about the payment", "Adding your votes" — each
 * pending, active or done, and says in as many words: "These are the real steps from
 * supportDesk(); don't fake the timing in production." The design file fakes it: three
 * timers at 700, 1500 and 2300 ms.
 *
 * The support chat is one request and one response, and a repair either runs to the end
 * inside it or does not. So the honest card is drawn in two moments and no others:
 *
 *   WHILE THE REQUEST IS OUT — the first step active, the other two pending. That is
 *     true: the server starts by reading the order, and nothing has come back yet.
 *   WHEN IT ANSWERS — exactly the steps that HAPPENED, from this class: each `done` or
 *     `failed`, and a step that did not run (the order was already confirmed, so no
 *     gateway was asked) is not drawn at all. A tick beside "Asking Paystack" for a
 *     payment we never asked Paystack about would be the card lying in the one place a
 *     person is checking it against their bank app.
 *
 * Nothing here infers a step from the outcome word. `found` and `asked` are recorded by
 * {@see PaymentReconciler::reclaim()} where the work is done — see its docblock — and
 * the votes step is the count of votes the repair itself reports adding.
 *
 * ── WHAT THE RESULT CARD MAY SAY, AND TO WHOM ────────────────────────────────
 *
 * The repair is open to anybody holding a reference (SupportContext's class note:
 * acting on a payment is open, reading one is not). So the result card prints what the
 * holder of a reference already knows — the amount, when it was paid and through whom,
 * the public nominee it backed — and prints the address the receipt went to ONLY when
 * that address is the signed-in member's own. Everybody else is told "the email on the
 * payment", which is true and discloses nothing.
 */
final class SupportWork
{
    /** The three steps, in the order §8.22 draws them. */
    public const STEPS = ['order', 'provider', 'votes'];

    /** Outcomes after which the payment is put right — the result card's condition. */
    private const FIXED = ['MINTED', 'CONFIRMED'];

    /** Outcomes where the gateway was asked and did not confirm the payment. */
    private const PROVIDER_REFUSED = ['NOT_PAID', 'MISMATCH'];

    /**
     * The card for the LAST repair in a turn's tool results, or null if none ran.
     *
     * @param list<array<string,mixed>> $results SupportAgentService::ask()['results'], or
     *        one SupportContext::run() result in a list
     * @return array<string,mixed>|null
     */
    public static function fromResults(array $results, SupportContext $ctx): ?array
    {
        foreach (array_reverse($results) as $r) {
            if (is_array($r) && ($r['tool'] ?? '') === 'fix_payment') return self::card($r, $ctx);
        }
        return null;
    }

    /**
     * One repair's card.
     *
     * Null when nothing was attempted — no reference, the repair allowance spent, a tool
     * error before the order was read. A card of zero steps is not a quieter card, it is
     * a card claiming work happened; the reply bubble already says why it did not.
     *
     * @param array<string,mixed> $result a SupportContext::run('fix_payment') result
     * @return array{reference:string, provider:?string, steps:list<array{key:string,state:string}>,
     *               fixed:bool, result:?array<string,mixed>}|null
     */
    public static function card(array $result, SupportContext $ctx): ?array
    {
        $d = $result['data'] ?? null;
        if (!is_array($d)) return null;

        $outcome = (string) ($d['outcome'] ?? '');
        $ref     = (string) ($d['reference'] ?? '');

        // Not ours at all: the one step that ran is the lookup, and it found nothing.
        if ($outcome === 'NOT_OUR_REFERENCE') {
            return ['reference' => '', 'provider' => null, 'fixed' => false, 'result' => null,
                    'steps' => [['key' => 'order', 'state' => 'failed']]];
        }
        // No reference, rate limited, or the reconciler threw before it could say what it
        // did: no `found` key, so there is no record of a step — and so no card.
        if ($ref === '' || !array_key_exists('found', $d)) return null;

        $found = (bool) $d['found'];
        $asked = array_values(array_filter(array_map('strval', (array) ($d['asked'] ?? []))));
        $votes = (int) ($d['votes_added'] ?? 0);
        // A vote order whose mint was refused is MINT_REFUSED from the reconciler, never
        // CONFIRMED — so "Fixed" can never be drawn over votes that are not on the tally
        // (PaymentReconciler::reclaim(); this card found the CONFIRMED case first).

        $steps = [['key' => 'order', 'state' => $found ? 'done' : 'failed']];
        if ($found && $asked !== []) {
            $steps[] = ['key' => 'provider',
                        'state' => in_array($outcome, self::PROVIDER_REFUSED, true) ? 'failed' : 'done'];
        }
        if ($found && $outcome === 'MINT_REFUSED') {
            $steps[] = ['key' => 'votes', 'state' => 'failed'];
        } elseif ($found && in_array($outcome, self::FIXED, true) && $votes > 0) {
            $steps[] = ['key' => 'votes', 'state' => 'done'];
        }

        $labels = array_values(array_filter(array_map(
            static fn (string $id): ?string => PaymentService::label($id), $asked)));

        $fixed = $found && in_array($outcome, self::FIXED, true);
        $order = $fixed ? self::order($ref) : null;

        return [
            'reference' => $ref,
            // "Paystack", or "Paystack and Flutterwave" when both were tried — the names of
            // the providers that were ASKED, never a guess at which one took the money.
            'provider'  => $labels === [] ? null : implode(' · ', $labels),
            'steps'     => $steps,
            'fixed'     => $fixed,
            'result'    => $fixed && $order !== null ? self::receipt($order, $votes, $ctx) : null,
        ];
    }

    /** The order the repair worked on, or null if it cannot be read now. */
    private static function order(string $ref): ?object
    {
        try {
            $cols = ['payment_ref', 'amount_naira', 'tier', 'intent_nominee_id', 'donor_email', 'created_at'];
            foreach (['provider', 'confirmed_at'] as $opt) {
                if (SchemaHas::column('gates_donations', $opt)) $cols[] = $opt;
            }
            return DB::table('gates_donations')->where('payment_ref', $ref)->first($cols) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The result card's facts, read from the order the repair just confirmed.
     *
     * @return array<string,mixed>
     */
    private static function receipt(object $o, int $votes, SupportContext $ctx): array
    {
        $ref = (string) $o->payment_ref;

        $nominee = null;
        $nid = (int) ($o->intent_nominee_id ?? 0);
        if ($nid > 0) {
            try {
                // The sandbox is contained by its chain, and a lookup by id has no chain
                // (CLAUDE.md, "The sandbox must never reach the public"): a rehearsal
                // nominee's name is never printed here, whatever reference was typed.
                $q = DB::table('gates_nominees')->where('gates_nominees.id', $nid);
                $n = DemoSeeder::liveAwardOnly($q)->first(['gates_nominees.id', 'gates_nominees.name']);
                if ($n) $nominee = ['name' => (string) $n->name, 'url' => NomineeUrl::path((int) $n->id)];
            } catch (\Throwable) {
                $nominee = null;
            }
        }

        $paidAt   = (string) ($o->confirmed_at ?? '') !== '' ? (string) $o->confirmed_at : (string) $o->created_at;
        $provider = PaymentService::label((string) ($o->provider ?? ''));
        $email    = (string) ($o->donor_email ?? '');

        return [
            'votes'      => $votes,
            'nominee'    => $nominee,
            'amount'     => '₦' . number_format((float) $o->amount_naira),
            'paid'       => trim(($provider !== null ? $provider . ' · ' : '') . DisplayTime::show($paidAt, 'j M, H:i')),
            // Only ever the viewer's own address — see the class note.
            'receipt_to' => $email !== '' && $ctx->ownsEmail($email) ? $email : null,
            'receipt_url' => ((string) ($o->tier ?? '') === 'paid-vote' ? '/vote/paid/success' : '/pay/success')
                           . '?ref=' . rawurlencode($ref),
        ];
    }
}
