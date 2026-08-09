<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Find a payment from whatever the supporter actually pasted.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Seven places in this codebase did `where('payment_ref', $ref)` — the verify page, the
 * support assistant, admin triage, the refund path, the reconciler. Every one of them
 * matched exactly one thing: the reference WE minted and handed to the gateway.
 *
 * That is not the number a supporter has. Paystack's receipt email, its dashboard and
 * its SMS all show ITS transaction id and ITS own reference. A bank app shows a
 * narration and an RRN. Somebody who paid on their phone and wants to know where their
 * votes went has, in front of them, precisely the identifiers this platform could not
 * match — and the old error message admitted it: "if you paid inside a bank or wallet
 * app, that app shows its own different number".
 *
 * So the platform's answer to its most common support question depended on the
 * supporter having kept an email from us rather than the one from the gateway.
 *
 * ── FOUR THINGS IT TRIES, CHEAPEST FIRST ─────────────────────────────────────
 *
 *   1. OUR reference, exact.                                     one indexed hit
 *   2. The gateway's id or reference, as captured at confirm.     one indexed hit
 *   3. A fuzzy form of ours — case, spacing, a missing prefix,
 *      the "AFG" lost to a copy-paste.                            one indexed hit
 *   4. ASK the gateway. Paystack's verify endpoint takes a
 *      reference; if the supporter pasted Paystack's own, the
 *      transaction comes back carrying OURS, which finds the
 *      order. This is what rescues every payment confirmed
 *      before the columns in step 2 existed.                      one HTTPS call
 *
 * ── AND IT NEVER BECOMES AN ORACLE ───────────────────────────────────────────
 *
 * It returns the order it found and says which kind of identifier matched. It does NOT
 * say "that reference exists but is not yours" — the callers that hand data back
 * (VoteProof, the assistant's lookup) apply their own ownership rule on top, and
 * splitting "unknown" from "not yours" here would make this a probe for walking the
 * reference space. Every miss reads the same.
 */
final class PaymentLookup
{
    /** Longest thing anybody could paste that is still a reference. */
    private const MAX_LEN = 120;

    /**
     * Every prefix this platform actually mints, longest first.
     *
     * Guessed at first, and three of the five guesses were wrong — there is no AFG-DON- or
     * AFG-TKT-, and the shop is AFG-SHP- rather than AFG-SHOP-. A wrong entry here costs a
     * pointless indexed lookup; a MISSING one means a real supporter's half-pasted
     * reference goes unfound, so these are copied from the four places that mint them:
     *
     *   AFG-PVOTE-  PaidVoteController      AFG-GIVE-  DonationController
     *   AFG-SHP-    ShopCheckoutController  AFG-       PaymentController (generic)
     */
    private const PREFIXES = ['AFG-PVOTE-', 'AFG-GIVE-', 'AFG-SHP-', 'AFG-'];

    /**
     * Resolve whatever was pasted.
     *
     * @return array{found:bool, kind:string, donation:?object, order:?object,
     *               reference:?string, say:string, asked_gateway:bool}
     */
    public static function resolve(string $input, ?PaymentService $payments = null): array
    {
        $raw = trim($input);
        $miss = static fn(string $say): array => [
            'found' => false, 'kind' => 'none', 'donation' => null, 'order' => null,
            'reference' => null, 'say' => $say, 'asked_gateway' => false,
        ];

        if ($raw === '' || mb_strlen($raw) > self::MAX_LEN) {
            return $miss('That does not look like a payment reference.');
        }

        // ── 1 · our own reference, exactly as minted ─────────────────────────
        if ($hit = self::byOurRef($raw)) {
            return self::found($hit, 'ours', $raw, false);
        }

        // ── 2 · the gateway's own identifiers, captured at confirmation ──────
        if ($hit = self::byGatewayId($raw)) {
            return self::found($hit, 'gateway-stored', $raw, false);
        }

        // ── 3 · ours, typed by a human ───────────────────────────────────────
        //
        // People drop the prefix, paste with a trailing full stop from a sentence, read
        // it off a screenshot in the wrong case, or lose the leading "AFG-" to a
        // double-click that selected only the last word.
        foreach (self::variants($raw) as $candidate) {
            if ($hit = self::byOurRef($candidate)) {
                return self::found($hit, 'ours-fuzzy', $candidate, false);
            }
        }

        // ── 4 · ask the gateway ──────────────────────────────────────────────
        //
        // Last because it is the only step that costs a network round trip. It is also
        // the only one that can rescue a payment confirmed before we stored gateway ids,
        // which is every payment made before this shipped.
        $probe = self::askGateway($raw, $payments);
        if ($probe !== null) {
            return $probe;
        }

        return $miss(
            'No payment matching that is on record. We can find it from the reference we sent you '
            . '(it begins with AFG-), from the transaction number on your bank or Paystack receipt, '
            . 'or from the email address you paid with — any one of those works.'
        );
    }

    /**
     * Our own reference for whatever was pasted — or the input back, unchanged.
     *
     * This is the retrofit door. Everywhere that already does the right thing given one of
     * OUR references (refund verdicts, admin triage, the clawback command, the assistant's
     * refund status) keeps its exact-match query and its ownership rules, and simply asks
     * this first. A supporter's gateway receipt number becomes our reference before the
     * existing logic ever sees it; an input we cannot place passes straight through, so the
     * caller's own "not found" answer is still the one that gets given.
     */
    public static function canonical(string $input, ?PaymentService $payments = null): string
    {
        $raw = trim($input);
        if ($raw === '') return $raw;
        $r = self::resolve($raw, $payments);
        return $r['found'] && ($r['reference'] ?? '') !== '' ? (string) $r['reference'] : $raw;
    }

    /**
     * Store the gateway's identifiers against an order we have just confirmed.
     *
     * Called from the confirm paths. Never throws and never blocks a confirmation: a
     * payment that went through must be recorded as confirmed even if this bookkeeping
     * fails, and the live-probe path in resolve() is the fallback for exactly that.
     *
     * @param array $verify the array PaymentService::verify() returned
     */
    public static function remember(string $table, int $id, array $verify): void
    {
        $txn = trim((string) ($verify['gateway_id'] ?? ''));
        $ref = trim((string) ($verify['gateway_ref'] ?? ''));
        if ($id < 1 || ($txn === '' && $ref === '')) return;

        try {
            DB::table($table)->where('id', $id)->update(
                \AfricaGates\Support\OptionalColumn::filter($table, [
                    'gateway_txn_id' => $txn !== '' ? mb_substr($txn, 0, 64) : null,
                    'gateway_ref'    => $ref !== '' ? mb_substr($ref, 0, 80) : null,
                ], ['gateway_txn_id', 'gateway_ref'])
            );
        } catch (\Throwable $e) {
            error_log('[payment-lookup] could not store gateway ids on ' . $table . ' #' . $id
                    . ': ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The steps
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The two tables that hold money, and the column each one keys its reference by.
     *
     * These names differ — `gates_donations.payment_ref` for paid votes, donations and
     * tickets; `gates_orders.reference` for the shop — and the difference is a live trap.
     * Reading it off one hard-coded column name means the query throws on the other table,
     * gets swallowed by the catch, and a whole product's payments quietly become
     * unfindable with no error anywhere.
     */
    private const REF_COLUMN = ['gates_donations' => 'payment_ref', 'gates_orders' => 'reference'];

    /** @return array{table:string,row:object}|null */
    private static function byOurRef(string $ref): ?array
    {
        foreach (self::REF_COLUMN as $table => $col) {
            try {
                $row = DB::table($table)->where($col, $ref)->first();
            } catch (\Throwable) { continue; }
            if ($row !== null) return ['table' => $table, 'row' => $row];
        }
        return null;
    }

    /** @return array{table:string,row:object}|null */
    private static function byGatewayId(string $value): ?array
    {
        foreach (array_keys(self::REF_COLUMN) as $table) {
            foreach (['gateway_txn_id', 'gateway_ref'] as $col) {
                // The column may not exist yet on an unmigrated database, and asking is
                // cheaper than catching a query error per lookup.
                if (!\AfricaGates\Support\OptionalColumn::on($table, $col)) continue;
                try {
                    $row = DB::table($table)->where($col, $value)->first();
                } catch (\Throwable) { continue; }
                if ($row !== null) return ['table' => $table, 'row' => $row];
            }
        }
        return null;
    }

    /**
     * Plausible re-typings of one of our references.
     *
     * @return list<string>
     */
    private static function variants(string $raw): array
    {
        $out = [];
        // Trailing punctuation from a sentence, and surrounding quotes.
        $clean = trim($raw, " \t\n\"'.,;:()[]<>");
        if ($clean !== $raw) $out[] = $clean;

        $upper = mb_strtoupper($clean);
        if ($upper !== $clean) $out[] = $upper;

        // Whitespace inside a reference, from a line-wrapped copy.
        $tight = preg_replace('/\s+/', '', $upper) ?? $upper;
        if ($tight !== $upper) $out[] = $tight;

        // A double-click frequently selects only the last hyphen-separated word, so the
        // "AFG-PVOTE-" is gone and only the random tail is left. Both known prefixes get
        // tried — a supporter cannot be expected to know which product they bought.
        if ($tight !== '' && !str_starts_with($tight, 'AFG-')) {
            foreach (self::PREFIXES as $prefix) {
                $out[] = $prefix . strtolower($tight);
                $out[] = $prefix . $tight;
            }
        }

        // Our references are an UPPERCASE product prefix plus a LOWERCASE random tail, and
        // SQLite compares case-sensitively (MySQL's default collation does not, so this is
        // invisible in production and a hard miss on a local copy of the same data). Anyone
        // reading a reference off a screenshot, a shouted phone call, or a form that
        // uppercases as you type sends the whole thing in caps — so every candidate also
        // gets a form with the segment after the final hyphen lowercased.
        foreach (array_merge($out, [$clean]) as $cand) {
            $cut = strrpos($cand, '-');
            if ($cut === false || $cut === strlen($cand) - 1) continue;
            $mixed = substr($cand, 0, $cut + 1) . strtolower(substr($cand, $cut + 1));
            if ($mixed !== $cand) $out[] = $mixed;
        }

        return array_values(array_unique(array_filter($out, fn($v) => $v !== '' && $v !== $raw)));
    }

    /**
     * Ask each enabled gateway whether it knows this reference.
     *
     * ── WHY THIS WORKS ──────────────────────────────────────────────────────
     *
     * Paystack's verify endpoint takes a REFERENCE. Given its own reference for a
     * transaction we created, it returns that transaction — and the payload carries the
     * reference WE supplied, which is the key to our order. So one call converts "the
     * number on my receipt" into "the order in our database", for payments made long
     * before we started storing gateway ids.
     *
     * @return array{found:bool, kind:string, donation:?object, order:?object,
     *               reference:?string, say:string, asked_gateway:bool}|null
     */
    private static function askGateway(string $raw, ?PaymentService $payments): ?array
    {
        // Only for something that could BE a gateway reference. Firing an HTTPS call per
        // typo would make the support page slow for everybody who mistyped an email.
        if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $raw)) return null;

        $payments ??= self::payments();
        if ($payments === null) return null;

        foreach ($payments->enabledProviderIds() as $provider) {
            try {
                $v = $payments->verify($provider, $raw);
            } catch (\Throwable) { continue; }
            if (!($v['ok'] ?? false)) continue;

            // What OUR reference was, according to the gateway.
            $ours = trim((string) ($v['gateway_ref'] ?? ''));
            // Some providers echo the reference we asked with rather than ours, in which
            // case the metadata is where our own reference lives.
            if ($ours === '' || $ours === $raw) {
                $meta = is_array($v['meta'] ?? null) ? $v['meta'] : [];
                $ours = trim((string) ($meta['reference'] ?? ''));
            }
            if ($ours === '' || $ours === $raw) continue;

            $hit = self::byOurRef($ours);
            if ($hit === null) continue;

            // Now that we know, write it down so nobody pays for this call twice.
            self::remember($hit['table'], (int) $hit['row']->id, $v + ['gateway_ref' => $raw]);

            return self::found($hit, 'gateway-live', $ours, true);
        }
        return null;
    }

    /** A PaymentService, or null when no gateway is configured (CLI, tests). */
    private static function payments(): ?PaymentService
    {
        foreach (['PAYSTACK_SECRET_KEY', 'FLUTTERWAVE_SECRET_KEY'] as $k) {
            if (Env::has($k)) {
                try { return new PaymentService(); } catch (\Throwable) { return null; }
            }
        }
        return null;
    }

    /**
     * @param array{table:string,row:object} $hit
     * @return array{found:bool, kind:string, donation:?object, order:?object,
     *               reference:?string, say:string, asked_gateway:bool}
     */
    private static function found(array $hit, string $kind, string $matched, bool $askedGateway): array
    {
        $isDonation = $hit['table'] === 'gates_donations';
        $refCol = self::REF_COLUMN[$hit['table']] ?? 'payment_ref';
        $ours = trim((string) ($hit['row']->{$refCol} ?? ''));

        // Says WHICH identifier matched, because a supporter who pasted their bank's
        // number and got an answer should be told what our reference is — it is the one
        // that will work everywhere else, including on the phone to us.
        $say = match ($kind) {
            'ours'            => 'Found it.',
            'ours-fuzzy'      => 'Found it — our reference is ' . $ours . '.',
            'gateway-stored',
            'gateway-live'    => 'Found it from your bank or gateway receipt number. Our own '
                               . 'reference for it is ' . $ours . ' — that is the one to quote to us.',
            default           => 'Found it.',
        };

        return [
            'found' => true, 'kind' => $kind,
            'donation' => $isDonation ? $hit['row'] : null,
            'order'    => $isDonation ? null : $hit['row'],
            'reference' => $ours !== '' ? $ours : $matched,
            'say' => $say,
            'asked_gateway' => $askedGateway,
        ];
    }
}
