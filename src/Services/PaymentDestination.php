<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Which Paystack subaccount each kind of money lands in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS PER STREAM AND NOT ONE SETTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Ticket money, shop money and vote money are three different kinds of money to whoever has to
 * account for them. Event income belongs to the event's own budget; shop income is trading
 * revenue against stock somebody bought; vote income funds the programme. Settling all three
 * into one bank account means the first question anybody asks at the end of the month —
 * "how much of this is ticket money" — can only be answered by exporting the platform's own
 * records and hoping they agree with the bank.
 *
 * A subaccount answers it at the bank instead, which is the only place the answer is
 * unarguable.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULES THAT MATTER MORE THAN THE FEATURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · UNCONFIGURED MUST BEHAVE EXACTLY AS BEFORE. No subaccount set means no `subaccount` field
 *     on the initialise call, which is today's behaviour to the byte. An operator who never
 *     opens this screen must not discover that their settlements changed.
 *
 * 2 · A MALFORMED CODE IS REFUSED, NOT SENT. Paystack rejects an initialise with a bad
 *     subaccount, and a rejected initialise is a buyer who cannot pay — so a typo in an admin
 *     field would take the shop offline. The shape is checked here, and {@see forStream()}
 *     returns nothing rather than something wrong. Refusing to route is recoverable; refusing
 *     to sell is not.
 *
 * 3 · WHAT WAS USED IS RECORDED ON THE PAYMENT ROW. Not derived later from the settings, because
 *     settings change: an order settled to the old subaccount would silently re-attribute itself
 *     the moment somebody edited the field, and the platform's history would stop matching the
 *     bank's. This is the same doctrine as money columns being written on the row and never
 *     recomputed.
 *
 * 4 · AND THE BEARER OF THE FEES IS EXPLICIT. Paystack's default is that the MAIN account bears
 *     the transaction charge, which is very rarely what somebody splitting revenue intends and
 *     is invisible until a settlement arrives short. It is a stated choice per stream.
 */
final class PaymentDestination
{
    /**
     * The streams money can arrive in, and what each is called on the settings screen.
     *
     * Keyed by a stable slug, because the key is written onto payment rows and a renamed key
     * would orphan every historical attribution.
     */
    public const STREAMS = [
        'events' => 'Event tickets',
        'shop'   => 'Shop orders',
        'votes'  => 'Votes and donations',
    ];

    /** Who pays Paystack's cut. */
    public const BEARERS = [
        'account'    => 'We do (the main account)',
        'subaccount' => 'The subaccount does',
    ];

    private const KEY_PREFIX = 'paystack_sub_';
    private const BEARER_PREFIX = 'paystack_bearer_';

    /**
     * A Paystack subaccount code, or '' when this stream is not routed.
     *
     * '' rather than null so a caller can compare it without a coalesce, and so "not routed" has
     * exactly one spelling — this codebase has been bitten before by two ways to say absent.
     */
    public static function forStream(string $stream): string
    {
        if (!isset(self::STREAMS[$stream])) {
            return '';
        }
        return self::code(self::setting(self::KEY_PREFIX . $stream));
    }

    /** Who bears the transaction charge for this stream. Defaults to the main account. */
    public static function bearerFor(string $stream): string
    {
        if (!isset(self::STREAMS[$stream])) {
            return 'account';
        }
        $v = trim(self::setting(self::BEARER_PREFIX . $stream));
        return isset(self::BEARERS[$v]) ? $v : 'account';
    }

    /**
     * The extra fields to merge into a Paystack initialise for this stream.
     *
     * Returns an EMPTY ARRAY when nothing is configured, which is the whole of rule 1: merging
     * an empty array changes nothing about the request that goes out.
     *
     * `bearer` is only sent alongside a subaccount, because Paystack rejects it on its own — and
     * a rejected initialise is a buyer who cannot pay.
     *
     * @return array<string,string>
     */
    public static function initFields(string $stream): array
    {
        $code = self::forStream($stream);
        if ($code === '') {
            return [];
        }
        $out = ['subaccount' => $code];

        $bearer = self::bearerFor($stream);
        if ($bearer === 'subaccount') {
            $out['bearer'] = 'subaccount';
        }
        return $out;
    }

    /**
     * Validate and normalise a code an admin typed.
     *
     * Paystack's codes look like `ACCT_` followed by alphanumerics. Anything else is a paste
     * accident — an account NUMBER, a bank name, a whole URL copied from the dashboard — and
     * sending it would make every payment on that stream fail at initialise.
     *
     * Case is preserved apart from the prefix: the codes are case-sensitive at Paystack, and
     * "helpfully" upper-casing one is how a valid code becomes invalid.
     */
    public static function code(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') {
            return '';
        }
        // A whole dashboard URL pasted in. Take the code out of it rather than refusing, because
        // copying the URL is the likeliest thing somebody does and the code is unambiguous.
        if (preg_match('/\b(ACCT_[A-Za-z0-9]{6,})\b/', $v, $m) === 1) {
            return $m[1];
        }
        // ── TYPED WITHOUT THE PREFIX ─────────────────────────────────────────
        //
        // Allowed, because some dashboard views show the code without `ACCT_` and completing it
        // is not a guess about intent. But the test that lists the real paste accidents rejected
        // two versions of this rule before this one, and both failures were the same shape:
        //
        //   `^[A-Za-z0-9]{6,40}$`            accepted `0123456789` — a BANK ACCOUNT NUMBER, the
        //                                    single likeliest thing to be pasted on a payouts
        //                                    screen.
        //   …plus "must contain a letter"    accepted `GTBank` — a bank NAME.
        //
        // Either one stored would make Paystack reject every payment on that stream: the shop
        // simply stops selling, with nothing on screen to say why. So the rule is now tight
        // enough that no plausible account number or institution name can pass — at least ten
        // characters, containing BOTH a letter and a digit, which every real Paystack code does
        // and neither of those mistakes does.
        //
        // Worth saying plainly: each narrowing came from the test naming a concrete accident, not
        // from re-reading the pattern. The pattern looked fine all three times.
        if (preg_match('/^[A-Za-z0-9]{10,40}$/', $v) === 1
            && preg_match('/[A-Za-z]/', $v) === 1
            && preg_match('/[0-9]/', $v) === 1) {
            return 'ACCT_' . $v;
        }
        return '';
    }

    /** Whether an admin-entered value would be accepted, for inline feedback on the form. */
    public static function looksValid(string $raw): bool
    {
        return self::code($raw) !== '';
    }

    /**
     * Save the whole screen at once.
     *
     * A blank field CLEARS the routing, which is a legitimate act — an operator winding down a
     * subaccount needs to be able to stop using it — so an empty value is stored as empty rather
     * than skipped. A value that does not validate is REFUSED and reported, because storing it
     * would take that stream's payments offline and the admin would have no idea why.
     *
     * @param array<string,string> $codes   stream => raw code
     * @param array<string,string> $bearers stream => bearer choice
     * @return array{saved:list<string>, refused:array<string,string>}
     */
    public static function save(array $codes, array $bearers = []): array
    {
        $saved = [];
        $refused = [];

        foreach (self::STREAMS as $stream => $label) {
            if (!array_key_exists($stream, $codes)) {
                continue;                       // not on the submitted form; leave it alone
            }
            $raw = trim((string) $codes[$stream]);

            if ($raw === '') {
                self::put(self::KEY_PREFIX . $stream, '');
                self::put(self::BEARER_PREFIX . $stream, '');
                $saved[] = $stream;
                continue;
            }

            $code = self::code($raw);
            if ($code === '') {
                $refused[$stream] = $raw;
                continue;                       // and the old value stays, rather than being lost
            }

            self::put(self::KEY_PREFIX . $stream, $code);

            $bearer = trim((string) ($bearers[$stream] ?? ''));
            self::put(self::BEARER_PREFIX . $stream,
                      isset(self::BEARERS[$bearer]) ? $bearer : 'account');
            $saved[] = $stream;
        }

        return ['saved' => $saved, 'refused' => $refused];
    }

    /**
     * Everything the settings screen needs to draw itself.
     *
     * @return list<array{stream:string, label:string, code:string, bearer:string, routed:bool}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::STREAMS as $stream => $label) {
            $code = self::forStream($stream);
            $out[] = ['stream' => $stream, 'label' => $label, 'code' => $code,
                      'bearer' => self::bearerFor($stream), 'routed' => $code !== ''];
        }
        return $out;
    }

    /** Whether any stream is routed at all — what the screen keys its explanation on. */
    public static function anyRouted(): bool
    {
        foreach (array_keys(self::STREAMS) as $s) {
            if (self::forStream($s) !== '') return true;
        }
        return false;
    }

    /**
     * Which stream a reference belongs to, from its own prefix.
     *
     * Read from the REFERENCE rather than passed in, so the recorded attribution cannot disagree
     * with the reference the gateway knows the payment by. The prefixes are the ones the
     * platform already mints — see EventTicketService and ShopCheckoutController.
     */
    public static function streamForReference(string $reference): string
    {
        $r = strtoupper(trim($reference));
        if (str_starts_with($r, 'AFG-EVT')) return 'events';
        if (str_starts_with($r, 'AFG-SHP')) return 'shop';
        if (str_starts_with($r, 'AFG-'))    return 'votes';
        return '';
    }

    // ── settings plumbing ────────────────────────────────────────────────────

    /**
     * Read one setting.
     *
     * The column is `key_name`, not `key`: `key` is reserved in MySQL. Wrapped so the name is
     * written twice in this file rather than in every caller.
     */
    private static function setting(string $name): string
    {
        try {
            return (string) (DB::table('gates_settings')->where('key_name', $name)->value('value') ?? '');
        } catch (\Throwable) {
            // No settings table on a deployment that has not migrated. Unrouted is the safe
            // answer: payments keep working exactly as they did.
            return '';
        }
    }

    private static function put(string $name, string $value): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $name], ['value' => $value]);
        } catch (\Throwable $e) {
            error_log('[payment] could not save ' . $name . ': ' . $e->getMessage());
        }
    }
}
