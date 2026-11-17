<?php
declare(strict_types=1);

namespace Tests\Feature;

use AfricaGates\Services\BallotGuard;
use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\SnapshotService;
use AfricaGates\Services\VotingExtension;
use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * EXTENDING VOTING, ON THE RECORD (Services\VotingExtension; docs/VOTING-EXTENSION-POLICY.md).
 *
 * An extension used to be a quiet edit of `voting_close`: nothing recorded it, nothing told
 * the public why, and paid checkout simply carried on into the extra time. These tests hold
 * the four things that make it honest instead:
 *
 *   · it is a row (and an audit entry) with a public reason, and it can RECORD an extension
 *     already made by editing the date — the operator has already done exactly that once;
 *   · it is refused where it cannot be honest: an earlier date, a released result, a close at
 *     or past the results date, a second extension nobody confirmed;
 *   · its paid cutoff stops NEW paid checkouts while free voting stays open, and an order
 *     started before the cutoff still mints;
 *   · the public page says so, and escapes the operator's reason.
 *
 * Ids stay under 255: `programme_id` is a TINYINT on MySQL (CLAUDE.md).
 */
final class VotingExtensionTest extends TestCase
{
    private const PROG = 231;
    private const CYCLE = 231;
    private const CAT = 2311;
    private const NOM = 23101;
    private const REASON = 'Verification codes were not delivered to many voters because of an email provider outage.';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';
        DB::table('gates_settings')->where('key_name', 'like', 'paid_vot%')->delete();
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => self::PROG, 'slug' => 'extension-award', 'title' => 'Extension Award', 'is_active' => 1, 'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'],
              $_SESSION['flash_ok'], $_SESSION['flash_error'], $_SESSION['paid_vote_retry']);
        parent::tearDown();
    }

    private static function at(string $rel): string
    {
        return date('Y-m-d H:i:s', strtotime($rel));
    }

    /** A cycle on the vote with one category and one approved nominee. */
    private function seedCycle(array $over = []): object
    {
        DB::table('gates_award_cycles')->insert(array_merge([
            'id' => self::CYCLE, 'programme_id' => self::PROG, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => self::at('-30 days'),
            'voting_close' => self::at('+1 day'),
        ], $over));
        DB::table('gates_award_categories')->insertOrIgnore(
            ['id' => self::CAT, 'cycle_id' => self::CYCLE, 'slug' => 'ext-cat', 'title' => 'Extension Category']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOM, 'category_id' => self::CAT, 'name' => 'Adaeze Okafor', 'status' => 'approved',
            'vote_count' => 12, 'organic_vote_count' => 12,
        ]);
        return DB::table('gates_award_cycles')->where('id', self::CYCLE)->first();
    }

    private function close(): string
    {
        return (string) DB::table('gates_award_cycles')->where('id', self::CYCLE)->value('voting_close');
    }

    /** POST the editor's "Extend voting" form, dates given as STORED (UTC) values. */
    private function post(string $prevStored, string $newStored, array $over = []): void
    {
        $body = array_merge([
            '_token' => 'tok', 'cycle_id' => self::CYCLE,
            'previous_close' => DisplayTime::forInput($prevStored),
            'new_close'      => DisplayTime::forInput($newStored),
            'reason'         => self::REASON,
            'stop_paid'      => '1',
        ], $over);
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $builder->build()->get(\AfricaGates\Admin\Controllers\ProgrammesController::class);
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/programmes/' . self::PROG . '/cycle/extend')->withParsedBody($body);
        $res = $c->cycleExtend($req, new Response(), ['id' => (string) self::PROG]);
        $this->assertSame(303, $res->getStatusCode());
    }

    private function rows(): array
    {
        return DB::table('gates_voting_extensions')->where('cycle_id', self::CYCLE)->orderBy('id')->get()->all();
    }

    // ══ 1. recorded, and the date moved ═════════════════════════════════════

    public function test_an_extension_moves_the_close_for_the_whole_edition_and_is_recorded(): void
    {
        $cycle = $this->seedCycle();
        $prev  = (string) $cycle->voting_close;
        $new   = self::at('+2 days');

        $this->post($prev, $new);

        $this->assertArrayNotHasKey('flash_error', $_SESSION, (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($new, $this->close(), 'voting_close is the cycle\'s, so every category moves with it');
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $x = $rows[0];
        $this->assertSame('moved', $x->mode);
        $this->assertSame($prev, (string) $x->previous_close);
        $this->assertSame($new, (string) $x->new_close);
        $this->assertSame($prev, (string) $x->paid_cutoff_at, 'paid checkout stops at the previous close by default');
        $this->assertSame(self::REASON, $x->reason);
        $this->assertSame(1, (int) $x->admin_id);
        $this->assertNotEmpty($x->snapshot_ref, 'the standings at that moment are captured and referenced');
        $this->assertSame((string) $x->snapshot_ref,
            (string) DB::table('gates_vote_snapshots')->where('cycle_id', self::CYCLE)->orderByDesc('id')->value('hash'));

        $audit = DB::table('gates_audit_log')->where('action', 'cycle.voting_extended')->where('target_id', self::CYCLE)->first();
        $this->assertNotNull($audit, 'the extension is in the audit log');
        $meta = json_decode((string) $audit->meta, true);
        $this->assertSame($prev, $meta['before']['voting_close']);
        $this->assertSame($new, $meta['after']['voting_close']);
    }

    public function test_without_an_admin_the_row_stores_null_never_zero(): void
    {
        $cycle = $this->seedCycle();
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+2 days')), 'reason' => self::REASON, 'stop_paid' => true,
        ], 0);

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertNull(DB::table('gates_voting_extensions')->where('id', $r['id'])->value('admin_id'),
            'there is no admin 0 (CLAUDE.md, the audit FK): store NULL');
    }

    public function test_a_failed_snapshot_does_not_block_the_extension(): void
    {
        $cycle = $this->seedCycle();
        $broken = new class extends SnapshotService {
            public function captureCycle(int $cycleId): ?string { throw new \RuntimeException('archive down'); }
        };
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+2 days')), 'reason' => self::REASON, 'stop_paid' => true,
        ], 1, null, $broken);

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertNull($r['snapshot_ref']);
        $this->assertNull(DB::table('gates_voting_extensions')->where('id', $r['id'])->value('snapshot_ref'));
        $this->assertStringContainsString('could not be captured', implode(' ', $r['warnings']), 'and it says so');
    }

    // ══ 2. recording an extension already made ══════════════════════════════

    public function test_an_extension_already_made_by_editing_the_date_can_be_recorded_without_moving_it(): void
    {
        // The operator already moved the close from +1 day to +3 days in the cycle form.
        $cycle = $this->seedCycle(['voting_close' => self::at('+3 days')]);
        $current = (string) $cycle->voting_close;
        $prev    = self::at('+1 day');

        $this->post($prev, $current);

        $this->assertArrayNotHasKey('flash_error', $_SESSION, (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($current, $this->close(), 'record-only: the date does not change');
        $x = $this->rows()[0];
        $this->assertSame('recorded', $x->mode);
        $this->assertSame($prev, (string) $x->previous_close);
        $this->assertSame($current, (string) $x->new_close);
        $this->assertSame($prev, (string) $x->paid_cutoff_at, 'the previous close has not passed, so it is still the cutoff');
        $this->assertStringContainsString('Extension recorded', (string) $_SESSION['flash_ok']);
    }

    public function test_recording_after_the_previous_close_passed_does_not_claim_paid_voting_stopped_then(): void
    {
        // Moved silently; the old close was two hours ago and paid checkout kept selling.
        $cycle = $this->seedCycle(['voting_close' => self::at('+2 days')]);
        $prev  = self::at('-2 hours');

        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput($prev), 'new_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'reason' => self::REASON, 'stop_paid' => true,
        ], 1);

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame('recorded', $r['mode']);
        $cut = strtotime((string) $r['row']['paid_cutoff_at']);
        $this->assertGreaterThan(strtotime($prev), $cut, '"paid voting closed at {previous}" would be false');
        $this->assertLessThanOrEqual(time() + 1, $cut, 'it stops now, the moment the record is made');
    }

    // ══ 3. refusals ═════════════════════════════════════════════════════════

    /** Post an extension that must be refused with `$needle`, and prove nothing changed. */
    private function refuse(array $cycleOver, callable $input, string $needle): void
    {
        $cycle  = $this->seedCycle($cycleOver);
        $before = $this->close();
        [$prev, $new, $extra] = $input($cycle);
        $this->post($prev, $new, $extra);

        $this->assertStringContainsString($needle, (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($before, $this->close(), 'a refusal changes nothing');
        $this->assertCount(0, $this->rows());
    }

    public function test_a_new_close_not_later_than_the_previous_is_refused(): void
    {
        $this->refuse([], fn ($c) => [(string) $c->voting_close, self::at('-1 hour'), []], 'later than the previous close');
    }

    public function test_a_previous_close_after_the_current_close_is_refused(): void
    {
        $this->refuse([], fn ($c) => [self::at('+2 days'), self::at('+3 days'), []], 'cannot be later than the voting close');
    }

    public function test_a_released_edition_cannot_be_extended(): void
    {
        $this->refuse(['status' => 'archived', 'voting_close' => self::at('-10 days')],
            fn ($c) => [(string) $c->voting_close, self::at('+2 days'), []], 'already released');
    }

    public function test_an_edition_released_early_cannot_be_extended(): void
    {
        // Windows say judging; the stored status says an operator released it.
        $this->refuse(['status' => 'results', 'voting_close' => self::at('-2 days'), 'results_date' => self::at('+5 days')],
            fn ($c) => [(string) $c->voting_close, self::at('+1 day'), []], 'already released');
    }

    public function test_a_new_close_at_or_after_the_results_date_is_refused(): void
    {
        $this->refuse(['results_date' => self::at('+2 days')],
            fn ($c) => [(string) $c->voting_close, self::at('+2 days'), []], 'before the results date');
    }

    public function test_a_short_reason_is_refused(): void
    {
        $this->refuse([], fn ($c) => [(string) $c->voting_close, self::at('+2 days'), ['reason' => 'Because.']], 'between 20 and 1000');
    }

    public function test_a_second_extension_needs_its_confirmation(): void
    {
        $cycle = $this->seedCycle();
        $this->post((string) $cycle->voting_close, self::at('+2 days'));
        $this->assertCount(1, $this->rows());
        unset($_SESSION['flash_ok']);

        $after = $this->close();
        $this->post($after, self::at('+3 days'));
        $this->assertStringContainsString('already been extended', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($after, $this->close());
        $this->assertCount(1, $this->rows());

        unset($_SESSION['flash_error']);
        $this->post($after, self::at('+3 days'), ['further' => '1']);
        $this->assertArrayNotHasKey('flash_error', $_SESSION, (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertCount(2, $this->rows(), 'confirmed, it is recorded');
    }

    public function test_the_editor_lists_prior_extensions(): void
    {
        $cycle = $this->seedCycle();
        $this->post((string) $cycle->voting_close, self::at('+2 days'));

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $builder->build()->get(\AfricaGates\Admin\Controllers\ProgrammesController::class);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/programmes/' . self::PROG . '/cycle')
            ->withQueryParams(['cycle' => (string) self::CYCLE]);
        $html = (string) $c->cycleEdit($req, new Response(), ['id' => (string) self::PROG])->getBody();

        $this->assertStringContainsString('action="/admin/programmes/' . self::PROG . '/cycle/extend"', $html);
        $this->assertStringContainsString('every category and every nominee in this edition', $html);
        $this->assertStringContainsString(self::REASON, $html, 'the prior extension and its reason are listed');
        $this->assertStringContainsString('This is a further extension', $html);
    }

    // ══ 4. the paid cutoff ══════════════════════════════════════════════════

    /** Voting closed an hour ago and is reopened for a day, paid votes stopping at the old close. */
    private function extendedPastThePaidCutoff(): object
    {
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $cycle = $this->seedCycle(['voting_close' => self::at('-1 hour')]);
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+1 day')), 'reason' => self::REASON, 'stop_paid' => true,
        ], 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame((string) $cycle->voting_close, (string) $r['row']['paid_cutoff_at']);
        return $cycle;
    }

    public function test_after_the_cutoff_new_paid_checkouts_are_refused_and_free_voting_stays_open(): void
    {
        $this->extendedPastThePaidCutoff();

        $this->assertTrue(BallotGuard::isVotable(self::CAT), 'the free OTP ballot is open: the extension reopened voting');
        $this->assertTrue(PaidVoteService::votingOpenFor(self::CAT));
        $this->assertFalse(PaidVoteService::checkoutOpenFor(self::CAT), 'but no new paid checkout');
        $this->assertTrue(VotingExtension::paidClosedFor(self::CAT));

        $sentence = (string) VotingExtension::paidClosedSentence(self::CYCLE);
        $this->assertStringContainsString('Paid voting for this edition closed on', $sentence);
        $this->assertStringContainsString('free voting stays open until', $sentence);

        // The checkout itself refuses, with the extension's own reason.
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $pc = $builder->build()->get(\AfricaGates\Controllers\PaidVoteController::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/vote/paid/start')
            ->withParsedBody(['nominee_id' => self::NOM, 'qty' => '5', 'email' => 'buyer@example.test', 'provider' => 'paystack']);
        $res = $pc->start($req, new Response());
        $this->assertStringContainsString('paid=extended', $res->getHeaderLine('Location'));
        $this->assertSame(0, DB::table('gates_donations')->where('intent_nominee_id', self::NOM)->count(), 'no order was created');
    }

    public function test_an_order_started_before_the_cutoff_still_mints(): void
    {
        $this->extendedPastThePaidCutoff();
        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Okun Alimosho', 'donor_email' => 'okun@example.test',
            'amount_naira' => 2000, 'tier' => 'paid-vote', 'bonus_votes' => 20, 'votes_used' => 0,
            'intent_nominee_id' => self::NOM, 'payment_ref' => 'AFG-PVOTE-' . bin2hex(random_bytes(6)),
            'status' => 'confirmed', 'created_at' => self::at('-2 hours'),
        ]);

        $r = PaidVoteService::mint($id);

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame(20, (int) DB::table('gates_donations')->where('id', $id)->value('votes_used'));
    }

    public function test_without_the_cutoff_paid_voting_runs_to_the_new_close(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $cycle = $this->seedCycle(['voting_close' => self::at('-1 hour')]);
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+1 day')), 'reason' => self::REASON, 'stop_paid' => false,
        ], 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertTrue(PaidVoteService::checkoutOpenFor(self::CAT));
    }

    // ══ 5. the public notice ════════════════════════════════════════════════

    public function test_the_vote_page_states_the_extension_and_escapes_the_reason(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $cycle = $this->seedCycle(['voting_close' => self::at('-1 hour')]);
        $reason = 'Codes failed for many voters <script>alert(1)</script> during the outage.';
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+1 day')), 'reason' => $reason, 'stop_paid' => true,
        ], 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $vc = $builder->build()->get(\AfricaGates\Controllers\VoteController::class);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/vote/extension-award');
        $res = $vc->program($req, new Response(), ['program' => 'extension-award']);
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();

        $from = DisplayTime::showZoned((string) $cycle->voting_close);
        $this->assertStringContainsString('data-voting-extension', $html);
        $this->assertStringContainsString('Voting extended from ' . $from . ' to ', $html);
        $this->assertStringContainsString('Every vote already cast stands. Rules and prices are unchanged. The new date applies to every nominee in this edition.', $html);
        $this->assertStringContainsString('Paid voting closed at ' . $from . '; free voting stays open.', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the reason is escaped');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_an_edition_without_an_extension_has_no_notice(): void
    {
        $this->seedCycle();
        $this->assertNull(VotingExtension::notice(self::CYCLE));
        $this->assertNull(VotingExtension::paidCutoffForCategory(self::CAT));
    }
}
