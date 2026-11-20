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

    /**
     * The extension is ONE ROW OF THE NOTICE STRIP, not a card inside the page (owner, 20 Nov
     * 2026: "use the announce feature, dismissable, and not crowd the whole interface").
     */
    public function test_the_extension_is_a_slim_dismissable_row_not_a_card_in_the_page(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        $cycle = $this->seedCycle(['voting_close' => self::at('-1 hour')]);
        $reason = 'Codes failed for many voters <script>alert(1)</script> during the outage.';
        $r = VotingExtension::extend($cycle, [
            'previous_close' => DisplayTime::forInput((string) $cycle->voting_close),
            'new_close' => DisplayTime::forInput(self::at('+1 day')), 'reason' => $reason, 'stop_paid' => true,
        ], 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        $_SESSION = [];
        $rows = array_values(array_filter(\AfricaGates\Services\SiteNotice::forPage('/privacy'), fn ($n) => $n['kind'] === 'extension'));
        $this->assertCount(1, $rows, 'the extension is a row of the strip on every page');
        $this->assertSame(['Extension Award'], $rows[0]['awards']);
        $this->assertSame('Extension Award', $rows[0]['which']);
        $this->assertStringStartsWith('until ', $rows[0]['short']);
        $this->assertTrue($rows[0]['dismiss']);
        $this->assertCount(1, $rows[0]['paid']);
        $this->assertStringStartsWith('Paid voting closed at ', $rows[0]['paid'][0]);

        $html = \Tests\Support\ChromeRender::html('/privacy');
        $this->assertStringContainsString('class="ag-notice ag-notice--extension"', $html);
        $this->assertStringContainsString('Voting extended', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the reason is escaped');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('aria-label="Hide the voting extension notice"', $html);
        // A marquee, to WCAG 2.2.2: its own pause control (no script needed), the moving copies
        // hidden from screen readers, and the sentence once as text for them.
        $this->assertStringContainsString('class="ag-tick"', $html);
        $this->assertStringContainsString('aria-label="Pause the voting extension notice"', $html);
        $this->assertMatchesRegularExpression('~<div class="ag-tick__track" aria-hidden="true"~', $html);
        $this->assertMatchesRegularExpression('~<p class="ag-sr">Voting extended\. Voting now closes at [^<]*Extension Award\.~', $html);
        $this->assertStringNotContainsString('ag-tick__more', $html, 'the expanding panel is back');

        // Hidden once, gone from every page; and the vote page itself carries no card.
        \AfricaGates\Services\SiteNotice::hide($rows[0]['id']);
        $this->assertSame([], array_filter(\AfricaGates\Services\SiteNotice::forPage('/privacy'), fn ($n) => $n['kind'] === 'extension'));
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $vc = $builder->build()->get(\AfricaGates\Controllers\VoteController::class);
        $page = (string) $vc->program((new ServerRequestFactory())->createServerRequest('GET', '/vote/extension-award'),
            new Response(), ['program' => 'extension-award'])->getBody();
        $this->assertStringNotContainsString('ag-xt', $page, 'the card that crowded the page is back');
        $this->assertStringNotContainsString($reason, $page);
    }

    public function test_awards_extended_together_share_one_row_and_a_finished_one_has_none(): void
    {
        $cycle = $this->seedCycle();
        $prev = (string) $cycle->voting_close;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 232, 'slug' => 'second-award', 'title' => 'Second Award', 'is_active' => 1, 'sort_order' => 2]);
        DB::table('gates_award_cycles')->insert(['id' => 232, 'programme_id' => 232, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => self::at('-30 days'), 'voting_close' => $prev]);
        $this->post($prev, self::at('+9 days'), ['also' => ['232']]);

        $rows = VotingExtension::rows();
        $this->assertCount(1, $rows, 'one extension over two awards stacked two banners');
        $this->assertSame(['Extension Award', 'Second Award'], $rows[0]['awards']);
        $this->assertSame('2 awards', $rows[0]['which'], 'the closed row names a count, the names are inside');

        // A third award recorded later, with a different paid cutoff, joins the same row
        // rather than starting a banner of its own; its cutoff is stated by name.
        DB::table('gates_settings')->insert(['key_name' => 'paid_voting_enabled', 'value' => '1']);
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 233, 'slug' => 'third-award', 'title' => 'Third Award', 'is_active' => 1, 'sort_order' => 3]);
        DB::table('gates_award_cycles')->insert(['id' => 233, 'programme_id' => 233, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => self::at('-30 days'), 'voting_close' => self::at('+9 days')]);
        $third = DB::table('gates_award_cycles')->where('id', 233)->first();
        $this->assertTrue(VotingExtension::extend($third, ['previous_close' => DisplayTime::forInput(self::at('-2 hours')),
            'new_close' => DisplayTime::forInput((string) $third->voting_close), 'reason' => self::REASON, 'stop_paid' => true], 1)['ok']);
        $rows = VotingExtension::rows();
        $this->assertCount(1, $rows, 'a different paid cutoff split one announcement into two banners');
        $this->assertCount(3, $rows[0]['awards']);
        $this->assertStringContainsString('Third Award: Paid voting', implode(' ', $rows[0]['paid']));

        // Once the extended voting is over, there is nothing left to announce.
        $this->assertSame([], VotingExtension::rows(\Illuminate\Support\Carbon::now()->addDays(10)));
    }

    public function test_an_edition_without_an_extension_has_no_notice(): void
    {
        $this->seedCycle();
        $this->assertNull(VotingExtension::notice(self::CYCLE));
        $this->assertNull(VotingExtension::paidCutoffForCategory(self::CAT));
    }

    // ══ 5. the announcement says what it means, once, on every award it applies to ═══════

    /** A close at 00:00 is the end of the day before, and the card says so (owner, 20 Nov 2026). */
    public function test_a_midnight_close_reads_as_the_end_of_the_day_before(): void
    {
        $zone = new \DateTimeZone(DisplayTime::zone());
        $mid = static fn (string $d): string => (new \DateTimeImmutable($d . ' 00:00:00', $zone))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $prev = $mid(date('Y-m-d', strtotime('+1 day')));
        $new  = $mid(date('Y-m-d', strtotime('+10 days')));
        $this->seedCycle(['voting_close' => $prev]);
        $this->post($prev, $new);

        $n = VotingExtension::notice(self::CYCLE);
        $dayBefore = (new \DateTimeImmutable(date('Y-m-d', strtotime('+9 days')), $zone))->format('D j M Y');
        $this->assertSame('the end of ' . $dayBefore . ' (midnight ' . DisplayTime::abbr() . ')', $n['to'],
            'a midnight close named the next day, promising a day of voting that does not exist');
        $this->assertStringStartsWith('the end of ', $n['from']);
        $this->assertStringNotContainsString('00:00', $n['to'] . $n['from']);
        // Any other time is written as before.
        $this->assertSame(DisplayTime::showZoned(self::at('+1 day 14:30')), DisplayTime::showClose(self::at('+1 day 14:30')));
    }

    public function test_the_card_does_not_repeat_what_the_reason_already_says(): void
    {
        $this->assertSame([], VotingExtension::ticks('Several nominees heard late. Voting is extended for every nominee '
            . 'in every category equally. Every vote already cast stands, and the rules and prices are unchanged.'),
            'every tick restated the operator\'s own words directly beneath them');
        $this->assertSame(['Prices unchanged'], VotingExtension::ticks('Every vote already cast stands, the rules are unchanged, every nominee equally.'),
            'rules said, prices not: the prices still have to be said');
        $this->assertSame(['Votes already cast stand', 'Rules and prices unchanged', 'Same date for every nominee'],
            VotingExtension::ticks(self::REASON), 'a reason that says none of it gets all three');
    }

    /** Moving the close later on the edition form once voting has opened is refused (ABA and ACA, live). */
    public function test_the_edition_form_cannot_extend_voting_silently(): void
    {
        $cycle = $this->seedCycle();
        $save = function (string $closeStored) use ($cycle) {
            $builder = new \DI\ContainerBuilder();
            $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
            $c = $builder->build()->get(\AfricaGates\Admin\Controllers\ProgrammesController::class);
            $body = ['_token' => 'tok', 'cycle_id' => self::CYCLE, 'year' => $cycle->year, 'status' => 'voting',
                     'voting_open' => DisplayTime::forInput((string) $cycle->voting_open), 'voting_close' => DisplayTime::forInput($closeStored)];
            $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/programmes/' . self::PROG . '/cycle')->withParsedBody($body);
            unset($_SESSION['flash_error'], $_SESSION['flash_ok']);
            return $c->cycleSave($req, new Response(), ['id' => (string) self::PROG]);
        };
        $before = $this->close();
        $res = $save(self::at('+9 days'));
        $this->assertSame($before, $this->close(), 'the close moved later with no extension on record');
        $this->assertStringContainsString('Extend voting', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertStringContainsString('#extend', $res->getHeaderLine('Location'));
        $this->assertSame([], $this->rows());

        // An earlier close is not an extension, and stays the operator's to set.
        $save(self::at('+12 hours'));
        $this->assertNotSame($before, $this->close());
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    /** One extension, applied to other awards' editions — moved, or recorded where already moved. */
    public function test_one_extension_can_cover_other_awards_and_each_gets_its_notice(): void
    {
        $cycle = $this->seedCycle();
        $prev = (string) $cycle->voting_close;
        $new  = self::at('+9 days');
        // Two other awards, live: one still on the old close, one already moved by editing the date.
        foreach ([[232, 'second-award', $prev], [233, 'third-award', $new]] as [$id, $slug, $close]) {
            DB::table('gates_award_programmes')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'title' => ucfirst($slug), 'is_active' => 1, 'sort_order' => 2]);
            DB::table('gates_award_cycles')->insert(['id' => $id, 'programme_id' => $id, 'year' => (int) date('Y'), 'status' => 'voting',
                'voting_open' => self::at('-30 days'), 'voting_close' => $close]);
        }
        $ids = array_column(VotingExtension::companions(self::CYCLE), 'id');
        sort($ids);
        $this->assertSame([232, 233], $ids, 'a live edition of another award was not offered');

        $this->post($prev, $new, ['also' => ['232', '233', '999']]);

        foreach ([self::CYCLE, 232, 233] as $id) {
            $this->assertNotNull(VotingExtension::notice($id), "edition $id has no public notice");
            $this->assertSame(
                \Illuminate\Support\Carbon::parse($new)->toDateTimeString(),
                \Illuminate\Support\Carbon::parse((string) DB::table('gates_award_cycles')->where('id', $id)->value('voting_close'))->toDateTimeString());
        }
        $this->assertSame(VotingExtension::MODE_MOVED, VotingExtension::latest(232)->mode);
        $this->assertSame(VotingExtension::MODE_RECORDED, VotingExtension::latest(233)->mode, 'the one already moved is recorded, not moved again');
        $this->assertSame(\Illuminate\Support\Carbon::parse($prev)->toDateTimeString(),
            \Illuminate\Support\Carbon::parse((string) VotingExtension::latest(233)->previous_close)->toDateTimeString(),
            'the recorded one takes the previous close typed on the form');
        $this->assertSame(self::REASON, VotingExtension::latest(232)->reason);
        $this->assertStringContainsString('Also:', (string) ($_SESSION['flash_ok'] ?? ''));
        $this->assertNull(VotingExtension::latest(999), 'an id that is not a live edition was acted on');
    }
}
