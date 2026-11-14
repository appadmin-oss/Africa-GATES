<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ChallengeAdmin;
use AfricaGates\Services\ChallengeCopy;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The challenge prize audit (owner, 3 Nov 2026: "fix all other findings too").
 *
 * A challenge pays named people real money, so each case is a prize that could be paid
 * twice, paid to the wrong person, owed to somebody who was skipped, or earned by work the
 * challenge never asked for.
 */
final class ChallengeAuditTest extends TestCase
{
    private function challenge(array $over = []): int
    {
        return (int) DB::table('gates_challenges')->insertGetId($over + [
            'slug' => 'audit-' . bin2hex(random_bytes(4)), 'title' => 'Audit probe', 'kicker' => 'Probe',
            'action' => E::ACTION_NOMINATE, 'target' => 1, 'mode' => E::MODE_FIRST, 'cap' => 2,
            'prize_type' => E::PRIZE_CASH_EACH, 'prize_amount' => 6000, 'prize_currency' => '₦',
            'theme' => E::THEME_GREEN, 'status' => E::ST_OPEN, 'created_at' => '2026-10-01 00:00:00',
        ]);
    }

    private function entrant(int $ch, string $name = 'Entrant'): int
    {
        $u = (int) DB::table('gates_users')->insertGetId(['name' => $name, 'points' => 0, 'status' => 'active',
            'email' => 'e.' . bin2hex(random_bytes(4)) . '@example.test']);
        $r = CS::join($ch, $u, '+23480' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
        $this->assertTrue($r['ok'], 'fixture failed to join: ' . $r['code']);
        return (int) $r['entry']->id;
    }

    /** One verified nomination, and the recount that a confirmation would trigger. */
    private function finish(int $entry): int
    {
        $n = (int) DB::table('gates_nominations')->insertGetId([
            'cycle_id' => 1, 'nominee_name' => 'N', 'nominator_name' => 'E', 'nominator_email' => 'e@example.test',
            'status' => 'verified', 'challenge_entry_id' => $entry, 'nominee_confirmed_at' => date('Y-m-d H:i:s'),
            'nominee_identity_hash' => hash('sha256', 'n' . $entry . random_int(1, 1 << 30)),
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        CS::recount($entry);
        return $n;
    }

    private function close(int $ch): void
    {
        DB::table('gates_challenges')->where('id', $ch)->update(['ends_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
    }

    public function test_a_second_draw_never_rewrites_a_paid_winner(): void
    {
        $ch = $this->challenge(['mode' => E::MODE_DRAW, 'cap' => null, 'draw_count' => 2]);
        foreach (range(1, 4) as $i) $this->finish($this->entrant($ch, 'E' . $i));
        $this->close($ch);
        $won = CS::draw($ch, 'seed-1')['winners'];
        $this->assertTrue(CS::markPaid($ch, $won[0], 'GTB/1')['ok']);
        $again = CS::draw($ch);
        $this->assertSame('ALREADY_DRAWN', $again['code']);
        $this->assertSame(E::PAY_PAID, (string) DB::table('gates_challenge_entries')->where('id', $won[0])->value('payout_status'),
            'pressing the draw again made a paid prize owed again');
    }

    public function test_nobody_qualifies_after_the_close_or_after_the_draw(): void
    {
        $ch = $this->challenge();
        $e  = $this->entrant($ch);
        $this->close($ch);                     // the stored status still says open
        $this->finish($e);
        $this->assertNull(CS::entry($e)->standing, 'a place was taken after the challenge had closed');

        $d = $this->challenge(['mode' => E::MODE_DRAW, 'cap' => null, 'draw_count' => 1]);
        $this->finish($this->entrant($d, 'First'));
        $late = $this->entrant($d, 'Late');
        DB::table('gates_challenges')->where('id', $d)->update(['draw_seed' => 'already']);
        $this->finish($late);
        $this->assertNull(CS::entry($late)->qualified_at, 'joined the pool after the seed was applied to it');
    }

    public function test_a_freed_place_goes_to_the_first_person_waiting_behind_the_cap(): void
    {
        $ch = $this->challenge(['cap' => 2]);
        [$a, $b, $c] = [$this->entrant($ch, 'A'), $this->entrant($ch, 'B'), $this->entrant($ch, 'C')];
        $this->finish($a); $this->finish($b); $this->finish($c);   // C qualified, no place
        $this->assertNull(CS::entry($c)->standing);
        $r = CS::disqualify($a, 'Duplicate accounts');
        $this->assertSame($c, $r['promoted']);
        $this->assertSame(1, (int) CS::entry($b)->standing);
        $this->assertSame(2, (int) CS::entry($c)->standing, 'the person who finished next behind the cap was skipped');
        $this->assertSame(E::ST_FULL, (string) DB::table('gates_challenges')->where('id', $ch)->value('status'),
            'reopened for a stranger while the freed place had already been filled');
    }

    public function test_disqualifying_a_paid_winner_keeps_the_record_and_does_not_pay_the_place_twice(): void
    {
        $ch = $this->challenge(['cap' => 1]);
        [$a, $b] = [$this->entrant($ch, 'A'), $this->entrant($ch, 'B')];
        $this->finish($a); $this->finish($b);
        $this->assertTrue(CS::markPaid($ch, $a, 'GTB/9')['ok']);
        $r = CS::disqualify($a, 'Fraud found later');
        $this->assertSame(E::PAY_PAID, (string) CS::entry($a)->payout_status, 'the record that money went was erased');
        $this->assertNull($r['promoted'], 'a paid place was handed to somebody else, paying it twice');
    }

    public function test_a_prize_is_paid_only_once_to_a_placed_entry_of_this_challenge(): void
    {
        $ch = $this->challenge(['cap' => 2]);
        $other = $this->challenge();
        [$a, $b] = [$this->entrant($ch, 'A'), $this->entrant($ch, 'B')];
        $this->finish($a);
        $this->assertFalse(CS::markPaid($other, $a, 'X')['ok'], 'paid through another challenge’s page');
        $this->assertFalse(CS::markPaid($ch, $b, 'X')['ok'], 'paid an entry with no place');
        $this->assertFalse(CS::markPaid($ch, $a, '')['ok']);
        $this->assertTrue(CS::markPaid($ch, $a, 'GTB/1')['ok']);
        $this->assertFalse(CS::markPaid($ch, $a, 'GTB/2')['ok'], 'a second reference written over the first');
        $this->assertSame('GTB/1', (string) CS::entry($a)->payout_ref);

        $src = ChromeRender::code('src/Admin/Controllers/ChallengesController.php');
        $this->assertStringContainsString('CS::markPaid($id,', $src, 'the controller updates the entry by id again');
    }

    public function test_a_place_whose_work_was_undone_is_held_not_paid(): void
    {
        $ch = $this->challenge(['cap' => 2]);
        $a  = $this->entrant($ch);
        $n  = $this->finish($a);
        DB::table('gates_nominations')->where('id', $n)->update(['status' => 'rejected']);
        CS::recount($a);
        $this->assertSame(1, (int) CS::entry($a)->standing, 'a standing is never taken back');
        $r = CS::markPaid($ch, $a, 'GTB/1');
        $this->assertFalse($r['ok']);
        $this->assertStringStartsWith('Held', $r['message']);
    }

    public function test_only_work_inside_the_window_counts(): void
    {
        $ch = $this->challenge(['action' => E::ACTION_REFER, 'starts_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        DB::table('gates_challenge_scopes')->insert(['challenge_id' => $ch, 'scope_type' => E::SCOPE_EVENT, 'scope_id' => 7]);
        $e = $this->entrant($ch);
        $uid = (int) CS::entry($e)->user_id;
        foreach ([date('Y-m-d H:i:s', time() - 86400 * 30), date('Y-m-d H:i:s')] as $i => $at) {
            DB::table('gates_referral_credits')->insert(['code_id' => 1, 'user_id' => $uid, 'registration_id' => 900 + $i,
                'source_type' => 'registration', 'source_id' => 900 + $i, 'event_id' => 7, 'paid_naira' => 5000,
                'commission_naira' => 250, 'rate_bps' => 500, 'created_at' => $at]);
        }
        CS::recount($e);
        $this->assertSame(1, (int) CS::entry($e)->verified, 'a ticket sold a month before the challenge counted towards it');
    }

    public function test_an_unwinnable_mode_cannot_be_published(): void
    {
        $ch = $this->challenge(['mode' => E::MODE_TOP]);
        $this->assertNotEmpty(array_filter(ChallengeAdmin::blockers($ch), static fn ($b) => str_contains($b, '"top"')));
    }

    public function test_a_points_prize_moves_the_balance_and_a_pool_is_quoted_per_winner(): void
    {
        $ch = $this->challenge(['prize_type' => E::PRIZE_POINTS, 'prize_amount' => 500, 'cap' => 1]);
        $a  = $this->entrant($ch);
        $this->finish($a);
        $this->assertTrue(CS::markPaid($ch, $a, 'points')['ok']);
        $this->assertSame(500, (int) DB::table('gates_users')->where('id', CS::entry($a)->user_id)->value('points'),
            '"paid" with no points credited');

        $pool = ['prize_type' => E::PRIZE_CASH_POOL, 'prize_amount' => 100000, 'prize_currency' => '₦', 'mode' => E::MODE_FIRST, 'cap' => 4];
        $this->assertSame('₦25,000', ChallengeCopy::perWinner($pool), 'each of four winners was told they had won the whole pool');
    }
}
