<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Who qualifies, in what order, and who is kept out.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THING THIS SUITE CANNOT PROVE ON ITS OWN HARNESS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `lockForUpdate()` compiles to nothing on SQLite. So the concurrency case below
 * passes here whether or not `qualify()` holds a lock — the writer serialisation of a
 * single-file database gives the same answer for an entirely different reason, and a
 * green run says nothing about MySQL.
 *
 * That is why `2027_02_12_challenge_standing_guard.php` puts a UNIQUE on
 * `(challenge_id, standing)`: the constraint holds on both engines, and a
 * double-assignment becomes a refused write rather than two people both told they
 * came fourth. {@see test_two_qualifiers_can_never_hold_the_same_place} drives that
 * constraint directly, which IS meaningful here.
 *
 * Run `scripts/mysql-parity.sh --filter ChallengeServiceTest` before trusting the
 * ordering under real concurrency.
 */
final class ChallengeServiceTest extends TestCase
{
    private int $cycle = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $prog = DB::table('gates_award_programmes')->insertGetId([
            'title' => 'Challenge service probe', 'slug' => 'cs-probe-' . bin2hex(random_bytes(3)),
            'is_active' => 1,
        ]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $prog, 'year' => 2026, 'status' => 'nominations',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Joining
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_member_without_a_verified_phone_is_refused(): void
    {
        $ch = $this->challenge();

        // "Your account's phone number is verified" is the first published rule, and
        // the only thing between one person and ten entries.
        $r = CS::join($ch, $this->user('No Phone'), null);

        $this->assertFalse($r['ok']);
        $this->assertSame('NO_VERIFIED_PHONE', $r['code']);
        $this->assertSame(0, DB::table('gates_challenge_entries')->where('challenge_id', $ch)->count());
    }

    public function test_one_person_gets_one_entry_however_many_times_they_press_join(): void
    {
        $ch = $this->challenge();
        $u  = $this->user('Twice Presser');

        $a = CS::join($ch, $u, '+2348031234567');
        $b = CS::join($ch, $u, '+2348031234567');

        $this->assertSame('JOINED', $a['code']);
        $this->assertSame('ALREADY', $b['code'], 'pressing join twice made a second entry');
        $this->assertSame($a['entry']->id, $b['entry']->id);
        $this->assertSame(1, DB::table('gates_challenge_entries')->where('challenge_id', $ch)->count());
    }

    /**
     * The same human on a second account is the case the phone rule exists for.
     *
     * And the two spellings must collide: a constraint on what was typed refuses only
     * somebody who typed it identically twice, which is nobody trying.
     */
    public function test_a_second_account_on_the_same_phone_is_refused_however_it_is_spelled(): void
    {
        $ch = $this->challenge();

        $this->assertSame('JOINED', CS::join($ch, $this->user('First'), '+234 803 123 4567')['code']);

        $r = CS::join($ch, $this->user('Second'), '08031234567', 'NG');

        $this->assertFalse($r['ok']);
        $this->assertSame('PHONE_ALREADY_ENTERED', $r['code'],
            'a second account on the same number got in — the hash is not normalising');
    }

    public function test_a_draft_or_ended_challenge_takes_no_entries(): void
    {
        foreach ([E::ST_DRAFT, E::ST_ENDED, E::ST_UPCOMING, E::ST_CANCELLED] as $status) {
            $ch = $this->challenge(['status' => $status]);
            $r  = CS::join($ch, $this->user('Early ' . $status), '+23480' . random_int(10000000, 99999999));

            $this->assertSame('NOT_OPEN', $r['code'], "a $status challenge accepted an entry");
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Counting
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A nomination counts only when the nominee confirmed AND a moderator approved.
     *
     * Either half alone is somebody else's decision standing in for a prize.
     */
    public function test_a_nomination_counts_only_with_both_halves(): void
    {
        [$ch, $entry] = $this->joined(['target' => 3]);

        $this->nomination($entry, 'Confirmed and approved', 'verified', '2026-10-01 09:00:00');
        $this->nomination($entry, 'Approved, never confirmed', 'verified', null);
        $this->nomination($entry, 'Confirmed, not yet approved', 'checking', '2026-10-01 09:00:00');

        CS::recount($entry);
        $row = CS::entry($entry);

        $this->assertSame(1, (int) $row->verified, 'a half-finished nomination was counted');
        // BOTH unfinished rows are "checking": one is waiting on the nominee and one
        // on a moderator, and from the entrant's side those are the same sentence —
        // somebody else has it. Only `needs_details` is work the entrant can do.
        $this->assertSame(2, (int) $row->checking);
        $this->assertNull($row->qualified_at);
    }

    /**
     * "10 different nominees" counts PEOPLE, not rows.
     *
     * One friend sent through under two spellings of their number is one nominee —
     * which is the whole reason the identity hash exists. The same shape as
     * `VoterReach` counting supporters rather than ballots.
     */
    public function test_the_same_nominee_twice_counts_once(): void
    {
        [$ch, $entry] = $this->joined(['target' => 2]);

        $hash = CS::identityHash('+2348090000001', null);
        $this->assertSame($hash, CS::identityHash('0809 000 0001', null, 'NG'),
            'two spellings of one number produced two identities');

        $this->nomination($entry, 'Same Person A', 'verified', '2026-10-01 09:00:00', $hash);
        $this->nomination($entry, 'Same Person B', 'verified', '2026-10-01 09:00:00', $hash);

        CS::recount($entry);

        $this->assertSame(1, (int) CS::entry($entry)->verified,
            'one nominee under two spellings counted twice toward the target');
        $this->assertTrue(CS::identityTaken($entry, $hash));
    }

    public function test_an_entry_qualifies_the_moment_it_reaches_the_target(): void
    {
        [$ch, $entry] = $this->joined(['target' => 2, 'cap' => 5]);

        $this->nomination($entry, 'One', 'verified', '2026-10-01 09:00:00');
        CS::recount($entry);
        $this->assertNull(CS::entry($entry)->qualified_at);

        $this->nomination($entry, 'Two', 'approved', '2026-10-01 09:10:00');
        $r = CS::recount($entry);

        $this->assertSame('QUALIFIED', $r['code']);
        $this->assertSame(1, (int) CS::entry($entry)->standing);
        $this->assertSame(E::PAY_PENDING, CS::entry($entry)->payout_status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The race
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Eleven qualifiers, a cap of eleven: ranks 1 to 11, each exactly once, then full.
     *
     * The handoff names this case specifically. What it proves on SQLite is the
     * ARITHMETIC — that the count is read inside the transaction and that nothing
     * double-assigns across eleven sequential-but-interleaved qualifications. The
     * genuine concurrency guarantee is MySQL's row lock plus the UNIQUE constraint,
     * and only the parity run exercises the first of those.
     */
    public function test_eleven_qualifiers_take_eleven_distinct_places_and_then_it_is_full(): void
    {
        $ch = $this->challenge(['target' => 1, 'cap' => 11]);

        $entries = [];
        for ($i = 0; $i < 11; $i++) {
            $entries[] = $this->joinOne($ch, 'Racer ' . $i);
        }

        // Every nomination written FIRST, so the eleven qualifications run back to back
        // against one another rather than each being separated by unrelated work.
        foreach ($entries as $e) $this->nomination($e, 'N', 'verified', '2026-10-01 09:00:00');
        foreach ($entries as $e) CS::recount($e);

        $standings = DB::table('gates_challenge_entries')->where('challenge_id', $ch)
            ->orderBy('standing')->pluck('standing')->map(static fn($v) => (int) $v)->all();

        $this->assertSame(range(1, 11), $standings,
            'eleven qualifiers did not take eleven distinct places');
        $this->assertSame(E::ST_FULL, DB::table('gates_challenges')->where('id', $ch)->value('status'),
            'the cap was reached and the challenge did not go full');
        $this->assertSame(11, CS::claimed($ch));
    }

    /**
     * A twelfth finisher is recorded as qualified and given no prize.
     *
     * Not refused: they did the work, their own rows say so, and a challenge that
     * tells somebody who finished that they did not is contradicted by its own data.
     */
    public function test_a_finisher_past_the_cap_qualifies_without_a_place(): void
    {
        $ch = $this->challenge(['target' => 1, 'cap' => 2]);

        // All three join while it is OPEN — which is the only way this happens. A
        // challenge that has already filled refuses a NEW entrant outright, and that
        // is tested separately; the case here is somebody who was racing and lost.
        $ids = [];
        for ($i = 0; $i < 3; $i++) $ids[] = $this->joinOne($ch, 'Late ' . $i);

        $all = [];
        foreach ($ids as $e) {
            $this->nomination($e, 'N', 'verified', '2026-10-01 09:00:00');
            $all[] = [$e, CS::recount($e)];
        }

        $this->assertSame('QUALIFIED', $all[0][1]['code']);
        $this->assertSame('QUALIFIED', $all[1][1]['code']);
        $this->assertSame('QUALIFIED_NO_PRIZE', $all[2][1]['code']);

        $third = CS::entry($all[2][0]);
        $this->assertNotNull($third->qualified_at, 'the twelfth finisher was not recorded at all');
        $this->assertNull($third->standing);
        $this->assertSame(E::PAY_NONE, $third->payout_status, 'a prize was queued past the cap');
    }

    /**
     * The database refuses a second entry on one place, whatever the caller does.
     *
     * This is the half of the guarantee that is real on SQLite: it drives the UNIQUE
     * constraint directly rather than relying on a lock the driver does not have.
     */
    public function test_two_qualifiers_can_never_hold_the_same_place(): void
    {
        $ch = $this->challenge(['target' => 1, 'cap' => 5]);
        $a  = $this->joinOne($ch, 'A');
        $b  = $this->joinOne($ch, 'B');

        DB::table('gates_challenge_entries')->where('id', $a)->update(['standing' => 1]);

        $this->expectException(\Throwable::class);
        DB::table('gates_challenge_entries')->where('id', $b)->update(['standing' => 1]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Losing a count, and losing an entry
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A count that falls takes the qualification and never a place already given.
     *
     * A standing is a published fact that other people's standings were assigned
     * around. Handing it back would renumber strangers who did nothing.
     */
    public function test_a_place_already_assigned_survives_a_count_falling(): void
    {
        [$ch, $entry] = $this->joined(['target' => 2, 'cap' => 5]);

        $n1 = $this->nomination($entry, 'One', 'verified', '2026-10-01 09:00:00');
        $this->nomination($entry, 'Two', 'verified', '2026-10-01 09:05:00');
        CS::recount($entry);
        $this->assertSame(1, (int) CS::entry($entry)->standing);

        // A moderator revokes one.
        DB::table('gates_nominations')->where('id', $n1)->update(['status' => 'rejected']);
        CS::recount($entry);

        $row = CS::entry($entry);
        $this->assertSame(1, (int) $row->verified);
        $this->assertSame(1, (int) $row->standing, 'a published place was taken back');
        $this->assertNotNull($row->qualified_at);
    }

    public function test_a_disqualification_needs_a_reason_and_moves_everybody_up(): void
    {
        $ch = $this->challenge(['target' => 1, 'cap' => 3]);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $e = $this->joinOne($ch, 'Q' . $i);
            $this->nomination($e, 'N', 'verified', '2026-10-01 09:0' . $i . ':00');
            CS::recount($e);
            $ids[] = $e;
        }

        $this->assertSame(E::ST_FULL, DB::table('gates_challenges')->where('id', $ch)->value('status'));

        // An operator removing a named person from a prize without saying why leaves
        // the next operator, and any appeal, with nothing.
        $this->assertSame('NO_REASON', CS::disqualify($ids[0], '   ')['code']);
        $this->assertSame(1, (int) CS::entry($ids[0])->standing, 'the refusal still changed the row');

        $r = CS::disqualify($ids[0], 'Nominated ten people who do not exist');
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['freed']);

        $this->assertNull(CS::entry($ids[0])->standing);
        $this->assertSame(E::E_DISQUALIFIED, CS::entry($ids[0])->status);
        $this->assertSame(1, (int) CS::entry($ids[1])->standing, 'second place did not move up');
        $this->assertSame(2, (int) CS::entry($ids[2])->standing);

        $this->assertSame(E::ST_OPEN, DB::table('gates_challenges')->where('id', $ch)->value('status'),
            'a place opened and the challenge stayed full');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The draw
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The same seed and the same pool give the same winners, for ever.
     *
     * "The draw is recorded and published" is a published rule, so somebody who does
     * not trust us has to be able to re-run it. A shuffle is an assertion; a keyed
     * hash of a published seed is evidence.
     */
    public function test_a_draw_is_reproducible_and_is_never_re_drawn(): void
    {
        $ch = $this->challenge(['mode' => E::MODE_DRAW, 'target' => 1, 'cap' => null, 'draw_count' => 3]);

        for ($i = 0; $i < 8; $i++) {
            $e = $this->joinOne($ch, 'Entrant ' . $i);
            $this->nomination($e, 'N', 'verified', '2026-10-01 09:00:00');
            CS::recount($e);
        }

        // A draw mode never assigns places on qualification — it is drawn, not raced.
        $this->assertSame(0, CS::claimed($ch));

        $first = CS::draw($ch, 'a-published-seed');
        $this->assertTrue($first['ok']);
        $this->assertCount(3, $first['winners']);
        $this->assertSame('a-published-seed', $first['seed']);

        // Pressing it again, even with a different seed, must not reshuffle the prize.
        $again = CS::draw($ch, 'a-completely-different-seed');
        $this->assertSame($first['winners'], $again['winners'], 'the draw was re-run');
        $this->assertSame('a-published-seed', $again['seed']);
    }

    public function test_a_draw_only_includes_people_who_finished(): void
    {
        $ch = $this->challenge(['mode' => E::MODE_DRAW, 'target' => 2, 'draw_count' => 5]);

        $done   = $this->joinOne($ch, 'Finished');
        $short  = $this->joinOne($ch, 'Halfway');

        $this->nomination($done, 'A', 'verified', '2026-10-01 09:00:00');
        $this->nomination($done, 'B', 'verified', '2026-10-01 09:01:00');
        $this->nomination($short, 'A', 'verified', '2026-10-01 09:00:00');
        CS::recount($done);
        CS::recount($short);

        $this->assertSame([$done], CS::draw($ch)['winners'],
            'somebody who had not reached the target was in the draw');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // What the public sees
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_winner_is_published_as_a_first_name_and_an_initial(): void
    {
        $this->assertSame('Chidinma O.', CS::publicName('Chidinma Okafor'));
        $this->assertSame('Ngozi A.', CS::publicName('Ngozi Chimamanda Adichie'));
        // A single name is somebody's whole name, and abbreviating it to "Tolu T."
        // invents a surname.
        $this->assertSame('Tolu', CS::publicName('Tolu'));
        $this->assertSame('Someone', CS::publicName('   '));
    }

    public function test_the_winners_list_carries_no_full_name_and_no_contact(): void
    {
        [$ch, $entry] = $this->joined(['target' => 1, 'cap' => 3], 'Adaeze Nwankwo');
        $this->nomination($entry, 'N', 'verified', '2026-10-01 09:00:00');
        CS::recount($entry);

        $w = CS::winners($ch);

        $this->assertCount(1, $w);
        $this->assertSame('Adaeze N.', $w[0]['name']);
        $this->assertSame(1, $w[0]['standing']);

        $json = json_encode($w);
        $this->assertStringNotContainsString('Nwankwo', $json, 'a full surname reached the public list');
        $this->assertStringNotContainsString('@', $json, 'an email address reached the public list');
    }

    /** A disqualified entry leaves the public list entirely. */
    public function test_a_disqualified_entry_is_not_published(): void
    {
        $ch = $this->challenge(['target' => 1, 'cap' => 3]);
        $a  = $this->joinOne($ch, 'Honest Person');
        $b  = $this->joinOne($ch, 'Removed Person');

        foreach ([$a, $b] as $e) {
            $this->nomination($e, 'N', 'verified', '2026-10-01 09:00:00');
            CS::recount($e);
        }

        CS::disqualify($b, 'Duplicate accounts');

        $names = array_column(CS::winners($ch), 'name');
        $this->assertSame(['Honest P.'], $names);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Fraud signals
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The same nominee in two different entries is FLAGGED, never refused.
     *
     * Two neighbours nominating the same teacher is the system working. One person
     * running two accounts is not, and only a human looking at the pair can tell.
     */
    public function test_a_nominee_appearing_in_two_entries_is_reported_and_not_blocked(): void
    {
        $ch = $this->challenge(['target' => 5]);
        $a  = $this->joinOne($ch, 'Neighbour A');
        $b  = $this->joinOne($ch, 'Neighbour B');

        $hash = CS::identityHash('+2348097777777', null);
        $this->nomination($a, 'The Teacher', 'verified', '2026-10-01 09:00:00', $hash);
        $this->nomination($b, 'The Teacher', 'verified', '2026-10-01 09:05:00', $hash);
        CS::recount($b);

        $this->assertSame(1, CS::crossEntryDuplicates($ch, $hash, $b));
        $this->assertSame(1, (int) CS::entry($b)->verified, 'a flagged duplicate was silently not counted');
    }

    /** A nominee with neither a phone nor an email cannot be proved distinct. */
    public function test_an_unidentifiable_nominee_gets_no_hash(): void
    {
        $this->assertNull(CS::identityHash(null, null));
        $this->assertNull(CS::identityHash('  ', ''));
        $this->assertSame(CS::identityHash(null, 'A@Example.COM '), CS::identityHash(null, 'a@example.com'));
    }

    // ══════════════════════════════════════════════════════════════════════════

    private function challenge(array $over = []): int
    {
        return (int) DB::table('gates_challenges')->insertGetId($over + [
            'slug' => 'probe-' . bin2hex(random_bytes(4)),
            'title' => 'Probe', 'kicker' => 'Probe',
            'action' => E::ACTION_NOMINATE, 'target' => 1, 'mode' => E::MODE_FIRST, 'cap' => 11,
            'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 6000, 'prize_currency' => '₦',
            'theme' => E::THEME_GREEN, 'status' => E::ST_OPEN,
            'created_at' => '2026-10-01 00:00:00',
        ]);
    }

    private function user(string $name): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => $name,
            'email' => strtolower(preg_replace('/\W+/', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'status' => 'active',
        ]);
    }

    /** @return array{0:int,1:int} challenge id, entry id */
    private function joined(array $over = [], string $name = 'Entrant'): array
    {
        $ch = $this->challenge($over);

        return [$ch, $this->joinOne($ch, $name)];
    }

    private function joinOne(int $ch, string $name): int
    {
        $r = CS::join($ch, $this->user($name), '+23480' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
        $this->assertTrue($r['ok'], 'fixture failed to join: ' . $r['code']);

        return (int) $r['entry']->id;
    }

    private function nomination(int $entry, string $who, string $status, ?string $confirmed, ?string $hash = null): int
    {
        return (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => $this->cycle, 'nominee_name' => $who,
            'nominator_name' => 'Entrant', 'nominator_email' => 'entrant@example.test',
            'nominator_lga' => 'Ikotun',
            'status' => $status, 'challenge_entry_id' => $entry,
            'nominee_confirmed_at' => $confirmed,
            'nominee_identity_hash' => $hash ?? hash('sha256', $who . $entry . random_int(1, 1000000000)),
        ]);
    }
}
