<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Services\ConsoleCalendar;
use AfricaGates\Admin\Services\HomeBlocks;
use AfricaGates\Admin\Services\HomeBoard;
use AfricaGates\Admin\Support\TimeRange;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * AdminConsole v2's Home blocks and the calendar they share (20 Oct 2026).
 *
 * What each test holds is a rule the screen states: a calendar entry is a STORED date and
 * only one the role can open; the brief puts the broken before the waiting; Performance
 * counts inside its window and compares with the window before; Recent is changes, not
 * sign-ins; an edition is placed on its five-step tracker by the computed phase.
 */
final class HomeBlocksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConsoleAlerts::reset();
        HomeBoard::resetAll();
    }

    private function programme(bool $active = true): int
    {
        return (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'hb-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => $active ? 1 : 0,
        ]);
    }

    private function cycle(int $programme, array $o = []): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId($o + [
            'programme_id' => $programme, 'year' => 2026, 'status' => 'voting', 'edition_label' => '4th Edition',
        ]);
    }

    // ── the calendar ─────────────────────────────────────────────────────────

    public function test_the_calendar_lists_stored_dates_in_the_window_and_nothing_else(): void
    {
        $p = $this->programme();
        $in = date('Y-m-d H:i:s', time() + 3 * 86400);
        $out = date('Y-m-d H:i:s', time() + 40 * 86400);
        $this->cycle($p, ['voting_close' => $in, 'results_date' => $out]);
        // The sandbox and anything else switched off are not the operator's week.
        $this->cycle($this->programme(false), ['voting_close' => $in]);

        $from = (int) strtotime('today');
        $week = ConsoleCalendar::between($from, $from + 7 * 86400 - 1, 'superadmin');

        $this->assertCount(1, $week, 'only the stored date inside the window, from an active programme');
        $this->assertSame('Teachers Prize: voting closes', $week[0]['title']);
        $this->assertSame('/admin/programmes/' . $p, $week[0]['href']);
        $this->assertSame('4th Edition · 2026', $week[0]['sub']);
    }

    public function test_a_calendar_entry_is_offered_only_to_a_role_that_can_open_its_page(): void
    {
        $this->cycle($this->programme(), ['voting_close' => date('Y-m-d H:i:s', time() + 86400)]);
        $from = (int) strtotime('today');
        $this->assertNotEmpty(ConsoleCalendar::between($from, $from + 7 * 86400, 'superadmin'));
        foreach (['superadmin', 'admin', 'editor', 'moderator', 'viewer'] as $role) {
            foreach (ConsoleCalendar::between($from, $from + 7 * 86400, $role) as $e) {
                $this->assertTrue(\AfricaGates\Admin\Support\Permissions::canOpen($role, $e['href']), "$role was offered {$e['href']}");
            }
        }
    }

    // ── the brief ────────────────────────────────────────────────────────────

    public function test_the_brief_says_the_broken_before_the_waiting_and_is_at_most_three_lines(): void
    {
        $jobs = [
            ['key' => 'review', 'count' => 4, 'label' => 'nominations waiting for a decision', 'age' => date('Y-m-d H:i:s', time() - 2 * 86400)],
            ['key' => 'owed', 'count' => 2, 'label' => 'paid orders with no votes and no refund'],
        ];
        $brief = HomeBlocks::brief('superadmin', $jobs);
        $this->assertSame(['red', 'gold'], array_column($brief, 'tone'));
        $this->assertStringContainsString('2 paid orders have no votes and no refund', $brief[0]['text']);
        $this->assertStringContainsString('the oldest has waited 2 days', $brief[1]['text']);
        $this->assertLessThanOrEqual(3, count(HomeBlocks::brief('superadmin', $jobs)));
        $this->assertSame([], HomeBlocks::brief('superadmin', []), 'a quiet day has no brief, not an empty box');
    }

    // ── Performance ──────────────────────────────────────────────────────────

    public function test_performance_counts_inside_its_window_and_compares_with_the_window_before(): void
    {
        $now = time();
        $rows = [];
        foreach ([1, 2, 3] as $d) $rows[] = ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', "a$d"),
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => date('Y-m-d H:i:s', $now - $d * 86400)];
        // Two in the week before, one long before both windows.
        foreach ([9, 10, 40] as $d) $rows[] = ['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => hash('sha256', "b$d"),
            'vote_type' => 'standard', 'weight' => 1, 'voted_at' => date('Y-m-d H:i:s', $now - $d * 86400)];
        DB::table('gates_votes')->insert($rows);

        $kpis = HomeBlocks::kpis(TimeRange::fromQuery(['range' => '7d'], '7d'), 'votes');
        $votes = array_values(array_filter($kpis, static fn (array $k): bool => $k['key'] === 'votes'))[0];
        $this->assertSame(3, $votes['total']);
        $this->assertSame(50, $votes['delta'], 'three against two is up 50%');
        $this->assertTrue($votes['on']);
        $this->assertSame(3, (int) array_sum(array_column($votes['series']['bars'], 'n')), 'the bars hold what the figure counts');
    }

    public function test_raised_sums_money_that_cleared_and_leaves_out_what_was_given_back(): void
    {
        $at = date('Y-m-d H:i:s', time() - 86400);
        DB::table('gates_donations')->insert([
            ['donor_name' => 'Ada', 'donor_email' => 'a@demo.invalid', 'amount_naira' => 5000, 'status' => 'confirmed', 'payment_ref' => 'hb1', 'created_at' => $at],
            ['donor_name' => 'Bo', 'donor_email' => 'b@demo.invalid', 'amount_naira' => 2000, 'status' => 'pending', 'payment_ref' => 'hb2', 'created_at' => $at],
        ]);
        if (\AfricaGates\Support\SchemaHas::column('gates_donations', 'refunded_at')) {
            DB::table('gates_donations')->insert(['donor_name' => 'Cy', 'donor_email' => 'c@demo.invalid', 'amount_naira' => 9000, 'status' => 'confirmed', 'payment_ref' => 'hb3',
                'created_at' => $at, 'refunded_at' => $at]);
        }
        $kpis = HomeBlocks::kpis(TimeRange::fromQuery(['range' => '7d'], '7d'), 'raised');
        $raised = array_values(array_filter($kpis, static fn (array $k): bool => $k['key'] === 'raised'))[0];
        $this->assertSame(5000, $raised['total']);
        $this->assertSame('₦5,000', $raised['value']);
    }

    // ── Editions ─────────────────────────────────────────────────────────────

    public function test_an_edition_is_placed_on_its_tracker_by_the_computed_phase(): void
    {
        $this->cycle($this->programme(), [
            'status' => 'voting',
            'nominations_open' => date('Y-m-d H:i:s', time() - 30 * 86400),
            'nominations_close' => date('Y-m-d H:i:s', time() - 10 * 86400),
            'voting_open' => date('Y-m-d H:i:s', time() - 5 * 86400),
            'voting_close' => date('Y-m-d H:i:s', time() + 5 * 86400),
        ]);
        $ed = HomeBlocks::editions('superadmin');
        $this->assertCount(1, $ed);
        $this->assertSame('Voting', $ed[0]['stage']);
        $this->assertSame(3, $ed[0]['step']);
        $this->assertSame(['done', 'done', 'now', 'next', 'next'], array_column($ed[0]['stages'], 'state'));
        $this->assertStringStartsWith('Voting closes ', $ed[0]['next']);
        foreach (['admin', 'editor', 'moderator', 'viewer'] as $role) {
            $this->assertCount(\AfricaGates\Admin\Support\Permissions::canOpen($role, '/admin/programmes') ? 1 : 0,
                HomeBlocks::editions($role), "$role is shown editions exactly when it can open programmes");
        }
    }

    // ── Recent ───────────────────────────────────────────────────────────────

    public function test_recent_is_changes_said_as_sentences_and_never_a_sign_in(): void
    {
        $admin = (int) DB::table('gates_admins')->insertGetId([
            'email' => 'ada@demo.invalid', 'name' => 'Ada Obi', 'password_hash' => 'x', 'role' => 'superadmin', 'is_active' => 1,
        ]);
        DB::table('gates_audit_log')->insert([
            ['admin_id' => $admin, 'action' => 'login', 'created_at' => date('Y-m-d H:i:s', time() - 10)],
            ['admin_id' => $admin, 'action' => 'media.view', 'created_at' => date('Y-m-d H:i:s', time() - 20)],
            ['admin_id' => $admin, 'action' => 'post.publish', 'created_at' => date('Y-m-d H:i:s', time() - 30)],
        ]);
        $recent = HomeBlocks::recent('superadmin', new AuditService());
        $this->assertCount(1, $recent, 'the sign-in and the look are not changes');
        $this->assertSame('Ada Obi', $recent[0]['who']);
        $this->assertSame('AO', $recent[0]['initials']);
        $this->assertStringStartsWith('published', $recent[0]['what']);
    }

    public function test_waiting_is_said_in_words_and_late_means_past_the_four_hour_target(): void
    {
        $now = time();
        $this->assertSame('40 minutes', HomeBlocks::waited(date('Y-m-d H:i:s', $now - 2400), $now));
        $this->assertSame('1 hour', HomeBlocks::waited(date('Y-m-d H:i:s', $now - 3700), $now));
        $this->assertSame('2 days', HomeBlocks::waited(date('Y-m-d H:i:s', $now - 2 * 86400 - 60), $now));
        $this->assertTrue(HomeBlocks::late(date('Y-m-d H:i:s', $now - 5 * 3600)));
        $this->assertFalse(HomeBlocks::late(date('Y-m-d H:i:s', $now - 3 * 3600)));
        $this->assertFalse(HomeBlocks::late(null));
    }
}
