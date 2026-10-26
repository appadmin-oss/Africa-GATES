<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\Presence;
use AfricaGates\Admin\Services\ReviewQueue;
use AfricaGates\Services\AwardService;
use AfricaGates\Services\NominationCategoryFit;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The nomination review list (AdminConsole v2, screenshot 02), 20 Oct 2026.
 *
 * Each test holds a rule the screen states or depends on: the checks are the ones the
 * desk and the Approve button run, never a parallel copy; the quick views mean what their
 * labels say; facet counts come from the list before facets apply; "Approved" holds both
 * spellings of a pass; a count never carries an ORDER BY (MySQL refuses it under
 * ONLY_FULL_GROUP_BY and SQLite does not); the bulk Approve is N single approvals; a
 * decision's `back` cannot leave the list; the export carries no contact details.
 */
final class ReviewQueueTest extends TestCase
{
    private int $cycle = 0;
    /** @var array<string,int> */
    private array $cats = [];
    /** Each nomination is a different person's unless a test says otherwise (NominationSignals). */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'rq-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        foreach (['Teacher of the Year', 'STEM Educator', 'Innovation in Teaching'] as $i => $t) {
            $this->cats[$t] = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'c' . $i . bin2hex(random_bytes(2)), 'title' => $t, 'sort_order' => $i]);
        }
        DB::table('gates_settings')->where('key_name', 'nomination_eligibility_enabled')->delete();
    }

    private function nom(string $name, array $o = [], int $evidence = 1): int
    {
        $id = (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cats['Teacher of the Year'], 'nominee_name' => $name,
            'country_code' => 'NG', 'nominator_name' => 'Bola Ade', 'nominator_email' => 'bola' . (++$this->seq) . '@school.ng',
            'reason' => 'Runs a weekend club and built the school lab.', 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        for ($i = 0; $i < $evidence; $i++) {
            DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => 'link', 'url' => "https://example.org/$id/$i", 'created_at' => date('Y-m-d H:i:s')]);
        }
        return $id;
    }

    private function row(int $id, ?AwardService $awards = null): array
    {
        foreach (ReviewQueue::build(ReviewQueue::params(['tab' => 'pending']), $awards)['rows'] as $r) if ((int) $r['id'] === $id) return $r;
        $this->fail("nomination $id is not on the list");
    }

    // ── the checks ───────────────────────────────────────────────────────────

    public function test_a_duplicate_is_matched_the_way_the_desk_matches_it_and_only_inside_one_edition(): void
    {
        $a = $this->nom('Grace Mensah');
        $b = $this->nom('  grace   MENSAH ');
        $p2 = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'rq2-' . bin2hex(random_bytes(3)), 'title' => 'Other', 'is_active' => 1]);
        $c2 = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p2, 'year' => 2026, 'status' => 'nominations']);
        $c = $this->nom('Grace Mensah', ['cycle_id' => $c2]);
        $alone = $this->nom('Tunde Bakare');

        $this->assertContains('dup', $this->row($a)['keys']);
        $this->assertSame([$a], $this->row($b)['dup_of']);
        $this->assertNotContains('dup', $this->row($c)['keys'], 'another edition is another race');
        $this->assertSame(['clear'], $this->row($alone)['keys']);
        // One rule: the desk's own duplicate banner finds the same row the list flagged.
        $desk = \AfricaGates\Services\NominationTriageService::duplicatesFor(DB::table('gates_nominations')->find($b));
        $this->assertSame([$a], array_column($desk['same_cycle'], 'id'));
    }

    public function test_evidence_counts_files_links_and_the_old_reference_columns_once_each(): void
    {
        $none = $this->nom('Fatima Bello', [], 0);
        $this->assertSame(0, $this->row($none)['evidence']);
        $this->assertContains('evidence', $this->row($none)['keys']);

        $old = $this->nom('Chidi Nwosu', ['reference_url' => 'https://example.org/same'], 0);
        DB::table('gates_nomination_evidence')->insert(['nomination_id' => $old, 'kind' => 'link', 'url' => 'https://example.org/same', 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(1, $this->row($old)['evidence'], 'the same address in both places is one piece of evidence');
        $this->assertNotContains('evidence', $this->row($old)['keys']);
    }

    public function test_the_eligibility_check_is_the_approve_gate_and_is_silent_while_the_rule_is_off(): void
    {
        $awards = new AwardService();
        $id = $this->nom('Musa Ibrahim', ['nominator_country' => 'NG', 'nominator_state' => 'Kano']);
        $this->assertNotContains('locations', $this->row($id, $awards)['keys'], 'rule off: nothing to say');

        DB::table('gates_settings')->insert(['key_name' => 'nomination_eligibility_enabled', 'value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_min_locations'], ['value' => '3']);
        $r = $this->row($id, $awards);
        $this->assertContains('locations', $r['keys']);
        $this->assertSame('Needs 2 more places', array_column($r['checks'], 't', 'key')['locations']);
        // The batch answer is the single answer Approve asks for, field for field.
        $single = $awards->nomineeEligibility('Musa Ibrahim', $this->cycle);
        $this->assertSame([$single['distinct_locations'], $single['eligible'], $single['enabled']],
            [$r['eligibility']['distinct_locations'], $r['eligibility']['eligible'], $r['eligibility']['enabled']]);
        $this->assertFalse(ReviewQueue::inView($r, 'ready'));
        $this->assertTrue(ReviewQueue::inView($r, 'check'));
    }

    public function test_a_category_is_flagged_only_when_another_reads_clearly_closer(): void
    {
        $close = $this->nom('Adaeze Okafor', ['category_id' => $this->cats['STEM Educator']]);
        $far = $this->nom('Musa Ibrahim', ['category_id' => $this->cats['STEM Educator']]);
        foreach ([[$close, 60, 70], [$far, 52, 81]] as [$n, $filed, $other]) {
            DB::table('gates_nomination_category_fit')->insert(['nomination_id' => $n, 'category_id' => $this->cats['STEM Educator'], 'fit' => $filed, 'chosen' => 1]);
            DB::table('gates_nomination_category_fit')->insert(['nomination_id' => $n, 'category_id' => $this->cats['Innovation in Teaching'], 'fit' => $other, 'chosen' => 0]);
        }
        $this->assertNull($this->row($close)['suggest'], 'ten points is inside the margin');
        $s = $this->row($far)['suggest'];
        $this->assertSame('Innovation in Teaching', $s['title']);
        $this->assertGreaterThanOrEqual(NominationCategoryFit::SUGGEST_MARGIN, $s['fit'] - $s['filed_fit']);
        $this->assertTrue(ReviewQueue::inView($this->row($far), 'move'));
        $this->assertFalse(ReviewQueue::inView($this->row($far), 'ready'));
        $this->assertTrue(ReviewQueue::inView($this->row($close), 'ready'));
    }

    public function test_no_check_claims_a_verification_this_platform_does_not_perform(): void
    {
        // The design draws "Phone unverified"; nothing here verifies a nominator's phone,
        // so the pill would be on every row and true of every nomination ever made.
        $this->nom('Grace Mensah', [], 0);
        foreach (ReviewQueue::build(ReviewQueue::params([]))['rows'] as $r) {
            foreach ($r['checks'] as $c) $this->assertStringNotContainsStringIgnoringCase('phone', $c['t']);
        }
        $this->assertArrayNotHasKey('phone', ReviewQueue::CHECKS);
    }

    // ── tabs, views, facets ──────────────────────────────────────────────────

    public function test_the_approved_tab_holds_both_spellings_of_a_pass(): void
    {
        $this->nom('A Approved', ['status' => 'approved']);
        $this->nom('B Verified', ['status' => 'verified']);
        $this->nom('C Pending');
        $m = ReviewQueue::build(ReviewQueue::params(['tab' => 'approved', 'scope' => 'c' . $this->cycle]));
        $names = array_map(static fn ($r) => $r['name'], $m['rows']);
        sort($names);
        $this->assertSame(['A Approved', 'B Verified'], $names);
        $tabs = array_column($m['tabs'], 'n', 'key');
        $this->assertSame(2, $tabs['approved']);
        $this->assertSame(1, $tabs['pending']);
    }

    public function test_facet_counts_come_from_the_list_before_facets_apply_and_a_view_with_none_is_hidden(): void
    {
        $this->nom('One', ['category_id' => $this->cats['STEM Educator']]);
        $this->nom('Two', ['category_id' => $this->cats['STEM Educator']]);
        $this->nom('Three');
        $p = ReviewQueue::params(['scope' => 'c' . $this->cycle, 'cat' => [$this->cats['Teacher of the Year']]]);
        $m = ReviewQueue::build($p);
        $this->assertSame(1, $m['total']);
        $cat = array_values(array_filter($m['facets'], static fn ($f) => $f['key'] === 'cat'))[0];
        $this->assertSame([['STEM Educator', 2], ['Teacher of the Year', 1]], array_map(static fn ($o) => [$o['label'], $o['n']], $cat['opts']),
            'sorted by count, and STEM still says 2 while it is filtered out');
        $this->assertSame([['Category', 'Teacher of the Year'], ['Scope', 'Teachers Prize · 1st Edition · 2026']],
            array_map(static fn ($c) => [$c['k'], $c['v']], array_reverse($m['chips'])));
        $keys = array_column($m['views'], 'key');
        $this->assertNotContains('dup', $keys, 'a view with nothing in it is not drawn');
        $this->assertContains('all', $keys);
        $this->assertStringContainsString('cat%5B%5D=', $cat['opts'][0]['only']);
    }

    public function test_no_count_or_group_query_carries_an_order_by(): void
    {
        // MySQL answers `SELECT COUNT(*) … ORDER BY n.id` with error 1140 under
        // ONLY_FULL_GROUP_BY; SQLite runs it. Only a query log can see the difference here.
        $this->nom('One');
        $this->nom('Two', ['status' => 'approved']);
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        ReviewQueue::build(ReviewQueue::params(['tab' => 'pending']));
        ReviewQueue::build(ReviewQueue::params(['tab' => 'approved']));
        $log = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $bad = [];
        foreach ($log as $q) {
            $sql = strtolower($q['query']);
            if ((str_contains($sql, 'count(') || str_contains($sql, 'group by')) && str_contains($sql, 'order by')) $bad[] = $q['query'];
        }
        $this->assertNotEmpty($log);
        $this->assertSame([], $bad);
    }

    public function test_links_keep_their_state_and_drop_defaults(): void
    {
        $p = ReviewQueue::params(['tab' => 'pending', 'view' => 'dup', 'cat' => ['5', '9'], 'q' => 'kano', 'page' => '3']);
        $this->assertSame('/admin/nominations/review?q=kano&cat%5B%5D=5&cat%5B%5D=9&view=dup&page=3', ReviewQueue::qs($p));
        $this->assertSame('all', ReviewQueue::params(['tab' => 'approved', 'view' => 'dup'])['view'], 'a readiness view means nothing on the record');
        $this->assertSame('pending', ReviewQueue::params(['tab' => 'bogus'])['tab']);
    }

    // ── the controller ───────────────────────────────────────────────────────

    private function controller(): \AfricaGates\Admin\Controllers\NominationsController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Admin\Controllers\NominationsController::class);
    }

    private function as(string $role = 'superadmin'): void
    {
        $id = (int) DB::table('gates_admins')->insertGetId(['name' => 'Chioma Obi', 'email' => 'c' . bin2hex(random_bytes(3)) . '@example.test', 'password_hash' => 'x', 'role' => $role]);
        $_SESSION['admin_id'] = $id; $_SESSION['admin_role'] = $role; $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/nominations/review';
    }

    public function test_the_page_draws_the_list_and_the_peek_with_its_decisions(): void
    {
        $this->as();
        $id = $this->nom('Grace Mensah');
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/nominations/review')->withQueryParams(['item' => (string) $id]);
        $html = (string) $this->controller()->queue($req, (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringContainsString('Nomination review', $html);
        $this->assertStringContainsString('data-rq-row="' . $id . '"', $html);
        $this->assertStringContainsString('action="/admin/nominations/' . $id . '/approve"', $html);
        $this->assertStringContainsString('action="/admin/nominations/' . $id . '/reject"', $html);
        $this->assertStringContainsString('name="back" value="/admin/nominations/review?item=' . $id . '"', $html);

        // The fragment the script swaps in draws the same decisions: it has no layout, so
        // a forgotten `shell` would quietly draw every peek read-only.
        $frag = (string) $this->controller()->queue($req->withQueryParams(['item' => (string) $id, 'part' => 'peek']), (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringNotContainsString('<html', $frag);
        $this->assertStringContainsString('/approve"', $frag);
    }

    public function test_a_viewer_sees_no_decision_and_no_bulk_bar(): void
    {
        $this->as('viewer');
        $id = $this->nom('Grace Mensah');
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/nominations/review')->withQueryParams(['item' => (string) $id]);
        $html = (string) $this->controller()->queue($req, (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringNotContainsString('/approve"', $html);
        $this->assertStringNotContainsString('rq-bulk"', $html);
        $this->assertStringNotContainsString('data-rq-cb', $html);
    }

    public function test_a_decision_can_only_come_back_to_the_list(): void
    {
        $m = new \ReflectionMethod(\AfricaGates\Admin\Controllers\NominationsController::class, 'reviewBack');
        $this->assertSame('/admin/nominations/review?tab=approved', $m->invoke(null, '/admin/nominations/review?tab=approved'));
        foreach (['https://evil.example/', '//evil.example', '/admin/nominations/review//evil', '/admin/users', '/\\evil.example', '/admin/nominations/reviewer'] as $bad) {
            $this->assertNull($m->invoke(null, $bad), $bad);
        }
    }

    public function test_bulk_approve_is_n_single_approvals_and_reports_what_it_refused(): void
    {
        $this->as();
        DB::table('gates_settings')->insert(['key_name' => 'nomination_eligibility_enabled', 'value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'nomination_min_locations'], ['value' => '2']);
        $ok = $this->nom('Ruth Achieng', ['nominator_country' => 'KE', 'nominator_state' => 'Nairobi']);
        $this->nom('Ruth Achieng', ['nominator_country' => 'KE', 'nominator_state' => 'Mombasa', 'status' => 'approved']);
        $short = $this->nom('Samuel Okon', ['nominator_country' => 'NG', 'nominator_state' => 'Lagos']);

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/nominations/review/bulk')
            ->withParsedBody(['do' => 'approve', 'ids' => [(string) $ok, (string) $short], 'back' => '/admin/nominations/review']);
        $res = $this->controller()->bulk($req, (new ResponseFactory())->createResponse());

        $this->assertSame('/admin/nominations/review', $res->getHeaderLine('Location'));
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', $ok)->value('status'));
        $this->assertSame('pending', DB::table('gates_nominations')->where('id', $short)->value('status'), 'the eligibility gate held');
        $this->assertStringContainsString('Samuel Okon', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertTrue(DB::table('gates_audit_log')->where('action', 'nomination.approve')->where('target_id', $ok)->exists(),
            'the same audit row a single approval writes');
    }

    public function test_the_export_follows_the_filters_and_carries_no_contact_details(): void
    {
        $this->as();
        $a = $this->nom('Adaeze Okafor', ['nominee_email' => 'adaeze@example.test', 'nominee_phone' => '+2348000000000']);
        $this->nom('Musa Ibrahim', ['category_id' => $this->cats['STEM Educator']]);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/nominations/review/export.csv')
            ->withQueryParams(['scope' => 'c' . $this->cycle, 'cat' => [(string) $this->cats['Teacher of the Year']]]);
        $csv = (string) $this->controller()->queueExport($req, (new ResponseFactory())->createResponse())->getBody();
        $this->assertStringContainsString('Adaeze Okafor', $csv);
        $this->assertStringNotContainsString('Musa Ibrahim', $csv);
        $this->assertStringNotContainsString('@example.test', $csv);
        $this->assertStringNotContainsString('+234', $csv);
        $this->assertTrue(DB::table('gates_audit_log')->where('action', 'nomination.export')->exists());
        unset($a);
    }

    // ── notes and presence ───────────────────────────────────────────────────

    public function test_a_team_note_lands_in_the_history_and_an_empty_one_is_refused(): void
    {
        $this->as();
        $id = $this->nom('Grace Mensah');
        $this->assertFalse(ReviewQueue::addNote($id, (int) $_SESSION['admin_id'], '   '));
        $this->assertTrue(ReviewQueue::addNote($id, (int) $_SESSION['admin_id'], 'Called the school — she is real.'));
        $h = ReviewQueue::peek($id)['history'];
        $notes = array_values(array_filter($h, static fn ($x) => $x['note']));
        $this->assertSame('Called the school — she is real.', $notes[0]['what']);
        $this->assertSame('Chioma Obi', $notes[0]['who']);
        $this->assertSame('submitted the nomination', $h[0]['what']);
    }

    public function test_presence_names_the_others_who_are_here_now_and_nobody_else(): void
    {
        $me = (int) DB::table('gates_admins')->insertGetId(['name' => 'Me Self', 'email' => 'm@example.test', 'password_hash' => 'x', 'role' => 'admin']);
        $tunde = (int) DB::table('gates_admins')->insertGetId(['name' => 'Tunde Adewale', 'email' => 't@example.test', 'password_hash' => 'x', 'role' => 'admin']);
        $gone = (int) DB::table('gates_admins')->insertGetId(['name' => 'Long Gone', 'email' => 'g@example.test', 'password_hash' => 'x', 'role' => 'admin']);
        Presence::touch($me, 'review', 4);
        Presence::touch($tunde, 'review', 4);
        DB::table('gates_admin_presence')->insert(['admin_id' => $gone, 'area' => 'review', 'seen_at' => date('Y-m-d H:i:s', time() - Presence::WINDOW - 5)]);
        $o = Presence::others($me, 'review');
        $this->assertSame(['Tunde A.'], array_column($o, 'short'));
        $this->assertSame(4, $o[0]['target_id']);
        $this->assertSame('TA', $o[0]['initials']);
    }
}
