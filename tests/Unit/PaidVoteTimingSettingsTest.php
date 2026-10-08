<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\SettingsController;
use AfricaGates\Admin\Services\{SettingsService, AuditService};
use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The two paid-vote timing windows, reachable from a browser.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A TEST AND NOT JUST A FORM FIELD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `paid_vote_grace_hours` decides whether a payment confirmed AFTER a cycle closed
 * can still be delivered. {@see PaidVoteDeadlineTest} already proves the service
 * honours it and clamps it.
 *
 * What was missing was the route to it. The setting was read from the database and
 * written by nothing — no admin field, no console command — so in practice it was
 * a constant, and the operator of this platform has no shell. That is not a
 * cosmetic gap: it is the difference between a backlog of stranded payments being
 * recoverable and being permanently stuck at CONFIRMED_TOO_LATE, on the exact day
 * somebody needs to recover them.
 *
 * A setting nobody can set is not configuration. These tests are about the path
 * from the form to the value the mint reads.
 */
final class PaidVoteTimingSettingsTest extends TestCase
{
    private function save(array $body): void
    {
        $_SESSION['admin_id'] = 1;

        $c = new SettingsController(
            $this->createStub(\Slim\Views\Twig::class),
            new SettingsService(),
            new AuditService(),
        );

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/settings')
            ->withParsedBody($body);

        $c->save($req, new Response());
    }

    /**
     * THE BLOCKER. An operator with no shell must be able to widen the window
     * before running a backlog recovery for a cycle that has already closed.
     */
    public function test_an_admin_can_widen_the_grace_window_from_the_browser(): void
    {
        $this->assertSame(6, PaidVoteService::lateMintGraceHours(), 'the shipped default');

        $this->save(['paid_vote_settings' => '1', 'paid_vote_grace_hours' => '72']);

        $this->assertSame(72, PaidVoteService::lateMintGraceHours(),
            'the value the mint reads is the value the form wrote');
    }

    public function test_the_checkout_cutoff_is_settable_too(): void
    {
        $this->save(['paid_vote_settings' => '1', 'paid_vote_cutoff_minutes' => '25']);

        $this->assertSame(25, PaidVoteService::checkoutCutoffMinutes());
    }

    /**
     * A settings row is not a trusted input just because an admin typed it. Both
     * fields are clamped at the controller AND in the service — a fat-fingered
     * grace window must not become an open-ended right to rewrite settled tallies.
     */
    public function test_absurd_values_are_clamped_on_the_way_in(): void
    {
        $this->save([
            'paid_vote_settings'       => '1',
            'paid_vote_grace_hours'    => '99999',
            'paid_vote_cutoff_minutes' => '99999',
        ]);

        $this->assertSame('168', (string) DB::table('gates_settings')
            ->where('key_name', 'paid_vote_grace_hours')->value('value'));
        $this->assertSame('240', (string) DB::table('gates_settings')
            ->where('key_name', 'paid_vote_cutoff_minutes')->value('value'));

        $this->save([
            'paid_vote_settings'       => '1',
            'paid_vote_grace_hours'    => '-40',
            'paid_vote_cutoff_minutes' => '-5',
        ]);

        $this->assertSame('0', (string) DB::table('gates_settings')
            ->where('key_name', 'paid_vote_grace_hours')->value('value'));
        $this->assertSame('0', (string) DB::table('gates_settings')
            ->where('key_name', 'paid_vote_cutoff_minutes')->value('value'));
    }

    /**
     * And the end-to-end consequence, which is the whole reason the field exists:
     * the same stranded order refuses before the change and delivers after it.
     */
    public function test_widening_the_window_recovers_an_order_that_was_refusing(): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p', 'title' => 'P']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-20 hours')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(
            ['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);

        // Paid while the ballot was open; the confirmation reached us far too late.
        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Stranded Supporter', 'donor_email' => 'stranded@example.test',
            'amount_naira' => 2000, 'tier' => 'paid-vote', 'bonus_votes' => 20,
            'votes_used' => 0, 'intent_nominee_id' => 1, 'payment_ref' => 'AFG-STRANDED',
            'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s', strtotime('-21 hours')),
        ]);

        $this->assertSame('CONFIRMED_TOO_LATE', PaidVoteService::mint($id)['code'],
            'at the 6-hour default this order is unrecoverable');

        $this->save(['paid_vote_settings' => '1', 'paid_vote_grace_hours' => '48']);

        $this->assertTrue(PaidVoteService::mint($id)['ok'],
            'and after one form field, the supporter gets the votes they paid for');
        $this->assertSame(20, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }
}
