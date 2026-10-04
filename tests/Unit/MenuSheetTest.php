<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MenuShortcuts;
use AfricaGates\Services\SystemStatus;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The phone Menu (REFERENCE §7.4), destroyed and rebuilt on 4 Oct 2026 with detents, drag
 * and most-used tiles (GAPS §8e, docs/handoff/MENU-SHEET.md). Rebuilt with its rules:
 *
 *  · What it SAYS (kept from Phase 2): §7.4's Explore list; Status says what was measured or
 *    nothing; Cookies is the consent hook and Sign out a POST.
 *  · The MOST-USED rule (Services\MenuShortcuts): warm-up, qualification, decay, a
 *    deterministic tie-break, and never a destination the visitor cannot open — asked of the
 *    real router, as each audience, not of the catalogue's own word.
 *  · ONE RULE, TWO IMPLEMENTATIONS: a guest's tiles are ranked by menu-sheet.js, so the JS
 *    `Rank` is run under Node against the PHP on sampled histories.
 *  · The beacon that counts an open is the member's own and nobody else's.
 *  · The Menu's code is its own: nothing of it is left inside the shared chrome files.
 *
 * The gesture half (detents, drag, hand-over, rubber band, scrim) is browser behaviour and
 * is measured in Chromium with touch emulation — MENU-SHEET.md §5 has every scenario's row.
 */
final class MenuSheetTest extends TestCase
{
    private const UID = 4102;
    private const MEMBER = ['user_id' => self::UID, 'user_name' => 'Chioma Obi'];
    private const NOW = 1_790_000_000;
    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaHas::forget();
        DB::table('gates_users')->insert([
            'id' => self::UID, 'name' => 'Chioma Obi', 'email' => 'chioma.menu@example.test',
            'status' => 'active', 'email_verified' => 1, 'created_at' => '2026-10-03 09:00:00',
        ]);
    }

    private function menu(array $session = []): string
    {
        $html = ChromeRender::html('/_dev/ui', $session);
        $at = (int) strpos($html, 'data-ag-menu-sheet');
        $this->assertGreaterThan(0, $at, 'the Menu is not on the page');

        return substr($html, $at, (int) strpos($html, 'data-ag-quick-sheet', $at) - $at);
    }

    /** The menu without its guest `<template>`: what is drawn, not what may be cloned. */
    private function drawn(array $session = []): string
    {
        return (string) preg_replace('~<template\b.*?</template>~s', '', $this->menu($session));
    }

    /** @return list<string> */
    private function tiles(string $menu): array
    {
        $nav = substr($menu, (int) strpos($menu, 'data-ag-menu-tiles'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
        preg_match_all('~class="ag-menu__tile" href="[^"]+" data-ag-dest="([a-z]+)"~', $nav, $m);
        return $m[1];
    }

    /** @return list<string> */
    private function explore(string $menu): array
    {
        $ex = substr($menu, (int) strpos($menu, 'id="ag-menu-h-explore"'));
        $ex = substr($ex, 0, (int) strpos($ex, '</section>'));
        preg_match_all('~<a class="ag-list__row" href="[^"]+" data-ag-dest="([a-z]+)"~', $ex, $m);
        return $m[1];
    }

    private function history(array $d, int $n): array
    {
        return ['n' => $n, 'd' => $d];
    }

    // ══ What it says (Phase 2's rules, carried) ═══════════════════════════════

    public function test_the_explore_list_is_the_phase_files_seven_and_a_member_is_not_offered_register(): void
    {
        $this->assertSame(['discover', 'pulse', 'giving', 'shop', 'legacy', 'blog', 'register'], $this->explore($this->drawn()));
        // /account/register sends a signed-in member to /account: a row that goes somewhere
        // other than where it says. Asked of the router below; here, that it is not drawn.
        $this->assertSame(['discover', 'pulse', 'giving', 'shop', 'legacy', 'blog'], $this->explore($this->drawn(self::MEMBER)));
        $this->assertStringNotContainsString('/account/register', $this->menu(self::MEMBER));
    }

    public function test_status_says_the_recorded_state_and_nothing_when_it_is_stale(): void
    {
        $this->assertStringNotContainsString('ag-menu__live', $this->menu(), 'no record, yet a state was drawn');

        DB::table('gates_status_log')->insert([
            'taken_at' => Carbon::now()->subMinutes(5)->toDateTimeString(),
            'overall' => SystemStatus::OK, 'components_json' => '[]',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->assertMatchesRegularExpression('~href="/status" data-ag-dest="status">.*?ag-menu__live--operational"><span class="ag-menu__dot" aria-hidden="true"></span>Working</span>~s',
            $this->menu(), 'a fresh record of "operational" must read "Working", in words, on the Status row');

        DB::table('gates_status_log')->update(['taken_at' => Carbon::now()->subHours(3)->toDateTimeString()]);
        $this->assertStringNotContainsString('ag-menu__live', $this->menu(),
            'a three-hour-old "Working" is the cached all-clear that outlives an outage');
    }

    public function test_cookies_is_the_consent_hook_and_sign_out_is_a_post(): void
    {
        $this->assertMatchesRegularExpression('~<a href="/cookies#choices" data-ag-do="consent-open">~', $this->menu());

        $in = $this->menu(self::MEMBER);
        $this->assertMatchesRegularExpression('~<form method="post" action="/account/logout"[^>]*>\s*<input type="hidden" name="_token" value="test-token">~', $in);
        $this->assertStringNotContainsString('Sign out', $this->menu(), 'a signed-out visitor is offered Sign out');
        $this->assertStringContainsString('href="/account#notifications"', $in);
        $this->assertStringNotContainsString('/account#notifications', $this->menu(), 'a guest is offered a member\'s settings');
    }

    public function test_the_display_sub_view_and_the_grabber_control_are_drawn(): void
    {
        $m = $this->menu();
        $this->assertStringContainsString("data-ag-menu-view=\"display\" hidden", $m);
        $this->assertMatchesRegularExpression('~<button type="button" class="ag-menu__grab" data-ag-menu-grab aria-label="Expand menu">~', $m,
            'WCAG 2.5.7: the drag between heights needs a single-pointer control');
        $this->assertMatchesRegularExpression('~data-ag-menu-back aria-label="Back to menu" hidden>~', $m,
            'a visibility-hidden back button is still in the shell\'s focus list, and focusing it fails silently');
    }

    // ══ The four squares ═════════════════════════════════════════════════════

    public function test_with_no_history_the_squares_are_the_participate_four(): void
    {
        foreach ([[], self::MEMBER] as $who) {
            $m = $this->drawn($who);
            $this->assertSame(MenuShortcuts::DEFAULTS, $this->tiles($m));
            $this->assertStringContainsString('aria-label="Participate"', $m);
        }
    }

    public function test_a_members_most_used_are_drawn_and_a_pushed_out_default_heads_explore(): void
    {
        $now = time();
        $h = $this->history([
            'shop'   => [6.0, $now - self::DAY],
            'status' => [3.0, $now - 2 * self::DAY],
            'vote'   => [2.0, $now - self::DAY],
            'blog'   => [2.5, $now - 3 * self::DAY],
            'pulse'  => [1.0, $now],   // under the bar: one stray tap is not a habit
        ], 14);
        DB::table('gates_users')->where('id', self::UID)->update(['menu_use_json' => json_encode($h)]);

        $m = $this->drawn(self::MEMBER);
        $this->assertSame(['shop', 'status', 'blog', 'vote'], $this->tiles($m));
        $this->assertStringContainsString('aria-label="Most used"', $m);
        // Nominate, Awards and Events appear nowhere else in the Menu: pushed out of the
        // squares, they head Explore, in the Participate order.
        $this->assertSame(['nominate', 'awards', 'events', 'discover', 'pulse', 'giving', 'shop', 'legacy', 'blog'], $this->explore($m));

        // And nothing the member may open has left the Menu.
        preg_match_all('~data-ag-dest="([a-z]+)"~', $m, $all);
        foreach (array_keys(MenuShortcuts::DESTINATIONS) as $k) {
            if (MenuShortcuts::opens($k, true)) $this->assertContains($k, $all[1], "$k became unreachable from the Menu");
        }
    }

    // ══ The ranking rule ═════════════════════════════════════════════════════

    public function test_nothing_is_personalised_before_the_warm_up(): void
    {
        $h = $this->history(['shop' => [4.0, self::NOW]], MenuShortcuts::WARMUP - 1);
        $this->assertSame(['keys' => MenuShortcuts::DEFAULTS, 'personal' => false], MenuShortcuts::rank($h, false, self::NOW));

        $h['n'] = MenuShortcuts::WARMUP;
        $this->assertSame(['keys' => ['shop', 'nominate', 'vote', 'awards'], 'personal' => true], MenuShortcuts::rank($h, false, self::NOW));
    }

    public function test_frequency_and_recency_both_count_and_an_old_habit_decays_out(): void
    {
        $H = MenuShortcuts::HALF_LIFE_DAYS * self::DAY;
        // Frequent but a month old (5 → 1.25) loses to a little, lately (2).
        $h = $this->history(['shop' => [5.0, self::NOW - 2 * $H], 'blog' => [2.0, self::NOW]], 20);
        $this->assertSame(['blog', 'nominate', 'vote', 'awards'], MenuShortcuts::rank($h, false, self::NOW)['keys']);
        // Recent and frequent beats recent alone.
        $h = $this->history(['shop' => [5.0, self::NOW - self::DAY], 'blog' => [2.0, self::NOW]], 20);
        $this->assertSame(['shop', 'blog'], array_slice(MenuShortcuts::rank($h, false, self::NOW)['keys'], 0, 2));
        // Gone quiet for long enough, it falls out and the defaults come back.
        $h = $this->history(['shop' => [5.0, self::NOW - 4 * $H]], 20);
        $this->assertSame(['keys' => MenuShortcuts::DEFAULTS, 'personal' => false], MenuShortcuts::rank($h, false, self::NOW));
    }

    public function test_record_decays_the_stored_count_before_adding_the_open(): void
    {
        $H = MenuShortcuts::HALF_LIFE_DAYS * self::DAY;
        $h = MenuShortcuts::record($this->history(['shop' => [4.0, self::NOW - $H]], 9), 'shop', self::NOW);
        $this->assertSame(['n' => 10, 'd' => ['shop' => [3.0, self::NOW]]], $h, '4 halved by one half-life, plus this open');
        $this->assertSame($h, MenuShortcuts::record($h, 'nowhere', self::NOW), 'an unknown key changes nothing');
    }

    public function test_the_tie_break_is_score_then_recency_then_the_menus_own_order(): void
    {
        $H = MenuShortcuts::HALF_LIFE_DAYS * self::DAY;
        $h = $this->history([
            'status' => [2.0, self::NOW], 'blog' => [2.0, self::NOW], 'shop' => [2.0, self::NOW],
            // 4 a half-life ago is EXACTLY 2 now: the same score, opened longer ago. Pulse sits
            // before all three in the catalogue, so only recency puts it last.
            'pulse'  => [4.0, self::NOW - $H],
        ], 20);
        // Equal scores AND times fall back to the catalogue: shop, blog, status.
        $this->assertSame(['shop', 'blog', 'status', 'pulse'], MenuShortcuts::rank($h, false, self::NOW)['keys']);
        // And it is a function: the same history, the same four, every time.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(['shop', 'blog', 'status', 'pulse'], MenuShortcuts::rank(array_reverse($h, true), false, self::NOW)['keys']);
        }
    }

    public function test_a_shortcut_is_never_one_the_visitor_cannot_open(): void
    {
        $h = $this->history(['register' => [9.0, self::NOW], 'notifications' => [8.0, self::NOW], 'shop' => [2.0, self::NOW]], 30);
        $this->assertSame(['register', 'shop', 'nominate', 'vote'], MenuShortcuts::rank($h, false, self::NOW)['keys']);
        $this->assertSame(['notifications', 'shop', 'nominate', 'vote'], MenuShortcuts::rank($h, true, self::NOW)['keys']);

        $this->assertFalse(MenuShortcuts::recordFor(self::UID, 'register'), 'a member must not earn a guest-only shortcut');
        $this->assertFalse(MenuShortcuts::recordFor(self::UID, 'admin'), 'nor anything outside the catalogue');
    }

    public function test_a_stored_document_is_normalised_on_the_way_out(): void
    {
        $doc = ['n' => '7', 'd' => [
            'shop' => [3.25, self::NOW + 9999],       // a clock ahead would decay UPWARDS
            'blog' => [-1, self::NOW],                // nonsense
            'evil' => [99, self::NOW],                // not ours
            'vote' => ['x', self::NOW],
        ]];
        $this->assertSame(['n' => 7, 'd' => ['shop' => [3.25, self::NOW]]], MenuShortcuts::normalise($doc, self::NOW));
        $this->assertSame(['n' => 0, 'd' => []], MenuShortcuts::normalise('{"n":', self::NOW));

        // Bounded by the catalogue, so the column cannot overflow (VARCHAR(1024) on MySQL).
        $full = ['n' => 1_000_000, 'd' => []];
        foreach (array_keys(MenuShortcuts::DESTINATIONS) as $k) $full['d'][$k] = [9999.1234, self::NOW];
        $this->assertLessThan(1024, strlen((string) json_encode(MenuShortcuts::normalise($full, self::NOW))));
    }

    // ══ The routes behind every destination ══════════════════════════════════

    /**
     * Asked of the router that serves, as each audience — not of `who`, which is the claim.
     * A destination a visitor may open must not bounce them anywhere; one they may not must,
     * which is what proves the label is true. A 500 is a page awaiting its phase (every
     * public page was destroyed on 3 Oct 2026), not an access rule, and is reported apart.
     */
    public function test_every_destination_opens_for_exactly_the_audience_it_claims(): void
    {
        $bad = [];
        foreach (MenuShortcuts::DESTINATIONS as $key => $d) {
            $path = (string) parse_url($d['href'], PHP_URL_PATH);
            foreach (['guest' => [], 'member' => self::MEMBER] as $who => $session) {
                $res  = ChromeRender::page($path, $session);
                $code = $res->getStatusCode();
                $to   = (string) parse_url($res->getHeaderLine('Location'), PHP_URL_PATH);
                $may  = MenuShortcuts::opens($key, $who === 'member');
                if ($code === 404 || $code === 405) { $bad[] = "$key: $path is not a GET route ($code)"; continue; }
                $bounced = $code >= 300 && $code < 400 && $to !== $path;
                if ($may && $bounced) $bad[] = "$key: offered to a $who, and $path sends them to $to";
                if (!$may && !$bounced) $bad[] = "$key: withheld from a $who, but $path serves them ($code) — the label is wrong";
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    // ══ One rule in two languages ════════════════════════════════════════════

    public function test_the_browser_ranks_a_guest_exactly_as_the_server_would(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') $this->markTestSkipped('no Node.js on this machine to run menu-sheet.js');

        $keys = array_keys(MenuShortcuts::DESTINATIONS);
        mt_srand(20261004);
        $cases = [];
        for ($i = 0; $i < 400; $i++) {
            $member = (bool) mt_rand(0, 1);
            $d = [];
            foreach ($keys as $k) {
                if (mt_rand(0, 2) === 0) continue;
                // Ties on purpose: a few shared scores and times.
                $d[$k] = [[1.5, 2.0, 3.0, 0.4, mt_rand(1, 900) / 100][mt_rand(0, 4)],
                          self::NOW - [0, 60, self::DAY, 20 * self::DAY, mt_rand(0, 90) * self::DAY][mt_rand(0, 4)]];
            }
            $h = ['n' => mt_rand(0, 12), 'd' => $d];
            $p = MenuShortcuts::params($member) + ['all' => $keys];
            $op = $keys[mt_rand(0, count($keys) - 1)];
            $cases[] = ['h' => $h, 'p' => $p, 'op' => $op,
                        'want' => MenuShortcuts::rank($h, $member, self::NOW),
                        'rec'  => MenuShortcuts::record($h, $op, self::NOW)];
        }

        // And the ties that decide nothing in a random sample: the same score reached by a
        // larger count opened a half-life earlier, and equal scores at equal times.
        $H = MenuShortcuts::HALF_LIFE_DAYS * self::DAY;
        foreach ([true, false] as $member) {
            $h = ['n' => 20, 'd' => ['status' => [2.0, self::NOW], 'blog' => [2.0, self::NOW],
                                     'shop' => [2.0, self::NOW], 'pulse' => [4.0, self::NOW - $H]]];
            $cases[] = ['h' => $h, 'p' => MenuShortcuts::params($member) + ['all' => $keys], 'op' => 'pulse',
                        'want' => MenuShortcuts::rank($h, $member, self::NOW),
                        'rec'  => MenuShortcuts::record($h, 'pulse', self::NOW)];
        }

        $js  = realpath(__DIR__ . '/../../public/assets/js/menu-sheet.js');
        $src = 'const R=require(' . json_encode($js) . ');const C=JSON.parse(require("fs").readFileSync(0,"utf8"));'
             . 'const out=C.map(c=>({rank:R.rank(c.h,' . self::NOW . ',c.p),rec:R.record(c.h,c.op,' . self::NOW . ',c.p)}));'
             . 'process.stdout.write(JSON.stringify(out));';
        $proc = proc_open([$node, '-e', $src], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        fwrite($pipes[0], (string) json_encode($cases));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $got = json_decode((string) $out, true);
        $this->assertIsArray($got, "menu-sheet.js did not run under Node: $err");

        $diff = [];
        foreach ($cases as $i => $c) {
            if ($got[$i]['rank'] !== $c['want']) {
                $diff[] = "case $i rank: PHP " . json_encode($c['want']) . ' JS ' . json_encode($got[$i]['rank']);
            }
            // JSON has no float/int distinction for 3.0; compare as numbers.
            if (json_encode($got[$i]['rec']) !== json_encode(json_decode((string) json_encode($c['rec']), true))
                && $got[$i]['rec'] != $c['rec']) {
                $diff[] = "case $i record: PHP " . json_encode($c['rec']) . ' JS ' . json_encode($got[$i]['rec']);
            }
        }
        $this->assertSame([], array_slice($diff, 0, 5), count($diff) . ' disagreements between MenuShortcuts and menu-sheet.js');
    }

    public function test_the_script_types_no_ranking_number_and_stores_nothing_without_preferences(): void
    {
        $js = ChromeRender::code('public/assets/js/menu-sheet.js');
        $rank = substr($js, (int) strpos($js, 'var Rank'), (int) strpos($js, 'module.exports') - (int) strpos($js, 'var Rank'));
        foreach (['1209600', '14', '1.5', 'warmup: ', "'nominate'"] as $typed) {
            $this->assertStringNotContainsString($typed, $rank, "the ranking half types $typed — it must come from data-ag-menu-params");
        }
        // The guest write sits behind the Preferences answer, and a member's count is a
        // beacon carrying the token in the body (a beacon cannot set a header).
        $this->assertMatchesRegularExpression('~if \(!keep\) return;\s*try \{ window\.localStorage\.setItem\(STORE~', $js);
        $this->assertMatchesRegularExpression("~fd\.append\('_token'.*?navigator\.sendBeacon\('/account/menu-use', fd\)~s", $js);
    }

    /**
     * Owner, 4 Oct 2026: "the open height varies". The medium detent is resolved from the
     * layout inside a 45–70% band — never a fixed fraction. The geometry itself is browser
     * behaviour, measured on three phones × two audiences × two text sizes (MENU-SHEET.md §5);
     * this holds the rule's shape so a "simplification" back to one number fails here.
     */
    public function test_the_open_height_is_resolved_from_the_content_inside_the_owners_band(): void
    {
        $js = ChromeRender::code('public/assets/js/menu-sheet.js');
        $this->assertMatchesRegularExpression('~var LOW\s*=\s*0\.45;~', $js);
        $this->assertMatchesRegularExpression('~var HIGH\s*=\s*0\.70;~', $js);
        $this->assertMatchesRegularExpression('~var medH = openHeight\(V, fullH\);~', $js,
            'the medium detent must come from openHeight(), which reads the layout');
        // The squares are what the open height must show whole.
        $this->assertStringContainsString("querySelector('[data-ag-menu-tiles]')", $js);
        $this->assertDoesNotMatchRegularExpression('~Math\.round\(V \* (?!LOW|HIGH)~', $js, 'a fixed fraction of the viewport is back');
    }

    // ══ The beacon ═══════════════════════════════════════════════════════════

    public function test_the_beacon_counts_the_sessions_member_and_nobody_else(): void
    {
        $post = fn (array $session, array $body) => ChromeRender::page('/account/menu-use', $session, [], 'POST', $body);

        $this->assertSame(204, $post(self::MEMBER, ['d' => 'shop', 'user_id' => 1])->getStatusCode());
        $doc = json_decode((string) DB::table('gates_users')->where('id', self::UID)->value('menu_use_json'), true);
        $this->assertSame(1, $doc['n']);
        $this->assertSame(1.0, (float) $doc['d']['shop'][0]);

        $this->assertSame(422, $post(self::MEMBER, ['d' => 'register'])->getStatusCode(), 'a guest-only destination');
        $this->assertSame(422, $post(self::MEMBER, ['d' => '../admin'])->getStatusCode());

        $guest = $post([], ['d' => 'shop']);
        $this->assertSame(302, $guest->getStatusCode(), 'a guest has no account to count on');
        $this->assertStringStartsWith('/account/login', $guest->getHeaderLine('Location'));
    }

    // ══ The Menu is its own files ════════════════════════════════════════════

    public function test_the_menus_code_lives_in_its_own_files_and_nowhere_else(): void
    {
        $this->assertStringNotContainsString('data-ag-menu-', ChromeRender::code('public/assets/js/chrome.js'),
            'the Menu was rebuilt whole in menu-sheet.js; a second controller is two answers to one sheet');
        $this->assertDoesNotMatchRegularExpression('~\.ag-menu~', ChromeRender::code('public/assets/css/components/chrome.css'));
        $this->assertStringNotContainsString('ag-menu__', ChromeRender::source('templates/partials/quick-settings.twig'),
            'Quick settings borrowed the Menu\'s avatar classes; destroying one sheet took the other\'s avatar');
    }
}
