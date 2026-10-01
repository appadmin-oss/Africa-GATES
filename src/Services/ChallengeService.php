<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Who is in a challenge, what they have done, and the order they finished in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING A MODEL SAYS REACHES ANY DECISION IN THIS FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A challenge pays money and ranks named people. Qualification, the standing, the
 * draw, a disqualification and a payout are decided here by counting rows against
 * written rules, and by nothing else. The assistant drafts copy, suggests scopes,
 * triages the verification queue and clusters entries a human should look at — all
 * of it advisory, all of it admin-facing, none of it in this class. The handoff puts
 * it plainly and so does §0.7: nothing AI is named on a nominator's screen either.
 *
 * ── THE RACE IS THE WHOLE PROBLEM ───────────────────────────────────────────
 *
 * "The first 11 people" is a claim about an ordering under concurrency. The shape
 * that looks right and is not:
 *
 *     $taken = count of qualified entries;          // a SELECT
 *     if ($taken < $cap) { … standing = $taken + 1 }   // an UPDATE
 *
 * Nothing serialises the gap. Fire eleven qualifications together and every one holds
 * a snapshot saying the same number, so every one concludes it is next and several
 * are handed the same standing — or a twelfth is let past the cap. This codebase has
 * the identical fault written up twice already: the OTP attempt cap, where
 * read-then-compare made the limit "decoration at any concurrency above one", and the
 * nominee merge, where a missing clause under-selected silently.
 *
 * So {@see qualify()} takes a row lock on the CHALLENGE for the whole decision, and
 * the predicate and the write travel together. Everything that reads a count to
 * decide something does it inside that lock.
 *
 * ── AND THE LOCK IS A NO-OP ON THE HARNESS, WHICH IS WHY THE GUARD IS TOO ───
 *
 * `lockForUpdate()` compiles to nothing on SQLite — the driver has no row locks, and
 * writer serialisation gives the same answer for a different reason. So the race test
 * passes on SQLite whatever this code does, and the only run that can tell is the
 * MySQL parity one. `VoteService::verifyAndVote()` is the precedent for the pattern
 * and for saying so out loud.
 *
 * The belt-and-braces half is that the standing is also UNIQUE-constrained per
 * challenge, so a double-assignment is a refused write rather than two people told
 * they came fourth.
 */
final class ChallengeService
{
    /**
     * How many of one person's nominations may point at the same identity.
     *
     * One. The whole target is "different people", and the hash is what makes two
     * spellings of one phone number the same human.
     */
    private const PER_IDENTITY = 1;

    // ══════════════════════════════════════════════════════════════════════════
    // Joining
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Put a member into a challenge, or hand back the entry they already have.
     *
     * Returns `['ok' => bool, 'code' => string, 'entry' => ?object]`. The refusals are
     * the published rules, in the order a person meets them.
     */
    public static function join(int $challengeId, int $userId, ?string $phone = null, ?string $country = null): array
    {
        $ch = self::find($challengeId);
        if (!$ch) return ['ok' => false, 'code' => 'NO_CHALLENGE', 'entry' => null];

        // A challenge that is not open takes no entries. `draft` and `cancelled` are
        // refused as hard as `ended`: a draft is a page an admin is still writing.
        if (!in_array($ch->status, [E::ST_OPEN, E::ST_FULL], true)) {
            return ['ok' => false, 'code' => 'NOT_OPEN', 'entry' => null];
        }

        $existing = DB::table('gates_challenge_entries')
            ->where('challenge_id', $challengeId)->where('user_id', $userId)->first();

        // Idempotent: pressing join twice is one entry, and an entry that exists is
        // returned even when the challenge has since filled — their progress is still
        // theirs, and the page has to be able to show it.
        if ($existing) return ['ok' => true, 'code' => 'ALREADY', 'entry' => $existing];

        if ($ch->status === E::ST_FULL) {
            return ['ok' => false, 'code' => 'FULL', 'entry' => null];
        }

        // ── THE PHONE IS THE RULE, SO A MISSING ONE IS A REFUSAL ────────────
        // "Your account's phone number is verified" is the first published rule and
        // the only one standing between one person and ten entries. A member with no
        // verified number is told to verify, not quietly let in.
        $hash = self::phoneHash($phone, $country);
        if ($hash === null) return ['ok' => false, 'code' => 'NO_VERIFIED_PHONE', 'entry' => null];

        $now = date('Y-m-d H:i:s');

        try {
            $id = DB::table('gates_challenge_entries')->insertGetId([
                'challenge_id' => $challengeId, 'user_id' => $userId,
                'phone_hash' => $hash, 'joined_at' => $now,
                'status' => E::E_ACTIVE, 'payout_status' => E::PAY_NONE,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // The UNIQUE on (challenge_id, phone_hash) is the second account for one
            // human. It is a refusal with a reason, not a crash — and it is caught
            // rather than pre-checked, because a pre-check is the same read-then-write
            // gap the standing has.
            $dupe = DB::table('gates_challenge_entries')
                ->where('challenge_id', $challengeId)->where('phone_hash', $hash)->first();

            if ($dupe) return ['ok' => false, 'code' => 'PHONE_ALREADY_ENTERED', 'entry' => null];

            throw $e;
        }

        self::event($challengeId, $id, 'joined', ['user_id' => $userId]);

        return ['ok' => true, 'code' => 'JOINED', 'entry' => self::entry($id)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Counting
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Recount one entry from its rows and qualify it if it has reached the target.
     *
     * Called after anything that could change the count: a nominee confirming, a
     * moderator approving, a ticket being paid for, a refund, a disqualification.
     *
     * **It recounts rather than increments.** A counter nudged from five places drifts
     * the first time one of them runs twice or dies halfway, and the drift is invisible
     * — the number looks like a number. Counting the rows is the one arithmetic that
     * cannot disagree with the rows.
     */
    public static function recount(int $entryId): array
    {
        $entry = self::entry($entryId);
        if (!$entry) return ['ok' => false, 'code' => 'NO_ENTRY', 'entry' => null];

        $ch = self::find((int) $entry->challenge_id);
        if (!$ch) return ['ok' => false, 'code' => 'NO_CHALLENGE', 'entry' => null];

        $counts = self::countFor($ch, $entry);

        DB::table('gates_challenge_entries')->where('id', $entryId)->update([
            'progress'      => $counts['verified'],
            'verified'      => $counts['verified'],
            'checking'      => $counts['checking'],
            'needs_details' => $counts['needs_details'],
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // A disqualified or withdrawn entry still has its rows counted — the queue
        // screen has to show what it had — but it can never qualify.
        if ($entry->status !== E::E_ACTIVE) {
            return ['ok' => true, 'code' => 'NOT_ACTIVE', 'entry' => self::entry($entryId)];
        }

        if ($counts['verified'] < (int) $ch->target) {
            // ── A COUNT CAN GO DOWN ─────────────────────────────────────────
            // A refund removes a paid ticket and a revoked approval removes a
            // nomination. An entry that was qualified and no longer meets the target
            // loses the qualification — but NEVER the standing it was already given,
            // because that standing is a published fact other people's standings were
            // assigned around. Reassigning it would renumber strangers.
            if ($entry->qualified_at !== null && $entry->standing === null) {
                DB::table('gates_challenge_entries')->where('id', $entryId)
                    ->update(['qualified_at' => null, 'status' => E::E_ACTIVE,
                              'updated_at' => date('Y-m-d H:i:s')]);
                self::event((int) $ch->id, $entryId, 'unqualified', $counts);
            }

            return ['ok' => true, 'code' => 'SHORT', 'entry' => self::entry($entryId)];
        }

        return self::qualify($entryId);
    }

    /**
     * What one entry's rows are worth, by the challenge's own action and scope.
     *
     * @return array{verified:int,checking:int,needs_details:int}
     */
    private static function countFor(object $ch, object $entry): array
    {
        if ((string) $ch->action === E::ACTION_NOMINATE) {
            $rows = DB::table('gates_nominations')
                ->where('challenge_entry_id', $entry->id)
                ->get(['status', 'nominee_confirmed_at', 'nominee_identity_hash']);

            // ── DISTINCT PEOPLE, NOT DISTINCT ROWS ──────────────────────────
            // The target is "10 different nominees". Counting rows lets one friend
            // sent through under two spellings of their number count twice, which is
            // precisely what the identity hash exists to stop. This is the same shape
            // as `VoterReach` counting people rather than ballots.
            $seen = $checking = $needs = 0;
            $ids  = [];

            foreach ($rows as $r) {
                $key = (string) ($r->nominee_identity_hash ?? '');

                if (NS::countsForChallenge($r->status ?? null, $r->nominee_confirmed_at ?? null)) {
                    // A row with no hash at all cannot be proved distinct from any
                    // other, so it counts once and only once — conservatively, because
                    // the error that costs somebody a prize is the one to avoid.
                    if ($key === '') { $seen++; continue; }
                    if (!isset($ids[$key])) { $ids[$key] = true; $seen++; }
                    continue;
                }

                if (($r->status ?? '') === NS::NEEDS_DETAILS) { $needs++; continue; }
                $checking++;
            }

            return ['verified' => $seen, 'checking' => $checking, 'needs_details' => $needs];
        }

        if ((string) $ch->action === E::ACTION_REFER) {
            return self::countReferrals($ch, $entry);
        }

        // vote / give / attend have no designed copy yet and must not be publishable;
        // see `ChallengeCopy::steps()`. Counting nothing is the honest answer until
        // the rule for each is written, rather than a guess that silently pays out.
        return ['verified' => 0, 'checking' => 0, 'needs_details' => 0];
    }

    /**
     * Paid tickets bought through this member's referral link, inside the scope.
     *
     * Counted from `gates_referral_credits`, which is the platform's own record that a
     * sale happened and earned a share: one row per registration, UNIQUE on
     * `registration_id`, and only ever written for a payment of at least ₦1. So "paid,
     * not reserved" is already true of every row in it, and a free ticket is absent
     * rather than filtered.
     *
     * ── A PUBLISHED RULE WITH NOTHING TO READ ───────────────────────────────
     *
     * "Refunded tickets are removed" is printed on the challenge page. Nothing in this
     * codebase reverses a referral credit: `refunded_at` exists on donations and on
     * nothing else, and `creditSale()` has no counterpart. So the rule was publishable
     * and unenforceable — somebody could buy five tickets through their own promotion,
     * collect the prize, and charge all five back.
     *
     * `reversed_at` is added to the credits table for it, and excluded here through
     * `SchemaHas` because the column is younger than the table and a database part-way
     * through its migrations must not throw. Its absence means no reversal has been
     * recorded, which is the same answer as none existing.
     *
     * **Nothing calls `reverse()` yet** — the event side has no refund path at all to
     * call it from. That is recorded in the step 3 notes rather than left to be
     * discovered: a mechanism with no route in is this codebase's most expensive shape,
     * and the honest state is "the rule can now be enforced the day a refund path
     * exists", not "refunds are handled".
     *
     * @return array{verified:int,checking:int,needs_details:int}
     */
    private static function countReferrals(object $ch, object $entry): array
    {
        if (!SchemaHas::table('gates_referral_credits')) {
            return ['verified' => 0, 'checking' => 0, 'needs_details' => 0];
        }

        $events = self::scopeIds($ch, E::SCOPE_EVENT);

        $q = DB::table('gates_referral_credits')->where('user_id', $entry->user_id);

        // An action counts ONLY inside a scope (§3). A challenge scoped to one gala
        // must not be advanced by a ticket to a different event.
        if ($events !== []) $q->whereIn('event_id', $events);

        if (SchemaHas::column('gates_referral_credits', 'reversed_at')) $q->whereNull('reversed_at');

        // "Buying tickets for yourself through your own link doesn't count" — also
        // published. The credit's own `user_id` is the REFERRER, so the buyer is read
        // off the registration.
        if (SchemaHas::table('gates_event_registrations')) {
            $q->whereNotExists(static function ($sub) use ($entry) {
                $sub->selectRaw('1')->from('gates_event_registrations as r')
                    ->whereColumn('r.id', 'gates_referral_credits.registration_id')
                    ->where('r.user_id', $entry->user_id);
            });
        }

        // There is no pending state here: a credit exists or the sale did not clear.
        // Reporting a false "checking" figure would put a number on the progress card
        // that no row can ever turn into a prize.
        return ['verified' => (int) $q->count(), 'checking' => 0, 'needs_details' => 0];
    }

    /**
     * Take a referral credit back out of a challenge count, for a refunded ticket.
     *
     * Stamped rather than deleted: the credit is the record that the money once
     * cleared, and this codebase has the rule written down already — a donation
     * clawback stamps `refunded_at` and deliberately leaves the row, because rewriting
     * it destroys the fact. The entry is recounted, which can take a qualification
     * away but never a standing already assigned.
     */
    public static function reverseReferralCredit(int $creditId, string $reason = ''): bool
    {
        if (!SchemaHas::column('gates_referral_credits', 'reversed_at')) return false;

        $credit = DB::table('gates_referral_credits')->where('id', $creditId)->first();
        if (!$credit || $credit->reversed_at !== null) return false;

        DB::table('gates_referral_credits')->where('id', $creditId)
            ->update(['reversed_at' => date('Y-m-d H:i:s')]);

        foreach (DB::table('gates_challenge_entries')->where('user_id', $credit->user_id)
                     ->whereNull('standing')->pluck('id') as $entryId) {
            self::recount((int) $entryId);
        }

        self::event(0, null, 'referral_reversed',
            ['credit' => $creditId, 'user' => (int) $credit->user_id, 'reason' => $reason]);

        return true;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The standing
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Give an entry its place, inside a lock on the challenge.
     *
     * The lock is taken on the CHALLENGE row and not on the entry: the question being
     * answered — "how many places are left" — is about the challenge, so two entries
     * qualifying at once must contend for the same row. Locking each entry would let
     * both proceed, each correctly locking a row nobody else wanted.
     *
     * `mode = top` and `mode = draw` get `qualified_at` and no standing: a top race is
     * ordered at the close by count, and a draw is drawn. Only `first` is a race for
     * numbered places, and only `first` can fill.
     */
    public static function qualify(int $entryId): array
    {
        return DB::transaction(static function () use ($entryId): array {
            // ── THE LOCK IS THE FIRST STATEMENT, AND THAT IS NOT A STYLE CHOICE ──
            //
            // MySQL's default isolation is REPEATABLE READ, where the transaction's
            // read view is established by its FIRST consistent read and every later
            // non-locking SELECT answers from that frozen snapshot. An unlocked
            // `SELECT … FROM gates_challenge_entries` above this line would set that
            // view BEFORE the lock was acquired — so the count below would be read
            // from a moment before the other qualifiers committed, and every one of
            // eleven concurrent callers would compute the same `taken`.
            //
            // That is not hypothetical. It is what this code did:
            // `scripts/challenge-race-check.php` fired eleven at once and nine were
            // refused by the UNIQUE on (challenge_id, standing), with places 1 and 2
            // assigned and the challenge left `open` past its cap. The row lock was
            // there and held; the snapshot was older than the lock.
            //
            // So: lock first, and make every count below a LOCKING read, which always
            // sees the latest committed row rather than the view.
            $ch = DB::table('gates_challenges')->where('id', self::challengeIdOf($entryId))
                ->lockForUpdate()->first();

            if (!$ch) return ['ok' => false, 'code' => 'NO_CHALLENGE', 'entry' => null];

            $entry = DB::table('gates_challenge_entries')->where('id', $entryId)
                ->lockForUpdate()->first();
            if (!$entry) return ['ok' => false, 'code' => 'NO_ENTRY', 'entry' => null];
            if ($entry->status !== E::E_ACTIVE) {
                return ['ok' => false, 'code' => 'NOT_ACTIVE', 'entry' => $entry];
            }
            if ($entry->qualified_at !== null) {
                // Already in. Idempotent, because `recount()` may legitimately be
                // called again by a later approval on the same entry.
                return ['ok' => true, 'code' => 'ALREADY', 'entry' => $entry];
            }
            if (!in_array($ch->status, [E::ST_OPEN, E::ST_FULL], true)) {
                return ['ok' => false, 'code' => 'NOT_OPEN', 'entry' => $entry];
            }

            $now = date('Y-m-d H:i:s');

            if ((string) $ch->mode !== E::MODE_FIRST) {
                DB::table('gates_challenge_entries')->where('id', $entryId)->update([
                    'qualified_at' => $now, 'status' => E::E_QUALIFIED, 'updated_at' => $now,
                ]);
                self::event((int) $ch->id, $entryId, 'qualified', ['mode' => $ch->mode]);

                return ['ok' => true, 'code' => 'QUALIFIED', 'entry' => self::entry($entryId)];
            }

            $cap = (int) ($ch->cap ?? 0);

            // Read inside the lock AND as a locking read. This is the count the whole
            // mechanism turns on, and a plain `count()` here answers from the
            // transaction's snapshot rather than from the table. Measured: without
            // `lockForUpdate()` on this line, nine of eleven concurrent qualifiers
            // computed `taken = 0`.
            $taken = (int) DB::table('gates_challenge_entries')
                ->where('challenge_id', $ch->id)->whereNotNull('standing')
                ->where('status', '!=', E::E_DISQUALIFIED)
                ->lockForUpdate()->count();

            if ($cap > 0 && $taken >= $cap) {
                // Qualified, and too late for a prize. Recorded as qualified rather
                // than refused: they did the work, the page says so, and a challenge
                // that tells somebody who finished that they did not is a lie its own
                // rows contradict.
                DB::table('gates_challenge_entries')->where('id', $entryId)->update([
                    'qualified_at' => $now, 'status' => E::E_QUALIFIED, 'updated_at' => $now,
                ]);
                self::markFull((int) $ch->id);
                self::event((int) $ch->id, $entryId, 'qualified_after_cap', ['taken' => $taken]);

                return ['ok' => true, 'code' => 'QUALIFIED_NO_PRIZE', 'entry' => self::entry($entryId)];
            }

            $standing = $taken + 1;

            DB::table('gates_challenge_entries')->where('id', $entryId)->update([
                'qualified_at' => $now, 'standing' => $standing,
                'status' => E::E_QUALIFIED, 'payout_status' => E::PAY_PENDING,
                'updated_at' => $now,
            ]);

            self::event((int) $ch->id, $entryId, 'qualified', ['standing' => $standing]);

            // The cap being reached is a fact about the rows; the column catches up
            // here so every reader that is not inside this lock can see it.
            if ($cap > 0 && $standing >= $cap) self::markFull((int) $ch->id);

            return ['ok' => true, 'code' => 'QUALIFIED', 'standing' => $standing,
                    'entry' => self::entry($entryId)];
        });
    }

    private static function markFull(int $challengeId): void
    {
        DB::table('gates_challenges')->where('id', $challengeId)
            ->where('status', E::ST_OPEN)
            ->update(['status' => E::ST_FULL, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Taking somebody out
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Disqualify an entry, with a reason that is written down.
     *
     * **The reason is required and is not free of consequence.** "Fraud disqualifies
     * the whole entry, with a logged reason" is published, and an operator removing a
     * named person from a prize without saying why leaves the next operator — and any
     * appeal — with nothing. An empty reason is refused.
     *
     * The standing is RELEASED, because a disqualified entry never held a place: the
     * people behind them move up. That is the one case where renumbering is right, and
     * it is why {@see recount()} refuses to do it for a count that merely fell.
     */
    public static function disqualify(int $entryId, string $reason, ?int $adminId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'code' => 'NO_REASON'];

        return DB::transaction(static function () use ($entryId, $reason, $adminId): array {
            $entry = DB::table('gates_challenge_entries')->where('id', $entryId)->first();
            if (!$entry) return ['ok' => false, 'code' => 'NO_ENTRY'];

            DB::table('gates_challenges')->where('id', $entry->challenge_id)->lockForUpdate()->first();

            $freed = $entry->standing !== null ? (int) $entry->standing : null;

            DB::table('gates_challenge_entries')->where('id', $entryId)->update([
                'status' => E::E_DISQUALIFIED, 'disqualify_reason' => mb_substr($reason, 0, 300),
                'standing' => null, 'payout_status' => E::PAY_NONE,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Everybody behind them moves up one. Done as one statement so there is no
            // window in which two people hold the same place.
            if ($freed !== null) {
                DB::table('gates_challenge_entries')
                    ->where('challenge_id', $entry->challenge_id)
                    ->where('standing', '>', $freed)
                    ->decrement('standing');

                // A place has opened, so the challenge is not full any more.
                DB::table('gates_challenges')->where('id', $entry->challenge_id)
                    ->where('status', E::ST_FULL)
                    ->update(['status' => E::ST_OPEN, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            self::event((int) $entry->challenge_id, $entryId, 'disqualified',
                ['reason' => mb_substr($reason, 0, 300), 'freed' => $freed, 'by' => $adminId]);

            return ['ok' => true, 'code' => 'DISQUALIFIED', 'freed' => $freed];
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The draw
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Draw the winners, reproducibly, from a seed that is stored before it is used.
     *
     * **"The draw is recorded and published" is a published rule, so it has to be
     * checkable by somebody who does not trust us.** A draw nobody can re-run is an
     * assertion; a draw anybody can re-run from a published seed and a published list
     * of entrants is evidence. So the seed is stored on the challenge, the ordering is
     * a keyed hash of (seed, entry id) rather than a shuffle, and re-running produces
     * the same winners for ever.
     *
     * `random_shuffle` with a seeded `mt_srand` would be reproducible only for as long
     * as PHP's generator never changes, which is not a promise anyone has made.
     */
    public static function draw(int $challengeId, ?string $seed = null): array
    {
        return DB::transaction(static function () use ($challengeId, $seed): array {
            $ch = DB::table('gates_challenges')->where('id', $challengeId)->lockForUpdate()->first();
            if (!$ch) return ['ok' => false, 'code' => 'NO_CHALLENGE', 'winners' => []];
            if ((string) $ch->mode !== E::MODE_DRAW) return ['ok' => false, 'code' => 'NOT_A_DRAW', 'winners' => []];

            // A draw already made is NOT re-made. The seed is the record; re-drawing
            // on a different one would be exactly the quiet edit the publication rule
            // exists to prevent.
            $seed = $ch->draw_seed ?: ($seed ?: bin2hex(random_bytes(16)));

            if (!$ch->draw_seed) {
                DB::table('gates_challenges')->where('id', $challengeId)
                    ->update(['draw_seed' => $seed, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            $pool = DB::table('gates_challenge_entries')
                ->where('challenge_id', $challengeId)
                ->whereNotNull('qualified_at')
                ->where('status', '!=', E::E_DISQUALIFIED)
                ->orderBy('id')->get(['id']);

            $ordered = [];
            foreach ($pool as $p) {
                // Keyed on the seed, so the order cannot be known before the seed is
                // published and cannot change after it.
                $ordered[(int) $p->id] = hash_hmac('sha256', (string) $p->id, $seed);
            }
            asort($ordered, SORT_STRING);

            $take    = max(0, (int) ($ch->draw_count ?? 0));
            $winners = array_slice(array_keys($ordered), 0, $take);

            foreach ($winners as $i => $entryId) {
                DB::table('gates_challenge_entries')->where('id', $entryId)->update([
                    'standing' => $i + 1, 'status' => E::E_WON,
                    'payout_status' => E::PAY_PENDING, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            self::event($challengeId, null, 'drawn',
                ['seed' => $seed, 'pool' => count($ordered), 'winners' => $winners]);

            return ['ok' => true, 'code' => 'DRAWN', 'seed' => $seed, 'winners' => $winners];
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Reading
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The public winners list: a first name, an initial, an area and a time.
     *
     * §3: "Public winners show first name + initial, area and time only." A full name
     * beside a published cash amount is an invitation addressed to whoever reads it,
     * and this list is on a page with no sign-in.
     *
     * **The area is read from what the entrant typed, not from their account.**
     * `gates_users` holds no location at all, so an area joined from there would be
     * blank on every row — a column that renders as nothing and reads as a layout
     * fault. The nomination carries `nominator_lga`, which is the entrant's own
     * answer, so that is the source; a referral challenge has no nomination and shows
     * no area rather than an invented one.
     */
    public static function winners(int $challengeId, int $limit = 50): array
    {
        $rows = DB::table('gates_challenge_entries as e')
            ->where('e.challenge_id', $challengeId)
            ->whereNotNull('e.standing')
            ->where('e.status', '!=', E::E_DISQUALIFIED)
            ->leftJoin('gates_users as u', 'u.id', '=', 'e.user_id')
            ->orderBy('e.standing')->limit(max(1, $limit))
            ->get(['e.id', 'e.standing', 'e.qualified_at', 'u.name']);

        $ids = array_map(static fn($r) => (int) $r->id, $rows->all());
        $areas = [];

        if ($ids !== [] && SchemaHas::column('gates_nominations', 'challenge_entry_id')) {
            foreach (DB::table('gates_nominations')->whereIn('challenge_entry_id', $ids)
                         ->get(['challenge_entry_id', 'nominator_lga', 'nominator_state']) as $n) {
                $k = (int) $n->challenge_entry_id;
                if (isset($areas[$k])) continue;
                $a = trim((string) ($n->nominator_lga ?: $n->nominator_state ?: ''));
                if ($a !== '') $areas[$k] = $a;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'standing' => (int) $r->standing,
                'name'     => self::publicName((string) ($r->name ?? '')),
                'where'    => $areas[(int) $r->id] ?? '',
                'when'     => (string) ($r->qualified_at ?? ''),
            ];
        }

        return $out;
    }

    /** "Chidinma Okafor" → "Chidinma O." — and a single name stays a single name. */
    public static function publicName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if ($parts === []) return 'Someone';
        if (count($parts) === 1) return $parts[0];

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    /** How many prizes are gone, for the meter. Never a cached counter. */
    public static function claimed(int $challengeId): int
    {
        return (int) DB::table('gates_challenge_entries')
            ->where('challenge_id', $challengeId)->whereNotNull('standing')
            ->where('status', '!=', E::E_DISQUALIFIED)->count();
    }

    public static function entryFor(int $challengeId, int $userId): ?object
    {
        return DB::table('gates_challenge_entries')
            ->where('challenge_id', $challengeId)->where('user_id', $userId)->first();
    }

    /** The scope ids of one kind, so a count can be confined to them. */
    public static function scopeIds(object $ch, string $type): array
    {
        return DB::table('gates_challenge_scopes')
            ->where('challenge_id', $ch->id)->where('scope_type', $type)
            ->pluck('scope_id')->map(static fn($v) => (int) $v)->all();
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * One hash for one human.
     *
     * The number itself is never stored beside a prize: a table of phone numbers and
     * cash amounts is the thing somebody wants a copy of. The E.164 normalisation is
     * what makes "+234 803 123 4567" and "08031234567" the same person — without it
     * the UNIQUE constraint enforces nothing, since the same human types it both ways.
     *
     * ── THE COUNTRY IS A PARAMETER AND HAS NO DEFAULT, DELIBERATELY ─────────
     *
     * `Phone::normalize()` cannot resolve a trunk-0 national number without one:
     * `08031234567` is Nigerian, Ivorian or Kenyan depending on who typed it. Defaulting
     * to NG here would be convenient and wrong in the one direction that matters — two
     * different people in two countries hashing to one value, so the second is refused
     * entry with "that number is already in" about a stranger's number. The caller
     * knows the country (the member's own record, the nomination's `country_code`) and
     * passes it; a number that cannot be resolved is a refusal the person can act on,
     * which is what `NO_VERIFIED_PHONE` is for.
     */
    public static function phoneHash(?string $raw, ?string $country = null): ?string
    {
        $e164 = Phone::normalize($raw, $country);

        return $e164 === null ? null : hash('sha256', 'ag-challenge-phone:' . $e164);
    }

    /**
     * One hash for one nominee, for the "different people" rule.
     *
     * Phone first because it is the stronger claim to being a distinct human; the
     * email is the fallback for a nomination that carries no number. A nominee with
     * neither is unprovable and gets no hash, and {@see countFor()} then counts the
     * row once rather than collapsing every such nominee into one.
     */
    public static function identityHash(?string $phone, ?string $email, ?string $country = null): ?string
    {
        $e164 = Phone::normalize($phone, $country);
        if ($e164 !== null) return hash('sha256', 'ag-nominee-identity:tel:' . $e164);

        $mail = mb_strtolower(trim((string) $email));

        return $mail === '' ? null : hash('sha256', 'ag-nominee-identity:mail:' . $mail);
    }

    /** Is this nominee already inside this entry? The "different people" rule. */
    public static function identityTaken(int $entryId, ?string $hash): bool
    {
        if ($hash === null) return false;

        return DB::table('gates_nominations')
            ->where('challenge_entry_id', $entryId)
            ->where('nominee_identity_hash', $hash)
            ->count() >= self::PER_IDENTITY;
    }

    /**
     * The same nominee inside somebody ELSE's entry, which is flagged, never refused.
     *
     * §3: "Cross-entry duplicate nominees are flagged for review." Two neighbours
     * nominating the same teacher is the system working; one person running ten
     * accounts is not, and only a human looking at the pair can tell which.
     */
    public static function crossEntryDuplicates(int $challengeId, string $hash, int $exceptEntryId): int
    {
        return (int) DB::table('gates_nominations as n')
            ->join('gates_challenge_entries as e', 'e.id', '=', 'n.challenge_entry_id')
            ->where('e.challenge_id', $challengeId)
            ->where('n.nominee_identity_hash', $hash)
            ->where('n.challenge_entry_id', '!=', $exceptEntryId)
            ->count();
    }

    /**
     * The challenge a given entry belongs to, read before any lock is taken.
     *
     * Deliberately OUTSIDE the locked section of {@see qualify()}: it answers a
     * question that never changes for an entry, and reading it inside would be the
     * very snapshot-establishing read the lock order exists to avoid.
     */
    private static function challengeIdOf(int $entryId): int
    {
        return (int) DB::table('gates_challenge_entries')->where('id', $entryId)
            ->value('challenge_id');
    }

    public static function find(int $id): ?object
    {
        return DB::table('gates_challenges')->where('id', $id)->first();
    }

    public static function bySlug(string $slug): ?object
    {
        return DB::table('gates_challenges')->where('slug', $slug)->first();
    }

    public static function entry(int $id): ?object
    {
        return DB::table('gates_challenge_entries')->where('id', $id)->first();
    }

    /**
     * The ledger. Every decision this class makes leaves one of these.
     *
     * `gates_audit_log` is the admin's record and takes an admin id; this is the
     * challenge's own, and most of what it records has no admin behind it — a nominee
     * confirming at midnight, a refund arriving from a gateway. Writing those as
     * admin 0 is the fault `AuditService` already paid for.
     */
    private static function event(int $challengeId, ?int $entryId, string $kind, array $meta = []): void
    {
        try {
            DB::table('gates_challenge_events')->insert([
                'challenge_id' => $challengeId, 'entry_id' => $entryId, 'kind' => $kind,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // A ledger that cannot be written must not stop a prize being awarded. It
            // is swallowed deliberately, and the queue screens read the entries
            // themselves rather than this, so nothing a person sees depends on it.
        }
    }
}
