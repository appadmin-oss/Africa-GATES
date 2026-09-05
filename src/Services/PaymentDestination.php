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
     * Is subaccount routing switched on at all?
     *
     * Default ON, so nothing changes for a deployment that never sets the variable. Setting
     * `PAYSTACK_SUBACCOUNTS` to off/0/false/no disables it globally — see {@see initFields()}
     * for why a feature in the path of every payment needs a switch an operator can reach
     * without a deploy.
     */
    public static function enabled(): bool
    {
        $v = strtolower(trim((string) \AfricaGates\Support\Env::get('PAYSTACK_SUBACCOUNTS', '')));
        return !in_array($v, ['off', '0', 'false', 'no', 'disabled'], true);
    }

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
        // ── THE KILL SWITCH ──────────────────────────────────────────────────
        //
        // `PAYSTACK_SUBACCOUNTS=off` in .env turns routing off everywhere, without touching
        // the database and without losing the configured codes. It exists because this
        // feature sits directly in the path of every payment on the platform: if anything
        // about it misbehaves on a live site, the fix has to be one line an operator can
        // apply in the file manager, not a code change and a deploy.
        //
        // Off restores byte-for-byte the behaviour from before subaccounts existed — no
        // `subaccount` field goes out, and money settles to the main account.
        if (!self::enabled()) {
            return [];
        }

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
     * The stream a reference belongs to when a PARTNER ORGANISATION is the recipient.
     *
     * ── WHY THIS IS ALSO READ FROM THE REFERENCE ─────────────────────────────
     *
     * Same doctrine as {@see streamForReference()}: the recipient is looked up from the
     * pending donation row the reference already identifies, rather than threaded down
     * through initialize()'s call sites. The row is written BEFORE the gateway is called, so
     * it is always there by the time this runs, and the attribution physically cannot
     * disagree with the reference the gateway knows the payment by.
     *
     * Returns '' for every other kind of payment, including Africa GATES' own donations,
     * which keeps the existing three-stream behaviour exactly as it was.
     */
    public static function partnerOrgIdForReference(string $reference): int
    {
        $reference = trim($reference);
        if ($reference === '' || !str_starts_with(strtoupper($reference), 'AFG-GIVE')) return 0;

        try {
            if (!DB::schema()->hasColumn('gates_donations', 'recipient_org_id')) return 0;
            return (int) (DB::table('gates_donations')
                ->where('payment_ref', $reference)
                ->value('recipient_org_id') ?? 0);
        } catch (\Throwable) {
            // Unmigrated, or the database is unhappy. Unrouted is the safe answer — the
            // payment still goes out, it just settles to the main account.
            return 0;
        }
    }

    /**
     * Route a partner donation to the organisation's OWN subaccount.
     *
     * ── THE ORGANISATION'S ELIGIBILITY IS CHECKED HERE, NOT ONLY ON THE PAGE ──
     *
     * A suspended partner must stop being able to receive money at the moment somebody
     * presses pay, not the next time a page is rendered. The public page checks too, but the
     * page is a cache of a decision and this is the decision.
     *
     * Returning an empty array means the donation settles to the main account, where it is
     * visible, attributable and refundable — the correct failure for money that should not
     * have been collected, and far better than a payment that cannot be made at all.
     *
     * @return array<string,string>
     */
    public static function initFieldsForPartner(int $orgId): array
    {
        if (!self::enabled() || $orgId < 1) return [];

        try {
            $org = \AfricaGates\Services\PartnerOrg::find($orgId);
        } catch (\Throwable) {
            return [];
        }
        if (!\AfricaGates\Services\PartnerOrg::canReceive($org)) return [];

        // No `bearer` here. Who absorbs Paystack's cut on a partner donation is set by the
        // subaccount's own percentage_charge at creation, and sending a conflicting bearer
        // is how a partner discovers their share is not what they agreed to.
        return ['subaccount' => (string) $org->subaccount_code];
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
     * ── AND IT ASKS PAYSTACK, NOT JUST THE REGEX ─────────────────────────────
     *
     * Shape validation cannot tell a code belonging to THIS integration from one belonging to
     * somebody else's, and Paystack refuses the second kind at initialise — which used to mean
     * a stream that silently stopped selling with a correct-looking code in the box. So when a
     * gateway is available the code is looked up before it is stored, and Paystack's own words
     * come back with the refusal.
     *
     * The lookup is SKIPPED, not failed, when Paystack cannot be reached: an outbound blip at
     * the moment somebody presses Save must not refuse a configuration that is perfectly good.
     * {@see PaymentService::subaccount()} distinguishes the two.
     *
     * @param array<string,string> $codes   stream => raw code
     * @param array<string,string> $bearers stream => bearer choice
     * @return array{saved:list<string>, refused:array<string,string>, checked:array<string,string>}
     */
    public static function save(array $codes, array $bearers = [], ?PaymentService $payments = null): array
    {
        $saved = [];
        $refused = [];
        $checked = [];

        foreach (self::STREAMS as $stream => $label) {
            if (!array_key_exists($stream, $codes)) {
                continue;                       // not on the submitted form; leave it alone
            }
            $raw = trim((string) $codes[$stream]);

            if ($raw === '') {
                self::put(self::KEY_PREFIX . $stream, '');
                self::put(self::BEARER_PREFIX . $stream, '');
                self::put(self::REFUSED_PREFIX . $stream, '');
                $saved[] = $stream;
                continue;
            }

            $code = self::code($raw);
            if ($code === '') {
                $refused[$stream] = 'That is not a Paystack subaccount code (they look like '
                                  . 'ACCT_xxxxxxxxxx).';
                continue;                       // and the old value stays, rather than being lost
            }

            // Ask Paystack, when we can. An unreachable gateway is not a refusal.
            if ($payments !== null && $payments->isEnabled('paystack')) {
                $probe = $payments->subaccount($code);
                if (!$probe['ok'] && !str_contains($probe['message'], 'could not be reached')) {
                    $refused[$stream] = $probe['message'];
                    continue;
                }
                if ($probe['ok'] && $probe['name'] !== '') {
                    // Shown back on the screen. An operator who pasted the wrong code will
                    // usually recognise the wrong business name faster than the wrong code.
                    $checked[$stream] = trim($probe['name'] . ' · ' . $probe['bank'], ' ·');
                }
            }

            // Read BEFORE the write, or the comparison is always true and the flag never clears.
            $wasChanged = self::setting(self::KEY_PREFIX . $stream) !== $code;
            self::put(self::KEY_PREFIX . $stream, $code);
            // A new code is a fresh start: any refusal recorded against the old one is history.
            if ($wasChanged) {
                self::put(self::REFUSED_PREFIX . $stream, '');
            }

            $bearer = trim((string) ($bearers[$stream] ?? ''));
            self::put(self::BEARER_PREFIX . $stream,
                      isset(self::BEARERS[$bearer]) ? $bearer : 'account');
            $saved[] = $stream;
        }

        return ['saved' => $saved, 'refused' => $refused, 'checked' => $checked];
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
                      'bearer' => self::bearerFor($stream), 'routed' => $code !== '',
                      // A live refusal, shown beside the field that caused it. Without this the
                      // only symptom of a bad code is money quietly settling somewhere else.
                      'refusal' => self::refusal($stream)];
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

    /** Where a refusal is remembered, so the settings screen can show it beside the field. */
    private const REFUSED_PREFIX = 'paystack_sub_refused_';

    /**
     * Paystack would not accept this stream's subaccount on a live payment.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A REFUSAL HAS TO BE LOUD, AND WHY IT DOES NOT UNSET THE CODE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see code()} validates the SHAPE of what an admin typed. That catches a pasted bank
     * account number and a bank's name, and it cannot catch the failure that actually takes a
     * stream offline: a well-formed code belonging to a different Paystack integration, or one
     * that has been deleted, or one that was never activated. Paystack refuses those at
     * initialise, and every payment on that stream dies.
     *
     * {@see \AfricaGates\Services\PaymentService::initializePaystack()} now retries without the
     * routing, so the sale still completes. That is the right trade — but it makes the problem
     * SILENT, and a silent misconfiguration that quietly redirects a revenue stream into the
     * main account for a month is its own kind of bad. Hence this: the refusal is written where
     * the settings screen reads it, and the team is told once.
     *
     * The code itself is LEFT IN PLACE. Clearing it would be this method deciding, from one
     * failed HTTP call, that an operator's configuration is wrong — and the likeliest cause of
     * a single refusal is a Paystack incident, not a bad code. A stream that is routed and
     * flagged can be fixed by a person; one that has been silently un-routed cannot even be
     * seen.
     *
     * Alerted at most once an hour per stream: a busy shop would otherwise send one email per
     * checkout, and a hundred identical alerts is the same as none.
     */
    public static function reportRefusal(string $stream, string $code, string $why): void
    {
        if (!isset(self::STREAMS[$stream])) return;

        $now  = time();
        $key  = self::REFUSED_PREFIX . $stream;
        $prev = json_decode(self::setting($key), true);
        $last = is_array($prev) ? (int) ($prev['at'] ?? 0) : 0;

        self::put($key, (string) json_encode([
            'code' => $code, 'why' => mb_substr($why, 0, 300), 'at' => $now,
            'count' => (is_array($prev) ? (int) ($prev['count'] ?? 0) : 0) + 1,
        ]));

        if ($now - $last < 3600) return;                 // already shouted about this recently

        Notifier::adminAlert(null,
            'Paystack refused the subaccount for ' . (self::STREAMS[$stream] ?? $stream),
            "Paystack would not accept the subaccount configured for "
            . (self::STREAMS[$stream] ?? $stream) . ".\n\n"
            . "Subaccount: {$code}\n"
            . "Paystack said: {$why}\n\n"
            . "The payment was retried WITHOUT the subaccount and went through, so nobody has "
            . "been prevented from paying — but this money has settled to the MAIN account, and "
            . "will keep doing so until the code is fixed.\n\n"
            . "Check it under Settings → Where the money settles. The commonest causes are a "
            . "code copied from a different Paystack integration, a subaccount that has been "
            . "deleted, and one that was never activated.");
    }

    /**
     * The last refusal for a stream, for the settings screen. Null when there has never been
     * one, or when the code has been changed since — see {@see save()}.
     *
     * @return array{code:string, why:string, at:int, count:int}|null
     */
    public static function refusal(string $stream): ?array
    {
        $v = json_decode(self::setting(self::REFUSED_PREFIX . $stream), true);
        if (!is_array($v) || ($v['code'] ?? '') === '') return null;
        // A refusal against a code that is no longer configured is history, not a warning.
        if (self::forStream($stream) !== (string) $v['code']) return null;
        return ['code' => (string) $v['code'], 'why' => (string) ($v['why'] ?? ''),
                'at' => (int) ($v['at'] ?? 0), 'count' => (int) ($v['count'] ?? 1)];
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
