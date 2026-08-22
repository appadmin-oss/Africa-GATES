<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Event referrals: a member shares a link, and once ten people have actually PAID,
 * they earn 10% of what those people paid.
 *
 * ── THE LINK IS THE PRODUCT; THE CODE IS THE FALLBACK ────────────────────────
 * Attribution is meant to happen by link — /events/{slug}?ref=CODE — because a link
 * requires the referrer to do nothing but share and the buyer to do nothing at all. Typing
 * a code is the path for the case a link cannot survive: read aloud, printed on a flyer,
 * forwarded as plain text. Both end in the same place, and {@see EventReferralResolver}
 * is what lets one input box accept either a discount or a referral without asking the
 * buyer which of the two they hold — they usually do not know.
 *
 * ── EARNING IS GATED, AND THE GATE IS PAID TICKETS ───────────────────────────
 * THRESHOLD referrals before a penny is payable, and a referral only counts once its
 * ticket is CONFIRMED. Counting reservations instead would mean ten abandoned bookings
 * unlock earning, which is a five-minute attack on a live campaign.
 *
 * Crossing the threshold is RETROACTIVE: the tenth referral makes all ten payable, not
 * just the eleventh onward. The alternative reads as a bait — "you needed ten to start"
 * is a rule people accept; "your first ten earned nothing" is one they feel cheated by.
 */
final class ReferralService
{
    /** Paid referrals required before anything is payable. */
    public const THRESHOLD = 10;

    /** Commission in basis points. 1000 = 10%. */
    public const RATE_BPS = 1000;

    // ── The member's own code ────────────────────────────────────────────────

    /**
     * This member's referral code, minting one on first use.
     *
     * Requires an account by construction: there is no anonymous variant, because a code
     * with no owner is a code with nobody to pay.
     */
    public static function codeFor(int $userId): ?string
    {
        if ($userId < 1) return null;

        $existing = DB::table('gates_referral_codes')->where('user_id', $userId)->value('code');
        if (is_string($existing) && $existing !== '') return $existing;

        // A few attempts, because the code is random and the column is unique. Two members
        // pressing "get my link" in the same second is the case this survives.
        for ($i = 0; $i < 6; $i++) {
            $code = self::mint();
            try {
                DB::table('gates_referral_codes')->insert([
                    'user_id' => $userId, 'code' => $code,
                    'created_at' => Carbon::now()->toDateTimeString(),
                ]);
                return $code;
            } catch (\Throwable) {
                // Either the code collided, or this member already has one because a
                // concurrent request won. Re-read before trying again: if they now have a
                // code, that IS the answer.
                $again = DB::table('gates_referral_codes')->where('user_id', $userId)->value('code');
                if (is_string($again) && $again !== '') return $again;
            }
        }

        return null;
    }

    /**
     * A shareable code: unambiguous when read aloud or copied off a screen.
     *
     * No O/0, I/1/L, or vowels — the last of those so the generator cannot produce a real
     * word by accident, which on a code somebody prints is worth more than the entropy it
     * costs. 6 characters from this 24-glyph alphabet is ~191 million combinations, which
     * for a referral code is not a secret and does not need to be.
     */
    private static function mint(): string
    {
        $alphabet = 'BCDFGHJKMNPQRSTVWXYZ2346';
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'AG' . $out;
    }

    /** Upper case, no spaces or punctuation — the same shape PromoCode stores. */
    public static function normalise(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($raw)) ?? '');
    }

    /** The code row for a typed or linked code, or null. */
    public static function find(string $raw): ?object
    {
        $code = self::normalise($raw);
        if ($code === '') return null;

        return DB::table('gates_referral_codes')->where('code', $code)->first() ?: null;
    }

    // ── Attribution ──────────────────────────────────────────────────────────

    /**
     * Is this code usable by this buyer?
     *
     * Returns the code row, or a refusal message. Self-referral is the case worth naming:
     * without this check the cheapest way to reach ten is to buy ten tickets yourself and
     * take 10% back, which is not a referral scheme, it is a discount with extra steps.
     *
     * @return array{ok:bool, message:string, row?:object}
     */
    public static function usable(string $raw, ?int $buyerUserId, string $buyerEmail = ''): array
    {
        $row = self::find($raw);
        if (!$row) return ['ok' => false, 'message' => 'That code is not recognised.'];

        if ($buyerUserId !== null && (int) $row->user_id === $buyerUserId) {
            return ['ok' => false, 'message' => 'That is your own referral link — it cannot be used on your own ticket.'];
        }

        // Not signed in, but the address on the ticket is the code owner's. Same attack,
        // one step further along.
        if ($buyerEmail !== '') {
            $ownerEmail = DB::table('gates_users')->where('id', (int) $row->user_id)->value('email');
            if (is_string($ownerEmail) && strtolower(trim($ownerEmail)) === strtolower(trim($buyerEmail))) {
                return ['ok' => false, 'message' => 'That is your own referral link — it cannot be used on your own ticket.'];
            }
        }

        return ['ok' => true, 'message' => 'Referral applied — thanks for supporting them.', 'row' => $row];
    }

    /**
     * Record commission for a registration that has just been CONFIRMED.
     *
     * Called from the single winning pending→confirmed transition, so this runs once per
     * ticket per lifetime. The unique key on `registration_id` is the belt to that braces:
     * the browser callback, the gateway webhook and the reconciliation sweep all reach
     * confirmation and they race.
     *
     * Silent on every failure by design. A referral is a bonus on top of a sale; nothing
     * here may turn a paid ticket into an error the buyer sees.
     */
    public static function credit(object $reg): void
    {
        try {
            $raw = (string) ($reg->referral_code ?? '');
            if (self::normalise($raw) === '') return;

            $row = self::find($raw);
            if (!$row) return;

            $paid = max(0, (int) ($reg->amount_naira ?? 0));
            if ($paid < 1) return;   // a free ticket earns nothing to take a share of

            // intdiv, so commission can never round up beyond the rate.
            $commission = intdiv($paid * self::RATE_BPS, 10000);

            DB::table('gates_referral_credits')->insert([
                'code_id'          => (int) $row->id,
                'user_id'          => (int) $row->user_id,
                'registration_id'  => (int) $reg->id,
                'event_id'         => (int) ($reg->event_id ?? 0) ?: null,
                'paid_naira'       => $paid,
                'commission_naira' => $commission,
                'rate_bps'         => self::RATE_BPS,
                'created_at'       => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // Duplicate key on a raced confirmation is the expected path here, not an
            // exception worth surfacing.
        }
    }

    // ── What a member has earned ─────────────────────────────────────────────

    /**
     * @return array{code:?string, referrals:int, threshold:int, remaining:int, unlocked:bool,
     *               gross_naira:int, accrued_naira:int, payable_naira:int, paid_out_naira:int, rate_pct:float}
     */
    public static function stats(int $userId): array
    {
        $code = DB::table('gates_referral_codes')->where('user_id', $userId)->value('code');

        $rows = DB::table('gates_referral_credits')->where('user_id', $userId)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(paid_naira),0) as gross, '
                      . 'COALESCE(SUM(commission_naira),0) as accrued, '
                      . 'COALESCE(SUM(CASE WHEN paid_out_at IS NULL THEN 0 ELSE commission_naira END),0) as paid_out')
            ->first();

        $n        = (int) ($rows->n ?? 0);
        $accrued  = (int) ($rows->accrued ?? 0);
        $paidOut  = (int) ($rows->paid_out ?? 0);
        $unlocked = $n >= self::THRESHOLD;

        return [
            'code'      => is_string($code) && $code !== '' ? $code : null,
            'referrals' => $n,
            'threshold' => self::THRESHOLD,
            'remaining' => max(0, self::THRESHOLD - $n),
            'unlocked'  => $unlocked,
            'gross_naira'   => (int) ($rows->gross ?? 0),
            // Accrued from the first referral so the number is never a surprise, but not
            // payable until the gate opens. Showing 0 until the tenth would make the tenth
            // look like a jackpot rather than a threshold.
            'accrued_naira' => $accrued,
            'payable_naira' => $unlocked ? max(0, $accrued - $paidOut) : 0,
            'paid_out_naira' => $paidOut,
            // Cast: 1000/100 is int 10 in PHP, and the declared shape here promises a
            // float. A template formatting "10.0%" versus "10%" is cosmetic; a caller
            // type-hinting float and getting int is not.
            'rate_pct'      => (float) (self::RATE_BPS / 100),
        ];
    }

    /** The shareable link. The link is the primary path — see the class note. */
    /**
     * Who is owed what, across everybody. The other side of {@see stats()}.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * Commission accrues to `gates_referral_credits`, and `paid_out_at IS NULL` means
     * owed. Until now nobody could see the total without opening a SQL client: the
     * member's own page showed their balance, and no screen anywhere added them up. A
     * liability nobody can read is one that gets discovered by the person chasing it.
     *
     * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────
     *
     * It does not mark anything paid. `HANDOFF.md` §4 leaves the payout flow open on a
     * question this cannot answer — how money actually leaves — and a "mark as paid"
     * button that writes `paid_out_at` without a transfer behind it is worse than no
     * button: it makes the ledger say somebody was paid when they were not, and the
     * evidence they were not is gone.
     *
     * ── THE THRESHOLD IS PER MEMBER, SO THE TOTAL IS NOT ONE SUM ─────────────
     *
     * Earnings unlock at {@see THRESHOLD} referrals. So the platform's real liability is
     * the sum of the PAYABLE balances — accrued minus paid, but only for members who have
     * crossed the gate — and the accrued total is a larger, softer number that includes
     * balances nobody can withdraw yet. Both are reported, because presenting either
     * alone misleads: `accrued` overstates what is owed today and `payable` hides what is
     * owed eventually.
     *
     * @return array{payable_naira:int, accrued_naira:int, locked_naira:int,
     *               paid_out_naira:int, gross_naira:int, referrals:int,
     *               earners:int, owed_members:int, threshold:int, rate_pct:float,
     *               rows:list<array<string,mixed>>}
     */
    public static function liability(int $limit = 50): array
    {
        // The gate and the rate travel WITH the numbers. The panel states both in prose
        // beside the totals, and a screen that hardcodes "10%" is a screen that lies the
        // day RATE_BPS changes.
        $empty = ['payable_naira' => 0, 'accrued_naira' => 0, 'locked_naira' => 0,
                  'paid_out_naira' => 0, 'gross_naira' => 0, 'referrals' => 0,
                  'earners' => 0, 'owed_members' => 0, 'rows' => [],
                  'threshold' => self::THRESHOLD, 'rate_pct' => (float) (self::RATE_BPS / 100)];

        try {
            if (!DB::schema()->hasTable('gates_referral_credits')) return $empty;
        } catch (\Throwable) {
            return $empty;
        }

        try {
            // Grouped per member, because the threshold is per member. Doing this in SQL
            // and then deciding payable in PHP keeps the gate in ONE place — the same
            // comparison stats() makes — rather than restating it as a HAVING clause that
            // would quietly disagree the day THRESHOLD changes.
            $per = DB::table('gates_referral_credits')
                ->selectRaw('user_id, COUNT(*) as n, '
                          . 'COALESCE(SUM(paid_naira),0) as gross, '
                          . 'COALESCE(SUM(commission_naira),0) as accrued, '
                          . 'COALESCE(SUM(CASE WHEN paid_out_at IS NULL THEN 0 ELSE commission_naira END),0) as paid_out')
                ->groupBy('user_id')
                ->get();
        } catch (\Throwable) {
            return $empty;
        }

        $out = $empty;
        $rows = [];

        foreach ($per as $r) {
            $n       = (int) ($r->n ?? 0);
            $accrued = (int) ($r->accrued ?? 0);
            $paidOut = (int) ($r->paid_out ?? 0);
            $owed    = max(0, $accrued - $paidOut);
            $open    = $n >= self::THRESHOLD;

            $out['referrals']      += $n;
            $out['gross_naira']    += (int) ($r->gross ?? 0);
            $out['accrued_naira']  += $accrued;
            $out['paid_out_naira'] += $paidOut;
            $out['earners']++;

            if ($open) {
                $out['payable_naira'] += $owed;
                if ($owed > 0) $out['owed_members']++;
            } else {
                $out['locked_naira'] += $owed;
            }

            if ($owed <= 0) continue;
            $rows[] = [
                'user_id'   => (int) ($r->user_id ?? 0),
                'referrals' => $n,
                'owed'      => $owed,
                'accrued'   => $accrued,
                'paid_out'  => $paidOut,
                'unlocked'  => $open,
                'remaining' => max(0, self::THRESHOLD - $n),
            ];
        }

        // Largest debts first: that is the order somebody settling them works in.
        usort($rows, static fn (array $a, array $b): int => $b['owed'] <=> $a['owed']);
        $out['rows'] = self::nameRows(array_slice($rows, 0, max(1, $limit)));

        return $out;
    }

    /**
     * Attach names and emails to liability rows.
     *
     * One query for the page rather than one per row, and a member erased under
     * {@see \AfricaGates\Console\Commands\PrivacyEraseUserCommand} shows as the
     * tombstone that command wrote — the debt survives the erasure, which is correct, and
     * settling it is then a conversation rather than a lookup.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function nameRows(array $rows): array
    {
        if ($rows === []) return [];

        $ids   = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $rows)));
        $names = [];
        try {
            foreach (DB::table('gates_users')->whereIn('id', $ids)->get(['id', 'name', 'email']) as $u) {
                $names[(int) $u->id] = ['name' => (string) ($u->name ?? ''), 'email' => (string) ($u->email ?? '')];
            }
        } catch (\Throwable) {
            // A name is a convenience; the debt is the point. Leave them blank.
        }

        foreach ($rows as $i => $r) {
            $u = $names[(int) $r['user_id']] ?? null;
            $rows[$i]['name']  = $u['name']  ?? ('Member #' . $r['user_id']);
            $rows[$i]['email'] = $u['email'] ?? '';
        }
        return $rows;
    }

    public static function link(string $siteUrl, string $code, string $eventSlug = ''): string
    {
        $base = rtrim($siteUrl, '/') . ($eventSlug !== '' ? '/events/' . rawurlencode($eventSlug) : '/events');

        return $base . '?ref=' . rawurlencode($code);
    }
}
