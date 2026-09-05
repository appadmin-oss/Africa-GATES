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
    /**
     * The shipped defaults, and the fallback when the settings table cannot be read.
     *
     * Kept as constants deliberately: a rate read from a database needs an answer when the
     * database is the thing that is broken, and paying 0% because a query failed is worse
     * than paying the default.
     */
    public const THRESHOLD = 10;

    /** Commission in basis points. 1000 = 10%. */
    public const RATE_BPS = 1000;

    // ── The terms, as an administrator set them ──────────────────────────────

    /**
     * Commission in basis points, from `gates_settings`.
     *
     * ── CHANGING THIS DOES NOT REWRITE HISTORY ───────────────────────────────
     *
     * Every credit row stamps the `rate_bps` that applied when it was earned, so a change
     * here governs FUTURE referrals and leaves settled balances exactly as they were. That
     * is the only version of an editable rate that is safe: silently restating what people
     * were told they had earned is not a settings change, it is a repudiation.
     *
     * Clamped to 0–50%. Zero is legitimate — it turns earning off while leaving the links
     * working and the history readable — and the upper bound is there because a typo in a
     * basis-point field is easy and expensive: "10000" meant to be "1000" gives away every
     * naira of the gate.
     */
    public static function rateBps(): int
    {
        $v = self::setting('referral_rate_bps');
        if ($v === '' || !ctype_digit($v)) return self::RATE_BPS;

        return max(0, min(5000, (int) $v));
    }

    /** Commission as a percentage, for screens and prose. */
    public static function ratePct(): float
    {
        return self::rateBps() / 100;
    }

    /**
     * Paid referrals required before anything is payable.
     *
     * ── AND CHANGING THIS *DOES* MOVE MONEY ──────────────────────────────────
     *
     * Unlike the rate, the threshold is evaluated live against everybody's current count.
     * Lowering it makes balances payable that were locked yesterday; raising it locks
     * balances that were payable. Those are real amounts owed to real people, and the
     * admin screen says so where somebody is about to change the number.
     *
     * Floored at 1: zero would mean "unlocked before the first referral", which is not a
     * threshold, and the retroactive rule below already gives the first referral its
     * commission the moment the gate opens.
     */
    public static function threshold(): int
    {
        $v = self::setting('referral_threshold');
        if ($v === '' || !ctype_digit($v)) return self::THRESHOLD;

        return max(1, min(1000, (int) $v));
    }

    /**
     * The platform-wide switch.
     *
     * Off means no NEW commission accrues and no new code is minted. It does not delete
     * anything: existing balances stay owed and stay visible, because switching a feature
     * off is not a reason to stop owing somebody money.
     */
    public static function enabled(): bool
    {
        $v = self::setting('referrals_enabled');
        return $v === '' ? true : ($v === '1' || strtolower($v) === 'true');
    }

    /**
     * Whether one event shares its gate.
     *
     * Not every event can afford to give away a tenth of the takings — a free community
     * night, a partner-funded evening, or one whose margin is already committed. Defaults
     * to ON for an event predating the column, since that was the behaviour then and a
     * missing column must not silently stop paying people.
     */
    public static function enabledForEvent(?int $eventId): bool
    {
        if (!self::enabled()) return false;
        if ($eventId === null || $eventId < 1) return true;

        try {
            if (!DB::schema()->hasColumn('gates_site_events', 'referrals_enabled')) return true;
            $v = DB::table('gates_site_events')->where('id', $eventId)->value('referrals_enabled');
            return $v === null || (int) $v === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE LINK, ANYWHERE ON THE SITE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Where a followed referral link is remembered.
     *
     * Was `event_ref`, private to EventsController, and captured only on /events pages. So
     * a member who shared their link to the shop, or to the home page, or to anything a
     * reader would plausibly click, earned nothing — the code was stripped by the redirect
     * and never seen again. The referrer had done everything right.
     *
     * Renamed as it moved, and {@see fromSession()} still reads the old key, because a
     * session created minutes before a deploy is a real person mid-purchase.
     */
    public const SESSION_KEY = 'ag_ref';

    /** The key this used to be stored under. Read, never written. */
    private const LEGACY_SESSION_KEY = 'event_ref';

    /**
     * Lift a referral code off a URL and hold it for the rest of the session.
     *
     * THE LINK IS THE PRIMARY PATH. A referrer shares a URL and the buyer does nothing at
     * all — there is no code to carry from the post they saw to the checkout they reach ten
     * minutes later.
     *
     * Overwritten by a later `?ref=`, deliberately: the most recent link somebody followed
     * is the one that brought them to this purchase.
     */
    public static function capture(string $raw): void
    {
        $code = self::normalise($raw);
        if ($code === '') return;
        if (!isset($_SESSION) || !is_array($_SESSION)) return;

        // Length-capped before it is stored: this string arrived from a URL.
        $_SESSION[self::SESSION_KEY] = mb_substr($code, 0, 32);
    }

    /** The code captured from a link earlier in this session, or ''. */
    public static function fromSession(): string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return '';

        $v = (string) ($_SESSION[self::SESSION_KEY] ?? $_SESSION[self::LEGACY_SESSION_KEY] ?? '');

        return self::normalise($v);
    }

    /** Forget it — after it has been credited, so one link cannot earn on two purchases. */
    public static function clearSession(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return;
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::LEGACY_SESSION_KEY]);
    }

    /** One setting, read the way every other service here reads one. */
    private static function setting(string $key): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return is_string($v) ? trim($v) : '';
        } catch (\Throwable) {
            return '';
        }
    }

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

        // Switched off: mint nothing new. An existing code is still returned below —
        // somebody who already shared their link keeps a working one, and their balance
        // stays owed. Turning a feature off is not a reason to break links already in
        // other people's hands.
        if (!self::enabled()) {
            $have = DB::table('gates_referral_codes')->where('user_id', $userId)->value('code');
            return is_string($have) && $have !== '' ? $have : null;
        }

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
    public static function usable(string $raw, ?int $buyerUserId, string $buyerEmail = '', ?int $eventId = null): array
    {
        // Asked BEFORE the code is looked up, so a buyer on an event that does not share
        // its gate is told plainly instead of being thanked for a referral that will never
        // pay. credit() refuses the same case again at the moment money is earned; this is
        // the half that keeps the screen honest.
        if (!self::enabledForEvent($eventId)) {
            return ['ok' => false, 'message' => 'Referral links are not being used for this event.'];
        }

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
    /**
     * What a referral link can earn a member a share of.
     *
     * ── AND, MORE IMPORTANTLY, WHAT IT CANNOT ───────────────────────────────
     *
     * This is a whitelist and it is in code rather than in settings, because the two
     * omissions are not preferences an operator should be able to change from a form:
     *
     *  · PAID VOTES ARE ABSENT, PERMANENTLY. Paying a member a percentage of vote
     *    purchases is a standing offer to be paid for bringing in money that moves an award
     *    result. Every other integrity control here — the fraud scoring, the collusion
     *    scan, the separate organic_vote_count, the shortlist's organic-only switch —
     *    exists to keep bought support distinguishable from real support. Commission on it
     *    would put the platform on the other side of its own defences.
     *
     *  · DONATIONS ARE ABSENT. A cut taken out of a charitable gift and paid to whoever
     *    forwarded the link is not what the donor believed they were funding, and in
     *    several jurisdictions soliciting donations for a commission is regulated activity
     *    this organisation is not registered for.
     *
     * Tickets and merchandise are ordinary retail: somebody bought a thing at a stated
     * price, and a share of that is an arrangement no buyer would be surprised by.
     *
     * ── AND WHY VENDOR STANDS ARE NOT LISTED EITHER ─────────────────────────
     *
     * Not on principle — a vendor referring another vendor is perfectly reasonable — but
     * because there is no payment event to hang it on. {@see StandApplication::accept()}
     * says "you will be invoiced for the stand fee", and that invoice is settled off the
     * platform. Crediting on acceptance would pay commission on money that has not moved
     * and may never move.
     *
     * Declaring a source nothing can write is the kind of half-built promise that gets
     * found later as a bug report about missing payouts, so it is left out until stand fees
     * are actually collected here. Adding it then is one line and a call site.
     *
     * @var array<string,string>
     */
    public const SOURCES = [
        'registration' => 'Event ticket',
        'shop_order'   => 'Shop order',
    ];

    /**
     * Credit a referral against any earning source.
     *
     * The generic path. {@see credit()} is the registration-shaped wrapper around it, kept
     * because the checkout calls it with a row object rather than fields.
     *
     * Idempotent on `(source_type, source_id)`, which is a UNIQUE index — a gateway that
     * confirms the same payment twice cannot pay commission twice, and the duplicate-key
     * exception that results is the EXPECTED path rather than an error.
     *
     * @param string $sourceType one of {@see SOURCES}
     * @param int    $sourceId   the id of the order/registration/application
     * @param string $rawCode    the referral code the buyer arrived with
     * @param int    $paidNaira  what was actually paid, after any discount
     * @param int    $eventId    the event, where there is one — for the per-event switch
     */
    public static function creditSale(string $sourceType, int $sourceId, string $rawCode,
                                      int $paidNaira, ?int $eventId = null): bool
    {
        // An unlisted source is a programming error and must fail LOUDLY in the log rather
        // than quietly not paying somebody. The two absences above are the whole reason
        // this check is not a permissive default.
        if (!isset(self::SOURCES[$sourceType])) {
            error_log('[referral] refusing to credit an undeclared source: ' . $sourceType);
            return false;
        }
        if ($sourceId < 1) return false;

        try {
            if (self::normalise($rawCode) === '') return false;

            $row = self::find($rawCode);
            if (!$row) return false;

            $paid = max(0, $paidNaira);
            if ($paid < 1) return false;   // a free ticket earns nothing to take a share of

            // The switches, checked at the moment money is earned rather than at the
            // moment the link was shared: an event whose referrals were turned off after
            // somebody clicked must not still pay out on it. A source with no event —
            // a shop order — is governed by the global switch alone.
            if (!self::enabledForEvent($eventId)) return false;

            // The rate as it is TODAY, stamped onto the row so this credit keeps it
            // forever. intdiv, so commission can never round up beyond the rate.
            $rate       = self::rateBps();
            $commission = intdiv($paid * $rate, 10000);

            DB::table('gates_referral_credits')->insert([
                'code_id'          => (int) $row->id,
                'user_id'          => (int) $row->user_id,
                'source_type'      => $sourceType,
                'source_id'        => $sourceId,
                // 0 rather than NULL: the column is NOT NULL on SQLite, and its own unique
                // index was dropped by the 2026_10_09 migration precisely so several
                // non-registration credits can share this value.
                'registration_id'  => $sourceType === 'registration' ? $sourceId : 0,
                'event_id'         => $eventId,
                'paid_naira'       => $paid,
                'commission_naira' => $commission,
                'rate_bps'         => $rate,
                'created_at'       => Carbon::now()->toDateTimeString(),
            ]);

            return true;
        } catch (\Throwable) {
            // Duplicate key on a raced confirmation is the expected path here, not an
            // exception worth surfacing.
            return false;
        }
    }

    /** The event-ticket path, unchanged for its callers. */
    public static function credit(object $reg): void
    {
        self::creditSale(
            'registration',
            (int) ($reg->id ?? 0),
            (string) ($reg->referral_code ?? ''),
            (int) ($reg->amount_naira ?? 0),
            (int) ($reg->event_id ?? 0) ?: null,
        );
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
        $threshold = self::threshold();
        $unlocked  = $n >= $threshold;

        return [
            'code'      => is_string($code) && $code !== '' ? $code : null,
            'referrals' => $n,
            'threshold' => $threshold,
            'remaining' => max(0, $threshold - $n),
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
            'rate_pct'      => self::ratePct(),
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
                  'threshold' => self::threshold(), 'rate_pct' => self::ratePct()];

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

        $out  = $empty;
        $rows = [];
        // Read once for the whole page, not once per member: a threshold that changed
        // mid-loop would produce a table whose totals do not add up.
        $threshold = self::threshold();

        foreach ($per as $r) {
            $n       = (int) ($r->n ?? 0);
            $accrued = (int) ($r->accrued ?? 0);
            $paidOut = (int) ($r->paid_out ?? 0);
            $owed    = max(0, $accrued - $paidOut);
            $open    = $n >= $threshold;

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
                'remaining' => max(0, $threshold - $n),
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
