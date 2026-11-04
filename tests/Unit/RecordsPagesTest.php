<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Recognitions;
use AfricaGates\Services\ReleasedStanding;
use AfricaGates\Services\RuleEngine;
use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\TestApp;
use Tests\TestCase;

/**
 * PHASE 6 — THE RECORDS: leaderboard, Legacy Vault, profile (owner and visitor), the registry
 * redirect. Rendered through the real router (TestApp), against real rows.
 *
 * It also re-asserts the rules the destroyed pages' guards held (inventory, "Rules held by
 * guard tests destroyed with this page"): the leaderboard and the profile render their data
 * and not their empty state (PageRenderSmokeTest), and the profile describes its index by the
 * basis it was computed on (ProfileCpiClaimTest).
 */
final class RecordsPagesTest extends TestCase
{
    private const PROG = 63;

    protected function setUp(): void
    {
        parent::setUp();
        ReleasedStanding::forget();
        SchemaHas::forget();
        OptionalColumn::forget();
        unset($_SESSION['user_id']);
        DB::table('gates_cache')->delete();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }

    private function get(string $uri): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://afg.test' . $uri);
        return TestApp::build()->handle($req);
    }

    private function profile(string $slug, string $name, int $cpi, string $basis, string $email = ''): int
    {
        return (int) DB::table('gates_profiles')->insertGetId([
            'slug' => $slug, 'display_name' => $name, 'email' => $email ?: $slug . '@x.invalid',
            'status' => 'approved', 'cpi_score' => $cpi, 'cpi_tier' => 'gold', 'cpi_basis' => $basis,
            'category' => 'Education', 'country_code' => 'NG', 'region' => 'west', 'verification_tier' => 'verified',
        ]);
    }

    /** A released, sealed edition with one decided category. Returns [cycle, winner nominee]. */
    private function released(): array
    {
        DB::table('gates_award_programmes')->insert(['id' => self::PROG, 'slug' => 'teachers', 'title' => 'Teachers Prize', 'is_active' => 1, 'scope' => 'national']);
        $cy  = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => self::PROG, 'year' => 2025, 'status' => 'results', 'results_date' => '2025-12-01 10:00:00']);
        // No panel in this fixture: a programme rule with no judge quorum, so the decided
        // category draws (the seal is what the test is about, not the quorum).
        DB::table('gates_rule_sets')->insert(['scope' => 'programme', 'scope_id' => self::PROG, 'rules' => json_encode(['min_judges_per_nominee' => 0])]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cy, 'slug' => 'stem', 'title' => 'STEM Educator']);
        $win = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Ngozi Chimamanda Adichie-Okafor', 'status' => 'winner', 'country_code' => 'NG', 'vote_count' => 3, 'organic_vote_count' => 3]);
        // One ballot row per vote (CLAUDE.md: a counter with no rows scores a different rule).
        foreach (range(1, 3) as $v) {
            DB::table('gates_votes')->insert(['nominee_id' => $win, 'category_id' => $cat, 'voter_email_hash' => hash('sha256', 'v' . $v)]);
        }
        DB::table('gates_vote_snapshots')->insert(['cycle_id' => $cy, 'nominee_id' => $win, 'vote_count' => 40, 'cpi_score' => 700,
            'capture_kind' => 'release', 'standing_rank' => 1, 'in_running' => 1, 'snapshot_at' => '2025-12-01 10:00:00', 'hash' => str_repeat('a1', 32)]);
        return [$cy, $win];
    }

    // ── Leaderboard ──────────────────────────────────────────────────────────

    public function test_the_leaderboard_renders_its_ranking_and_not_the_empty_state(): void
    {
        foreach (range(1, 6) as $i) $this->profile('p' . $i, 'Profile Number ' . $i, 900 - $i * 10, 'judged');
        $res = $this->get('/leaderboard');
        $html = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Profile Number 1', $html, 'the top profile is on the podium');
        $this->assertStringContainsString('Profile Number 6', $html);
        $this->assertStringNotContainsString('No ranking yet', $html);
        // Movement is said in words in every row's aria-label (§8.19).
        $this->assertMatchesRegularExpression('/aria-label="Rank 4: Profile Number 4[^"]*(no earlier standing|since the last update)"/', $html);
        // The split is the rule engine's, not typed.
        $w = (new RuleEngine())->weights();
        $this->assertStringContainsString('<b>' . (int) round($w['community'] * 100) . '%</b>', $html);
    }

    public function test_an_empty_board_says_so_minimally(): void
    {
        $html = (string) $this->get('/leaderboard')->getBody();
        $this->assertStringContainsString('No ranking yet', $html);
        $this->assertStringContainsString('Nominate someone', $html);
        $this->assertStringNotContainsString('lb-pod__c', $html, 'no ghost podium (§8.19)');
    }

    public function test_leaderboard_names_wrap_and_never_truncate(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/leaderboard.css');
        $this->assertDoesNotMatchRegularExpression('/text-overflow\s*:\s*ellipsis|-webkit-line-clamp/', $css);
        foreach (['.lb-row__n', '.lb-pod__n'] as $sel) {
            $this->assertMatchesRegularExpression('/' . preg_quote($sel, '/') . '\{[^}]*overflow-wrap:anywhere/', $css, $sel . ' must wrap');
        }
    }

    // ── Legacy Vault ─────────────────────────────────────────────────────────

    public function test_the_vault_lists_an_announced_edition_and_draws_its_sealed_winner(): void
    {
        [$cy] = $this->released();
        $index = (string) $this->get('/legacy')->getBody();
        $this->assertStringContainsString('Teachers Prize', $index);
        $this->assertStringNotContainsString('The vault opens after the first edition', $index);

        $res = $this->get('/legacy/teachers-2025');
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Ngozi Chimamanda Adichie-Okafor', $html, 'the winner, from the seal');
        $this->assertStringContainsString('Overall winner of the edition', $html);
        $this->assertStringContainsString('A1A1·A1A1', $html, 'the seal fingerprint');
        $this->assertSame(404, $this->get('/legacy/nothing-here-1999')->getStatusCode());
    }

    public function test_an_unannounced_or_sandbox_edition_is_not_in_the_vault(): void
    {
        [$cy] = $this->released();
        DB::table('gates_award_cycles')->where('id', $cy)->update(['status' => 'judging']);
        $this->assertStringContainsString('The vault opens after the first edition', (string) $this->get('/legacy')->getBody());
        $this->assertSame(404, $this->get('/legacy/teachers-2025')->getStatusCode());

        DB::table('gates_award_cycles')->where('id', $cy)->update(['status' => 'results']);
        DB::table('gates_award_programmes')->where('id', self::PROG)->update(['is_active' => 0]);
        $this->assertSame(404, $this->get('/legacy/teachers-2025')->getStatusCode(), 'an inactive programme (the sandbox) is not archived publicly');
    }

    // ── Registry → Discover, and the profile ─────────────────────────────────

    public function test_the_registry_index_is_a_permanent_redirect_to_discover_keeping_its_query(): void
    {
        $res = $this->get('/registry?q=ngozi&page=3');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/discover?tab=people&q=ngozi', $res->getHeaderLine('Location'));

        $this->profile('ada-obi', 'Ada Obi', 500, 'baseline');
        $one = $this->get('/registry?slug=ada-obi');
        $this->assertSame(301, $one->getStatusCode());
        $this->assertSame('/registry/ada-obi', $one->getHeaderLine('Location'), 'a URL naming one profile goes to it');
        $this->assertSame('/discover?tab=people', $this->get('/profiles')->getHeaderLine('Location'), 'no chain through /registry');
    }

    /** The three bases, each described as itself (ProfileCpiClaimTest, restored). */
    public function test_the_profile_describes_its_index_by_the_basis_it_was_computed_on(): void
    {
        $this->profile('judged-one', 'Judged One', 640, 'judged');
        $this->profile('pending-one', 'Pending One', 300, 'pending');
        $this->profile('baseline-one', 'Baseline One', 300, 'baseline');
        $w = (new RuleEngine())->weights();

        $j = (string) $this->get('/registry/judged-one')->getBody();
        $this->assertStringContainsString('independent judge panel (' . (int) round($w['judge'] * 100) . '%)', $j, 'a judged profile is described as judged, with the rules\' split');

        $p = (string) $this->get('/registry/pending-one')->getBody();
        $this->assertStringContainsString('no panel has finished judging yet', $p, 'pending is not "never nominated"');
        $this->assertStringNotContainsString('independent judge panel (', $p);

        $b = (string) $this->get('/registry/baseline-one')->getBody();
        $this->assertStringContainsString('Not yet nominated, so no jury has scored this profile', $b, 'never credited to a jury');
        $this->assertStringNotContainsString('independent judge panel (', $b);
    }

    public function test_owner_and_visitor_views_and_recognition_from_a_verified_issuer(): void
    {
        [$cy, $win] = $this->released();
        $pid = $this->profile('ngozi', 'Ngozi Chimamanda Adichie-Okafor', 700, 'judged', 'ngozi@x.invalid');
        DB::table('gates_nominees')->where('id', $win)->update(['profile_id' => $pid]);
        Recognitions::syncCycle($cy);

        $v = (string) $this->get('/registry/ngozi')->getBody();
        $this->assertStringContainsString('verified issuer', $v);
        $this->assertStringContainsString('STEM Educator', $v);
        $this->assertStringContainsString('/registry/ngozi/follow', $v, 'a visitor may follow');
        $this->assertStringNotContainsString('Edit profile', $v);

        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Ngozi', 'email' => 'ngozi@x.invalid', 'status' => 'active', 'email_verified' => 1]);
        $_SESSION['user_id'] = $uid;
        $o = (string) $this->get('/registry/ngozi')->getBody();
        $this->assertStringContainsString('Edit profile', $o, 'the owner sees Edit profile');
        $this->assertStringNotContainsString('/registry/ngozi/follow', $o);

        // An unverified account on the same address is NOT the owner.
        DB::table('gates_users')->where('id', $uid)->update(['email_verified' => 0]);
        $this->assertStringNotContainsString('Edit profile', (string) $this->get('/registry/ngozi')->getBody());
    }

    /**
     * EVIDENCE IS LOCKED (Phase 6 "Done when"): no route anywhere edits or deletes a reviewed
     * item, and no writer in src/ can delete or update a reviewed row. The only delete the
     * sweep accepts is the sandbox's own teardown (DemoSeeder) and the questionnaire's, which
     * is scoped to `verified = 0` — and that scope is asserted, not trusted.
     * Proven failing: removing `->where('verified', 0)` from QuestionnaireService made this
     * test name the file (docs/handoff/PHASE-6.md).
     */
    public function test_evidence_has_no_edit_or_delete_path(): void
    {
        $app = TestApp::build();
        $bad = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            $write = array_diff($r->getMethods(), ['GET', 'HEAD', 'OPTIONS']);
            if ($write !== [] && preg_match('~evidence~i', $r->getPattern()) && !str_contains($r->getPattern(), '/payments/disputes')) {
                $bad[] = implode(',', $write) . ' ' . $r->getPattern();
            }
        }
        $this->assertSame([], $bad, 'a write route on evidence');

        $root = dirname(__DIR__, 2) . '/src';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $body = (string) file_get_contents($f->getPathname());
            if (!preg_match_all('/[\'"]gates_nominee_evidence[\'"]\)([^;]*);/s', $body, $m)) continue;
            foreach ($m[1] as $stmt) {
                if (!preg_match('/->(update|delete|increment|upsert)\s*\(/', $stmt)) continue;
                $name = substr($f->getPathname(), strlen($root) + 1);
                if ($name === 'Services/DemoSeeder.php') continue;   // the sandbox purging its own rows
                if (preg_match("/->where\('verified',\s*0\)/", $stmt)) continue;
                $found[] = $name;
            }
        }
        $this->assertSame([], $found, 'a writer that can change or remove reviewed evidence');

        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/profile.twig');
        $this->assertDoesNotMatchRegularExpression('~action="[^"]*evidence~i', $tpl);
    }
}
