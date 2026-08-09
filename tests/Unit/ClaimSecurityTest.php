<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ClaimDispute;
use AfricaGates\Services\ClaimGuard;
use AfricaGates\Services\ClaimRisk;
use AfricaGates\Services\CommunityReturnService as Ret;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Claiming, from the attacker's side — docs/CLAIM-FAIRNESS-AND-FRAUD.md §5.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO THINGS THAT WERE WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. Every contact on a nomination was told, in writing: "No money moves on a claim
 *    less than 7 days old, and any payment can only ever go to a bank account in the
 *    nominee's own name." `COOLING_OFF_DAYS = 7` was a private constant interpolated
 *    into that email and referenced by NOTHING ELSE. No code read claim state before
 *    describing money as available. A hijacked claim was worth cash immediately.
 *
 * 2. The same email said "if this was not you, reply to this email … we will stop it
 *    while a person looks." The claim was already ACTIVE. So a wrongly-claimed
 *    nominee's only lever was composing an email and waiting, while somebody else held
 *    their page — and the people least likely to draft a support email are exactly the
 *    population the doctrine's §1 is written about.
 *
 * These tests are the code behind both sentences.
 */
final class ClaimSecurityTest extends TestCase
{
    private const NOM = 8800;
    private const CYCLE = 8800;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::CYCLE, 'title' => 'P', 'slug' => 'p-8800']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => self::CYCLE, 'programme_id' => self::CYCLE, 'year' => 2026, 'status' => 'results',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => self::CYCLE, 'cycle_id' => self::CYCLE, 'title' => 'Cat', 'slug' => 'c-8800',
        ]);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CYCLE, 'name' => 'Amina Bello',
            'status' => 'approved', 'vote_count' => 40,
        ]);
    }

    /** A claim row in whatever state the test needs. */
    private function claim(array $over = []): int
    {
        return (int) DB::table('gates_nominee_claims')->insertGetId($over + [
            'nominee_id' => self::NOM,
            'status' => 'active',
            'method' => 'otp',
            'channel' => 'email',
            'channel_hint' => 'a***@example.com',
            'reference' => 'AGC-TEST-01',
            'active_nominee_id' => self::NOM,
            'activated_at' => Carbon::now()->toDateTimeString(),
            'cooling_off_until' => ClaimGuard::windowFromNow(),
            'dispute_token' => ClaimDispute::mintToken(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ── the cooling-off window, which had no code behind it ──────────────────

    /**
     * THE PROMISE. A claim activated today cannot release money, whatever the cycle
     * rules say — that sentence is in an email sent to every contact on the nomination.
     */
    public function test_money_cannot_move_inside_the_window_the_nominee_was_promised(): void
    {
        $this->claim();

        $s = ClaimGuard::payoutState(self::NOM);

        $this->assertFalse($s['payable']);
        $this->assertSame('COOLING_OFF', $s['code']);
        $this->assertStringContainsString((string) ClaimGuard::COOLING_OFF_DAYS, $s['reason']);
    }

    public function test_money_may_move_once_the_window_has_passed(): void
    {
        $this->claim([
            'activated_at'      => Carbon::now()->subDays(30)->toDateTimeString(),
            'cooling_off_until' => Carbon::now()->subDays(23)->toDateTimeString(),
        ]);

        $s = ClaimGuard::payoutState(self::NOM);

        $this->assertTrue($s['payable'], $s['reason']);
        $this->assertSame('PAYABLE', $s['code']);
    }

    /**
     * The window is read from the STORED date, not recomputed from today's constant.
     * The length is a policy that will change, and a claim must be governed by the
     * policy in force when it was made — otherwise editing the constant silently moves a
     * date a nominee has already been given in writing.
     */
    public function test_the_stored_window_wins_over_recomputing_it(): void
    {
        // Activated long ago but carrying a window that has not closed: an operator
        // extended it, or the policy was longer then. Either way it must be honoured.
        $this->claim([
            'activated_at'      => Carbon::now()->subDays(60)->toDateTimeString(),
            'cooling_off_until' => Carbon::now()->addDays(3)->toDateTimeString(),
        ]);

        $this->assertFalse(ClaimGuard::payable(self::NOM),
            'the stored window was ignored in favour of activated_at + the current constant');
    }

    /** No claim at all is not permission to pay — there is nobody verified to pay. */
    public function test_an_unclaimed_page_can_never_release_money(): void
    {
        $s = ClaimGuard::payoutState(self::NOM);

        $this->assertFalse($s['payable']);
        $this->assertSame('UNCLAIMED', $s['code']);
    }

    /** A held claim is a review in progress, and the review is the point. */
    public function test_a_held_claim_cannot_release_money(): void
    {
        $this->claim(['status' => 'held', 'active_nominee_id' => null, 'activated_at' => null,
                      'cooling_off_until' => null]);

        $this->assertSame('NOT_ACTIVE', ClaimGuard::payoutState(self::NOM)['code']);
    }

    /**
     * FAILS CLOSED. An unreadable claims table is not permission to pay — the opposite
     * default is the one mistake in this class that could not be undone after the money
     * had gone.
     */
    public function test_it_refuses_when_claim_state_cannot_be_read(): void
    {
        DB::statement('DROP TABLE gates_nominee_claims');

        $s = ClaimGuard::payoutState(self::NOM);

        $this->assertFalse($s['payable']);
        $this->assertSame('UNREADABLE', $s['code']);
    }

    // ── and the ledger honours it ────────────────────────────────────────────

    /**
     * `payable_kobo` and `withdrawable_kobo` answer DIFFERENT questions and both are
     * needed. The first is "have the cycle rules released this" — about the money. The
     * second is "is there a verified person to send it to, and may it go yet" — about the
     * claim. Collapsing them into one figure would either report zero earned for every
     * unclaimed nominee on the platform or pay out to whoever claimed an hour ago.
     */
    public function test_the_ledger_separates_what_is_earned_from_what_may_leave(): void
    {
        DB::table('gates_community_returns')->insert([
            'nominee_id' => self::NOM, 'cycle_id' => self::CYCLE, 'entry_type' => 'accrual',
            'amount_kobo' => 150000, 'basis_kobo' => 300000, 'note' => 'test',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->claim();   // active, but inside its window

        $b = Ret::balance(self::NOM);

        $this->assertSame(150000, $b['payable_kobo'], 'the cycle rules have released it');
        $this->assertSame(0, $b['withdrawable_kobo'], 'but the claim bar has not');
        $this->assertNotNull($b['claim_block']);
        $this->assertSame('COOLING_OFF', $b['claim_block']['code']);
    }

    public function test_withdrawable_matches_payable_once_the_claim_clears(): void
    {
        DB::table('gates_community_returns')->insert([
            'nominee_id' => self::NOM, 'cycle_id' => self::CYCLE, 'entry_type' => 'accrual',
            'amount_kobo' => 150000, 'basis_kobo' => 300000, 'note' => 'test',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->claim([
            'activated_at'      => Carbon::now()->subDays(30)->toDateTimeString(),
            'cooling_off_until' => Carbon::now()->subDays(23)->toDateTimeString(),
        ]);

        $b = Ret::balance(self::NOM);

        $this->assertSame(150000, $b['payable_kobo']);
        $this->assertSame(150000, $b['withdrawable_kobo']);
        $this->assertNull($b['claim_block']);
    }

    // ── the dispute link ─────────────────────────────────────────────────────

    /**
     * THE OTHER HALF. One action from the message we sent, no account, no waiting for a
     * human to read support mail.
     */
    public function test_a_dispute_freezes_the_claim_and_hands_the_page_back(): void
    {
        $id    = $this->claim();
        $token = (string) DB::table('gates_nominee_claims')->where('id', $id)->value('dispute_token');

        $r = ClaimDispute::freeze($token, 'This is my daughter and nobody in the family claimed it.');

        $this->assertTrue($r['ok']);
        $this->assertSame('FROZEN', $r['code']);

        $row = DB::table('gates_nominee_claims')->where('id', $id)->first();
        $this->assertSame('held', (string) $row->status);
        // THIS is what actually returns the page: active_nominee_id is the unique key
        // that grants control, so a freeze that left it set would pause the claim on
        // paper while the claimant kept the page.
        $this->assertNull($row->active_nominee_id);
        $this->assertNotNull($row->disputed_at);
        $this->assertStringContainsString('daughter', (string) $row->dispute_note);
    }

    /** And money stops immediately, which is the point of freezing rather than emailing. */
    public function test_a_disputed_claim_blocks_payment_even_though_it_is_no_longer_active(): void
    {
        $id    = $this->claim(['activated_at' => Carbon::now()->subDays(30)->toDateTimeString(),
                               'cooling_off_until' => Carbon::now()->subDays(23)->toDateTimeString()]);
        $token = (string) DB::table('gates_nominee_claims')->where('id', $id)->value('dispute_token');

        $this->assertTrue(ClaimGuard::payable(self::NOM), 'precondition: it was payable');

        ClaimDispute::freeze($token);

        $s = ClaimGuard::payoutState(self::NOM);
        $this->assertFalse($s['payable']);
        // DISPUTED, not UNCLAIMED. The freeze clears active_nominee_id, so a guard that
        // only looked at the active row would fall through to "no claim, nothing to
        // check" — which reads as a block but for the wrong reason, and would clear the
        // moment anybody else claimed.
        $this->assertSame('DISPUTED', $s['code']);
        $this->assertTrue($s['disputed']);
    }

    public function test_disputing_twice_is_idempotent(): void
    {
        $id    = $this->claim();
        $token = (string) DB::table('gates_nominee_claims')->where('id', $id)->value('dispute_token');

        ClaimDispute::freeze($token, 'first');
        $second = ClaimDispute::freeze($token, 'second');

        $this->assertTrue($second['ok']);
        $this->assertSame('ALREADY', $second['code']);
        // The first note stands: somebody tapping twice because they are frightened
        // should not have their own words overwritten.
        $this->assertSame('first', (string) DB::table('gates_nominee_claims')->where('id', $id)->value('dispute_note'));
    }

    public function test_a_malformed_or_unknown_token_does_nothing(): void
    {
        $this->claim();

        foreach (['', 'short', str_repeat('z', 32), str_repeat('a', 31), bin2hex(random_bytes(16))] as $bad) {
            $r = ClaimDispute::freeze($bad);
            $this->assertFalse($r['ok'], 'accepted ' . var_export($bad, true));
        }
        $this->assertSame('active', (string) DB::table('gates_nominee_claims')->value('status'));
    }

    /**
     * THE MAIL-SCANNER TRAP. Gmail, Outlook and every link-safety scanner FETCH the URLs
     * in a message before a human sees them. A freeze on GET would fire automatically on
     * a large share of honest claims — and the request in the log would look like an
     * ordinary visitor arriving from an email, so the cause would be invisible.
     */
    public function test_fetching_the_link_does_not_freeze_anything(): void
    {
        $id    = $this->claim();
        $token = (string) DB::table('gates_nominee_claims')->where('id', $id)->value('dispute_token');

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $res = $app->handle((new ServerRequestFactory())
            ->createServerRequest('GET', '/claim/dispute/' . $token));
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('active', (string) DB::table('gates_nominee_claims')->where('id', $id)->value('status'),
            'a GET froze the claim — every mail scanner on earth will now do this for real');
        $this->assertNull(DB::table('gates_nominee_claims')->where('id', $id)->value('disputed_at'));

        // It rendered the confirm step, and it never names the claimant: the only
        // credential is a token that arrived in a message, and a token-holder is not
        // necessarily the nominee.
        $this->assertStringContainsString('Pause this claim', $html);
        $this->assertStringContainsString('Amina Bello', $html);
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
    }

    /** An unknown token gets a kind page and the support address, never a 404. */
    public function test_a_dead_link_explains_itself_rather_than_404ing(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $res = $app->handle((new ServerRequestFactory())
            ->createServerRequest('GET', '/claim/dispute/' . bin2hex(random_bytes(16))));

        $this->assertSame(200, $res->getStatusCode(),
            'a 404 would confirm to anybody enumerating which tokens are real');
        $this->assertStringContainsString('no longer active', (string) $res->getBody());
    }

    // ── risk signals: what owning one inbox cannot prove ─────────────────────

    /**
     * ONE ADDRESS, MANY CHILDREN. A teacher nominates thirty pupils and puts the school
     * address on every nomination because the pupils have no email. That address is
     * independent of the nominator on twenty-nine of them — so anybody with the school
     * inbox could claim thirty children's pages, each claim passing every existing check
     * cleanly. A shared mailbox cannot identify a person.
     */
    public function test_a_contact_on_many_nominations_is_treated_as_a_shared_mailbox(): void
    {
        foreach (['Ada One', 'Bola Two', 'Chidi Three', 'Dami Four'] as $i => $child) {
            DB::table('gates_nominations')->insert([
                'cycle_id' => self::CYCLE, 'category_id' => self::CYCLE,
                'nominee_name' => $child, 'nominee_email' => 'info@school.ng',
                'nominator_name' => 'A Teacher', 'nominator_email' => 'teacher' . $i . '@school.ng',
                'status' => 'approved', 'reason' => 'a good pupil',
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        $n = ClaimRisk::nomineesReachableBy(['channel' => 'email', 'value' => 'Info@School.NG ']);
        $this->assertSame(4, $n, 'normalisation failed, so a shared mailbox looked unique');

        $r = ClaimRisk::assess(self::NOM, ['channel' => 'email', 'value' => 'info@school.ng']);
        $this->assertTrue($r['hold']);
        $this->assertStringContainsString('other nominations', $r['say']);
        // Never a signal name in the claimant's face: "shared-contact:4" means nothing to
        // them and reads like a fraud score.
        $this->assertStringNotContainsString('shared-contact', $r['say']);
    }

    /** Two siblings on one parent's address is ordinary and must not be held. */
    public function test_one_parent_with_two_children_is_not_a_shared_mailbox(): void
    {
        foreach (['Ada One', 'Bola Two'] as $child) {
            DB::table('gates_nominations')->insert([
                'cycle_id' => self::CYCLE, 'category_id' => self::CYCLE,
                'nominee_name' => $child, 'nominee_email' => 'mum@example.com',
                'nominator_name' => 'Mum', 'nominator_email' => 'mum@example.com',
                'status' => 'approved', 'reason' => 'my child',
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        $r = ClaimRisk::assess(self::NOM, ['channel' => 'email', 'value' => 'mum@example.com']);
        $this->assertFalse($r['hold'], 'a parent of two was sent for review for having two children');
    }

    /** A published result is worth impersonating, so it always gets a person. */
    public function test_a_winners_page_is_always_reviewed(): void
    {
        DB::table('gates_nominees')->where('id', self::NOM)->update(['status' => 'winner']);

        $r = ClaimRisk::assess(self::NOM, ['channel' => 'email', 'value' => 'nobody@example.com']);

        $this->assertTrue($r['hold']);
        $this->assertStringContainsString('published result', $r['say']);
    }

    /** And the signals never refuse — a hold is "one more thing", not a no. */
    public function test_risk_never_refuses_a_claim(): void
    {
        DB::table('gates_nominees')->where('id', self::NOM)->update(['status' => 'winner']);

        $r = ClaimRisk::assess(self::NOM, ['channel' => 'email', 'value' => 'x@example.com']);

        $this->assertTrue($r['hold']);
        $this->assertStringContainsString('not a refusal', $r['say']);
        $this->assertStringContainsString('nothing is being charged', strtolower($r['say']));
    }

    /**
     * A check that could not run must not read as a pass. An unreadable nominations
     * table means we cannot tell whether the contact is shared, and "we could not tell"
     * is a reason to ask a person, not to hand over a child's page.
     */
    public function test_an_unreadable_nominations_table_holds_rather_than_passes(): void
    {
        DB::statement('DROP TABLE gates_nominations');

        $n = ClaimRisk::nomineesReachableBy(['channel' => 'email', 'value' => 'x@example.com']);

        $this->assertGreaterThanOrEqual(ClaimRisk::SHARED_CONTACT_NOMINEES, $n);
        $this->assertTrue(ClaimRisk::assess(self::NOM, ['channel' => 'email', 'value' => 'x@example.com'])['hold']);
    }
}
