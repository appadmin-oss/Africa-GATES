<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AttentionBoard;
use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\InterviewService;
use AfricaGates\Services\QuestionnaireService as Q;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's "what needs you" board.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THINGS THAT MATTER MOST HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. A ZERO IS NOT A CARD. The old dashboard was eight counts across the top, and a grid of
 *    green zeroes teaches an operator to stop reading the grid — after which the one red
 *    number in it is invisible too. An empty board is the useful answer, said once in a
 *    sentence.
 *
 * 2. NOBODY IS OFFERED A DOOR THEY CANNOT OPEN. The section is resolved from the item's own
 *    href through Permissions::sectionForPath() — the exact function the section guard uses —
 *    so the board and the guard cannot disagree. Being handed "3 chargebacks — respond" and
 *    then bounced with "your role has no access" is worse than never being told, and this
 *    codebase has already had to fix that same disagreement twice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE REST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   3. THE ORDER IS AN ARGUMENT. Money on a deadline first, then somebody waiting, then work
 *      already done and never filed, then things with a human on the other end.
 *   4. EVERY CARD CARRIES A REASON. "3 chargebacks" is a number; "Paystack accepts a dispute
 *      for you after 16 hours" is a reason to click.
 *   5. IT READS ONLY LOCAL TABLES. No gateway call, nothing that can time out on the first
 *      screen after login.
 */
final class AttentionBoardTest extends TestCase
{
    private const CAT = 9800;
    private const NOM = 9801;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => 9800, 'title' => 'P', 'slug' => 'p-9800',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 9800, 'programme_id' => 9800, 'year' => 2026, 'status' => 'judging',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CAT, 'cycle_id' => 9800, 'title' => 'C', 'slug' => 'c-9800',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Grace Mensah',
            'status' => 'approved', 'vote_count' => 10,
        ]);
        DB::table('gates_nominations')->insertOrIgnore([
            'id' => self::NOM, 'cycle_id' => 9800, 'category_id' => self::CAT,
            'nominee_name' => 'Grace Mensah', 'nominee_email' => 'g@example.org',
            'country_code' => 'GH', 'reason' => 'x', 'nominator_name' => 'K',
            'nominator_email' => 'k@example.org', 'status' => 'approved',
            'reference' => 'AFG-NOM-9801',
        ]);
    }

    /** @return array<string,array<string,mixed>> keyed by item key */
    private function board(): array
    {
        $out = [];
        foreach (AttentionBoard::items() as $i) $out[(string) $i['key']] = $i;
        return $out;
    }

    private function chargebackTicket(string $ref, string $status = 'open'): void
    {
        $now = Carbon::now()->toDateTimeString();
        DB::table('gates_support_tickets')->insert([
            'reference' => $ref, 'email' => 'gw@example.org', 'name' => 'Gateway',
            'subject' => 'Chargeback — respond before ' . $now . ' (' . $ref . ')',
            'transcript' => 'x', 'severity' => 'urgent', 'status' => $status,
            'created_at' => $now, 'last_activity' => $now,
        ]);
    }

    // ══ 1. a zero is not a card ══════════════════════════════════════════════

    /** THE test in this file. */
    public function test_nothing_waiting_returns_an_empty_board_not_a_row_of_zeroes(): void
    {
        // A quiet platform. The board must be EMPTY rather than twelve cards saying 0 —
        // a grid of green zeroes is what taught operators to stop reading the old dashboard.
        $this->assertSame([], AttentionBoard::items());
        $this->assertSame(0, AttentionBoard::total([]));
    }

    public function test_a_card_appears_the_moment_its_count_is_above_zero(): void
    {
        $this->assertArrayNotHasKey('disputes', $this->board());

        $this->chargebackTicket('AGS-CB1');

        $board = $this->board();
        $this->assertArrayHasKey('disputes', $board);
        $this->assertSame(1, $board['disputes']['count']);
    }

    public function test_a_resolved_item_stops_being_a_card(): void
    {
        $this->chargebackTicket('AGS-CB1');
        $this->assertArrayHasKey('disputes', $this->board());

        DB::table('gates_support_tickets')->where('reference', 'AGS-CB1')
            ->update(['status' => 'resolved']);

        $this->assertArrayNotHasKey('disputes', $this->board());
    }

    // ══ 2. nobody is offered a door they cannot open ═════════════════════════

    /**
     * The property that matters, asserted against EVERY item rather than a sample: any card a
     * role can see must be a screen that role can actually reach, decided by the same resolver
     * the guard uses.
     */
    public function test_every_card_a_role_sees_is_a_screen_that_role_can_reach(): void
    {
        $this->chargebackTicket('AGS-CB1');                    // finance
        Q::open(self::NOM);                                     // moderation
        DB::table('gates_partner_enquiries')->insert([          // content
            'org_name' => 'Org', 'contact_name' => 'A', 'contact_email' => 'a@b.c',
            'message' => 'hello', 'status' => 'new',
        ]);

        foreach (['superadmin', 'admin', 'editor', 'moderator', 'viewer'] as $role) {
            foreach (AttentionBoard::forRole($role) as $item) {
                $section = Permissions::sectionForPath((string) $item['href']);
                $allowed = $section !== null
                    ? Permissions::canAccess($role, $section)
                    : $role === 'superadmin';
                $this->assertTrue($allowed,
                    $role . ' was offered ' . $item['href'] . ', which the guard would bounce');
            }
        }
    }

    public function test_a_viewer_is_not_offered_the_chargeback_screen(): void
    {
        // Disputes move money and live in `finance`, which is superadmin + admin only.
        $this->chargebackTicket('AGS-CB1');

        $keys = static fn (string $role): array => array_column(AttentionBoard::forRole($role), 'key');

        $this->assertContains('disputes', $keys('superadmin'));
        $this->assertContains('disputes', $keys('admin'));
        $this->assertNotContains('disputes', $keys('viewer'));
        $this->assertNotContains('disputes', $keys('moderator'));
        $this->assertNotContains('disputes', $keys('editor'));
    }

    /** And filtering never invents an item that was not on the full board. */
    public function test_a_role_never_sees_something_the_board_does_not_hold(): void
    {
        $this->chargebackTicket('AGS-CB1');
        Q::open(self::NOM);

        $all = array_column(AttentionBoard::items(), 'key');
        foreach (['superadmin', 'admin', 'editor', 'moderator', 'viewer'] as $role) {
            foreach (AttentionBoard::forRole($role) as $i) {
                $this->assertContains($i['key'], $all);
            }
        }
    }

    // ══ 3. the order is an argument ══════════════════════════════════════════

    public function test_money_on_a_deadline_outranks_everything_else(): void
    {
        // Seeded in the WRONG order on purpose: the board's order must come from the board,
        // not from what happened to be written first.
        Q::open(self::NOM);
        DB::table('gates_nominations')->where('id', self::NOM)->update(['status' => 'pending']);
        $this->chargebackTicket('AGS-CB1');

        $keys = array_column(AttentionBoard::items(), 'key');

        $this->assertSame('disputes', $keys[0],
            'a chargeback with hours left on it was ranked below something that can wait');
        $this->assertLessThan(array_search('nominations', $keys, true),
            array_search('quest_not_invited', $keys, true),
            'work already done and thrown away should outrank a queue that waits well');
    }

    public function test_an_interview_already_missed_outranks_one_that_is_coming(): void
    {
        $this->interview('-2 days', 'confirmed');
        $this->interview('+4 hours', 'confirmed');

        $keys = array_column(AttentionBoard::items(), 'key');
        $this->assertLessThan(array_search('interviews_today', $keys, true),
            array_search('interviews_overdue', $keys, true));
    }

    private function interview(string $when, string $status): int
    {
        $now = Carbon::now()->toDateTimeString();
        return (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id' => self::NOM, 'status' => $status,
            'scheduled_at' => Carbon::parse($when)->toDateTimeString(),
            'timezone' => 'Africa/Lagos', 'invite_token' => bin2hex(random_bytes(16)),
            'meet_url' => 'https://meet.google.com/abc-defg-hij',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ══ 4. every card carries a reason ═══════════════════════════════════════

    /**
     * "3 chargebacks" is a number. "Paystack accepts a dispute for you after 16 hours and
     * refunds from your balance" is a reason to click, and the difference is whether the
     * dashboard changes what anybody does.
     */
    public function test_every_card_says_why_it_matters_and_where_to_go(): void
    {
        $this->chargebackTicket('AGS-CB1');
        Q::open(self::NOM);
        $this->interview('-2 days', 'confirmed');
        DB::table('gates_nominations')->where('id', self::NOM)->update(['status' => 'pending']);

        $items = AttentionBoard::items();
        $this->assertNotSame([], $items);

        foreach ($items as $i) {
            $this->assertGreaterThan(40, mb_strlen((string) $i['why']),
                $i['key'] . ' — a card with no reason on it is just another number');
            $this->assertStringStartsWith('/admin/', (string) $i['href'], (string) $i['key']);
            $this->assertNotSame('', trim((string) $i['cta']), (string) $i['key']);
            $this->assertNotSame('', trim((string) $i['label']), (string) $i['key']);
            // The label is a noun phrase the count reads into ("2 chargebacks awaiting a
            // response"), so it must not repeat the number.
            $this->assertDoesNotMatchRegularExpression('/^\d/', (string) $i['label'], (string) $i['key']);
        }
    }

    public function test_the_label_is_singular_when_there_is_one_of_them(): void
    {
        $this->chargebackTicket('AGS-CB1');
        $this->assertSame('chargeback awaiting a response', $this->board()['disputes']['label']);

        $this->chargebackTicket('AGS-CB2');
        $this->assertSame('chargebacks awaiting a response', $this->board()['disputes']['label']);
    }

    public function test_the_chargeback_reason_names_the_actual_deadline(): void
    {
        // Read from DisputeService rather than typed as "16", so the sentence cannot drift
        // away from the constant the rest of the flow enforces.
        $this->chargebackTicket('AGS-CB1');

        $this->assertStringContainsString(
            (string) \AfricaGates\Services\DisputeService::RESPOND_WITHIN_HOURS,
            (string) $this->board()['disputes']['why']);
    }

    // ══ 5. it never counts the same thing twice ══════════════════════════════

    /**
     * The chargeback tickets have their own card at the top. Counting them again under support
     * would make the board argue with itself on the one item where the number matters most.
     */
    public function test_a_chargeback_ticket_is_not_also_counted_as_a_support_conversation(): void
    {
        $this->chargebackTicket('AGS-CB1');
        $now = Carbon::now()->toDateTimeString();
        DB::table('gates_support_tickets')->insert([
            'reference' => 'AGS-ORD1', 'email' => 'a@b.c', 'name' => 'A person',
            'subject' => 'My vote never arrived', 'transcript' => 'x',
            'severity' => 'normal', 'status' => 'open',
            'created_at' => $now, 'last_activity' => $now,
        ]);

        $board = $this->board();
        $this->assertSame(1, $board['disputes']['count']);
        $this->assertSame(1, $board['support']['count'], 'the chargeback was counted twice');
        $this->assertSame(2, AttentionBoard::total(AttentionBoard::items()));
    }

    // ══ 6. the quiet numbers below the work ═════════════════════════════════

    public function test_the_counts_survive_but_are_kept_out_of_the_board(): void
    {
        // They were the reason the actual work had nowhere to go, so they moved rather than
        // disappeared: an operator does want to know how big this is.
        $pulse = AttentionBoard::pulse();

        $this->assertNotSame([], $pulse);
        foreach ($pulse as $p) {
            $this->assertArrayHasKey('k', $p);
            $this->assertArrayHasKey('v', $p);
            $this->assertIsInt($p['v']);
            $this->assertNotSame('', trim((string) $p['n']),
                'a bare number with no context is what this page was trying to stop being');
        }
    }

    public function test_the_counts_answer_both_how_big_and_whether_it_is_alive(): void
    {
        // A total says how large this is; a recent figure says whether anything is happening,
        // and on any given morning the second is the more useful question.
        $keys = array_column(AttentionBoard::pulse(), 'k');
        $this->assertContains('Votes in 24 hours', $keys);
        $this->assertContains('Nominations this week', $keys);
    }

    public function test_a_missing_table_costs_one_number_and_not_the_page(): void
    {
        // On this deployment "the code is uploaded but /__setup/migrate has not run" is an
        // ordinary state minutes wide, and this is the first screen after login.
        DB::schema()->drop('gates_partner_enquiries');

        $this->assertIsArray(AttentionBoard::items());
        $this->assertIsArray(AttentionBoard::pulse());
        $this->assertCount(8, AttentionBoard::pulse());
    }

    public function test_a_missing_interview_table_does_not_take_the_board_with_it(): void
    {
        DB::schema()->drop('gates_interviews');
        $this->chargebackTicket('AGS-CB1');

        $board = $this->board();
        $this->assertArrayHasKey('disputes', $board, 'one broken probe emptied the whole board');
        $this->assertArrayNotHasKey('interviews_overdue', $board);
    }

    // ══ 7. the real signals, end to end ═════════════════════════════════════

    public function test_a_transcript_nobody_published_is_surfaced(): void
    {
        // The quiet one: an hour of somebody's time spent, and the judges are still reading
        // only the nomination. It appeared nowhere on the old dashboard.
        $id = $this->interview('-3 days', 'confirmed');
        DB::table('gates_interviews')->where('id', $id)->update([
            'status' => 'done', 'ended_at' => Carbon::now()->subDays(3)->toDateTimeString(),
        ]);

        $this->assertSame(1, count(InterviewService::unpublished()));
        $this->assertSame(1, $this->board()['transcripts']['count']);
    }

    public function test_a_nominee_never_told_about_their_questionnaire_is_surfaced(): void
    {
        Q::open(self::NOM);

        $board = $this->board();
        $this->assertSame(1, $board['quest_not_invited']['count']);
        $this->assertSame('/admin/questionnaires', $board['quest_not_invited']['href']);
    }

    /** And an admin's own rehearsal is not somebody who needs inviting. */
    public function test_a_test_questionnaire_is_not_a_nominee_waiting_to_be_asked(): void
    {
        Q::openTest(null, 1, 'Ama Test');

        $this->assertArrayNotHasKey('quest_not_invited', $this->board());
        $this->assertSame([], AttentionBoard::items());
    }
}
