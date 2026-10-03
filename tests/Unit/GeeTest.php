<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaymentService;
use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportDesk;
use AfricaGates\Services\SupportWork;
use AfricaGates\Support\CookieRegistry;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Gee — the guide and the help desk (Phase 3, REFERENCE §7.7 and §8.22).
 *
 * Rebuilt from nothing on 3 Oct 2026 with the files it guards (docs/handoff/PHASE-3.md),
 * and each assertion below was watched failing against a planted break before it was
 * trusted (the mutation list is in that document). Grouped by the promise each holds:
 *
 *   WHERE IT IS      on the shell, on every page but Pulse; never on the desk's own pages
 *                    or a page without chrome.
 *   WHERE IT SITS    clear of every bottom bar through `--ag-bottom-ui` and nothing else;
 *                    the panel capped to the page, never `vh`.
 *   WHAT IT STORES   two declared keys, the privacy note's one a Preferences fact, and a
 *                    note whose sentence is the registry's own.
 *   WHAT IT SAYS     every word handed over through `|trans`, in both directions.
 *   THE WORK CARD    the steps that RAN, from the repair's own record — never a timer.
 *   THE DESK         the member's own rows, nobody else's, nothing from the sandbox.
 *   THE OLD ADDRESS  `/support/assistant` 301s to `/help?gee=support` and its parameters
 *                    are ones the script reads.
 */
final class GeeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const MINE = 'chioma.o@mail.test';

    private int $nominee = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->delete();
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 61, 'title' => 'Live', 'slug' => 'gee-live', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 611, 'programme_id' => 61, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 611, 'cycle_id' => 611, 'title' => 'C', 'slug' => 'gee-c']);
        $this->nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 611, 'name' => 'Achieng Otieno', 'status' => 'approved', 'vote_count' => 0,
        ]);
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $rel);
    }

    /** A gateway that answers from a table, so a repair runs to the end with no network. */
    private function gateway(array $answers): PaymentService
    {
        return new class ($answers) extends PaymentService {
            public function __construct(private array $answers) { parent::__construct(); }
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array
            {
                return $this->answers[$reference]
                    ?? ['ok' => false, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => [], 'message' => 'unknown'];
            }
        };
    }

    private function paid(int $naira): array
    {
        return ['ok' => true, 'status' => 'success', 'amount' => $naira, 'currency' => 'NGN', 'meta' => [], 'message' => 'ok'];
    }

    private function order(string $ref, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Chioma Obi', 'donor_email' => self::MINE, 'amount_naira' => 2000,
            'tier' => 'paid-vote', 'bonus_votes' => 10, 'votes_used' => 0, 'provider' => 'paystack',
            'intent_nominee_id' => $this->nominee, 'payment_ref' => $ref, 'status' => 'pending',
            'created_at' => Carbon::now()->subMinutes(6)->toDateTimeString(),
        ]);
    }

    private function ctx(?int $member, ?string $email, PaymentService $pay): SupportContext
    {
        return new SupportContext($member, $email, false, null, null, '', $pay);
    }

    /** @return array<string,mixed>|null */
    private function check(string $ref, SupportContext $ctx): ?array
    {
        return SupportWork::card($ctx->run('fix_payment', ['reference' => $ref]), $ctx);
    }

    // ══ WHERE IT IS ══════════════════════════════════════════════════════════

    public function test_gee_is_on_a_shell_page_and_its_script_and_sheet_are_loaded(): void
    {
        $html = (string) ChromeRender::page('/_dev/ui')->getBody();

        $this->assertStringContainsString('data-gee', $html);
        $this->assertMatchesRegularExpression('~<script defer src="/assets/js/gee\.js[^"]*"~', $html);
        $this->assertMatchesRegularExpression('~href="/assets/css/components/gee\.css[^"]*"~', $html);
        // The launcher starts hidden: with no script there is nothing behind it.
        $this->assertMatchesRegularExpression('~<button[^>]*class="gee__fab"[^>]*\bhidden\b~', $html);
    }

    /**
     * §7.7: every page except Pulse. The desk's own pages and a page without chrome keep
     * the old layout's reasons. Asked of the layout itself, rendered by the app's own Twig.
     */
    public function test_gee_is_not_mounted_on_pulse_the_support_pages_or_a_chromeless_page(): void
    {
        $twig = $this->appTwig();
        $render = static fn (array $vars): string => $twig->createTemplate("{% extends 'layout/shell.twig' %}")->render($vars);

        $this->assertStringContainsString('data-gee', $render(['page_title' => 'Any page']));
        foreach ([['gates_page' => 'pulse'], ['tab' => 'pulse'], ['gates_page' => 'support'], ['hide_chrome' => true]] as $v) {
            $html = $render($v + ['page_title' => 'X']);
            $this->assertStringNotContainsString('data-gee', $html, 'Gee mounted with ' . json_encode($v));
            $this->assertStringNotContainsString('/assets/js/gee.js', $html, 'gee.js loaded with ' . json_encode($v));
        }
    }

    private function appTwig(): \Twig\Environment
    {
        $_SESSION = ['csrf_token' => 't'];
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(self::ROOT . '/config/container.php');
        return $builder->build()->get(\Slim\Views\Twig::class)->getEnvironment();
    }

    // ══ WHERE IT SITS ════════════════════════════════════════════════════════

    public function test_the_launcher_and_the_panel_clear_the_bottom_ui_by_the_formula_and_nothing_else(): void
    {
        $css = $this->read('public/assets/css/components/gee.css');
        $formula = 'bottom:calc(var(--ag-bottom-ui, 0px) + 16px + env(safe-area-inset-bottom))';

        foreach (['.gee__fab{', '.gee__layer{'] as $sel) {
            $at = strpos($css, "\n" . $sel);
            $this->assertNotFalse($at, "$sel is not declared");
            $rule = substr($css, $at, (int) strpos($css, '}', $at) - $at);
            $this->assertStringContainsString($formula, str_replace(["\n", '  '], ['', ''], preg_replace('/\s*\n\s*/', '', $rule)),
                "$sel must sit at --ag-bottom-ui + 16px + the safe area (§7.7)");
        }
        // No typed offset anywhere: every `bottom:` in the file is the formula or a dot's -1/-2.
        preg_match_all('/(?<![\w-])bottom\s*:\s*([^;}]+)/', $css, $m);
        foreach ($m[1] as $v) {
            $v = trim($v);
            $this->assertTrue(str_starts_with($v, 'calc(var(--ag-bottom-ui') || in_array($v, ['-1px', '-2px'], true),
                "a typed bottom offset in gee.css: bottom:$v");
        }
        // Capped to the page, never the viewport (§7.7).
        $this->assertDoesNotMatchRegularExpression('/\d(?:d|s|l)?vh\b/', $css, 'the panel height is never vh');
        $this->assertStringContainsString('height:min(580px, 100%)', $css, '400×580 on desktop, capped to the page');
        $this->assertStringContainsString('width:400px', $css);
        $this->assertStringContainsString('inset-inline:12px', $css, 'the phone panel is inset 12px');
    }

    /**
     * The variable is set by every bottom-fixed element: the tracker measures how far up
     * the screen they reach (bars stack — the cookie notice sits above the tab bar), it
     * sees a bar mounted later and one removed, and the notice is one of them.
     */
    public function test_every_bottom_bar_sets_the_variable_and_a_later_mount_or_unmount_is_seen(): void
    {
        $js = $this->read('public/assets/js/shell.js');
        $this->assertSame(1, preg_match('~function trackBottomUI\(\)\s*\{(.*?)\n  \}~s', $js, $m));
        $body = $m[1];
        $this->assertMatchesRegularExpression('/vh\s*-\s*r\.top/', $body, 'the figure is the reach from the bottom edge, not a height');
        $this->assertStringContainsString('childList: true', $body, 'a bar mounted or removed later must re-measure');
        $this->assertMatchesRegularExpression('/ro\.observe\(els\[i\]\)/', $body, 'a bar mounted later is observed for size');
        $this->assertDoesNotMatchRegularExpression("/if \\(document\\.querySelector\\('\\[data-bottom-ui\\]'\\)\\)\\s*trackBottomUI/", $js,
            'the tracker must run on a page with no bar yet, or a bar mounted later is never measured');

        $this->assertStringContainsString('data-bottom-ui', $this->read('templates/partials/tab-bar.twig'));
        $consent = $this->read('templates/partials/cookie-consent.twig');
        $this->assertMatchesRegularExpression('~<section class="ag-consent"[^>]*data-bottom-ui~', $consent);
        $this->assertMatchesRegularExpression('~<div class="ag-consent-saved"[^>]*data-bottom-ui~', $consent);
    }

    // ══ WHAT IT STORES ═══════════════════════════════════════════════════════

    public function test_the_privacy_dismissal_outlives_the_tab_only_with_preferences(): void
    {
        $js = $this->read('public/assets/js/gee.js');
        $this->assertMatchesRegularExpression("/var PRIV\s*=\s*'ag-gee-privacy';/", $js);
        // Every write of the dismissal: localStorage only on the `keep()` branch.
        preg_match_all('/(\w+)\.setItem\(PRIV/', $js, $w);
        $this->assertNotEmpty($w[1]);
        $this->assertMatchesRegularExpression('/if \(keep\(\)\) localStorage\.setItem\(PRIV, \'1\'\);\s*else sessionStorage\.setItem\(PRIV, \'1\'\);/', $js);
        $this->assertSame(1, substr_count($js, 'localStorage.setItem('), 'the only thing Gee keeps on the device is the dismissal');
        $this->assertMatchesRegularExpression("/function keep\(\) \{ return document\.documentElement\.getAttribute\('data-ag-keep'\) === '1'; \}/", $js,
            'Preferences reaches the script only as the layout\'s data-ag-keep (CookiePrefs decides)');

        $row = array_values(array_filter(CookieRegistry::storage(), static fn ($s) => $s['key'] === 'ag-gee-privacy'))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(CookieRegistry::PREFERENCES, $row['category']);
        $this->assertSame('local-or-session', $row['where']);
    }

    /**
     * The note states a retention period, and it is the one the code has: the transcript
     * is in sessionStorage (this tab) and nowhere else on the device. The design file's
     * "kept for 30 days, then deleted" describes a mechanism nothing here has.
     */
    public function test_the_privacy_note_says_what_the_storage_actually_does(): void
    {
        $chat = array_values(array_filter(CookieRegistry::storage(), static fn ($s) => $s['key'] === 'ag-gee-chat:'))[0] ?? null;
        $this->assertNotNull($chat, 'the transcript key is declared');
        $this->assertSame('session', $chat['where']);

        $js = $this->read('public/assets/js/gee.js');
        $this->assertMatchesRegularExpression("/function chatKey\(\) \{ return 'ag-gee-chat:' \+ state\.mode; \}/", $js);
        $this->assertDoesNotMatchRegularExpression('/localStorage\.setItem\(chatKey/', $js);

        // What a READER sees: a Twig comment reaches nobody (and this one quotes the design
        // file's retired sentence to explain why it is not used).
        $twig = (string) preg_replace('/\{#.*?#\}/s', '', $this->read('templates/partials/gee.twig'));
        $this->assertStringContainsString('stays in this tab until you close it', $twig);
        $this->assertStringContainsString('Never share card numbers or passwords', $twig);
        $this->assertStringNotContainsString('30 days', $twig);
        $this->assertMatchesRegularExpression('~data-gee-note-x aria-label=~', $twig, 'the note is dismissible');
    }

    // ══ WHAT IT SAYS ═════════════════════════════════════════════════════════

    /** Both directions: a word the script asks for exists, and a word handed over is read. */
    public function test_every_word_the_script_reads_is_handed_over_and_every_one_handed_over_is_read(): void
    {
        $twig = $this->read('templates/partials/gee.twig');
        $js   = $this->read('public/assets/js/gee.js');

        preg_match_all('/data-msg-([a-z0-9-]+)="\{\{ \'[^\']+\'\|trans/u', $twig, $given);
        preg_match_all('/data-msg-([a-z0-9-]+)=/', $twig, $all);
        $this->assertSame(count($all[1]), count($given[1]), 'every data-msg is a |trans literal');

        preg_match_all("/\bM\('([a-z0-9-]+)'[,)]/", $js, $asked);
        // Built names: M('fab-' + mode) and friends — the two modes are the whole set.
        preg_match_all("/\bM\('([a-z0-9-]+-)' \+ (?:mode|state\.mode)\)/", $js, $built);
        $wanted = $asked[1];
        foreach ($built[1] as $stem) { $wanted[] = $stem . 'guide'; $wanted[] = $stem . 'support'; }
        $wanted = array_values(array_unique($wanted));

        $this->assertNotEmpty($wanted, 'the sweep read no M() call — it is not reading the file');
        $missing = array_values(array_diff($wanted, $given[1]));
        $unread  = array_values(array_diff($given[1], $wanted));
        sort($missing); sort($unread);
        $this->assertSame([], $missing, 'gee.js asks for words nobody handed over');
        $this->assertSame([], $unread, 'words handed over that the script never reads (§17)');
    }

    /** A support surface never says a bare "ticket" — Gee floats over event pages. */
    public function test_gee_never_says_a_bare_ticket(): void
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\|trans/u", $this->read('templates/partials/gee.twig'), $m);
        $this->assertNotEmpty($m[1]);
        $bad = [];
        foreach ($m[1] as $s) {
            if (!preg_match_all('/tickets?\b/i', $s, $h, PREG_OFFSET_CAPTURE)) continue;
            foreach ($h[0] as $hit) {
                $before = substr($s, 0, (int) $hit[1]);
                if (preg_match('/\b(support|event)\s+$/i', $before)) continue;
                if (preg_match('/\b(find|for)\s+$/i', $before) && preg_match('/\bevent\b/i', $s)) continue;
                $bad[] = $s;
            }
        }
        $this->assertSame([], $bad, 'say "support ticket" (or "event ticket"): ' . implode(' | ', $bad));
    }

    public function test_no_capitals_and_the_reference_is_mono_and_upper_cased_by_value(): void
    {
        $css = $this->read('public/assets/css/components/gee.css');
        $this->assertDoesNotMatchRegularExpression('/text-transform\s*:/', $css);
        $this->assertMatchesRegularExpression('/\.gee__ref-input\{[^}]*font:500 var\(--ag-fs-16\)\/1 var\(--ag-font-mono\)/s', $css,
            'the reference box is mono 16px (§8.22)');

        $twig = $this->read('templates/partials/gee.twig');
        $this->assertMatchesRegularExpression('~<input class="gee__ref-input"[^>]*autocapitalize="characters"[^>]*spellcheck="false"~s', $twig);
        $js = $this->read('public/assets/js/gee.js');
        $this->assertStringContainsString('var up = refIn.value.toUpperCase();', $js, 'auto-uppercase is the VALUE, never CSS');
    }

    public function test_reduced_motion_stops_the_ring_and_leaves_it_half_drawn(): void
    {
        $css = $this->read('public/assets/css/components/gee.css');
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion:reduce\)\{\s*\.gee \*\{ animation:none!important; transition:none!important \}/', $css);
        $this->assertMatchesRegularExpression('/html\.ag-rm \.gee \*\{ animation:none!important/', $css);
        // The half ring is drawn by the border, not by the turn, so stopping it leaves it.
        $this->assertMatchesRegularExpression('/\[data-state="active"\] \.gee__ring\{[^}]*border-block-start-color:var\(--ag-ink\); border-inline-end-color:var\(--ag-ink\)/s', $css);
    }

    // ══ THE WORK CARD ════════════════════════════════════════════════════════

    public function test_a_stuck_payment_put_right_shows_all_three_steps_and_the_result(): void
    {
        $this->order('AFG-PVOTE-aaaa1111bbbb');
        $ctx = $this->ctx(1, self::MINE, $this->gateway(['AFG-PVOTE-aaaa1111bbbb' => $this->paid(2000)]));

        $w = $this->check('AFG-PVOTE-aaaa1111bbbb', $ctx);
        $this->assertNotNull($w);
        $this->assertSame([['key' => 'order', 'state' => 'done'], ['key' => 'provider', 'state' => 'done'],
                           ['key' => 'votes', 'state' => 'done']], $w['steps']);
        $this->assertSame('Paystack', $w['provider'], 'the provider that was ASKED, by name');
        $this->assertTrue($w['fixed']);
        $this->assertSame(10, $w['result']['votes']);
        $this->assertSame('Achieng Otieno', $w['result']['nominee']['name']);
        $this->assertSame('₦2,000', $w['result']['amount']);
        $this->assertSame(self::MINE, $w['result']['receipt_to'], 'the member\'s own address');
        $this->assertSame('/vote/paid/success?ref=AFG-PVOTE-aaaa1111bbbb', $w['result']['receipt_url']);
        $this->assertSame(10, (int) DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-aaaa1111bbbb')->value('votes_used'),
            'the votes the card reports are on the order');
    }

    /** A step that did not run is not drawn: an already-confirmed order asks no gateway. */
    public function test_a_step_that_did_not_happen_is_not_drawn(): void
    {
        $this->order('AFG-PVOTE-cccc2222dddd', ['status' => 'confirmed', 'votes_used' => 10]);
        $asked = false;
        $pay = new class ($asked) extends PaymentService {
            public function __construct(private bool &$asked) { parent::__construct(); }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array { $this->asked = true; return ['ok' => false]; }
        };
        $w = $this->check('AFG-PVOTE-cccc2222dddd', $this->ctx(null, null, $pay));

        $this->assertFalse($asked);
        $this->assertSame([['key' => 'order', 'state' => 'done']], $w['steps'], 'no tick beside a gateway nobody asked');
        $this->assertFalse($w['fixed']);
        $this->assertNull($w['result']);
    }

    public function test_the_steps_that_failed_say_so(): void
    {
        // Not ours: the lookup is the one step, and it failed.
        $w = $this->check('nonsense', $this->ctx(null, null, $this->gateway([])));
        $this->assertSame([['key' => 'order', 'state' => 'failed']], $w['steps']);

        // The gateway was asked and has not seen the money.
        $this->order('AFG-PVOTE-eeee3333ffff');
        $w = $this->check('AFG-PVOTE-eeee3333ffff', $this->ctx(null, null, $this->gateway([])));
        $this->assertSame([['key' => 'order', 'state' => 'done'], ['key' => 'provider', 'state' => 'failed']], $w['steps']);
        $this->assertFalse($w['fixed']);

        // The money is confirmed and the mint was refused: never "Fixed".
        DB::table('gates_nominees')->where('id', $this->nominee)->update(['status' => 'pending']);
        $this->order('AFG-PVOTE-1212abab3434');
        $w = $this->check('AFG-PVOTE-1212abab3434', $this->ctx(null, null, $this->gateway(['AFG-PVOTE-1212abab3434' => $this->paid(2000)])));
        $this->assertSame('failed', end($w['steps'])['state'], json_encode($w['steps']));
        $this->assertSame('votes', end($w['steps'])['key']);
        $this->assertFalse($w['fixed']);
        $this->assertNull($w['result']);
    }

    /** Nothing ran (the repair allowance, no reference): no card, rather than an empty one. */
    public function test_no_card_when_nothing_ran(): void
    {
        $this->assertNull(SupportWork::card(['ok' => true, 'tool' => 'fix_payment',
            'data' => ['ok' => false, 'outcome' => 'RATE_LIMITED', 'say' => 'later']], $this->ctx(null, null, $this->gateway([]))));
        $this->assertNull(SupportWork::fromResults([['tool' => 'help_search', 'ok' => true, 'data' => []]],
            $this->ctx(null, null, $this->gateway([]))));
    }

    /** The repair is open to a reference holder; who paid is not. */
    public function test_a_reference_holder_is_never_told_whose_address_the_receipt_went_to(): void
    {
        $this->order('AFG-PVOTE-5656cdcd7878');
        $guest = $this->check('AFG-PVOTE-5656cdcd7878',
            $this->ctx(null, null, $this->gateway(['AFG-PVOTE-5656cdcd7878' => $this->paid(2000)])));
        $this->assertTrue($guest['fixed']);
        $this->assertNull($guest['result']['receipt_to']);

        $this->order('AFG-PVOTE-9090efef1212', ['donor_email' => 'someone.else@mail.test']);
        $other = $this->check('AFG-PVOTE-9090efef1212',
            $this->ctx(1, self::MINE, $this->gateway(['AFG-PVOTE-9090efef1212' => $this->paid(2000)])));
        $this->assertNull($other['result']['receipt_to'], 'a member is not told another person\'s address either');
    }

    /** Our references are lower-case hex; the box upper-cases what is typed. */
    public function test_an_upper_cased_reference_still_finds_the_order(): void
    {
        $this->order('AFG-PVOTE-abcdef012345');
        $w = $this->check('AFG-PVOTE-ABCDEF012345',
            $this->ctx(null, null, $this->gateway(['AFG-PVOTE-abcdef012345' => $this->paid(2000)])));
        $this->assertSame('done', $w['steps'][0]['state']);
        $this->assertTrue($w['fixed']);
    }

    public function test_a_sandbox_nominee_is_never_named_on_the_result(): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 62, 'title' => 'Sandbox', 'slug' => 'gee-sandbox', 'is_active' => 0]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 621, 'programme_id' => 62, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 621, 'cycle_id' => 621, 'title' => 'S', 'slug' => 'gee-s']);
        $demo = (int) DB::table('gates_nominees')->insertGetId(['category_id' => 621, 'name' => 'Rehearsal Person', 'status' => 'approved']);
        // A gift (no mint to refuse), so the result card IS drawn and the only thing that can
        // keep the name off it is the sandbox clause.
        $this->order('AFG-GIVE-dede34345656', ['intent_nominee_id' => $demo, 'tier' => 'donation']);
        $this->order('AFG-GIVE-dede34345657', ['tier' => 'donation']);

        $w = $this->check('AFG-GIVE-dede34345656',
            $this->ctx(null, null, $this->gateway(['AFG-GIVE-dede34345656' => $this->paid(2000)])));
        $this->assertTrue($w['fixed'], json_encode($w));
        $this->assertNull($w['result']['nominee']);

        $live = $this->check('AFG-GIVE-dede34345657',
            $this->ctx(null, null, $this->gateway(['AFG-GIVE-dede34345657' => $this->paid(2000)])));
        $this->assertSame('Achieng Otieno', $live['result']['nominee']['name'], 'and a live one IS named — the clause is not blanket');
    }

    /** The client draws the steps it is given and starts no timer of its own. */
    public function test_the_client_never_fakes_the_timing(): void
    {
        $js = $this->read('public/assets/js/gee.js');
        $this->assertSame(1, preg_match('/function startWork\(ref, provider\) \{(.*?)\n  \}/s', $js, $start));
        $this->assertStringContainsString("i === 0 ? 'active' : 'pending'", $start[1], 'in flight: the first step active, the rest pending');
        $this->assertSame(1, preg_match('/function drawWork\(m\) \{(.*?)\n  \}/s', $js, $draw));
        $this->assertStringContainsString('w.steps.forEach', $draw[1], 'the answer draws the server\'s steps');
        $this->assertDoesNotMatchRegularExpression('/setTimeout\([^)]*\b(700|1500|2300)\b/', $js, 'the design file\'s demo timers');
        $this->assertSame(1, substr_count($js, 'setTimeout('), 'the only timer is the focus hand-off on open');
    }

    // ══ THE DESK ═════════════════════════════════════════════════════════════

    public function test_from_your_account_is_the_members_own_unresolved_payment_and_newest_open_ticket(): void
    {
        $this->order('AFG-PVOTE-0000aaaa0001', ['created_at' => Carbon::now()->subMinutes(40)->toDateTimeString()]);
        $this->order('AFG-PVOTE-0000aaaa0002', ['status' => 'confirmed', 'votes_used' => 10]);      // resolved
        $this->order('AFG-PVOTE-0000aaaa0003', ['donor_email' => 'other@mail.test']);              // not theirs
        $this->order('AFG-PVOTE-0000aaaa0004', ['created_at' => Carbon::now()->subMinutes(9)->toDateTimeString()]);

        $t = (int) DB::table('gates_support_tickets')->insertGetId(['reference' => 'AGS-GEE001', 'user_id' => 7, 'email' => self::MINE,
            'subject' => 'Refund', 'status' => 'open', 'created_at' => Carbon::now()->subDay()->toDateTimeString(),
            'last_activity' => Carbon::now()->subHours(2)->toDateTimeString()]);
        DB::table('gates_support_messages')->insert(['ticket_id' => $t, 'author_type' => 'member', 'body' => 'x']);
        DB::table('gates_support_messages')->insert(['ticket_id' => $t, 'author_type' => 'staff', 'body' => 'y']);

        $d = SupportDesk::forMember(7, self::MINE);
        $this->assertSame('AFG-PVOTE-0000aaaa0004', $d['payment']['reference'], 'the most recent unresolved one');
        $this->assertSame('Paystack', $d['payment']['provider']);
        $this->assertSame('₦2,000', $d['payment']['amount']);
        $this->assertSame('AGS-GEE001', $d['ticket']['reference']);
        $this->assertTrue($d['ticket']['replied'], 'staff spoke last');
        $this->assertTrue($d['replied']);

        // The assistant answering is not a person replying.
        DB::table('gates_support_messages')->insert(['ticket_id' => $t, 'author_type' => 'agent', 'body' => 'z']);
        $d = SupportDesk::forMember(7, self::MINE);
        $this->assertFalse($d['ticket']['replied']);
        $this->assertFalse($d['replied']);
    }

    public function test_the_desk_shows_no_refunded_expired_or_sandbox_order(): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 63, 'title' => 'Sandbox', 'slug' => 'gee-sandbox-2', 'is_active' => 0]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 631, 'programme_id' => 63, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 631, 'cycle_id' => 631, 'title' => 'S', 'slug' => 'gee-s2']);
        $demo = (int) DB::table('gates_nominees')->insertGetId(['category_id' => 631, 'name' => 'Rehearsal', 'status' => 'approved']);

        $this->order('AFG-PVOTE-1111bbbb0001', ['refunded_at' => Carbon::now()->toDateTimeString()]);
        $this->order('AFG-PVOTE-1111bbbb0002', ['expired_at' => Carbon::now()->toDateTimeString()]);
        $this->order('AFG-PVOTE-1111bbbb0003', ['intent_nominee_id' => $demo]);
        $this->assertNull(SupportDesk::forMember(7, self::MINE)['payment']);

        $this->order('AFG-PVOTE-1111bbbb0004');
        $this->assertSame('AFG-PVOTE-1111bbbb0004', SupportDesk::forMember(7, self::MINE)['payment']['reference']);
    }

    public function test_the_desk_endpoint_tells_a_guest_nothing_about_anybody(): void
    {
        $this->order('AFG-PVOTE-2222cccc0001');
        $res = ChromeRender::page('/api/support/desk', []);
        $d = json_decode((string) $res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($d['can_see_payments']);
        $this->assertNull($d['payment']);
        $this->assertNull($d['ticket']);
        $this->assertNull($d['email']);
        $this->assertSame(\AfricaGates\Services\NominationFeedbackService::slaHours(), $d['sla_hours'],
            'the reply promise comes from the one resolver');
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
    }

    public function test_the_check_button_runs_the_repair_and_only_for_a_reference_shape(): void
    {
        $res = ChromeRender::page('/api/support/chat', [], [], 'POST', ['check' => 'AFG <script>'],
                                  ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(422, $res->getStatusCode());

        $this->order('AFG-PVOTE-3333dddd0001', ['status' => 'confirmed', 'votes_used' => 10]);
        $res = ChromeRender::page('/api/support/chat', [], [], 'POST', ['check' => 'AFG-PVOTE-3333dddd0001'],
                                  ['X-Requested-With' => 'XMLHttpRequest']);
        $d = json_decode((string) $res->getBody(), true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['fix_payment'], $d['used']);
        $this->assertSame([['key' => 'order', 'state' => 'done']], $d['work']['steps']);
    }

    /** Screenshots: images only, five megabytes — decided on the bytes, not the name. */
    public function test_a_screenshot_is_an_image_under_five_megabytes_by_its_bytes(): void
    {
        $dir = sys_get_temp_dir() . '/gee-shot-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $pdf = $dir . '/receipt.png';
        file_put_contents($pdf, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $file = new \Slim\Psr7\UploadedFile($pdf, 'receipt.png', 'image/png', filesize($pdf), UPLOAD_ERR_OK);
        $r = \AfricaGates\Services\SupportAttachmentService::store($file, 1, null, 'member', null, true);
        $this->assertFalse($r['ok'], 'a PDF named .png is refused for a screenshot');

        $big = new \Slim\Psr7\UploadedFile($pdf, 'big.png', 'image/png', 5 * 1024 * 1024 + 1, UPLOAD_ERR_OK);
        $r = \AfricaGates\Services\SupportAttachmentService::store($big, 1, null, 'member', null, true);
        $this->assertFalse($r['ok']);
        // The smaller of 5MB and what this server accepts. On a host allowing more than 5MB
        // (run with -d upload_max_filesize=10M -d post_max_size=10M to see it) this is 5MB.
        $cap = round(min(5 * 1024 * 1024, \AfricaGates\Services\SupportAttachmentService::limitBytes()) / 1048576, 1) . 'MB';
        $this->assertStringContainsString($cap, (string) $r['message'], 'the smaller of 5MB and what this server accepts');

        $this->assertSame(['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            \AfricaGates\Services\SupportAttachmentService::SCREENSHOT_TYPES);
        $twig = $this->read('templates/partials/gee.twig');
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp,image/gif"', $twig);
        $this->assertMatchesRegularExpression("/screenshots\(\\\$req, [^)]*\)/", $this->read('src/Controllers/SupportController.php'));
        $this->assertStringContainsString('screenshot: true', $this->read('src/Controllers/SupportController.php'));
        @unlink($pdf); @rmdir($dir);
    }

    /** "Pass this to a person" with nothing said asks first; it never files an empty ticket. */
    public function test_the_handoff_asks_before_it_files(): void
    {
        $js = $this->read('public/assets/js/gee.js');
        $this->assertSame(1, preg_match('/function escalate\(m, btn, emailInput\) \{(.*?)\n  \}/s', $js, $m));
        $body = $m[1];
        $ask  = strpos($body, "M('handoff-first')");
        $post = strpos($body, "post('/api/support/escalate'");
        $this->assertNotFalse($ask);
        $this->assertNotFalse($post);
        $this->assertLessThan($post, $ask);
        $this->assertMatchesRegularExpression("/if \(!problem\) \{.*?return;\s*\}/s", $body);
        // The problem is the PERSON's last words — never the request to talk to a person.
        $this->assertStringContainsString("x.kind === 'me' && !x.ask", $body);
    }

    // ══ THE OLD ADDRESS ══════════════════════════════════════════════════════

    public function test_the_retired_desk_is_a_301_to_the_help_centre_with_gee_open_and_q_kept(): void
    {
        $res = ChromeRender::page('/support/assistant?q=' . rawurlencode('My votes never arrived') . '&utm_source=x');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/help?gee=support&q=My+votes+never+arrived', $res->getHeaderLine('Location'));

        $res = ChromeRender::page('/support/assistant');
        $this->assertSame('/help?gee=support', $res->getHeaderLine('Location'));

        $res = ChromeRender::page('/support/assistant?ref=AFG-PVOTE-0a0a0a0a0a0a&ask=1');
        $this->assertSame('/help?gee=support&ref=AFG-PVOTE-0a0a0a0a0a0a&ask=1', $res->getHeaderLine('Location'));
    }

    /**
     * Every parameter the redirect keeps is one the script reads — and only from the
     * URLSearchParams object, only beside `gee=support`, `q` capped, `ref` held to a shape.
     */
    public function test_every_parameter_the_redirect_keeps_is_read_and_bounded(): void
    {
        preg_match("/array_flip\(\['q', 'ref', 'topic', 'ask'\]\)/", $this->read('src/routes.php'), $kept);
        $this->assertNotEmpty($kept, 'the redirect keeps exactly q, ref, topic and ask');

        $js = $this->read('public/assets/js/gee.js');
        $this->assertSame(1, preg_match('/function fromLink\(\) \{(.*?)\n  \}/s', $js, $m));
        $body = $m[1];
        foreach (['q', 'ref', 'topic', 'ask'] as $p) {
            $this->assertStringContainsString("p.get('{$p}')", $body, "fromLink() never reads ?{$p}=");
        }
        preg_match_all('/(\w+)\.get\(/', $body, $g);
        $this->assertSame(['p'], array_values(array_unique($g[1])), '.get() only on the URLSearchParams object');
        $this->assertMatchesRegularExpression("/if \(p\.get\('gee'\) !== 'support'\) return;/", $body);
        $this->assertStringContainsString('.slice(0, MAX_Q)', $body);
        $this->assertMatchesRegularExpression('/var MAX_Q\s*=\s*300;/', $js);
        $this->assertStringContainsString('REF_SHAPE.test(ref)', $body);
        $this->assertMatchesRegularExpression('/if \(ask\) runCheck\(ref\);/', $body, 'ask=1 RUNS the repair');
        $this->assertStringContainsString('history.replaceState', $body, 'a reload must not repair again');
    }

    public function test_the_public_api_is_open_and_close(): void
    {
        $js = $this->read('public/assets/js/gee.js');
        $this->assertMatchesRegularExpression('/window\.AGGee = \{\s*open: function \(o\) \{ o = o \|\| \{\}; open\(\{ mode: o\.mode, q: o\.q/', $js);
        $this->assertMatchesRegularExpression('/\n    close: close\n  \};/', $js);
        $this->assertStringNotContainsString('window.openGee', $js, 'no aliases for the destroyed hooks');
    }
}
