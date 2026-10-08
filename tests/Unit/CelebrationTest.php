<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{Celebration, DemoSeeder, JudgeRubric};
use AfricaGates\Support\{CookieRegistry, Csp, Translator};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\{AppTwig, VerbatimAssets};
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * THE CELEBRATION: WHEN A PAGE MAY HAVE ONE, AND WHAT IT MAY NOT DO TO THE PAGE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * DESTROYED AND REBUILT FOR PHASE 3 (3 Oct 2026)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This file held `celebrate.js`'s rules until that engine and every page carrying it
 * were destroyed; its rules were written into docs/handoff/inventory/_scripts.md as
 * MUST RESTORE for whatever engine shipped next. Phase 3 shipped the handoff's engine
 * (`celebration.js`/`.css`, byte-identical), `partials/celebration.twig`,
 * `celebration-boot.js` and `Services\Celebration`, and each restored rule is asserted
 * here against them:
 *
 *   · NO CELEBRATION ON A HELD OR DELAYED RESULT. It used to be enforced by placement —
 *     the loader sat inside the winner markup. The rebuilt pages belong to three later
 *     phases, so the rule moved into a decision the partial asks before it draws. Tested
 *     by REASON, never by a bare false: a guard that refuses for an accident of the
 *     fixture stops refusing when the fixture changes (CLAUDE.md, the sandbox section).
 *   · PLAY ONCE — through the engine's `ag-cel-` key, handed over only with the visitor's
 *     Preferences answer, because remembering for next time is what that category is.
 *   · NEVER INSTEAD OF THE PAGE — the title, the sub and every stat's final figure are the
 *     server's markup; the boot only replays numbers that are already right.
 *   · No sound; reduced motion (the OS's and the site's own) means no particles.
 *
 * What survives unchanged at the end is the server half of the member dashboard's
 * "someone you backed won" panel — {@see \AfricaGates\Services\MemberActivityService::backedWinners()}
 * — whose rule is the same rule in another place: a member told "someone you backed won"
 * before the announcement IS the announcement.
 */
final class CelebrationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private int $programmeId = 0;
    private int $cycleId     = 0;
    private int $categoryId  = 0;

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        parent::tearDown();
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $rel);
    }

    /** A script with its comments removed — a comment naming a call is not the call. */
    private static function code(string $rel): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', self::read($rel));
    }

    // ── fixtures: one award, released or not ────────────────────────────────

    /** A programme, a cycle in `$status` and one category; returns the category id. */
    private function award(string $status, ?string $resultsDate, string $slug = '', int $active = 1): int
    {
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug !== '' ? $slug : 'cel-' . bin2hex(random_bytes(3)),
            'title' => 'Kenya Creative Economy Awards', 'is_active' => $active,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2025, 'status' => $status,
            'edition_label' => 'KCEA 11th Edition', 'results_date' => $resultsDate,
        ]);

        return $this->categoryId = $this->category('Musician of the Year');
    }

    private function category(string $title): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => $title, 'sort_order' => 1,
        ]);
    }

    private function nominee(string $name, int $votes, ?int $categoryId = null): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId ?? $this->categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $votes, 'vote_count' => $votes,
        ]);
    }

    /** Two whole scorecards — the quorum — at a whole-number mark (a TINYINT holds no 7.9). */
    private function panel(int $nominee, int $mark, ?int $categoryId = null): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Cel judge ' . (++$n), 'is_active' => 1, 'email' => 'cj' . $n . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $categoryId ?? $this->categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2025-11-01 09:00:00', 'updated_at' => '2025-11-01 09:00:00',
                ]);
            }
        }
    }

    /** @return array{0:int,1:int} the winner and the runner-up of a decided award */
    private function decided(): array
    {
        $a = $this->nominee('Achieng Otieno', 1840);
        $b = $this->nominee('Wanjiru Kamau', 620);
        $this->panel($a, 9);
        $this->panel($b, 7);

        return [$a, $b];
    }

    private function won(int $nominee, ?int $categoryId = null): array
    {
        return ['moment' => 'award_won', 'category_id' => $categoryId ?? $this->categoryId, 'nominee_id' => $nominee];
    }

    // ── rendering the real partial ──────────────────────────────────────────

    private function render(array $args): string
    {
        $twig = Translator::register(new Environment(
            new FilesystemLoader(self::ROOT . '/templates'), ['strict_variables' => true, 'autoescape' => 'html']));
        AppTwig::equip($twig, ['csp_nonce' => 'test-nonce']);

        return $twig->render('partials/celebration.twig', $args);
    }

    private function vote(array $over = []): string
    {
        return $this->render($over + [
            'kind' => 'vote', 'context' => ['moment' => 'vote_cast', 'confirmed' => true,
                                            'edition' => 'kcea-2025', 'subject' => 'achieng'],
            'secondary' => ['href' => '/vote/kcea'],
        ]);
    }

    // ══ the shipped files ═════════════════════════════════════════════════════

    public function test_the_engine_is_the_handoffs_byte_for_byte(): void
    {
        // §7.8 "SHIP VERBATIM", and the reason the colour sweep may skip the sheet at all.
        foreach (VerbatimAssets::FILES as $rel => [, $sha]) {
            $this->assertSame($sha, hash_file('sha256', self::ROOT . '/' . $rel),
                "$rel is no longer the handoff's file. It ships verbatim (GAPS Q7) — an amendment "
              . 'is the owner\'s decision, and once made it is an ordinary file the house rules sweep.');
            $this->assertTrue(VerbatimAssets::is($rel));
        }
    }

    public function test_the_verbatim_exemption_is_lost_the_moment_the_file_is_edited(): void
    {
        // Narrow by name AND by content: an edited copy is swept like any other file.
        $rel  = 'public/assets/css/components/celebration.css';
        $path = self::ROOT . '/' . $rel;
        $orig = (string) file_get_contents($path);
        try {
            file_put_contents($path, $orig . "\n.x{color:#123456}");
            $this->assertFalse(VerbatimAssets::is($rel), 'an edited verbatim file kept its exemption');
            $this->assertArrayHasKey($rel, ColourLiteralTest::counts(), 'the edited copy is not being swept');
            $this->assertSame(3, ColourLiteralTest::counts()[$rel]);
        } finally {
            file_put_contents($path, $orig);
        }
        $this->assertArrayNotHasKey($rel, ColourLiteralTest::counts());
        $this->assertFalse(VerbatimAssets::is('public/assets/css/components/celebration-card.css'),
            'the house sheet beside it is authored here and is never exempt');
    }

    // ══ never celebrate ═══════════════════════════════════════════════════════

    public function test_the_moments_the_handoff_never_celebrates_are_refused_by_name(): void
    {
        // §7.8: saving drafts, settings, sign-in, add to cart, subscribe. For every kind,
        // so no kind is a way round it.
        $this->assertSame(['draft_saved', 'settings_saved', 'signed_in', 'added_to_cart', 'subscribed'], Celebration::NEVER);
        foreach (Celebration::NEVER as $moment) {
            foreach (Celebration::KINDS as $kind) {
                $this->assertSame(Celebration::R_NEVER,
                    Celebration::refusal($kind, ['moment' => $moment, 'confirmed' => true]), "$kind on $moment");
            }
            $this->assertSame('', trim($this->render([
                'kind' => 'vote', 'context' => ['moment' => $moment, 'confirmed' => true]])),
                "the partial drew something for '$moment'");
        }
    }

    public function test_a_moment_is_drawn_only_as_its_own_kind(): void
    {
        // A nomination must not borrow the winner's choreography — "a nomination is not a
        // win" was the destroyed engine's own rule.
        $this->assertSame(Celebration::R_WRONG_KIND, Celebration::refusal('win', ['moment' => 'nomination_sent']));
        $this->assertSame(Celebration::R_UNKNOWN, Celebration::refusal('vote', ['moment' => 'page_viewed']));
        $this->assertSame(Celebration::R_UNKNOWN, Celebration::refusal('vote', []), 'no moment is not a moment');
        $this->assertNull(Celebration::refusal('nominate', ['moment' => 'nomination_sent']));

        $this->expectException(\InvalidArgumentException::class);
        Celebration::refusal('confetti', ['moment' => 'vote_cast']);
    }

    public function test_money_a_seat_and_a_vote_celebrate_only_once_confirmed(): void
    {
        foreach (['vote_cast' => 'vote', 'gift_confirmed' => 'give', 'ticket_confirmed' => 'ticket'] as $m => $k) {
            $this->assertSame(Celebration::R_UNCONFIRMED, Celebration::refusal($k, ['moment' => $m]));
            $this->assertSame(Celebration::R_UNCONFIRMED, Celebration::refusal($k, ['moment' => $m, 'confirmed' => 1]),
                'a truthy non-boolean is not a confirmation');
            $this->assertNull(Celebration::refusal($k, ['moment' => $m, 'confirmed' => true]));
        }
    }

    // ══ no celebration on a held or delayed result ════════════════════════════

    public function test_a_released_award_celebrates_its_winner_and_nobody_else(): void
    {
        $this->award('results', Carbon::now()->subDay()->toDateTimeString());
        [$winner, $runnerUp] = $this->decided();

        $this->assertNull(Celebration::refusal('win', $this->won($winner)));
        $this->assertSame(Celebration::R_NOT_THE_WINNER, Celebration::refusal('win', $this->won($runnerUp)));
    }

    public function test_a_delayed_result_never_celebrates(): void
    {
        // The results date has passed and the cycle is still judging: the late page says
        // nothing is decided, and a burst beside it would announce what it refuses to.
        // The panel HAS finished, so the database already holds a top nominee — which is
        // exactly why the reason, not the absence of a winner, has to be what refuses.
        $this->award('judging', Carbon::now()->subDays(3)->toDateTimeString());
        [$winner] = $this->decided();

        $this->assertSame(Celebration::R_DELAYED, Celebration::refusal('win', $this->won($winner)));
        $this->assertSame('', trim($this->render(['kind' => 'win', 'first' => 'Achieng', 'context' => $this->won($winner)])));
    }

    public function test_an_unannounced_result_never_celebrates(): void
    {
        $this->award('judging', Carbon::now()->addDays(5)->toDateTimeString());
        [$winner] = $this->decided();

        $this->assertSame(Celebration::R_UNRELEASED, Celebration::refusal('win', $this->won($winner)));
    }

    public function test_a_held_result_never_celebrates(): void
    {
        // Released, but nobody met the judging quorum: the page withholds the award by name.
        $this->award('results', Carbon::now()->subDay()->toDateTimeString());
        $a = $this->nominee('Achieng Otieno', 1840);
        $this->nominee('Wanjiru Kamau', 620);

        $this->assertSame(Celebration::R_HELD, Celebration::refusal('win', $this->won($a)));
    }

    public function test_a_result_whose_community_half_was_never_counted_never_celebrates(): void
    {
        // The other held reason, and the one with a winner in it: every panel finished,
        // so the scorer names a top nominee — but not one organic vote is counted, the
        // public page withholds the award as HELD_DARK, and a burst would announce it.
        $this->award('results', Carbon::now()->subDay()->toDateTimeString());
        $a = $this->nominee('Achieng Otieno', 0);
        $b = $this->nominee('Wanjiru Kamau', 0);
        $this->panel($a, 9);
        $this->panel($b, 7);

        $this->assertSame(Celebration::R_HELD, Celebration::refusal('win', $this->won($a)));
    }

    public function test_the_sandbox_never_celebrates(): void
    {
        // The demo programme is a real released cycle with real rows; it must not reach a
        // public burst any more than a public page.
        $this->award('results', Carbon::now()->subDay()->toDateTimeString(), DemoSeeder::PROGRAMME_SLUG, 0);
        [$winner] = $this->decided();

        $this->assertSame(Celebration::R_UNRELEASED, Celebration::refusal('win', $this->won($winner)));
    }

    public function test_an_edition_is_celebrated_only_when_its_overall_winner_is_announced(): void
    {
        $slug = 'cel-ed-' . bin2hex(random_bytes(3));
        $this->award('results', Carbon::now()->subDay()->toDateTimeString(), $slug);
        [$winner, $runnerUp] = $this->decided();
        $edition = $slug . '-2025';

        $ctx = static fn (int $n): array => ['moment' => 'edition_won', 'edition' => $edition, 'nominee_id' => $n];
        $this->assertNull(Celebration::refusal('win', $ctx($winner)));
        $this->assertSame(Celebration::R_NOT_THE_WINNER, Celebration::refusal('win', $ctx($runnerUp)));

        // A second award in the edition still withheld makes the overall provisional.
        $held = $this->category('Producer of the Year');
        $this->nominee('Unjudged Nominee', 400, $held);
        $this->assertSame(Celebration::R_HELD, Celebration::refusal('win', $ctx($winner)));
        $this->assertSame(Celebration::R_UNRELEASED,
            Celebration::refusal('win', ['moment' => 'edition_won', 'edition' => 'nope-2025', 'nominee_id' => $winner]));
    }

    // ══ the play-once key ═════════════════════════════════════════════════════

    public function test_the_seen_key_is_kind_edition_subject_and_the_member(): void
    {
        $this->assertSame('win-kcea-2025-achieng-otieno', Celebration::seenKey('win', 'kcea-2025', 'Achieng Otieno'));
        $this->assertSame('win-kcea-2025-achieng-otieno-42', Celebration::seenKey('win', 'kcea-2025', 'Achieng Otieno', 42));
        $this->assertNotSame(Celebration::seenKey('vote', 'kcea-2025', 'achieng'), Celebration::seenKey('win', 'kcea-2025', 'achieng'),
            'a vote seen must not mark the win as seen');

        $_SESSION['user_id'] = 7;
        $this->assertStringContainsString('data-agc-seen-key="vote-kcea-2025-achieng-7"', $this->vote(),
            'the partial builds the key through the one resolver, with the signed-in member');
    }

    public function test_no_template_spells_a_seen_key_or_mounts_a_stage_by_hand(): void
    {
        // One resolver: a key typed into a page is a second spelling of a moment, which
        // plays twice or never. And only the partial mounts a stage, because only the
        // partial asks the decision first.
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates')) as $f) {
            if (!$f->isFile() || !str_ends_with((string) $f, '.twig')) continue;
            $rel  = str_replace(realpath(self::ROOT) . '/', '', (string) realpath((string) $f));
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents((string) $f));
            if ($rel !== 'templates/partials/celebration.twig' && str_contains($body, 'data-agc-kind')) {
                $bad[] = "$rel mounts a celebration stage itself";
            }
            if (preg_match_all('/seen_key\s*:\s*([^,}]+)/', $body, $m)) {
                foreach ($m[1] as $v) {
                    if (!str_starts_with(trim($v), 'celebration_seen_key(')) $bad[] = "$rel passes seen_key: " . trim($v);
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_play_once_is_kept_only_with_the_preferences_answer(): void
    {
        $engine = self::read('public/assets/js/celebration.js');
        $boot   = self::read('public/assets/js/celebration-boot.js');

        // The key the verbatim engine writes, declared under Preferences with that prefix.
        $this->assertStringContainsString("var k = 'ag-cel-' + key; if (w.localStorage.getItem(k)) return true; w.localStorage.setItem(k, '1');", $engine);
        $row = array_values(array_filter(CookieRegistry::storage(), static fn (array $s): bool => $s['key'] === 'ag-cel-'));
        $this->assertCount(1, $row);
        $this->assertSame(CookieRegistry::PREFERENCES, $row[0]['category']);
        $this->assertNotContains('ag-celebrated:', array_column(CookieRegistry::storage(), 'key'), 'the destroyed engine\'s row is gone');

        // …and the engine is handed a key only when Preferences is allowed — with none it
        // stores nothing. The seen-or-not read inside the engine fails open ("not seen").
        $this->assertStringContainsString("var keep = root.getAttribute('data-ag-keep') === '1';", $boot);
        $this->assertStringContainsString("seenKey: keep ? el.dataset.agcSeenKey : '',", $boot);
        $this->assertStringContainsString("catch (e) {} return false;", $engine);
        $this->assertStringNotContainsString('localStorage', self::code('public/assets/js/celebration-boot.js'),
            'the boot must not keep a store of its own');
    }

    // ══ never instead of the page ═════════════════════════════════════════════

    public function test_the_card_is_the_dcs_markup_with_every_figure_final_on_the_server(): void
    {
        $html = $this->render([
            'kind' => 'win', 'first' => 'Achieng',
            'sub' => 'Musician of the Year, KCEA 11th Edition. The community and the jury agreed.',
            'stats' => [['n' => 18402, 'l' => 'supporters'], ['n' => '9.1', 'l' => 'jury score'],
                        ['n' => '68%', 'l' => 'of goal'], ['n' => '1st', 'l' => 'of 5 finalists']],
            'secondary' => ['href' => '/results/x'],
            'context' => ['moment' => 'vote_cast'],   // replaced below by a real win
        ] + ['kind' => 'win']);
        $this->assertSame('', trim($html), 'a win with a vote moment drew a card');

        $this->award('results', Carbon::now()->subDay()->toDateTimeString());
        [$winner] = $this->decided();
        $html = $this->render([
            'kind' => 'win', 'first' => 'Achieng',
            'sub' => 'Musician of the Year, KCEA 11th Edition. The community and the jury agreed.',
            'stats' => [['n' => 18402, 'l' => 'supporters'], ['n' => '9.1', 'l' => 'jury score'],
                        ['n' => '68%', 'l' => 'of goal'], ['n' => '1st', 'l' => 'of 5 finalists']],
            'secondary' => ['href' => '/results/x'],
            'context' => $this->won($winner) + ['edition' => 'kcea-2025', 'subject' => 'achieng-otieno'],
        ]);

        // The stage is EMPTY — the engine owns its inside, the server owns everything else.
        $this->assertMatchesRegularExpression(
            '~<div class="ag-cel__stage" data-agc-kind="win" data-agc-size="full" data-agc-seen-key="win-kcea-2025-achieng-otieno"></div>~', $html);
        $this->assertStringContainsString('<b data-agc-count="18402">18,402</b>', $html);
        $this->assertStringContainsString('<b data-agc-count="9.1">9.1</b>', $html);
        $this->assertStringContainsString('<b data-agc-count="68%">68%</b>', $html);
        $this->assertStringContainsString('<b>1st</b>', $html, 'an ordinal is not counted up through "0st"');
        $this->assertStringContainsString('<h2 class="ag-cel__title">Achieng won.</h2>', $html);
        $this->assertStringContainsString('>Winner</span>', $html);
        $this->assertStringContainsString('style="--cel-ink:var(--ag-gold-ink);--cel-dot:var(--ag-gold);--cel-tint:var(--ag-gold-wash)"', $html);
        $this->assertStringContainsString('data-ag-share aria-label="Share the win"', $html);
        $this->assertStringContainsString('href="/results/x">See how it was decided</a>', $html);
        $this->assertStringContainsString('data-agc-replay>Celebrate again</button>', $html);
        $this->assertStringContainsString('role="status" aria-live="polite">Achieng won. Musician of the Year', $html,
            'the live region reads one full stop, not the DC\'s "won.."');

        // The boot replays numbers that are already right and touches nothing else.
        $boot = self::code('public/assets/js/celebration-boot.js');
        foreach (['innerHTML', '.hidden = false', 'style.display', 'textContent'] as $never) {
            $this->assertStringNotContainsString($never, $boot, "the boot writes the page with $never");
        }
    }

    public function test_the_headline_and_button_defaults_are_the_handoffs_table(): void
    {
        $this->award('results', Carbon::now()->subDay()->toDateTimeString());
        [$winner] = $this->decided();
        $ctx = [
            'vote'     => ['moment' => 'vote_cast', 'confirmed' => true],
            'win'      => $this->won($winner),
            'nominate' => ['moment' => 'nomination_sent'],
            'give'     => ['moment' => 'gift_confirmed', 'confirmed' => true],
            'ticket'   => ['moment' => 'ticket_confirmed', 'confirmed' => true],
        ];
        $table = [   // §7.8: title · primary · secondary
            'vote'     => ['Your vote is in', 'Share your vote', 'Vote in another category'],
            'win'      => ['Achieng won.', 'Share the win', 'See how it was decided'],
            'nominate' => ['Nomination sent', 'Let them know', 'Nominate someone else'],
            'give'     => ['Thank you for giving', 'Share the campaign', 'See where it goes'],
            'ticket'   => ['You’re going', 'Add to calendar', 'View ticket'],
        ];
        foreach ($table as $kind => [$title, $primary, $secondary]) {
            foreach (Celebration::SIZES as $size) {
                $html = $this->render(['kind' => $kind, 'size' => $size, 'first' => 'Achieng', 'context' => $ctx[$kind],
                                       'primary' => $kind === 'ticket' ? ['href' => '/t.ics'] : [],
                                       'secondary' => ['href' => '/next']]);
                $this->assertStringContainsString('<h2 class="ag-cel__title">' . htmlspecialchars($title) . '</h2>', $html, "$kind $size");
                $this->assertStringContainsString('>' . $primary . '</', $html, "$kind $size primary");
                $this->assertStringContainsString('>' . $secondary . '</a>', $html, "$kind $size secondary");
                $this->assertStringContainsString("ag-cel--$size ag-cel--$kind", $html);
            }
        }

        // And every one of them is passed through |trans in the partial.
        $src = self::read('templates/partials/celebration.twig');
        foreach (array_merge(...array_values($table)) as $s) {
            if ($s === 'Achieng won.') $s = '%first% won.';
            $this->assertStringContainsString("'" . $s . "'|trans", $src, "'$s' is not translated");
        }
    }

    public function test_the_demo_event_can_never_reach_a_real_ticket(): void
    {
        // The engine falls back to "KCEA Ceremony" / "Sat 6 Dec · 18:00" on an empty value
        // and writes both with innerHTML. So the boot escapes them, and never passes empty.
        $this->assertStringContainsString("(o.ticketLabel || 'KCEA Ceremony')", self::read('public/assets/js/celebration.js'));
        $boot = self::read('public/assets/js/celebration-boot.js');
        $this->assertStringContainsString('ticketLabel: esc(el.dataset.agcTicketLabel)', $boot);
        $this->assertStringContainsString('ticketWhen: esc(el.dataset.agcTicketWhen)', $boot);
        $this->assertStringContainsString("}) || '\\u00a0';", $boot);
        $this->assertStringContainsString("'<': '&lt;'", $boot);

        $html = $this->render(['kind' => 'ticket', 'primary' => ['href' => '/t.ics'],
            'context' => ['moment' => 'ticket_confirmed', 'confirmed' => true],
            'ticket' => ['label' => 'Gala <b>night</b>', 'when' => 'Fri 12 Dec · 19:00']]);
        $this->assertStringContainsString('data-agc-ticket-label="Gala &lt;b&gt;night&lt;/b&gt;" data-agc-ticket-when="Fri 12 Dec · 19:00"', $html);
        $this->assertStringNotContainsString('data-ag-share', $html, '"Add to calendar" is a link, never a share button');
    }

    public function test_it_makes_no_sound_and_reduced_motion_gets_no_particles(): void
    {
        foreach (['public/assets/js/celebration.js', 'public/assets/js/celebration-boot.js'] as $f) {
            foreach (['new Audio', 'AudioContext', '.play(', '<audio'] as $never) {
                $this->assertStringNotContainsString($never, self::read($f), "$f: $never");
            }
        }
        // The OS setting: the engine's own sheet. The site's own Reduce motion (html.ag-rm),
        // which the engine cannot see: the card's sheet and the boot.
        $this->assertStringContainsString('@media (prefers-reduced-motion:reduce){.agc-amb,.agc-fx{display:none!important}',
            self::read('public/assets/css/components/celebration.css'));
        $card = self::read('public/assets/css/components/celebration-card.css');
        $this->assertStringContainsString('.ag-rm .agc-amb,.ag-rm .agc-fx{ display:none!important }', $card);
        $this->assertStringContainsString('.ag-rm .ag-cel *{ animation:none!important }', $card);
        $boot = self::read('public/assets/js/celebration-boot.js');
        $this->assertStringContainsString("var rm = root.classList.contains('ag-rm');", $boot);
        $this->assertStringContainsString('haptics: !rm && tapped(),', $boot);
        $this->assertStringContainsString('window.AGCelebrate.countUp(card, fx.calm || rm);', $boot);
    }

    public function test_the_scripts_are_deferred_nonced_and_self_hosted(): void
    {
        $html = $this->vote();
        $this->assertSame(2, preg_match_all('~<script defer nonce="test-nonce" src="/assets/js/celebration(-boot)?\.js\?v=[^"]+"></script>~', $html),
            'a celebration must not block the first paint, and the public CSP is nonce-based');
        $this->assertLessThan(strpos($html, 'celebration-boot.js'), strpos($html, '/celebration.js'), 'the engine loads before its boot');
        $this->assertDoesNotMatchRegularExpression('~src="https?://~', $html);
    }

    public function test_the_csp_keeps_the_style_attributes_the_engine_writes(): void
    {
        // The engine builds its ticket stub and star orbit with `style="…"` ATTRIBUTES
        // through innerHTML (GAPS C10). Tightening style-src-attr would leave a ticket
        // celebration with an unstyled stub and nothing anywhere to say why.
        $this->assertStringContainsString('<div style="display:flex;flex-direction:column', self::read('public/assets/js/celebration.js'));
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", Csp::policy());
        $this->assertMatchesRegularExpression("~style-src 'self' 'unsafe-inline'~", Csp::staticPolicy());
    }

    public function test_the_showcase_is_dev_only(): void
    {
        // 404 in production, like /_dev/ui: not even discoverable.
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        try {
            $builder = new \DI\ContainerBuilder();
            $builder->addDefinitions(self::ROOT . '/config/container.php');
            \Slim\Factory\AppFactory::setContainer($builder->build());
            $app = \Slim\Factory\AppFactory::create();
            (require self::ROOT . '/src/routes.php')($app);
            $app->addRoutingMiddleware();
            $app->addErrorMiddleware(false, false, false);
            $res = $app->handle((new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', '/_dev/celebration?kind=vote'));
            $this->assertSame(404, $res->getStatusCode());
        } finally {
            if ($prev === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $prev;
        }
    }

    // ───────────────────────── the member's dashboard ─────────────────────────

    /** `slug` is NOT NULL and UNIQUE per cycle — a fixture that omits it passes on
     *  neither driver, and a shared literal collides with the next test's. */
    private function backedCategory(string $title): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => 1, 'title' => $title, 'slug' => 'c-' . bin2hex(random_bytes(5)),
        ]);
    }

    private function promoted(int $categoryId, string $name, string $status): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name,
            'status' => $status === '' ? 'approved' : $status,
            'vote_count' => 0,
        ]);
    }

    private function backerVote(string $email, int $categoryId, int $nomineeId): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nomineeId, 'category_id' => $categoryId,
            // The same hash the service looks up by — `sha256(lower(trim(email)))`.
            'voter_email_hash' => hash('sha256', strtolower(trim($email))),
            'vote_type' => 'standard', 'voted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_the_query_behind_the_dashboard_panel_actually_runs(): void
    {
        // THE POINT OF THIS TEST IS THE CATCH. backedWinners() swallows a database error
        // and returns [], which is right for an ornament on a records screen and is also
        // indistinguishable from "nobody won" — the first version selected `n.slug`, a
        // column gates_nominees does not have, and would have shown an empty panel for
        // ever without a single error anywhere. So this proves a row comes BACK.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A category each: one vote per person per category is enforced by a UNIQUE key.
        $cat = $this->backedCategory('Teachers’ Choice');
        $win = $this->promoted($cat, 'Oluwagbemiga Dorcas', 'winner');
        $this->backerVote($email, $cat, $win);

        $other = $this->backedCategory('Still Judging');
        $this->backerVote($email, $other, $this->promoted($other, 'Not Promoted Yet', 'approved'));

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);

        $this->assertCount(1, $got, 'the query came back empty — it may not be running at all');
        $this->assertSame('Oluwagbemiga Dorcas', $got[0]['nominee']);
        $this->assertSame('winner', $got[0]['kind']);
        $this->assertSame('Teachers’ Choice', $got[0]['category']);
        $this->assertSame($win, $got[0]['id']);
    }

    public function test_a_nominee_nobody_has_been_told_about_is_not_congratulated(): void
    {
        // 'winner' is written by CycleMaterialiser inside the promotion, so a nominee who
        // is merely leading, or approved, or pending, must never reach this panel. A
        // member told "someone you backed won" before the announcement IS the announcement.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A CATEGORY EACH. `gates_votes` is UNIQUE on (voter_email_hash, category_id) —
        // one vote per person per category is the platform's rule — so a fixture that
        // votes twice in one category is not a stricter test, it is an impossible one.
        foreach (['pending', 'approved'] as $status) {
            $cat = $this->backedCategory('Undecided ' . $status);
            $this->backerVote($email, $cat, $this->promoted($cat, 'Someone ' . $status, $status));
        }

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($email));
    }

    public function test_somebody_elses_vote_is_not_your_celebration(): void
    {
        $mine   = 'mine-' . bin2hex(random_bytes(4)) . '@example.test';
        $theirs = 'theirs-' . bin2hex(random_bytes(4)) . '@example.test';
        $cat = $this->backedCategory('Craft');
        $this->backerVote($theirs, $cat, $this->promoted($cat, 'Their Winner', 'winner'));

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($mine));
    }

    public function test_two_categories_backed_is_two_lines_and_one_key(): void
    {
        // Backing the same nominee twice is not reachable — one vote per person per
        // category, enforced by a UNIQUE key — so the case that matters is two
        // categories. Both appear, and the panel's celebrate key carries both ids, so a
        // member who backs a second winner next month gets a second moment rather than
        // one that already counts as seen.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        $ids = [];
        foreach (['Craft', 'Service'] as $title) {
            $cat = $this->backedCategory($title);
            $ids[] = $id = $this->promoted($cat, $title . ' Winner', 'winner');
            $this->backerVote($email, $cat, $id);
        }

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);
        $this->assertCount(2, $got);
        $this->assertSame(array_reverse($ids), array_column($got, 'id'),
            'newest first, so a fresh win is the first thing read');
    }
}
